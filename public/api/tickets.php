<?php
declare(strict_types=1);

use DDP\{App, Audit, Auth, Http, TicketCode, TicketService};

require dirname(__DIR__, 2) . '/src/api.php';

/**
 * Billets (administrateurs).
 * GET  ?q=&event_id=&status=&page= : recherche (n° de billet, code, titulaire, référence).
 * POST { action: create|cancel|reactivate|reset, … }
 */
$user = Auth::requireApi('admin');
$db = App::db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $w = [];
    $a = [];
    $eventId = Http::int($_GET, 'event_id');
    if ($eventId) {
        $w[] = 't.event_id = ?';
        $a[] = $eventId;
    }
    $status = Http::str($_GET, 'status', 12);
    if (in_array($status, ['valid', 'used', 'cancelled'], true)) {
        $w[] = 't.status = ?';
        $a[] = $status;
    }
    $q = Http::str($_GET, 'q', 300);
    if ($q !== '') {
        $parsed = TicketCode::parse($q);
        $like = '%' . addcslashes($q, '%_\\') . '%';
        if (preg_match('/^#?(\d{1,10})$/', $q, $m)) {
            $w[] = '(t.id = ? OR t.token = ? OR t.reference = ?)';
            array_push($a, (int) $m[1], $q, $q);
        } else {
            $w[] = '(t.token = ? OR t.holder_name LIKE ? OR t.reference LIKE ?)';
            array_push($a, $parsed['token'] ?? $q, $like, $like);
        }
    }
    $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $st = $db->prepare("SELECT COUNT(*) FROM tickets t $where");
    $st->execute($a);
    $total = (int) $st->fetchColumn();
    $page = max(1, Http::int($_GET, 'page') ?? 1);
    $offset = ($page - 1) * 50;
    $st = $db->prepare("SELECT t.id, t.token, t.holder_name, t.reference, t.status, t.created_at, t.used_at, e.name AS event_name, u.display_name AS used_by FROM tickets t JOIN events e ON e.id = t.event_id LEFT JOIN users u ON u.id = t.used_by $where ORDER BY t.id DESC LIMIT 50 OFFSET $offset");
    $st->execute($a);
    Http::json(['ok' => true, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / 50)), 'rows' => array_map(static fn ($r) => [
        'id' => (int) $r['id'],
        'code' => $r['token'],
        'holder' => $r['holder_name'],
        'reference' => $r['reference'],
        'status' => $r['status'],
        'event' => $r['event_name'],
        'created_at' => App::localTime($r['created_at'], 'd/m/Y H:i'),
        'used_at' => App::localTime($r['used_at'], 'd/m/Y H:i:s'),
        'used_by' => $r['used_by'],
    ], $st->fetchAll())]);
}

Http::requireMethod('POST');
Auth::requireCsrf();
$in = Http::body();
$action = Http::str($in, 'action', 20);

if ($action === 'create') {
    $eventId = Http::int($in, 'event_id');
    $count = Http::int($in, 'count') ?? 1;
    $st = $db->prepare('SELECT id FROM events WHERE id = ?');
    $st->execute([$eventId ?? 0]);
    if (!$eventId || !$st->fetch()) {
        Http::fail('Choisissez un événement.', 422);
    }
    if ($count < 1 || $count > 500) {
        Http::fail('Entre 1 et 500 billets à la fois.', 422);
    }
    $holder = Http::str($in, 'holder', 160);
    $ref = Http::str($in, 'reference', 80);
    foreach ([$holder, $ref] as $v) {
        if ($v !== '' && preg_match('/^[=+\-@]/', $v)) {
            Http::fail('Le nom et la référence ne peuvent pas commencer par = + - @.', 422);
        }
    }
    $created = TicketService::create($eventId, $count, $holder, $ref);
    Audit::log('tickets_create', "event:$eventId", null, ['count' => $count]);
    Http::json(['ok' => true, 'created' => count($created), 'tickets' => array_map(static fn ($t) => $t + ['qr' => TicketCode::qrPayload($t['token'])], array_slice($created, 0, 20))]);
}

$id = Http::int($in, 'id');
if (!$id) {
    Http::fail('Billet non précisé.', 422);
}
$done = match ($action) {
    'cancel' => TicketService::setCancelled($id, true),
    'reactivate' => TicketService::setCancelled($id, false),
    'reset' => TicketService::resetUsed($id),
    default => Http::fail('Action inconnue.', 422),
};
if (!$done) {
    Http::fail('Action impossible dans l\'état actuel du billet.', 409);
}
Audit::log('ticket_' . $action, "ticket:$id");
Http::json(['ok' => true, 'message' => ['cancel' => 'Billet annulé.', 'reactivate' => 'Billet réactivé.', 'reset' => 'Billet remis à « valide ».'][$action]]);
