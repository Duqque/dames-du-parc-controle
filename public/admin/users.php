<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/admin-page.php';

admin_render('Comptes', 'users', static function (): void { ?>
  <header class="page-head"><h1>Comptes et autorisations</h1></header>
  <form class="card form form-grid" id="user-form" autocomplete="off">
    <h2 class="full">Nouveau compte</h2>
    <label>Identifiant<input name="username" maxlength="60" pattern="[a-z0-9._\-]{3,60}" autocapitalize="none" required></label>
    <label>Nom affiché<input name="display_name" maxlength="120" required></label>
    <label>Rôle<select name="role"><option value="controller">Contrôleur</option><option value="admin">Administrateur</option></select></label>
    <label>Mot de passe (10 caractères min., lettres et chiffres)<input name="password" type="password" minlength="10" maxlength="200" autocomplete="new-password" required></label>
    <div class="filters-actions"><button class="btn btn-primary">Créer le compte</button></div>
  </form>
  <section class="card">
    <div class="table-wrap"><table class="table" id="users"><thead><tr><th>Nom</th><th>Identifiant</th><th>Rôle</th><th>État</th><th>Dernière connexion</th><th>Contrôles</th><th></th></tr></thead><tbody></tbody></table></div>
  </section>
<?php });
