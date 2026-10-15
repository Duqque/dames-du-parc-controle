<?php
declare(strict_types=1);

/** Amorçage commun des pages HTML. */
require __DIR__ . '/bootstrap.php';

DDP\Http::securityHeaders();
header('Cache-Control: no-store');
DDP\Auth::startSession();
