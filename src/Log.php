<?php
declare(strict_types=1);

namespace DDP;

/** Journal technique en fichier (storage/logs, hors accès public), une ligne JSON par événement. */
final class Log
{
    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $line = json_encode(['t' => gmdate('c'), 'level' => $level, 'message' => $message, 'context' => $context], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents(APP_ROOT . '/storage/logs/app-' . gmdate('Y-m') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
