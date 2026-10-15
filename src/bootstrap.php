<?php
declare(strict_types=1);

/**
 * Amorçage commun à toutes les pages, à l'API et aux scripts en ligne de commande :
 * chargement automatique des classes, configuration, gestion des erreurs.
 */

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'DDP\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

DDP\App::boot();
