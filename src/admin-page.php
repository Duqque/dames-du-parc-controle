<?php
declare(strict_types=1);

/** Pages d'administration : accès réservé aux administrateurs, contenu chargé par l'API (admin.js). */
require __DIR__ . '/page.php';

$user = DDP\Auth::requirePage('admin');
$scripts = ['admin.js'];

function admin_render(string $title, string $active, callable $content): void
{
    global $user, $scripts;
    $page = 'admin-' . $active;
    require APP_ROOT . '/templates/layout.php';
}
