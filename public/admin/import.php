<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/admin-page.php';

admin_render('Import de billets', 'import', static function (): void { ?>
  <header class="page-head"><h1>Importer des billets (CSV)</h1></header>
  <div class="grid-2">
    <form class="card form" id="import-form" enctype="multipart/form-data">
      <label>Événement<select name="event_id" data-event-filter required><option value="">Choisir…</option></select></label>
      <label>Fichier CSV (5 Mo maximum)<input type="file" name="file" accept=".csv,text/csv" required></label>
      <button class="btn btn-primary btn-xl">Importer</button>
      <div id="import-report" aria-live="polite"></div>
    </form>
    <section class="card prose">
      <h2>Format attendu</h2>
      <p>Une première ligne d'en-tête, séparateur <code>;</code> ou <code>,</code>, encodage UTF-8 :</p>
      <pre>code;nom;reference
482913705361;Camille Martin;CMD-1042
;Léa Dubois;CMD-1043</pre>
      <ul>
        <li><strong>code</strong> : code du billet (6 à 64 caractères : lettres, chiffres, <code>-</code> et <code>_</code>). Vide : un code aléatoire est généré.</li>
        <li><strong>nom</strong> et <strong>reference</strong> : facultatifs.</li>
        <li>Les doublons (dans le fichier ou déjà en base) et les lignes incorrectes sont ignorés et listés dans le rapport.</li>
        <li>Les valeurs commençant par <code>=</code>, <code>+</code>, <code>-</code> ou <code>@</code> (formules de tableur) sont refusées.</li>
      </ul>
    </section>
  </div>
  <section class="card">
    <h2>Imports précédents</h2>
    <div class="table-wrap"><table class="table" id="imports"><thead><tr><th>Date</th><th>Fichier</th><th>Événement</th><th>Lignes</th><th>Importés</th><th>Doublons</th><th>Erreurs</th><th>Par</th></tr></thead><tbody></tbody></table></div>
  </section>
<?php });
