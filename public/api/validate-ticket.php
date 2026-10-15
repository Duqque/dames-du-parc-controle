<?php
declare(strict_types=1);

use DDP\{Auth, Http, TicketService};

require dirname(__DIR__, 2) . '/src/api.php';

/**
 * Contrôle d'un billet à l'entrée : vérification et validation atomique côté serveur.
 * Entrée JSON : { code: string, event_id?: int, method: "scan"|"manual" }
 * Sortie : { ok, result: valid|already_used|invalid|cancelled|wrong_event|error, message, ticket?, used_at?, used_by? }
 * Seul result === "valid" autorise l'entrée. Toute erreur renvoie result "error" (accès refusé par défaut).
 */
Http::requireMethod('POST');
$user = Auth::requireApi('controller');
Auth::requireCsrf();
Auth::release();
$in = Http::body();
$code = Http::str($in, 'code', 300);
if ($code === '') {
    Http::fail('Aucun code reçu.', 422);
}
$eventId = Http::int($in, 'event_id');
$method = Http::str($in, 'method', 10) === 'manual' ? 'manual' : 'scan';
$t0 = microtime(true);
$res = TicketService::validate($code, $user['id'], $eventId ?: null, $method);
if (microtime(true) - $t0 > 0.5 || microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'] > 0.5) \DDP\Log::info('Contrôle lent', ['validate' => round(microtime(true) - $t0, 3), 'total' => round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3)]);
Http::json(['ok' => $res['result'] !== 'error'] + $res, $res['result'] === 'error' ? 503 : 200);
