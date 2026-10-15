<?php
declare(strict_types=1);

namespace DDP;

/**
 * Sessions et droits d'accès. Cookie de session HttpOnly, Secure (HTTPS) et SameSite=Strict ; identifiant régénéré à la connexion ;
 * expiration après inactivité et durée maximale ; le compte est relu en base à chaque requête (un compte désactivé ou supprimé
 * perd l'accès immédiatement). Refus par défaut : toute page ou API interne exige une session valide.
 */
final class Auth
{
    private static ?array $user = null;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $cfg = (array) App::config('session', []);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) ($cfg['absolute_timeout'] ?? 50400));
        session_name((string) ($cfg['name'] ?? 'DDPCTRL'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => (bool) ($cfg['secure_cookie'] ?? true),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
        $now = time();
        if (isset($_SESSION['uid'])) {
            $idle = (int) ($cfg['idle_timeout'] ?? 14400);
            $abs = (int) ($cfg['absolute_timeout'] ?? 50400);
            if ($now - (int) ($_SESSION['last_seen'] ?? 0) > $idle || $now - (int) ($_SESSION['login_at'] ?? 0) > $abs) {
                self::destroy();
                session_start();
                $_SESSION['expired'] = true;
                return;
            }
            $_SESSION['last_seen'] = $now;
        }
    }

    /** Compte connecté (relu en base), ou null. */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        self::startSession();
        $uid = $_SESSION['uid'] ?? null;
        if (!is_int($uid)) {
            return null;
        }
        $st = App::db()->prepare('SELECT id, username, display_name, role, is_active FROM users WHERE id = ?');
        $st->execute([$uid]);
        $u = $st->fetch();
        if (!$u || !(int) $u['is_active']) {
            self::destroy();
            return null;
        }
        $u['id'] = (int) $u['id'];
        return self::$user = $u;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? null) === 'admin';
    }

    /** API : 401 sans session, 403 sans le rôle requis. */
    public static function requireApi(string $role = 'controller'): array
    {
        $u = self::user();
        if (!$u) {
            Http::fail('Session expirée : reconnectez-vous.', 401, ['login' => true]);
        }
        if ($role === 'admin' && $u['role'] !== 'admin') {
            Http::fail('Accès réservé aux administrateurs.', 403);
        }
        return $u;
    }

    /** Pages : redirection vers la connexion, ou vers le contrôle pour un contrôleur sur une page d'administration. */
    public static function requirePage(string $role = 'controller'): array
    {
        $u = self::user();
        if (!$u) {
            Http::redirect('/login.php');
        }
        if ($role === 'admin' && $u['role'] !== 'admin') {
            Http::redirect('/scan.php');
        }
        return $u;
    }

    /**
     * Connexion : limitation des tentatives (par identifiant et par adresse IP), vérification password_hash, régénération de session.
     * Retourne [ok, message, user].
     */
    public static function attempt(string $username, string $password): array
    {
        $ip = Http::clientIp();
        $username = mb_strtolower(trim($username));
        if (RateLimiter::loginBlocked($username, $ip)) {
            Audit::log('login_blocked', $username, null, ['ip' => $ip]);
            return [false, 'Trop de tentatives. Patientez quelques minutes avant de réessayer.', null, 429];
        }
        $st = App::db()->prepare('SELECT id, username, display_name, role, is_active, password_hash FROM users WHERE username = ?');
        $st->execute([$username]);
        $u = $st->fetch();
        // Vérification exécutée même pour un identifiant inconnu : temps de réponse identique (pas d'énumération des comptes).
        $hash = $u['password_hash'] ?? password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
        $okPassword = !str_contains($password, "\0") && password_verify($password, $hash);
        if (!$u || !$okPassword || !(int) $u['is_active']) {
            RateLimiter::recordLogin($username, $ip, false);
            return [false, 'Identifiant ou mot de passe incorrect.', null, 401];
        }
        RateLimiter::recordLogin($username, $ip, true);
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            App::db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        self::startSession();
        session_regenerate_id(true);
        $_SESSION = [
            'uid'       => (int) $u['id'],
            'login_at'  => time(),
            'last_seen' => time(),
            'csrf'      => bin2hex(random_bytes(32)),
        ];
        App::db()->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$u['id']]);
        self::$user = null;
        Audit::log('login', $u['username'], (int) $u['id'], ['ip' => $ip]);
        unset($u['password_hash']);
        return [true, 'Connexion réussie.', $u, 200];
    }

    public static function logout(): void
    {
        $u = self::user();
        if ($u) {
            Audit::log('logout', $u['username'], $u['id']);
        }
        self::destroy();
    }

    private static function destroy(): void
    {
        self::$user = null;
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
        session_destroy();
    }

    /**
     * Libère le verrou de la session une fois les droits vérifiés, pour les requêtes qui ne la modifient pas : un même contrôleur
     * peut alors enchaîner les scans sans que ses requêtes s'attendent les unes les autres.
     */
    public static function release(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /** Jeton CSRF de la session (créé au besoin). */
    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf'];
    }

    /**
     * Vérification CSRF des requêtes qui modifient des données : en-tête X-CSRF-Token (fetch) ou champ csrf (formulaire),
     * comparé en temps constant, et origine de la requête contrôlée quand le navigateur l'indique.
     */
    public static function requireCsrf(): void
    {
        self::startSession();
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? (Http::body()['csrf'] ?? ($_POST['csrf'] ?? ''));
        $expected = $_SESSION['csrf'] ?? '';
        if (!is_string($sent) || $expected === '' || !hash_equals($expected, $sent)) {
            Http::fail('Requête refusée (jeton de sécurité invalide). Rechargez la page.', 403, ['csrf_error' => true]);
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '') {
            $host = $_SERVER['HTTP_HOST'] ?? '';
            if (parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '') !== $host) {
                Http::fail('Requête refusée (origine non autorisée).', 403);
            }
        }
    }
}
