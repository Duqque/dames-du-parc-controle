<?php
declare(strict_types=1);

use DDP\Auth;

require dirname(__DIR__) . '/src/page.php';

$user = Auth::requirePage('controller');
$title = 'Contrôle';
$active = 'scan';
$page = 'scan';
$scripts = ['scan.js'];
$content = static function (): void { ?>
  <section class="scan">
    <div class="scan-event">
      <label for="event">Événement contrôlé</label>
      <select id="event" class="input-lg"><option value="">Chargement…</option></select>
    </div>

    <div class="mode-switch" role="tablist" aria-label="Mode de contrôle">
      <button type="button" role="tab" id="tab-scan" aria-selected="true" aria-controls="panel-scan" class="mode active">Scanner le QR code</button>
      <button type="button" role="tab" id="tab-manual" aria-selected="false" aria-controls="panel-manual" class="mode">Saisie manuelle</button>
    </div>

    <div id="panel-scan" role="tabpanel" aria-labelledby="tab-scan" class="panel">
      <div class="camera" id="camera">
        <video id="video" playsinline muted></video>
        <canvas id="canvas" hidden></canvas>
        <div class="viewfinder" aria-hidden="true"><span></span></div>
        <div class="camera-idle" id="camera-idle">
          <p>Appuyez pour activer l'appareil photo et scanner les billets.</p>
          <button type="button" class="btn btn-primary btn-xl" id="start-camera">Activer la caméra</button>
        </div>
      </div>
      <p class="camera-hint muted" id="camera-hint">Placez le QR code du billet dans le cadre. Le contrôle est automatique.</p>
      <div class="camera-actions">
        <button type="button" class="btn btn-ghost" id="switch-camera" hidden>Changer de caméra</button>
        <button type="button" class="btn btn-ghost" id="torch" hidden>Lampe</button>
        <button type="button" class="btn btn-ghost" id="stop-camera" hidden>Arrêter la caméra</button>
      </div>
    </div>

    <div id="panel-manual" role="tabpanel" aria-labelledby="tab-manual" class="panel" hidden>
      <form id="manual-form" class="card form" autocomplete="off" novalidate>
        <label for="code">Numéro ou code du billet</label>
        <input id="code" name="code" class="input-lg mono" inputmode="text" autocapitalize="characters" spellcheck="false" maxlength="300" placeholder="Ex. 7KQ4 2M9X …" required>
        <button class="btn btn-primary btn-xl" type="submit">Vérifier le billet</button>
      </form>
    </div>

    <div class="counter" aria-live="polite"><span id="count-ok">0</span> entrée(s) validée(s) par vous depuis l'ouverture de cette page</div>
  </section>

  <div class="result" id="result" hidden role="alertdialog" aria-modal="true" aria-labelledby="result-title" aria-describedby="result-message">
    <div class="result-inner">
      <div class="result-icon" aria-hidden="true"></div>
      <h2 id="result-title"></h2>
      <p id="result-message"></p>
      <dl class="result-details" id="result-details"></dl>
      <button type="button" class="btn btn-xl btn-light" id="result-next">Billet suivant</button>
    </div>
  </div>
<?php };
require dirname(__DIR__) . '/templates/layout.php';
