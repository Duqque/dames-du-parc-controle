<?php
/**
 * Gabarit commun. Variables attendues : $title (string), $user (array|null), $active (string), $page (identifiant JS),
 * $content (callable qui affiche le contenu), $scripts (liste de fichiers JS de /assets/js).
 */
use DDP\{Auth, Http};

$e = [Http::class, 'e'];
$nav = $user && $user['role'] === 'admin' ? [
    'scan' => ['/scan.php', 'Contrôle'],
    'dashboard' => ['/admin/', 'Tableau de bord'],
    'checks' => ['/admin/checks.php', 'Historique'],
    'tickets' => ['/admin/tickets.php', 'Billets'],
    'import' => ['/admin/import.php', 'Import'],
    'events' => ['/admin/events.php', 'Événements'],
    'users' => ['/admin/users.php', 'Comptes'],
    'audit' => ['/admin/audit.php', 'Journal'],
] : [];
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#ffffff">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= $e(Auth::csrfToken()) ?>">
<title><?= $e($title) ?> · Contrôle des billets · Les Dames du Parc</title>
<link rel="icon" href="/assets/img/logo.webp">
<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Contrôle DDP">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="stylesheet" href="/assets/css/app.css?v=7">
</head>
<body class="page-<?= $e($page) ?>" data-page="<?= $e($page) ?>">
<a class="skip" href="#main">Aller au contenu</a>
<header class="topbar">
  <a class="brand" href="<?= $user ? ($user['role'] === 'admin' ? '/admin/' : '/scan.php') : '/login.php' ?>">
    <img src="/assets/img/logo.webp" alt="" width="40" height="40">
    <span><strong>Les Dames du Parc</strong><small>Contrôle des billets</small></span>
  </a>
  <?php if ($user): ?>
  <div class="who">
    <span class="who-name"><?= $e($user['display_name']) ?><small><?= $user['role'] === 'admin' ? 'Administrateur' : 'Contrôleur' ?></small></span>
    <button type="button" class="btn btn-ghost btn-sm" data-install-guide>Installer l'app</button>
    <button type="button" class="btn btn-ghost btn-sm" data-logout>Déconnexion</button>
  </div>
  <?php endif; ?>
</header>
<?php if ($nav): ?>
<nav class="tabs" aria-label="Navigation">
  <?php foreach ($nav as $key => [$href, $label]): ?>
  <a href="<?= $href ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= $e($label) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
<main id="main" class="main">
<?php $content(); ?>
</main>
<div class="toast" role="status" aria-live="polite" hidden></div>
<script src="/assets/js/common.js?v=4"></script>
<?php if ($user): ?><script src="/assets/js/install-guide.js?v=7"></script><?php endif; ?>
<?php foreach ($scripts ?? [] as $s): ?>
<script src="/assets/js/<?= $e($s) ?>?v=4"></script>
<?php endforeach; ?>
</body>
</html>
