<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/admin-page.php';

admin_render('Événements', 'events', static function (): void { ?>
  <header class="page-head"><h1>Événements</h1></header>
  <form class="card form form-grid" id="event-form">
    <input type="hidden" name="id">
    <label class="grow">Nom<input name="name" maxlength="160" required></label>
    <label>Date et heure<input type="datetime-local" name="starts_at" required></label>
    <label class="grow">Lieu (facultatif)<input name="location" maxlength="200"></label>
    <label class="check"><input type="checkbox" name="is_active" checked> Actif (proposé au contrôle)</label>
    <div class="filters-actions"><button class="btn btn-primary" id="event-submit">Créer l'événement</button><button class="btn btn-ghost" type="reset" id="event-cancel" hidden>Annuler</button></div>
  </form>
  <section class="card">
    <div class="table-wrap"><table class="table" id="events"><thead><tr><th>Événement</th><th>Date</th><th>Lieu</th><th>Billets</th><th>Valides</th><th>Utilisés</th><th>Annulés</th><th>État</th><th></th></tr></thead><tbody></tbody></table></div>
  </section>
<?php });
