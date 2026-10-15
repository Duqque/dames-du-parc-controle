<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/admin-page.php';

admin_render('Journal', 'audit', static function (): void { ?>
  <header class="page-head"><h1>Journal des opérations sensibles</h1></header>
  <section class="card">
    <p class="muted">Connexions, comptes, imports, exports, annulations et remises à zéro de billets (200 dernières opérations).</p>
    <div class="table-wrap"><table class="table" id="audit"><thead><tr><th>Date</th><th>Opération</th><th>Cible</th><th>Détails</th><th>Par</th><th>Adresse IP</th></tr></thead><tbody></tbody></table></div>
  </section>
<?php });
