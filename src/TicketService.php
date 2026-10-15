<?php
declare(strict_types=1);

namespace DDP;

use PDO;
use Throwable;

/**
 * Contrôle et gestion des billets.
 *
 * Validation atomique : dans une transaction MariaDB, la ligne du billet est verrouillée (SELECT … FOR UPDATE), son statut est
 * vérifié, puis passé à « used » par une mise à jour conditionnelle (WHERE status = 'valid') dont on contrôle qu'elle a bien modifié
 * une ligne. Deux contrôleurs qui scannent le même billet au même instant sont donc sérialisés par le verrou : le premier valide,
 * le second lit « used » et reçoit « déjà utilisé ». En cas d'erreur, la transaction est annulée et le résultat est « erreur
 * technique » : l'accès n'est jamais accordé par défaut.
 */
final class TicketService
{
    public const RESULTS = ['valid', 'already_used', 'invalid', 'cancelled', 'wrong_event', 'error'];

    /**
     * @return array{result:string, message:string, ticket?:array, used_at?:string|null, used_by?:string|null}
     */
    public static function validate(string $raw, int $controllerId, ?int $eventId, string $method, ?int $ticketId = null): array
    {
        $method = in_array($method, ['manual', 'list'], true) ? $method : 'scan';
        $hint = TicketCode::hint($raw);
        $hash = hash('sha256', trim($raw));
        $db = App::db();

        // Contrôle depuis la liste des participantes (billet oublié, téléphone déchargé…) : par le numéro interne du billet.
        if ($ticketId !== null) {
            $st = $db->prepare('SELECT token FROM tickets WHERE id = ?');
            $st->execute([$ticketId]);
            $token = $st->fetchColumn();
            if ($token === false) {
                self::log(null, $eventId, $controllerId, 'invalid', $method, '#' . $ticketId, $hash);
                return ['result' => 'invalid', 'message' => 'Billet introuvable.'];
            }
            $raw = (string) $token;
            $hint = TicketCode::hint($raw);
        }

        // QR code de la carte de membre (lien « …/verification/<jeton> ») : billet de cette adhérente pour l'événement contrôlé.
        if (preg_match('~/verification/([A-Za-z0-9_-]{8,128})(?:[/?#]|$)~', $raw, $m)) {
            if ($eventId === null) {
                self::log(null, null, $controllerId, 'invalid', $method, 'carte', $hash);
                return ['result' => 'invalid', 'message' => 'Carte de membre : choisissez d\'abord l\'événement contrôlé.'];
            }
            $st = $db->prepare("SELECT token FROM tickets WHERE member_hash = ? AND event_id = ? ORDER BY FIELD(status, 'valid', 'used', 'cancelled'), id DESC LIMIT 1");
            $st->execute([hash('sha256', $m[1]), $eventId]);
            $token = $st->fetchColumn();
            if ($token === false) {
                self::log(null, $eventId, $controllerId, 'invalid', $method, 'carte', $hash);
                return ['result' => 'invalid', 'message' => 'Carte de membre reconnue, mais aucun billet pour cet événement.'];
            }
            $raw = (string) $token;
            $hint = 'carte';
        }
        $parsed = TicketCode::parse($raw);

        if ($parsed['error'] !== null || $parsed['token'] === null) {
            self::log(null, $eventId, $controllerId, 'invalid', $method, $hint, $hash);
            return ['result' => 'invalid', 'message' => $parsed['error'] === 'signature' ? 'Signature du QR code invalide : billet falsifié ou modifié.' : 'Code illisible ou au mauvais format.'];
        }

        // Le journal du contrôle est écrit dans la même transaction, pendant que le billet est verrouillé : les contrôles d'un même
        // billet passent strictement l'un après l'autre. Si MariaDB annule malgré tout une transaction en conflit (interblocage,
        // attente de verrou trop longue), elle est rejouée.
        for ($attempt = 1; ; $attempt++) {
            try {
                return self::validateOnce($db, $parsed['token'], $controllerId, $eventId, $method, $hint, $hash);
            } catch (\PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $code = (int) ($e->errorInfo[1] ?? 0);
                if (($code === 1213 || $code === 1205) && $attempt < 4) {
                    Log::info('Contrôle rejoué après conflit de verrou', ['code' => $code, 'attempt' => $attempt]);
                    usleep(random_int(5000, 40000) * $attempt);
                    continue;
                }
                return self::failure($db, $e, $eventId, $controllerId, $method, $hint, $hash);
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                return self::failure($db, $e, $eventId, $controllerId, $method, $hint, $hash);
            }
        }
    }

    private static function failure(PDO $db, Throwable $e, ?int $eventId, int $controllerId, string $method, string $hint, string $hash): array
    {
        Log::error('Contrôle de billet impossible', ['error' => $e->getMessage()]);
        try {
            self::log(null, $eventId, $controllerId, 'error', $method, $hint, $hash);
        } catch (Throwable) {
            // base indisponible : le résultat reste « erreur », sans autorisation
        }
        return ['result' => 'error', 'message' => 'Contrôle impossible : accès NON autorisé. Réessayez ou contrôlez manuellement.'];
    }

    private static function validateOnce(PDO $db, string $token, int $controllerId, ?int $eventId, string $method, string $hint, string $hash): array
    {
            $db->beginTransaction();
            $st = $db->prepare(
                'SELECT t.id, t.token, t.event_id, t.holder_name, t.reference, t.places, t.birth_date, t.external_id, t.status, t.cancel_reason, t.used_at, t.used_by, u.display_name AS used_by_name, e.name AS event_name, e.starts_at
                   FROM tickets t
                   JOIN events e ON e.id = t.event_id
                   LEFT JOIN users u ON u.id = t.used_by
                  WHERE t.token = ?
                  FOR UPDATE'
            );
            $st->execute([$token]);
            $t = $st->fetch();

            if (!$t) {
                self::log(null, $eventId, $controllerId, 'invalid', $method, $hint, $hash);
                $db->commit();
                return ['result' => 'invalid', 'message' => 'Billet inconnu : aucun billet ne correspond à ce code.'];
            }
            $ticketId = (int) $t['id'];
            $public = self::publicTicket($t);

            if ($eventId !== null && (int) $t['event_id'] !== $eventId) {
                self::log($ticketId, $eventId, $controllerId, 'wrong_event', $method, $hint, $hash);
                $db->commit();
                return ['result' => 'wrong_event', 'message' => 'Billet d\'un autre événement : « ' . $t['event_name'] . ' ».', 'ticket' => $public];
            }
            if ($t['status'] === 'cancelled') {
                self::log($ticketId, $eventId, $controllerId, 'cancelled', $method, $hint, $hash);
                $db->commit();
                $why = match ($t['cancel_reason']) {
                    'perte' => 'Billet remplacé : un nouveau billet a été émis après une perte. Seul le nouveau billet est valable.',
                    'annulation' => 'Billet annulé par la participante : accès refusé.',
                    'supprimé' => 'Inscription supprimée par l\'équipe : accès refusé.',
                    default => 'Billet annulé : accès refusé.',
                };
                return ['result' => 'cancelled', 'message' => $why, 'ticket' => $public];
            }
            if ($t['status'] === 'used') {
                self::log($ticketId, $eventId, $controllerId, 'already_used', $method, $hint, $hash);
                $db->commit();
                return ['result' => 'already_used', 'message' => 'Billet déjà utilisé : accès refusé.', 'ticket' => $public, 'used_at' => App::localTime($t['used_at'], 'H:i:s'), 'used_at_full' => App::localTime($t['used_at']), 'used_by' => $t['used_by_name']];
            }

            // Passage atomique valid → used : la condition sur le statut garantit qu'une seule validation peut réussir.
            // site_pending : la présence sera transmise à lesdamesduparc.com juste après la réponse (billets venant du site).
            $up = $db->prepare("UPDATE tickets SET status = 'used', used_at = UTC_TIMESTAMP(), used_by = ?, site_pending = (external_id IS NOT NULL) WHERE id = ? AND status = 'valid'");
            $up->execute([$controllerId, $ticketId]);
            if ($up->rowCount() !== 1) {
                throw new \RuntimeException('Mise à jour du billet non appliquée.');
            }
            self::log($ticketId, $eventId, $controllerId, 'valid', $method, $hint, $hash);
            $db->commit();
            $public['status'] = 'used';
            return ['result' => 'valid', 'message' => 'Billet valide : accès autorisé.', 'ticket' => $public];
    }

    /** Statut d'un billet sans le modifier (consultation). */
    public static function status(string $raw): ?array
    {
        $parsed = TicketCode::parse($raw);
        if ($parsed['token'] === null || $parsed['error'] !== null) {
            return null;
        }
        $st = App::db()->prepare('SELECT t.*, u.display_name AS used_by_name, e.name AS event_name, e.starts_at FROM tickets t JOIN events e ON e.id = t.event_id LEFT JOIN users u ON u.id = t.used_by WHERE t.token = ?');
        $st->execute([$parsed['token']]);
        $t = $st->fetch();
        return $t ? self::publicTicket($t) + ['used_at' => App::localTime($t['used_at']), 'used_by' => $t['used_by_name'], 'created_at' => App::localTime($t['created_at'])] : null;
    }

    public static function publicTicket(array $t): array
    {
        return [
            'id'          => (int) $t['id'],
            'holder_name' => $t['holder_name'],
            'reference'   => $t['reference'],
            'places'      => (int) ($t['places'] ?? 1),
            'birth_date'  => !empty($t['birth_date']) ? date('d/m/Y', strtotime((string) $t['birth_date'])) : null,
            'from_site'   => !empty($t['external_id']),
            'event_id'    => (int) $t['event_id'],
            'event_name'  => $t['event_name'] ?? null,
            'event_date'  => isset($t['starts_at']) ? App::localTime($t['starts_at'], 'd/m/Y H:i') : null,
            'status'      => $t['status'],
        ];
    }

    private static function log(?int $ticketId, ?int $eventId, int $userId, string $result, string $method, string $hint, string $hash): void
    {
        App::db()->prepare('INSERT INTO check_logs (ticket_id, event_id, user_id, result, method, code_hint, code_hash, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(3))')
            ->execute([$ticketId, $eventId, $userId, $result, $method, $hint, $hash, PHP_SAPI === 'cli' ? 'cli' : Http::clientIp()]);
    }

    /** Création de billets (jetons aléatoires uniques). */
    public static function create(int $eventId, int $count, ?string $holder = null, ?string $reference = null): array
    {
        $db = App::db();
        $tokens = [];
        $st = $db->prepare('INSERT INTO tickets (token, event_id, holder_name, reference, status, created_at) VALUES (?, ?, ?, ?, \'valid\', UTC_TIMESTAMP())');
        $db->beginTransaction();
        try {
            for ($i = 0; $i < $count; $i++) {
                for ($try = 0; ; $try++) {
                    $token = TicketCode::generate();
                    try {
                        $st->execute([$token, $eventId, $holder ?: null, $reference ?: null]);
                        break;
                    } catch (\PDOException $e) {
                        if ($try >= 3 || ($e->errorInfo[1] ?? 0) !== 1062) {
                            throw $e;
                        }
                    }
                }
                $tokens[] = ['id' => (int) $db->lastInsertId(), 'token' => $token];
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return $tokens;
    }

    /** Annulation ou réactivation (billet non utilisé) par un administrateur. */
    public static function setCancelled(int $ticketId, bool $cancel): bool
    {
        if ($cancel) {
            $st = App::db()->prepare("UPDATE tickets SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'valid'");
        } else {
            $st = App::db()->prepare("UPDATE tickets SET status = 'valid', cancelled_at = NULL WHERE id = ? AND status = 'cancelled'");
        }
        $st->execute([$ticketId]);
        return $st->rowCount() === 1;
    }

    /** Remise à « valide » d'un billet utilisé par erreur (sortie autorisée, erreur de scan) : opération administrateur journalisée. */
    public static function resetUsed(int $ticketId): bool
    {
        $st = App::db()->prepare("UPDATE tickets SET status = 'valid', used_at = NULL, used_by = NULL WHERE id = ? AND status = 'used'");
        $st->execute([$ticketId]);
        return $st->rowCount() === 1;
    }
}
