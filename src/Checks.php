<?php
declare(strict_types=1);

namespace DDP;

/** Statistiques et historique des contrôles, calculés en direct depuis MariaDB. */
final class Checks
{
    public const LABELS = [
        'valid'        => 'Valide',
        'already_used' => 'Déjà utilisé',
        'invalid'      => 'Invalide',
        'cancelled'    => 'Annulé',
        'wrong_event'  => 'Autre événement',
        'error'        => 'Erreur technique',
    ];

    public static function stats(?int $eventId): array
    {
        $db = App::db();
        $where = $eventId ? 'WHERE event_id = ?' : '';
        $args = $eventId ? [$eventId] : [];
        $st = $db->prepare("SELECT COUNT(*) AS total, SUM(status = 'valid') AS valid, SUM(status = 'used') AS used, SUM(status = 'cancelled') AS cancelled FROM tickets $where");
        $st->execute($args);
        $t = $st->fetch() ?: [];
        $st = $db->prepare("SELECT result, COUNT(*) AS n FROM check_logs " . ($eventId ? 'WHERE event_id = ?' : '') . " GROUP BY result");
        $st->execute($args);
        $byResult = array_fill_keys(array_keys(self::LABELS), 0);
        foreach ($st->fetchAll() as $r) {
            $byResult[$r['result']] = (int) $r['n'];
        }
        $st = $db->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS h, COUNT(*) AS n FROM check_logs WHERE result = 'valid' " . ($eventId ? 'AND event_id = ?' : '') . " AND created_at > UTC_TIMESTAMP() - INTERVAL 24 HOUR GROUP BY h ORDER BY h");
        $st->execute($args);
        $hours = array_map(static fn ($r) => ['hour' => App::localTime($r['h'], 'H\h'), 'n' => (int) $r['n']], $st->fetchAll());
        return [
            'tickets' => ['total' => (int) ($t['total'] ?? 0), 'valid' => (int) ($t['valid'] ?? 0), 'used' => (int) ($t['used'] ?? 0), 'cancelled' => (int) ($t['cancelled'] ?? 0)],
            'checks' => $byResult + ['total' => array_sum($byResult)],
            'entries_last_24h' => $hours,
        ];
    }

    /** Filtres : event_id, user_id, result, from (AAAA-MM-JJ), to, q (code ou référence/nom). */
    public static function filters(array $src): array
    {
        $f = [];
        foreach (['event_id', 'user_id'] as $k) {
            $v = Http::int($src, $k);
            if ($v) {
                $f[$k] = $v;
            }
        }
        $r = Http::str($src, 'result', 20);
        if (isset(self::LABELS[$r])) {
            $f['result'] = $r;
        }
        foreach (['from', 'to'] as $k) {
            $v = Http::str($src, $k, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $f[$k] = $v;
            }
        }
        $q = Http::str($src, 'q', 80);
        if ($q !== '') {
            $f['q'] = $q;
        }
        return $f;
    }

    private static function where(array $f): array
    {
        $w = [];
        $a = [];
        if (isset($f['event_id'])) {
            $w[] = 'c.event_id = ?';
            $a[] = $f['event_id'];
        }
        if (isset($f['user_id'])) {
            $w[] = 'c.user_id = ?';
            $a[] = $f['user_id'];
        }
        if (isset($f['result'])) {
            $w[] = 'c.result = ?';
            $a[] = $f['result'];
        }
        if (isset($f['from'])) {
            $w[] = 'c.created_at >= ?';
            $a[] = App::toUtc($f['from'] . ' 00:00:00');
        }
        if (isset($f['to'])) {
            $w[] = 'c.created_at < ?';
            $a[] = App::toUtc($f['to'] . ' 00:00:00 +1 day');
        }
        if (isset($f['q'])) {
            $w[] = '(t.token = ? OR c.code_hash = ? OR t.reference LIKE ? OR t.holder_name LIKE ?)';
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            array_push($a, $f['q'], hash('sha256', $f['q']), $like, $like);
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a];
    }

    public static function list(array $f, int $page = 1, int $perPage = 50): array
    {
        [$where, $args] = self::where($f);
        $db = App::db();
        $st = $db->prepare("SELECT COUNT(*) FROM check_logs c LEFT JOIN tickets t ON t.id = c.ticket_id $where");
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $offset = max(0, ($page - 1) * $perPage);
        $st = $db->prepare("SELECT c.id, c.created_at, c.result, c.method, c.code_hint, t.id AS ticket_id, t.holder_name, t.reference, e.name AS event_name, u.display_name AS controller
                              FROM check_logs c
                              LEFT JOIN tickets t ON t.id = c.ticket_id
                              LEFT JOIN events e ON e.id = c.event_id
                              LEFT JOIN users u ON u.id = c.user_id
                              $where ORDER BY c.id DESC LIMIT $perPage OFFSET $offset");
        $st->execute($args);
        $rows = array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'at' => App::localTime($r['created_at']),
            'result' => $r['result'],
            'result_label' => self::LABELS[$r['result']] ?? $r['result'],
            'method' => $r['method'] === 'manual' ? 'Saisie' : 'Scan',
            'code' => $r['code_hint'],
            'ticket_id' => $r['ticket_id'] !== null ? (int) $r['ticket_id'] : null,
            'holder' => $r['holder_name'],
            'reference' => $r['reference'],
            'event' => $r['event_name'],
            'controller' => $r['controller'],
        ], $st->fetchAll());
        return ['total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage)), 'rows' => $rows];
    }

    /** Lignes pour l'export CSV (générateur : pas de chargement complet en mémoire). */
    public static function exportRows(array $f): \Generator
    {
        [$where, $args] = self::where($f);
        $st = App::db()->prepare("SELECT c.created_at, c.result, c.method, c.code_hint, t.id AS ticket_id, t.holder_name, t.reference, e.name AS event_name, u.username, c.ip_address
                                    FROM check_logs c
                                    LEFT JOIN tickets t ON t.id = c.ticket_id
                                    LEFT JOIN events e ON e.id = c.event_id
                                    LEFT JOIN users u ON u.id = c.user_id
                                    $where ORDER BY c.id");
        $st->execute($args);
        while ($r = $st->fetch()) {
            yield [App::localTime($r['created_at']), self::LABELS[$r['result']] ?? $r['result'], $r['method'] === 'manual' ? 'Saisie' : 'Scan', $r['event_name'], $r['ticket_id'], $r['holder_name'], $r['reference'], $r['code_hint'], $r['username']];
        }
    }
}
