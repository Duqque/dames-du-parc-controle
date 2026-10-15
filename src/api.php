<?php
declare(strict_types=1);

/** Amorçage commun des points d'accès de l'API : JSON, en-têtes de sécurité, session. */
require __DIR__ . '/bootstrap.php';

DDP\Http::securityHeaders();
header('Cache-Control: no-store');
DDP\Auth::startSession();
