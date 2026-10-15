<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/admin-page.php';

admin_render('Historique des contrôles', 'checks', static function (): void { ?>
  <header class="page-head"><h1>Historique des contrôles</h1><a class="btn btn-ghost" id="export-checks" href="/api/export-checks.php">Exporter en CSV</a></header>
  <form class="card filters" id="check-filters">
    <label>Événement<select name="event_id" data-event-filter><option value="">Tous</option></select></label>
    <label>Contrôleur<select name="user_id" data-user-filter><option value="">Tous</option></select></label>
    <label>Résultat<select name="result"><option value="">Tous</option><option value="valid">Valide</option><option value="already_used">Déjà utilisé</option><option value="invalid">Invalide</option><option value="cancelled">Annulé</option><option value="wrong_event">Autre événement</option><option value="error">Erreur technique</option></select></label>
    <label>Du<input type="date" name="from"></label>
    <label>Au<input type="date" name="to"></label>
    <label>Billet, nom ou référence<input name="q" maxlength="80"></label>
    <div class="filters-actions"><button class="btn btn-primary">Filtrer</button><button class="btn btn-ghost" type="reset">Réinitialiser</button></div>
  </form>
  <section class="card">
    <p class="muted" id="checks-count"></p>
    <div class="table-wrap"><table class="table" id="checks"><thead><tr><th>Date et heure</th><th>Résultat</th><th>Mode</th><th>Billet</th><th>Titulaire</th><th>Événement</th><th>Contrôleur</th></tr></thead><tbody></tbody></table></div>
    <div class="pager" id="checks-pager"></div>
  </section>
<?php });
