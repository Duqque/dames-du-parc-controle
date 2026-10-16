<?php
declare(strict_types=1);

use DDP\{App, Auth, Http};

require dirname(__DIR__, 2) . '/src/api.php';

Http::requireMethod('GET');
Auth::requireApi('admin');
$rows = App::db()->query('SELECT a.created_at, a.action, a.target, a.details, u.display_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 200')->fetchAll();
Http::json(['ok' => true, 'rows' => array_map(static fn ($r) => ['at' => App::localTime($r['created_at']), 'action' => $r['action'], 'target' => $r['target'], 'details' => $r['details'], 'by' => $r['display_name']], $rows)]);
