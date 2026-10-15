<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/admin-page.php';

admin_render('Tableau de bord', 'dashboard', static function (): void { ?>
  <header class="page-head">
    <h1>Tableau de bord</h1>
    <label class="inline">Événement <select data-event-filter><option value="">Tous les événements</option></select></label>
  </header>
  <div class="kpis" id="kpis">
    <div class="kpi"><span>Billets</span><strong data-k="total">—</strong></div>
    <div class="kpi kpi-ok"><span>Encore valides</span><strong data-k="valid">—</strong></div>
    <div class="kpi kpi-used"><span>Utilisés (entrées)</span><strong data-k="used">—</strong></div>
    <div class="kpi kpi-bad"><span>Annulés</span><strong data-k="cancelled">—</strong></div>
  </div>
  <div class="grid-2">
    <section class="card">
      <h2>Contrôles par résultat</h2>
      <ul class="bars" id="by-result"></ul>
    </section>
    <section class="card">
      <h2>Entrées validées (24 dernières heures)</h2>
      <ul class="hours" id="hours"></ul>
    </section>
  </div>
  <section class="card">
    <div class="card-head"><h2>Derniers contrôles</h2><a class="btn btn-ghost btn-sm" href="/admin/checks.php">Tout l'historique</a></div>
    <div class="table-wrap"><table class="table" id="recent"><thead><tr><th>Heure</th><th>Résultat</th><th>Billet</th><th>Événement</th><th>Contrôleur</th></tr></thead><tbody></tbody></table></div>
  </section>
<?php });
