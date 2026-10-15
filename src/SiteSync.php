<?php
declare(strict_types=1);

namespace DDP;

use Throwable;

/**
 * Synchronisation avec lesdamesduparc.com.
 *
 * - pull() : charge les billets vendus sur le site (événements publiés d'hier à dans 90 jours) : billets valides, annulés, anciens
 *   codes remplacés ou annulés, présences déjà relevées sur le site. Limité à un chargement toutes les « sync_seconds » secondes et
 *   protégé par un verrou MariaDB (un seul chargement à la fois, même avec plusieurs contrôleurs).
 * - push() : après une entrée validée dans l'application, la participante est marquée « présente » sur le site. En cas d'échec
 *   (réseau, site indisponible), la présence reste en attente (tickets.site_pending = 1) et est renvoyée au chargement suivant.
 *
 * L'application reste seule juge de la validité à l'entrée (validation atomique dans MariaDB) ; le site reçoit les présences.
 */
final class SiteSync
{
    public static function enabled(): bool
    {
        $key = (string) App::config('site.api_key', '');
        return (string) App::config('site.url', '') !== '' && strlen($key) >= 32 && !str_starts_with($key, 'REMPLACER');
    }

    public static function state(string $k): ?string
    {
        $st = App::db()->prepare('SELECT v FROM app_state WHERE k = ?');
        $st->execute([$k]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    private static function setState(string $k, ?string $v): void
    {
        App::db()->prepare('INSERT INTO app_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
    }

    /** État affiché dans l'application : activé, dernier chargement réussi, dernière erreur, présences en attente. */
    public static function status(): array
    {
        if (!self::enabled()) {
            return ['enabled' => false];
        }
        $last = self::state('site_last_pull');
        return [
            'enabled' => true,
            'last_pull' => $last ? App::localTime($last, 'd/m/Y H:i:s') : null,
            'seconds_ago' => $last ? max(0, time() - strtotime($last . ' UTC')) : null,
            'error' => self::state('site_last_error'),
            'pending' => (int) App::db()->query('SELECT COUNT(*) FROM tickets WHERE site_pending = 1')->fetchColumn(),
        ];
    }

    private static function request(string $method, string $path, ?array $body = null): array
    {
        $url = rtrim((string) App::config('site.url'), '/') . $path;
        $headers = ['Authorization: Bearer ' . App::config('site.api_key'), 'Accept: application/json', 'User-Agent: DDP-Controle/1.0'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : '',
            'timeout' => (float) App::config('site.timeout', 6),
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        if ($raw === false || $status === 0) {
            throw new \RuntimeException('Site injoignable.');
        }
        $data = json_decode($raw, true);
        if ($status === 401) {
            throw new \RuntimeException('Clé d\'accès au site refusée (vérifiez site.api_key et SCANNER_API_KEY).');
        }
        if ($status >= 400 || !is_array($data)) {
            throw new \RuntimeException("Réponse inattendue du site (HTTP $status).");
        }
        return $data;
    }

    /**
     * Charge les billets du site si le dernier chargement date de plus de $maxAge secondes (null : délai de la configuration).
     * $waitLock : secondes d'attente si un autre chargement est en cours (0 : on n'attend pas).
     * Retourne ['ok' => bool, 'skipped' => bool, 'tickets' => n, 'error' => ?string].
     */
    public static function pull(?int $maxAge = null, int $waitLock = 0): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'skipped' => true, 'error' => null];
        }
        $db = App::db();
        $maxAge ??= (int) App::config('site.sync_seconds', 30);
        $last = self::state('site_last_attempt');
        if ($last && time() - strtotime($last . ' UTC') < $maxAge) {
            return ['ok' => true, 'skipped' => true, 'error' => self::state('site_last_error')];
        }
        $lock = $db->prepare("SELECT GET_LOCK('ddp_site_sync', ?)");
        $lock->execute([$waitLock]);
        if ((int) $lock->fetchColumn() !== 1) {
            return ['ok' => true, 'skipped' => true, 'error' => null]; // un autre contrôleur charge déjà
        }
        try {
            self::setState('site_last_attempt', gmdate('Y-m-d H:i:s'));
            $data = self::request('GET', '/api/scanner/tickets');
            $n = self::apply($data);
            self::setState('site_last_pull', gmdate('Y-m-d H:i:s'));
            self::setState('site_last_error', null);
            self::retryPending();
            return ['ok' => true, 'skipped' => false, 'tickets' => $n, 'error' => null];
        } catch (Throwable $e) {
            self::setState('site_last_error', mb_substr($e->getMessage(), 0, 250));
            Log::error('Synchronisation avec le site impossible', ['error' => $e->getMessage()]);
            return ['ok' => false, 'skipped' => false, 'error' => $e->getMessage()];
        } finally {
            $db->query("SELECT RELEASE_LOCK('ddp_site_sync')");
        }
    }

    /** Applique la liste du site à la base locale. Retourne le nombre de billets reçus. */
    private static function apply(array $data): int
    {
        $db = App::db();
        $eventIds = [];
        $up = $db->prepare('INSERT INTO events (name, starts_at, location, address, external_id, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, UTC_TIMESTAMP())
                            ON DUPLICATE KEY UPDATE name = VALUES(name), starts_at = VALUES(starts_at), location = VALUES(location), address = VALUES(address)');
        foreach ((array) ($data['events'] ?? []) as $e) {
            if (!is_array($e) || !is_string($e['id'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($e['date'] ?? ''))) {
                continue;
            }
            $time = preg_match('/^\d{2}:\d{2}$/', (string) ($e['time'] ?? '')) ? $e['time'] : '00:00';
            $address = trim(implode(', ', array_filter([$e['address'] ?? null, $e['city'] ?? null], static fn ($x) => is_string($x) && $x !== '' && !preg_match('/communiqu/i', $x))));
            $up->execute([mb_substr((string) ($e['title'] ?? 'Événement'), 0, 160), App::toUtc($e['date'] . " $time:00"), mb_substr((string) ($e['venue'] ?? ''), 0, 200) ?: null, $address !== '' ? mb_substr($address, 0, 255) : null, mb_substr($e['id'], 0, 80)]);
            $st = $db->prepare('SELECT id FROM events WHERE external_id = ?');
            $st->execute([$e['id']]);
            $eventIds[$e['id']] = (int) $st->fetchColumn();
        }

        $find = $db->prepare('SELECT id, status, cancel_reason, site_pending FROM tickets WHERE token = ?');
        $ins = $db->prepare('INSERT INTO tickets (token, event_id, holder_name, reference, places, birth_date, member_hash, external_id, status, cancel_reason, used_at, cancelled_at, created_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
        $upd = $db->prepare('UPDATE tickets SET event_id = ?, holder_name = ?, reference = ?, places = ?, birth_date = ?, member_hash = ?, external_id = ? WHERE id = ?');
        $seen = [];
        $count = 0;
        foreach ((array) ($data['tickets'] ?? []) as $t) {
            if (!is_array($t) || !is_string($t['code'] ?? null) || !TicketCode::isValidFormat($t['code']) || !isset($eventIds[$t['event'] ?? ''])) {
                continue;
            }
            $count++;
            $seen[] = $t['code'];
            $eventId = $eventIds[$t['event']];
            $holder = mb_substr((string) ($t['holder'] ?? ''), 0, 160) ?: null;
            $ref = mb_substr((string) ($t['memberNumber'] ?? ''), 0, 80) ?: null;
            $places = max(1, min(50, (int) ($t['places'] ?? 1)));
            $birth = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($t['birthDate'] ?? '')) ? $t['birthDate'] : null;
            $member = preg_match('/^[0-9a-f]{64}$/', (string) ($t['member'] ?? '')) ? $t['member'] : null;
            $ext = mb_substr((string) ($t['id'] ?? ''), 0, 64) ?: null;
            $siteValid = ($t['status'] ?? '') === 'valid';
            $attendedAt = is_string($t['attendedAt'] ?? null) && strtotime($t['attendedAt']) ? gmdate('Y-m-d H:i:s', strtotime($t['attendedAt'])) : null;
            $find->execute([$t['code']]);
            $row = $find->fetch();
            if (!$row) {
                $status = !$siteValid ? 'cancelled' : (!empty($t['attended']) ? 'used' : 'valid');
                $ins->execute([$t['code'], $eventId, $holder, $ref, $places, $birth, $member, $ext, $status, $siteValid ? null : 'annulation', $status === 'used' ? ($attendedAt ?? gmdate('Y-m-d H:i:s')) : null, $siteValid ? null : gmdate('Y-m-d H:i:s')]);
                continue;
            }
            $upd->execute([$eventId, $holder, $ref, $places, $birth, $member, $ext, $row['id']]);
            if (!$siteValid && $row['status'] === 'valid') {
                $db->prepare("UPDATE tickets SET status = 'cancelled', cancel_reason = 'annulation', cancelled_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'valid'")->execute([$row['id']]);
            } elseif ($siteValid && $row['status'] === 'cancelled' && in_array($row['cancel_reason'], ['annulation', 'supprimé'], true)) {
                $db->prepare("UPDATE tickets SET status = 'valid', cancel_reason = NULL, cancelled_at = NULL WHERE id = ? AND status = 'cancelled'")->execute([$row['id']]);
            } elseif ($siteValid && !empty($t['attended']) && $row['status'] === 'valid') {
                // Présence déjà relevée sur le site (pointage par l'équipe) : le billet est consommé ici aussi.
                $db->prepare("UPDATE tickets SET status = 'used', used_at = ? WHERE id = ? AND status = 'valid'")->execute([$attendedAt ?? gmdate('Y-m-d H:i:s'), $row['id']]);
            } elseif ($siteValid && empty($t['attended']) && $row['status'] === 'used' && !(int) $row['site_pending']) {
                // Entrée validée ici mais pas encore connue du site : on la renvoie.
                $db->prepare('UPDATE tickets SET site_pending = 1 WHERE id = ?')->execute([$row['id']]);
            }
        }

        // Anciens codes (billet remplacé après une perte, ou annulé) : refusés à l'entrée.
        foreach ((array) ($data['revoked'] ?? []) as $r) {
            if (!is_array($r) || !is_string($r['code'] ?? null) || !TicketCode::isValidFormat($r['code']) || !isset($eventIds[$r['event'] ?? ''])) {
                continue;
            }
            $seen[] = $r['code'];
            $reason = ($r['reason'] ?? '') === 'annulation' ? 'annulation' : 'perte';
            $find->execute([$r['code']]);
            $row = $find->fetch();
            if (!$row) {
                $ins->execute([$r['code'], $eventIds[$r['event']], mb_substr((string) ($r['holder'] ?? ''), 0, 160) ?: null, null, 1, null, null, mb_substr((string) ($r['id'] ?? ''), 0, 64) ?: null, 'cancelled', $reason, null, gmdate('Y-m-d H:i:s')]);
            } elseif ($row['status'] !== 'cancelled' || $row['cancel_reason'] !== $reason) {
                $db->prepare("UPDATE tickets SET status = 'cancelled', cancel_reason = ?, cancelled_at = COALESCE(cancelled_at, UTC_TIMESTAMP()) WHERE id = ? AND status <> 'used'")->execute([$reason, $row['id']]);
            }
        }

        // Billets du site supprimés depuis (inscription effacée) : annulés ici.
        if ($eventIds) {
            $in = implode(',', array_fill(0, count($eventIds), '?'));
            $args = array_values($eventIds);
            $sql = "SELECT id, token FROM tickets WHERE external_id IS NOT NULL AND status = 'valid' AND event_id IN ($in)";
            $st = $db->prepare($sql);
            $st->execute($args);
            $keep = array_flip($seen);
            foreach ($st->fetchAll() as $row) {
                if (!isset($keep[$row['token']])) {
                    $db->prepare("UPDATE tickets SET status = 'cancelled', cancel_reason = 'supprimé', cancelled_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'valid'")->execute([$row['id']]);
                }
            }
        }
        return $count;
    }

    /** Transmet au site la présence d'un billet validé. Retourne true si le site l'a enregistrée. */
    public static function push(int $ticketId, ?string $controller = null): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $db = App::db();
        $st = $db->prepare('SELECT id, token, used_at, external_id FROM tickets WHERE id = ? AND status = \'used\' AND external_id IS NOT NULL');
        $st->execute([$ticketId]);
        $t = $st->fetch();
        if (!$t) {
            return false;
        }
        try {
            $res = self::request('POST', '/api/scanner/checkin', ['code' => $t['token'], 'scannedAt' => gmdate('c', strtotime($t['used_at'] . ' UTC')), 'controller' => $controller]);
            $result = (string) ($res['result'] ?? '');
            $error = in_array($result, ['present', 'already'], true) ? null : "Le site a répondu « $result » pour ce billet.";
            $db->prepare('UPDATE tickets SET site_pending = 0, site_error = ? WHERE id = ?')->execute([$error, $ticketId]);
            if ($error) {
                Log::error('Présence refusée par le site', ['ticket' => $ticketId, 'result' => $result]);
            }
            return $error === null;
        } catch (Throwable $e) {
            $db->prepare('UPDATE tickets SET site_pending = 1, site_error = ? WHERE id = ?')->execute([mb_substr($e->getMessage(), 0, 250), $ticketId]);
            return false;
        }
    }

    /** Renvoie les présences en attente (après une panne du réseau ou du site). */
    public static function retryPending(int $limit = 100): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $ids = App::db()->query("SELECT t.id FROM tickets t WHERE t.site_pending = 1 AND t.status = 'used' ORDER BY t.used_at LIMIT " . max(1, $limit))->fetchAll(\PDO::FETCH_COLUMN);
        $ok = 0;
        foreach ($ids as $id) {
            if (self::push((int) $id)) {
                $ok++;
            } else {
                break; // site toujours indisponible : inutile d'insister maintenant
            }
        }
        return $ok;
    }
}
