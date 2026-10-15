<?php
declare(strict_types=1);

/**
 * Installation par le navigateur (hébergement sans accès SSH) : création du PREMIER compte administrateur.
 * Fonctionne uniquement si :
 *  1. config/config.php contient un install_token d'au moins 24 caractères ;
 *  2. l'adresse est ouverte avec ce jeton : /install.php?token=… ;
 *  3. aucun administrateur n'existe encore.
 * Une fois le compte créé, la page se bloque d'elle-même ; remettez ensuite install_token à '' dans la configuration.
 */
use DDP\{App, Audit, Auth, Http, Users};

require dirname(__DIR__) . '/src/page.php';

$token = (string) App::config('install_token', '');
$given = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$hasAdmin = (int) App::db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
if (strlen($token) < 24 || !hash_equals($token, $given) || $hasAdmin) {
    http_response_code(404);
    echo 'Page introuvable.';
    exit;
}
$error = '';
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $username = mb_strtolower(Http::str($_POST, 'username', 60));
    $name = Http::str($_POST, 'display_name', 120);
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    if (!Users::validUsername($username) || $name === '') {
        $error = 'Identifiant (3 à 60 caractères : minuscules, chiffres, . _ -) et nom obligatoires.';
    } elseif ($err = Users::passwordError($password)) {
        $error = $err;
    } elseif ($password !== ($_POST['confirm'] ?? '')) {
        $error = 'Les deux mots de passe ne correspondent pas.';
    } else {
        Users::create($username, $name, $password, 'admin');
        Audit::log('user_create', $username, null, ['role' => 'admin', 'via' => 'install']);
        $done = true;
    }
}
$title = 'Installation';
$user = null;
$active = '';
$page = 'login';
$scripts = [];
$content = static function () use ($done, $error, $given): void { ?>
  <section class="login">
    <img class="login-logo" src="/assets/img/logo.webp" alt="" width="100" height="100">
    <h1>Premier administrateur</h1>
    <?php if ($done): ?>
      <p class="notice">Compte créé. Cette page est désormais bloquée. <strong>Videz install_token</strong> dans config/config.php, puis <a href="/login.php">connectez-vous</a>.</p>
    <?php else: ?>
      <form method="post" class="card form" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= Http::e(Auth::csrfToken()) ?>">
        <input type="hidden" name="token" value="<?= Http::e($given) ?>">
        <label>Identifiant<input name="username" required maxlength="60" autocapitalize="none"></label>
        <label>Nom affiché<input name="display_name" required maxlength="120"></label>
        <label>Mot de passe<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
        <label>Confirmation<input type="password" name="confirm" required minlength="10" autocomplete="new-password"></label>
        <?php if ($error): ?><p class="form-error"><?= Http::e($error) ?></p><?php endif; ?>
        <button class="btn btn-primary btn-xl">Créer le compte administrateur</button>
      </form>
    <?php endif; ?>
  </section>
<?php };
require dirname(__DIR__) . '/templates/layout.php';
