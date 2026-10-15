<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/admin-page.php';

admin_render('Billets', 'tickets', static function (): void { ?>
  <header class="page-head"><h1>Billets</h1><div class="filters-actions"><a class="btn btn-ghost" id="print-tickets" href="#" hidden>Imprimer les QR codes</a><a class="btn btn-ghost" id="export-tickets" href="/api/export-tickets.php">Exporter en CSV</a></div></header>
  <form class="card filters" id="ticket-filters">
    <label class="grow">Rechercher (n° de billet, code, titulaire, référence)<input name="q" maxlength="300" placeholder="Ex. 42, 7KQ4…, Dupont"></label>
    <label>Événement<select name="event_id" data-event-filter><option value="">Tous</option></select></label>
    <label>Statut<select name="status"><option value="">Tous</option><option value="valid">Valide</option><option value="used">Utilisé</option><option value="cancelled">Annulé</option></select></label>
    <div class="filters-actions"><button class="btn btn-primary">Rechercher</button></div>
  </form>
  <section class="card">
    <p class="muted" id="tickets-count"></p>
    <div class="table-wrap"><table class="table" id="tickets"><thead><tr><th>N°</th><th>Titulaire</th><th>Référence</th><th>Événement</th><th>Statut</th><th>Utilisé</th><th></th></tr></thead><tbody></tbody></table></div>
    <div class="pager" id="tickets-pager"></div>
  </section>
  <details class="card">
    <summary><h2>Créer des billets</h2></summary>
    <form class="form form-grid" id="create-tickets">
      <label>Événement<select name="event_id" data-event-filter required><option value="">Choisir…</option></select></label>
      <label>Nombre<input type="number" name="count" min="1" max="500" value="1" required></label>
      <label>Titulaire (facultatif)<input name="holder" maxlength="160"></label>
      <label>Référence (facultatif)<input name="reference" maxlength="80"></label>
      <div><button class="btn btn-primary">Créer</button></div>
    </form>
    <div id="created"></div>
  </details>
  <dialog class="dialog" id="ticket-dialog">
    <form method="dialog" class="dialog-close"><button class="btn btn-ghost btn-sm" aria-label="Fermer">Fermer</button></form>
    <div id="ticket-detail"></div>
  </dialog>
<?php });
