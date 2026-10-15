<?php
declare(strict_types=1);

namespace DDP;

/** Limitation des tentatives de connexion, stockée en base (fiable même avec plusieurs processus PHP). */
final class RateLimiter
{
    public static function loginBlocked(string $username, string $ip): bool
    {
        $cfg = (array) App::config('login_limit', []);
        $window = (int) ($cfg['window_minutes'] ?? 15);
        $db = App::db();
        $st = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND success = 0 AND attempted_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE');
        $st->execute([$username, $window]);
        if ((int) $st->fetchColumn() >= (int) ($cfg['max_per_user'] ?? 5)) {
            return true;
        }
        $st = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND success = 0 AND attempted_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE');
        $st->execute([$ip, $window]);
        return (int) $st->fetchColumn() >= (int) ($cfg['max_per_ip'] ?? 20);
    }

    public static function recordLogin(string $username, string $ip, bool $success): void
    {
        $db = App::db();
        $db->prepare('INSERT INTO login_attempts (username, ip_address, success, attempted_at) VALUES (?, ?, ?, UTC_TIMESTAMP())')->execute([mb_substr($username, 0, 60), $ip, $success ? 1 : 0]);
        if ($success) {
            // Une connexion réussie remet le compteur de l'identifiant à zéro.
            $db->prepare('DELETE FROM login_attempts WHERE username = ? AND success = 0')->execute([mb_substr($username, 0, 60)]);
        }
        // Ménage : on ne garde que 30 jours d'historique.
        if (random_int(1, 50) === 1) {
            $db->exec('DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 30 DAY');
        }
    }
}
