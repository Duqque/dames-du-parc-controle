<?php
declare(strict_types=1);

use DDP\{Auth, Http};

require dirname(__DIR__) . '/src/page.php';

$u = Auth::user();
if ($u) {
    Http::redirect($u['role'] === 'admin' ? '/admin/' : '/scan.php');
}
$expired = !empty($_SESSION['expired']);
unset($_SESSION['expired']);
$title = 'Connexion';
$user = null;
$active = '';
$page = 'login';
$scripts = ['login.js'];
$content = static function () use ($expired): void { ?>
  <section class="login">
    <img class="login-logo" src="/assets/img/logo.webp" alt="Les Dames du Parc" width="120" height="120">
    <h1>Contrôle des billets</h1>
    <p class="muted">Espace réservé à l'équipe d'accueil des Dames du Parc.</p>
    <?php if ($expired): ?><p class="notice">Votre session a expiré : reconnectez-vous.</p><?php endif; ?>
    <?php if (!DDP\Http::isHttps() && DDP\App::config('session.secure_cookie', true)): ?>
      <p class="notice notice-error">Connexion non sécurisée : ouvrez l'application en <strong>https://</strong>.</p>
    <?php endif; ?>
    <form id="login-form" class="card form" novalidate>
      <label>Identifiant
        <input name="username" autocomplete="username" autocapitalize="none" spellcheck="false" required maxlength="60">
      </label>
      <label>Mot de passe
        <input name="password" type="password" autocomplete="current-password" required maxlength="200">
      </label>
      <p class="form-error" role="alert" hidden></p>
      <button class="btn btn-primary btn-xl" type="submit">Se connecter</button>
    </form>
  </section>
<?php };
require dirname(__DIR__) . '/templates/layout.php';
