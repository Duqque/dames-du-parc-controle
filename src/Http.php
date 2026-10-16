<?php
declare(strict_types=1);

namespace DDP;

/** Réponses JSON, en-têtes de sécurité, lecture et validation des entrées. */
final class Http
{
    /** En-têtes de sécurité envoyés par chaque page et chaque réponse de l'API. */
    public static function securityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header_remove('X-Powered-By');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        $proxies = (array) App::config('trusted_proxies', []);
        return in_array($_SERVER['REMOTE_ADDR'] ?? '', $proxies, true) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /**
     * Adresse IP pseudonymisée (RGPD) : empreinte HMAC de l'adresse avec la clé secrète de l'application. Elle suffit pour limiter
     * les tentatives de connexion, sans jamais conserver ni afficher l'adresse réelle.
     */
    public static function clientIp(): string
    {
        $secret = (string) App::config('qr_secret', 'ddp');
        $mac = hash_hmac('sha256', 'ip:' . self::rawClientIp(), $secret, true);
        return 'ip~' . substr(rtrim(strtr(base64_encode($mac), '+/', '-_'), '='), 0, 16);
    }

    /** Adresse réelle, jamais enregistrée (lecture interne uniquement). */
    private static function rawClientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $proxies = (array) App::config('trusted_proxies', []);
        if (in_array($ip, $proxies, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return substr($ip, 0, 45);
    }

    public static function wantsJson(): bool
    {
        return str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/');
    }

    /** Réponse JSON et fin du script. Jamais mise en cache. */
    public static function json(array $data, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            self::securityHeaders();
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Envoie la réponse JSON tout de suite, puis exécute $after (ex. transmettre une présence au site) sans faire attendre le
     * navigateur.
     */
    public static function jsonThen(array $data, int $status, callable $after): never
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ignore_user_abort(true);
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('Content-Length: ' . strlen((string) $body));
            header('Connection: close');
            self::securityHeaders();
        }
        echo $body;
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
        }
        try {
            $after();
        } catch (\Throwable $e) {
            Log::error('Traitement après réponse impossible', ['error' => $e->getMessage()]);
        }
        exit;
    }

    public static function fail(string $message, int $status = 400, array $extra = []): never
    {
        self::json(['ok' => false, 'error' => $message] + $extra, $status);
    }

    public static function requireMethod(string ...$methods): void
    {
        $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($m, $methods, true)) {
            header('Allow: ' . implode(', ', $methods));
            self::fail('Méthode non autorisée.', 405);
        }
    }

    /** Corps JSON de la requête (taille limitée). */
    public static function body(): array
    {
        static $data = null;
        if ($data !== null) {
            return $data;
        }
        $type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_starts_with($type, 'application/json')) {
            $raw = file_get_contents('php://input', false, null, 0, 65536) ?: '';
            $decoded = json_decode($raw, true);
            $data = is_array($decoded) ? $decoded : [];
        } else {
            $data = $_POST;
        }
        return $data;
    }

    public static function str(array $src, string $key, int $max = 255): string
    {
        $v = $src[$key] ?? '';
        if (!is_string($v) && !is_int($v)) {
            return '';
        }
        $v = trim((string) $v);
        return mb_substr($v, 0, $max);
    }

    public static function int(array $src, string $key): ?int
    {
        $v = $src[$key] ?? null;
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^\d{1,10}$/', $v)) {
            return (int) $v;
        }
        return null;
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . $path, true, 303);
        exit;
    }
}
