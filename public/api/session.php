<?php
declare(strict_types=1);

use DDP\{App, Auth, Http};

require dirname(__DIR__, 2) . '/src/api.php';

Http::requireMethod('GET');
$u = Auth::user();
$events = [];
if ($u) {
    // Événements proposés au contrôle : actifs, du plus proche au plus lointain.
    $rows = App::db()->query("SELECT id, name, starts_at, location FROM events WHERE is_active = 1 ORDER BY ABS(TIMESTAMPDIFF(MINUTE, starts_at, UTC_TIMESTAMP())), starts_at")->fetchAll();
    $events = array_map(static fn ($e) => ['id' => (int) $e['id'], 'name' => $e['name'], 'date' => App::localTime($e['starts_at'], 'd/m/Y H:i'), 'location' => $e['location']], $rows);
}
$csrf = Auth::csrfToken();
Auth::release();
Http::json([
    'ok' => true,
    'user' => $u ? ['name' => $u['display_name'], 'username' => $u['username'], 'role' => $u['role']] : null,
    'csrf' => $csrf,
    'events' => $events,
]);
