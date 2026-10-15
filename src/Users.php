<?php
declare(strict_types=1);

namespace DDP;

/** Comptes administrateurs et contrôleurs. */
final class Users
{
    public const ROLES = ['admin' => 'Administrateur', 'controller' => 'Contrôleur'];

    public static function validUsername(string $u): bool
    {
        return (bool) preg_match('/^[a-z0-9._-]{3,60}$/', $u);
    }

    /** Mot de passe : 10 caractères minimum, au moins une lettre et un chiffre. */
    public static function passwordError(string $p): ?string
    {
        if (mb_strlen($p) < 10) {
            return 'Le mot de passe doit contenir au moins 10 caractères.';
        }
        if (mb_strlen($p) > 200) {
            return 'Mot de passe trop long.';
        }
        if (!preg_match('/\pL/u', $p) || !preg_match('/\d/', $p)) {
            return 'Le mot de passe doit contenir au moins une lettre et un chiffre.';
        }
        return null;
    }

    public static function all(): array
    {
        $rows = App::db()->query("SELECT u.id, u.username, u.display_name, u.role, u.is_active, u.created_at, u.last_login_at,
                                         (SELECT COUNT(*) FROM check_logs c WHERE c.user_id = u.id) AS checks
                                    FROM users u ORDER BY u.role, u.display_name")->fetchAll();
        return array_map(static fn ($u) => [
            'id' => (int) $u['id'],
            'username' => $u['username'],
            'display_name' => $u['display_name'],
            'role' => $u['role'],
            'is_active' => (bool) $u['is_active'],
            'created_at' => App::localTime($u['created_at'], 'd/m/Y'),
            'last_login_at' => App::localTime($u['last_login_at'], 'd/m/Y H:i'),
            'checks' => (int) $u['checks'],
        ], $rows);
    }

    public static function create(string $username, string $displayName, string $password, string $role): int
    {
        App::db()->prepare('INSERT INTO users (username, display_name, password_hash, role, is_active, created_at) VALUES (?, ?, ?, ?, 1, UTC_TIMESTAMP())')
            ->execute([$username, $displayName, password_hash($password, PASSWORD_DEFAULT), $role]);
        return (int) App::db()->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $st = App::db()->prepare('SELECT id, username, display_name, role, is_active FROM users WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function activeAdminCount(): int
    {
        return (int) App::db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
    }
}
