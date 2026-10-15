<?php
declare(strict_types=1);

use DDP\{App, Auth, Checks, Http, TicketService};

require dirname(__DIR__, 2) . '/src/api.php';

/**
 * Consultation du statut d'un billet, sans le modifier.
 * - ?code=… : contrôleurs et administrateurs (vérifier un billet sans le valider).
 * - ?id=…   : administrateurs, avec l'historique des contrôles du billet.
 */
Http::requireMethod('GET');
$user = Auth::requireApi('controller');
$id = Http::int($_GET, 'id');
if ($id) {
    Auth::requireApi('admin');
    $st = App::db()->prepare('SELECT t.*, u.display_name AS used_by_name, e.name AS event_name, e.starts_at FROM tickets t JOIN events e ON e.id = t.event_id LEFT JOIN users u ON u.id = t.used_by WHERE t.id = ?');
    $st->execute([$id]);
    $t = $st->fetch();
    if (!$t) {
        Http::fail('Billet introuvable.', 404);
    }
    $ticket = TicketService::publicTicket($t) + [
        'token' => $t['token'],
        'qr' => \DDP\TicketCode::qrPayload($t['token']),
        'created_at' => App::localTime($t['created_at']),
        'used_at' => App::localTime($t['used_at']),
        'used_by' => $t['used_by_name'],
        'cancelled_at' => App::localTime($t['cancelled_at']),
    ];
    $st = App::db()->prepare('SELECT c.created_at, c.result, c.method, u.display_name FROM check_logs c LEFT JOIN users u ON u.id = c.user_id WHERE c.ticket_id = ? ORDER BY c.id DESC LIMIT 50');
    $st->execute([$id]);
    $history = array_map(static fn ($r) => ['at' => App::localTime($r['created_at']), 'result' => $r['result'], 'result_label' => Checks::LABELS[$r['result']] ?? $r['result'], 'method' => $r['method'], 'controller' => $r['display_name']], $st->fetchAll());
    Http::json(['ok' => true, 'ticket' => $ticket, 'history' => $history]);
}
$code = Http::str($_GET, 'code', 300);
if ($code === '') {
    Http::fail('Indiquez un code de billet.', 422);
}
$t = TicketService::status($code);
if (!$t) {
    Http::json(['ok' => true, 'found' => false, 'message' => 'Aucun billet ne correspond à ce code.']);
}
Http::json(['ok' => true, 'found' => true, 'ticket' => $t]);
