<?php
declare(strict_types=1);

use DDP\{App, Auth, Checks, Http};

require dirname(__DIR__, 2) . '/src/api.php';

/** Historique des contrôles du contrôleur connecté (les 100 derniers), pour l'onglet « Historique » de l'application. */
Http::requireMethod('GET');
$user = Auth::requireApi('controller');
Auth::release();
$eventId = Http::int($_GET, 'event_id');
$st = App::db()->prepare('SELECT c.created_at, c.result, c.method, c.code_hint, t.id AS ticket_id, t.holder_name, t.places, e.name AS event_name
                            FROM check_logs c LEFT JOIN tickets t ON t.id = c.ticket_id LEFT JOIN events e ON e.id = c.event_id
                           WHERE c.user_id = ?' . ($eventId ? ' AND c.event_id = ?' : '') . ' ORDER BY c.id DESC LIMIT 100');
$st->execute($eventId ? [$user['id'], $eventId] : [$user['id']]);
$valid = 0;
$rows = array_map(static function ($r) use (&$valid) {
    if ($r['result'] === 'valid') {
        $valid++;
    }
    return ['at' => App::localTime($r['created_at'], 'H:i:s'), 'day' => App::localTime($r['created_at'], 'd/m'), 'result' => $r['result'], 'label' => Checks::LABELS[$r['result']] ?? $r['result'], 'method' => $r['method'], 'holder' => $r['holder_name'], 'places' => $r['places'] !== null ? (int) $r['places'] : null, 'code' => $r['code_hint'], 'event' => $r['event_name']];
}, $st->fetchAll());
Http::json(['ok' => true, 'valid' => $valid, 'rows' => $rows]);
