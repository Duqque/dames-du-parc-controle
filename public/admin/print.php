<?php
declare(strict_types=1);

/** Planche imprimable des QR codes d'un événement (billets valides), pour une billetterie papier. */
use DDP\{App, Audit, Http, TicketCode};

require dirname(__DIR__, 2) . '/src/admin-page.php';

$eventId = Http::int($_GET, 'event_id') ?? 0;
$st = App::db()->prepare('SELECT id, name, starts_at, location FROM events WHERE id = ?');
$st->execute([$eventId]);
$event = $st->fetch();
if (!$event) {
    Http::redirect('/admin/tickets.php');
}
$st = App::db()->prepare("SELECT id, token, holder_name, reference FROM tickets WHERE event_id = ? AND status = 'valid' ORDER BY id LIMIT 1000");
$st->execute([$eventId]);
$tickets = array_map(static fn ($t) => $t + ['qr' => TicketCode::qrPayload($t['token'])], $st->fetchAll());
Audit::log('tickets_print', "event:$eventId", null, ['count' => count($tickets)]);
$scripts = ['print.js'];
admin_render('Impression des billets', 'tickets', static function () use ($event, $tickets): void { ?>
  <header class="page-head"><h1>Billets · <?= Http::e($event['name']) ?></h1><button class="btn btn-primary" data-print>Imprimer</button></header>
  <p class="muted"><?= count($tickets) ?> billet(s) valide(s) · <?= Http::e(App::localTime($event['starts_at'], 'd/m/Y H:i')) ?><?= $event['location'] ? ' · ' . Http::e($event['location']) : '' ?></p>
  <div class="print-grid">
    <?php foreach ($tickets as $t): ?>
      <article class="print-ticket">
        <div class="qr" data-qr="<?= Http::e($t['qr']) ?>"></div>
        <strong>#<?= (int) $t['id'] ?> <?= Http::e($t['holder_name'] ?? '') ?></strong>
        <span class="mono"><?= Http::e($t['token']) ?></span>
      </article>
    <?php endforeach; ?>
  </div>
<?php });
