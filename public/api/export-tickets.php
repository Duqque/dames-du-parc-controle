<?php
declare(strict_types=1);

use DDP\{App, Audit, Auth, Csv, Http, TicketCode};

require dirname(__DIR__, 2) . '/src/api.php';

/** Export des billets d'un événement (ou de tous) avec leur statut, le code et le contenu signé du QR code. */
Http::requireMethod('GET');
Auth::requireApi('admin');
$eventId = Http::int($_GET, 'event_id');
$sql = 'SELECT t.id, e.name, t.holder_name, t.reference, t.token, t.status, t.created_at, t.used_at, u.username FROM tickets t JOIN events e ON e.id = t.event_id LEFT JOIN users u ON u.id = t.used_by' . ($eventId ? ' WHERE t.event_id = ?' : '') . ' ORDER BY t.id';
$st = App::db()->prepare($sql);
$st->execute($eventId ? [$eventId] : []);
Audit::log('export_tickets', $eventId ? "event:$eventId" : 'all');
$labels = ['valid' => 'Valide', 'used' => 'Utilisé', 'cancelled' => 'Annulé'];
$rows = (static function () use ($st, $labels) {
    while ($r = $st->fetch()) {
        yield [$r['id'], $r['name'], $r['holder_name'], $r['reference'], $r['token'], TicketCode::qrPayload($r['token']), $labels[$r['status']] ?? $r['status'], App::localTime($r['created_at']), App::localTime($r['used_at']), $r['username']];
    }
})();
Csv::download('billets-' . date('Y-m-d-His') . '.csv', ['N° billet', 'Événement', 'Titulaire', 'Référence', 'Code', 'Contenu du QR code', 'Statut', 'Créé le', 'Utilisé le', 'Contrôleur'], $rows);
