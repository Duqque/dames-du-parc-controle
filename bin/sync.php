<?php
declare(strict_types=1);

/**
 * Synchronisation avec lesdamesduparc.com en tâche planifiée (facultatif : l'application se synchronise déjà à chaque ouverture
 * et toutes les 30 s pendant le contrôle). Utile pour renvoyer les présences en attente après une panne du site.
 *   php bin/sync.php            (cron conseillé : toutes les 5 minutes les jours d'événement)
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use DDP\SiteSync;

if (!SiteSync::enabled()) {
    fwrite(STDERR, "Lien avec le site non configuré (config site.url / site.api_key).\n");
    exit(1);
}
$res = SiteSync::pull(0, 30);
$sent = SiteSync::retryPending();
echo $res['ok'] ? "Billets du site chargés : " . ($res['tickets'] ?? 0) . ". Présences renvoyées : $sent.\n" : "Échec : " . $res['error'] . "\n";
exit($res['ok'] ? 0 : 1);
