<?php
declare(strict_types=1);

namespace DDP;

/** Journal des opérations sensibles (connexions, comptes, imports, annulations de billets, exports…). */
final class Audit
{
    public static function log(string $action, ?string $target = null, ?int $userId = null, array $details = []): void
    {
        try {
            $uid = $userId ?? (Auth::user()['id'] ?? null);
            $ip = PHP_SAPI === 'cli' ? 'cli' : Http::clientIp();
            App::db()->prepare('INSERT INTO audit_logs (user_id, action, target, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())')
                ->execute([$uid, $action, $target !== null ? mb_substr($target, 0, 120) : null, $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null, $ip]);
        } catch (\Throwable $e) {
            Log::error('Journal d\'audit indisponible', ['action' => $action, 'message' => $e->getMessage()]);
        }
    }
}
