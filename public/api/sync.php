<?php
declare(strict_types=1);

use DDP\{Audit, Auth, Http, SiteSync};

require dirname(__DIR__, 2) . '/src/api.php';

/** Synchronisation immédiate avec lesdamesduparc.com (bouton « Synchroniser maintenant »). */
Http::requireMethod('POST');
$user = Auth::requireApi('controller');
Auth::requireCsrf();
Auth::release();
if (!SiteSync::enabled()) {
    Http::fail('Le lien avec le site n\'est pas configuré.', 409);
}
$res = SiteSync::pull(0, 8);
$sent = SiteSync::retryPending();
if (!$res['skipped']) {
    Audit::log('site_sync', null, $user['id'], ['ok' => $res['ok'], 'tickets' => $res['tickets'] ?? null]);
}
Http::json(['ok' => $res['ok'], 'message' => $res['ok'] ? 'Billets du site à jour.' : 'Synchronisation impossible : ' . ($res['error'] ?? 'erreur inconnue'), 'pushed' => $sent, 'site' => SiteSync::status()], $res['ok'] ? 200 : 502);
