<?php
declare(strict_types=1);

namespace DDP;

use PDO;
use Throwable;

/** Configuration, connexion MariaDB et gestion globale des erreurs (aucun détail technique n'est montré en production). */
final class App
{
    private static array $config = [];
    private static ?PDO $pdo = null;

    public static function boot(): void
    {
        $file = APP_ROOT . '/config/config.php';
        if (!is_file($file)) {
            self::fatal('Configuration absente : copiez config/config.example.php en config/config.php.');
        }
        self::$config = require $file;
        date_default_timezone_set('UTC'); // stockage en UTC, affichage dans le fuseau configuré
        ini_set('display_errors', self::debug() ? '1' : '0');
        ini_set('log_errors', '1');
        ini_set('error_log', APP_ROOT . '/storage/logs/php-error.log');
        error_reporting(E_ALL);
        set_exception_handler(static function (Throwable $e): void {
            Log::error('Exception non interceptée', ['type' => get_class($e), 'message' => $e->getMessage(), 'file' => $e->getFile() . ':' . $e->getLine()]);
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, $e->getMessage() . PHP_EOL);
                exit(1);
            }
            if (Http::wantsJson()) {
                Http::json(['ok' => false, 'error' => 'Erreur technique. Réessayez ; si le problème persiste, prévenez un administrateur.'] + (self::debug() ? ['debug' => $e->getMessage()] : []), 500);
            }
            http_response_code(500);
            echo 'Erreur technique. Réessayez dans un instant.';
            exit;
        });
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        $value = self::$config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public static function debug(): bool
    {
        return (bool) (self::$config['debug'] ?? false);
    }

    /** Connexion PDO unique : erreurs en exceptions, vraies requêtes préparées, dates en UTC. */
    public static function db(): PDO
    {
        if (self::$pdo === null) {
            $c = self::$config['db'];
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], (int) ($c['port'] ?? 3306), $c['name'], $c['charset'] ?? 'utf8mb4');
            self::$pdo = new PDO($dsn, $c['user'], $c['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            self::$pdo->exec("SET time_zone = '+00:00'");
            self::$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        return self::$pdo;
    }

    /** Pour les scripts de test : nouvelle connexion indépendante. */
    public static function resetDb(): void
    {
        self::$pdo = null;
    }

    /** Date UTC de la base → texte dans le fuseau d'affichage. */
    public static function localTime(?string $utc, string $format = 'd/m/Y H:i:s'): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        $d = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        return $d->setTimezone(new \DateTimeZone((string) (self::$config['timezone'] ?? 'Europe/Paris')))->format($format);
    }

    /** Date saisie dans le fuseau d'affichage → UTC pour la base. */
    public static function toUtc(string $local): string
    {
        $d = new \DateTimeImmutable($local, new \DateTimeZone((string) (self::$config['timezone'] ?? 'Europe/Paris')));
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function fatal(string $message): never
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $message . PHP_EOL);
            exit(1);
        }
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Application non configurée.";
        error_log($message);
        exit;
    }
}
