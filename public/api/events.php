<?php
declare(strict_types=1);

use DDP\{App, Audit, Auth, Http};

require dirname(__DIR__, 2) . '/src/api.php';

/**
 * Événements.
 * GET : liste (administrateurs : tous, avec leurs compteurs ; contrôleurs : événements actifs).
 * POST { action: create|update|delete, … } : administrateurs.
 */
$user = Auth::requireApi('controller');
$db = App::db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $admin = $user['role'] === 'admin';
    $rows = $db->query("SELECT e.id, e.name, e.starts_at, e.location, e.is_active,
                               COUNT(t.id) AS total, SUM(t.status = 'valid') AS valid, SUM(t.status = 'used') AS used, SUM(t.status = 'cancelled') AS cancelled
                          FROM events e LEFT JOIN tickets t ON t.event_id = e.id " . ($admin ? '' : 'WHERE e.is_active = 1 ') . "
                         GROUP BY e.id ORDER BY e.starts_at DESC")->fetchAll();
    Http::json(['ok' => true, 'events' => array_map(static fn ($e) => [
        'id' => (int) $e['id'], 'name' => $e['name'], 'starts_at' => App::localTime($e['starts_at'], 'Y-m-d\TH:i'), 'date' => App::localTime($e['starts_at'], 'd/m/Y H:i'),
        'location' => $e['location'], 'is_active' => (bool) $e['is_active'],
    ] + ($admin ? ['total' => (int) $e['total'], 'valid' => (int) $e['valid'], 'used' => (int) $e['used'], 'cancelled' => (int) $e['cancelled']] : []), $rows)]);
}

Http::requireMethod('POST');
Auth::requireApi('admin');
Auth::requireCsrf();
$in = Http::body();
$action = Http::str($in, 'action', 20);

if ($action === 'create' || $action === 'update') {
    $name = Http::str($in, 'name', 160);
    $startsAt = Http::str($in, 'starts_at', 16);
    $location = Http::str($in, 'location', 200);
    $active = !empty($in['is_active']);
    if ($name === '' || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $startsAt)) {
        Http::fail('Nom et date de l\'événement obligatoires.', 422);
    }
    $utc = App::toUtc(str_replace('T', ' ', $startsAt) . ':00');
    if ($action === 'create') {
        $db->prepare('INSERT INTO events (name, starts_at, location, is_active, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())')->execute([$name, $utc, $location ?: null, $active ? 1 : 0]);
        $id = (int) $db->lastInsertId();
        Audit::log('event_create', "event:$id", null, ['name' => $name]);
        Http::json(['ok' => true, 'id' => $id, 'message' => 'Événement créé.']);
    }
    $id = Http::int($in, 'id');
    $st = $db->prepare('UPDATE events SET name = ?, starts_at = ?, location = ?, is_active = ? WHERE id = ?');
    $st->execute([$name, $utc, $location ?: null, $active ? 1 : 0, $id ?? 0]);
    Audit::log('event_update', "event:$id", null, ['name' => $name, 'active' => $active]);
    Http::json(['ok' => true, 'message' => 'Événement mis à jour.']);
}

if ($action === 'delete') {
    $id = Http::int($in, 'id');
    $st = $db->prepare('SELECT COUNT(*) FROM tickets WHERE event_id = ?');
    $st->execute([$id ?? 0]);
    if ((int) $st->fetchColumn() > 0) {
        Http::fail('Cet événement a des billets : désactivez-le plutôt que de le supprimer.', 409);
    }
    $db->prepare('DELETE FROM events WHERE id = ?')->execute([$id ?? 0]);
    Audit::log('event_delete', "event:$id");
    Http::json(['ok' => true, 'message' => 'Événement supprimé.']);
}
Http::fail('Action inconnue.', 422);
