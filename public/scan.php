<?php
declare(strict_types=1);

use DDP\{Auth, Http};

require dirname(__DIR__) . '/src/page.php';

$user = Auth::requirePage('controller');
$e = [Http::class, 'e'];
$initials = mb_strtoupper(implode('', array_map(static fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($user['display_name'])) ?: [], 0, 2))));
// Icônes (traits) : un seul jeu, dessinées en SVG dans la page (aucune ressource externe).
$icon = static fn (string $d, string $extra = ''): string => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"' . $extra . '>' . $d . '</svg>';
$I = [
    'scan' => '<path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M7 8v8M10 8v8M13 8v8M17 8v8"/>',
    'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
    'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    'cal' => '<rect x="3" y="4" width="18" height="17" rx="3"/><path d="M8 2v4M16 2v4M3 10h18"/>',
    'chev' => '<path d="m9 6 6 6-6 6"/>',
    'keys' => '<rect x="2" y="6" width="20" height="13" rx="3"/><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M7 15h10"/>',
    'close' => '<path d="M18 6 6 18M6 6l12 12"/>',
    'flash' => '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
    'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    'phone' => '<rect x="6" y="2" width="12" height="20" rx="3"/><path d="M11 18h2"/>',
    'sync' => '<path d="M21 12a9 9 0 0 1-15.5 6.2L3 16M3 12a9 9 0 0 1 15.5-6.2L21 8"/><path d="M21 3v5h-5M3 21v-5h5"/>',
];
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#ffffff">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= $e(Auth::csrfToken()) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>Contrôle des billets · Les Dames du Parc</title>
<link rel="icon" href="/assets/img/logo.webp">
<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
<meta name="apple-mobile-web-app-title" content="Contrôle DDP">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="stylesheet" href="/assets/css/app.css?v=7">
</head>
<body class="app" data-user="<?= $e($user['display_name']) ?>" data-role="<?= $e($user['role']) ?>">
<a class="skip" href="#views">Aller au contenu</a>

<header class="app-top">
  <span class="brand">
    <img src="/assets/img/logo.webp" alt="" width="34" height="34">
    <span><strong>Les Dames du Parc</strong><small>Contrôle des billets</small></span>
  </span>
  <span class="net" id="net" role="status" aria-live="polite"><i></i><span>Connexion…</span></span>
</header>

<main id="views">
  <!-- Scanner (accueil) -->
  <section class="view" id="view-scan" aria-labelledby="t-scan">
    <h1 id="t-scan" hidden>Scanner</h1>
    <button type="button" class="event-pick" id="event-pick" aria-haspopup="dialog">
      <span class="ico"><?= $icon($I['cal']) ?></span>
      <span><small>Événement contrôlé</small><strong id="event-name">Chargement…</strong><em id="event-date"></em></span>
      <span class="chev"><?= $icon($I['chev'], ' width="20" height="20"') ?></span>
    </button>

    <div class="stats">
      <div class="stat"><span>Présentes</span><strong id="st-present">–<small id="st-total"></small></strong><div class="progress"><i id="st-bar"></i></div></div>
      <div class="stat"><span>Contrôlés par vous</span><strong id="st-mine">0</strong></div>
    </div>

    <div class="scan-cta">
      <button type="button" class="btn btn-primary btn-xl" id="open-scanner"><?= $icon($I['scan']) ?> Scanner un billet</button>
      <button type="button" class="btn btn-ghost btn-xl" id="open-manual"><?= $icon($I['keys']) ?> Saisir un numéro</button>
    </div>
    <p class="sync-line" id="sync-line" aria-live="polite">Chargement des billets…</p>
  </section>

  <!-- Historique -->
  <section class="view" id="view-history" aria-labelledby="t-history" hidden>
    <h1 id="t-history">Historique</h1>
    <div class="seg" role="tablist" aria-label="Affichage">
      <button type="button" role="tab" aria-selected="true" data-seg="mine">Mes contrôles</button>
      <button type="button" role="tab" aria-selected="false" data-seg="people">Participantes</button>
    </div>
    <div id="seg-mine">
      <ul class="list" id="mine-list"><li class="empty">Aucun contrôle pour l'instant.</li></ul>
    </div>
    <div id="seg-people" hidden>
      <div class="search"><?= $icon($I['search']) ?><input type="search" id="people-q" placeholder="Rechercher un nom, un n° de membre" autocomplete="off" aria-label="Rechercher une participante"></div>
      <div class="chips mt" role="group" aria-label="Filtrer">
        <button type="button" data-f="all" aria-pressed="true">Toutes <span id="c-all"></span></button>
        <button type="button" data-f="absent" aria-pressed="false">Absentes <span id="c-absent"></span></button>
        <button type="button" data-f="present" aria-pressed="false">Présentes <span id="c-present"></span></button>
        <button type="button" data-f="cancelled" aria-pressed="false">Annulés <span id="c-cancelled"></span></button>
      </div>
      <ul class="list mt" id="people-list"></ul>
    </div>
  </section>

  <!-- Profil -->
  <section class="view" id="view-profile" aria-labelledby="t-profile" hidden>
    <h1 id="t-profile" hidden>Profil</h1>
    <div class="card profile-card">
      <span class="avatar" aria-hidden="true"><?= $e($initials) ?></span>
      <span><strong><?= $e($user['display_name']) ?></strong><br><small class="muted"><?= $user['role'] === 'admin' ? 'Administrateur' : 'Contrôleur' ?> · <?= $e($user['username']) ?></small></span>
    </div>
    <div class="rows">
      <button type="button" id="profile-event"><span>Événement<br><small id="profile-event-name">—</small></span><?= $icon($I['chev'], ' width="20" height="20"') ?></button>
      <button type="button" data-install-guide><span>Installer l'application<br><small>sur l'écran d'accueil du téléphone</small></span><?= $icon($I['phone'], ' width="20" height="20"') ?></button>
      <button type="button" id="sync-now"><span>Billets du site<br><small id="profile-sync">—</small></span><?= $icon($I['sync'], ' width="20" height="20"') ?></button>
    </div>
    <div class="rows">
      <label class="check"><span>Son au résultat</span><span class="switch"><input type="checkbox" id="opt-sound" checked><span></span></span></label>
      <label class="check"><span>Vibration</span><span class="switch"><input type="checkbox" id="opt-vibrate" checked><span></span></span></label>
      <label class="check"><span>Billet suivant automatique<br><small>après une entrée valide</small></span><span class="switch"><input type="checkbox" id="opt-auto" checked><span></span></span></label>
    </div>
    <div class="rows">
      <?php if ($user['role'] === 'admin'): ?><a href="/admin/"><span>Administration</span><?= $icon($I['chev'], ' width="20" height="20"') ?></a><?php endif; ?>
      <button type="button" data-logout><span class="form-error">Se déconnecter</span></button>
    </div>
  </section>
</main>

<nav class="tabbar" aria-label="Navigation principale">
  <button type="button" data-tab="scan" aria-current="page"><?= $icon($I['scan']) ?>Scanner</button>
  <button type="button" data-tab="history"><?= $icon($I['history']) ?>Historique</button>
  <button type="button" data-tab="profile"><?= $icon($I['user']) ?>Profil</button>
</nav>

<!-- Scanner plein écran -->
<div class="scanner" id="scanner" hidden role="dialog" aria-modal="true" aria-label="Scanner un billet">
  <video id="video" playsinline muted></video>
  <div class="scanner-top">
    <button type="button" class="round" id="scanner-close" aria-label="Fermer le scanner"><?= $icon($I['close']) ?></button>
    <h2>Scanner le billet</h2>
    <button type="button" class="round" id="torch" aria-label="Lampe" aria-pressed="false" hidden><?= $icon($I['flash']) ?></button>
    <span id="torch-spacer" class="round spacer" aria-hidden="true"></span>
  </div>
  <div class="scanner-mid">
    <div class="frame" id="frame"><i></i><i></i><i></i><i></i><b></b><span class="scanner-hint" id="scanner-hint">Code-barres ou QR code dans le cadre</span></div>
  </div>
  <div class="scanner-bottom">
    <p class="scanner-state" id="scanner-state" aria-live="polite">Ouverture de la caméra…</p>
    <button type="button" class="pill" id="scanner-manual"><?= $icon($I['keys']) ?>Saisir le numéro</button>
  </div>
  <div class="scanner-error" id="scanner-error" hidden>
    <p id="scanner-error-text"></p>
    <button type="button" class="pill" id="scanner-error-manual"><?= $icon($I['keys']) ?>Saisir le numéro</button>
    <button type="button" class="btn btn-ghost" id="scanner-error-close">Fermer</button>
  </div>
  <canvas id="canvas" hidden></canvas>
</div>

<!-- Panneaux -->
<div class="backdrop" id="backdrop" hidden></div>
<section class="sheet" id="sheet-result" hidden role="alertdialog" aria-modal="true" aria-labelledby="r-title" aria-describedby="r-msg">
  <div class="grab"></div>
  <div class="result-head"><span class="result-icon" id="r-icon"></span><div><h2 id="r-title"></h2><p id="r-msg"></p></div></div>
  <p class="result-name" id="r-name"></p>
  <dl class="result-details" id="r-details"></dl>
  <div class="countdown" id="r-countdown" hidden><i></i></div>
  <div class="sheet-actions"><button type="button" class="btn btn-primary btn-xl" id="r-next">Billet suivant</button></div>
</section>
<section class="sheet" id="sheet-manual" hidden role="dialog" aria-modal="true" aria-labelledby="m-title">
  <div class="grab"></div>
  <h2 id="m-title">Saisir le numéro du billet</h2>
  <form id="manual-form" class="form" autocomplete="off" novalidate>
    <input id="code" class="code-input" inputmode="numeric" autocapitalize="characters" spellcheck="false" maxlength="300" placeholder="0000 0000 0000" aria-label="Numéro du billet">
    <button type="button" class="btn btn-ghost btn-sm" id="kbd-toggle">Clavier lettres</button>
    <button class="btn btn-primary btn-xl" type="submit">Vérifier</button>
  </form>
</section>
<section class="sheet" id="sheet-event" hidden role="dialog" aria-modal="true" aria-labelledby="e-title">
  <div class="grab"></div>
  <h2 id="e-title">Choisir l'événement</h2>
  <ul class="event-list" id="event-list"></ul>
</section>
<section class="sheet" id="sheet-person" hidden role="dialog" aria-modal="true" aria-labelledby="p-title">
  <div class="grab"></div>
  <h2 id="p-title"></h2>
  <dl class="result-details" id="p-details"></dl>
  <div class="sheet-actions">
    <button type="button" class="btn btn-primary btn-xl" id="p-checkin">Valider l'entrée sans billet</button>
    <button type="button" class="btn btn-ghost btn-xl" id="p-close">Fermer</button>
  </div>
</section>

<div class="toast" role="status" aria-live="polite" hidden></div>
<script src="/assets/js/common.js?v=6"></script>
<script src="/assets/js/code128-reader.js?v=6"></script>
<script src="/assets/js/app.js?v=6"></script>
<script src="/assets/js/install-guide.js?v=7"></script>
</body>
</html>
