<?php
declare(strict_types=1);

use DDP\{Auth, Checks, Http};

require dirname(__DIR__, 2) . '/src/api.php';

Http::requireMethod('GET');
Auth::requireApi('admin');
$eventId = Http::int($_GET, 'event_id');
Http::json(['ok' => true, 'stats' => Checks::stats($eventId ?: null), 'recent' => Checks::list($eventId ? ['event_id' => $eventId] : [], 1, 12)['rows']]);
