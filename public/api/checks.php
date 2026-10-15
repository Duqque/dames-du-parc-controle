<?php
declare(strict_types=1);

use DDP\{Auth, Checks, Http};

require dirname(__DIR__, 2) . '/src/api.php';

/** Historique des contrôles, filtrable (événement, contrôleur, résultat, dates, recherche) et paginé. */
Http::requireMethod('GET');
Auth::requireApi('admin');
$page = max(1, Http::int($_GET, 'page') ?? 1);
Http::json(['ok' => true] + Checks::list(Checks::filters($_GET), $page, 50));
