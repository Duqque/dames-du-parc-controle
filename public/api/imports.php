<?php
declare(strict_types=1);

use DDP\{App, Auth, Http};

require dirname(__DIR__, 2) . '/src/api.php';

Http::requireMethod('GET');
Auth::requireApi('admin');
$rows = App::db()->query('SELECT i.id, i.filename, i.total_rows, i.inserted_rows, i.duplicate_rows, i.error_rows, i.created_at, e.name AS event_name, u.display_name FROM imports i JOIN events e ON e.id = i.event_id LEFT JOIN users u ON u.id = i.user_id ORDER BY i.id DESC LIMIT 50')->fetchAll();
Http::json(['ok' => true, 'imports' => array_map(static fn ($r) => [
    'id' => (int) $r['id'], 'filename' => $r['filename'], 'event' => $r['event_name'], 'by' => $r['display_name'], 'at' => App::localTime($r['created_at']),
    'total' => (int) $r['total_rows'], 'inserted' => (int) $r['inserted_rows'], 'duplicates' => (int) $r['duplicate_rows'], 'errors' => (int) $r['error_rows'],
], $rows)]);
