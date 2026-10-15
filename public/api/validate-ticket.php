<?php
declare(strict_types=1);

use DDP\{Auth, Http, Log, SiteSync, TicketService};

require dirname(__DIR__, 2) . '/src/api.php';

/**
 * Contrôle d'un billet à l'entrée : vérification et validation atomique côté serveur.
 * Entrée JSON : { code: string, event_id?: int, method: "scan"|"manual" } ou { ticket_id: int, event_id?: int } (contrôle depuis la liste)
 * Sortie : { ok, result: valid|already_used|invalid|cancelled|wrong_event|error, message, ticket?, used_at?, used_by? }
 * Seul result === "valid" autorise l'entrée. Toute erreur renvoie result "error" (accès refusé par défaut).
 */
Http::requireMethod('POST');
$user = Auth::requireApi('controller');
Auth::requireCsrf();
Auth::release();
$in = Http::body();
$code = Http::str($in, 'code', 300);
$ticketId = Http::int($in, 'ticket_id');
if ($code === '' && !$ticketId) {
    Http::fail('Aucun code reçu.', 422);
}
$eventId = Http::int($in, 'event_id');
$method = Http::str($in, 'method', 10);
$method = $ticketId ? 'list' : ($method === 'manual' ? 'manual' : 'scan');
$t0 = microtime(true);
$res = TicketService::validate($code, $user['id'], $eventId ?: null, $method, $ticketId ?: null);
// Code inconnu : le billet vient peut-être d'être acheté sur le site. On recharge les billets du site (5 s minimum entre deux
// rechargements) et on contrôle une seconde fois.
if ($res['result'] === 'invalid' && !$ticketId && SiteSync::enabled() && !str_starts_with($res['message'], 'Signature')) {
    $pull = SiteSync::pull(5);
    if (!$pull['skipped'] && $pull['ok']) {
        $res = TicketService::validate($code, $user['id'], $eventId ?: null, $method);
    }
}
if (microtime(true) - $t0 > 1.5) {
    Log::info('Contrôle lent', ['seconds' => round(microtime(true) - $t0, 3)]);
}
$ticket = $res['ticket']['id'] ?? null;
$push = $res['result'] === 'valid' && !empty($res['ticket']['from_site']);
// Réponse immédiate au contrôleur, puis présence transmise au site (en attente et renvoyée plus tard en cas d'échec).
Http::jsonThen(['ok' => $res['result'] !== 'error', 'site' => $push ? 'pending' : null] + $res, $res['result'] === 'error' ? 503 : 200, static function () use ($push, $ticket, $user): void {
    if ($push && $ticket) {
        SiteSync::push((int) $ticket, $user['display_name']);
    }
});
