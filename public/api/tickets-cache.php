<?php
declare(strict_types=1);

use DDP\{App, Auth, Http, SiteSync};

require dirname(__DIR__, 2) . '/src/api.php';

/**
 * Chargement de tous les billets à l'ouverture de l'application (contrôleurs) : les billets du site sont d'abord rechargés
 * (au plus toutes les 30 s), puis la liste complète est envoyée au téléphone.
 * Les codes ne quittent jamais le serveur : le téléphone reçoit leur empreinte SHA-256 (reconnaissance immédiate au scan,
 * liste des participantes, présentes et absentes). La validation reste faite par le serveur.
 * GET ?event_id= (facultatif : sinon tous les événements actifs)
 */
Http::requireMethod('GET');
Auth::requireApi('controller');
Auth::release();
$pull = SiteSync::pull();
$eventId = Http::int($_GET, 'event_id');
$db = App::db();
$sql = "SELECT t.id, t.token, t.event_id, t.holder_name, t.reference, t.places, t.birth_date, t.status, t.cancel_reason, t.used_at, t.member_hash, u.display_name AS used_by
          FROM tickets t JOIN events e ON e.id = t.event_id LEFT JOIN users u ON u.id = t.used_by
         WHERE e.is_active = 1" . ($eventId ? ' AND t.event_id = ?' : '') . " ORDER BY t.holder_name, t.id";
$st = $db->prepare($sql);
$st->execute($eventId ? [$eventId] : []);
$tickets = [];
$counts = ['total' => 0, 'present' => 0, 'absent' => 0, 'cancelled' => 0];
while ($t = $st->fetch()) {
    // Les anciens codes remplacés (perte) ne comptent pas comme billets à part : ils servent seulement à refuser l'entrée.
    $replaced = $t['status'] === 'cancelled' && $t['cancel_reason'] === 'perte';
    if (!$replaced) {
        $counts['total']++;
        $counts[$t['status'] === 'used' ? 'present' : ($t['status'] === 'valid' ? 'absent' : 'cancelled')]++;
    }
    $tickets[] = [
        'i' => (int) $t['id'],
        'h' => hash('sha256', $t['token']),
        'e' => (int) $t['event_id'],
        'n' => $t['holder_name'],
        'r' => $t['reference'],
        'p' => (int) $t['places'],
        'b' => $t['birth_date'] ? date('d/m/Y', strtotime($t['birth_date'])) : null,
        's' => $t['status'],
        'c' => $t['cancel_reason'],
        'u' => App::localTime($t['used_at'], 'H:i'),
        'by' => $t['used_by'],
        'x' => $replaced,
    ];
}
Http::json(['ok' => true, 'loaded_at' => App::localTime(gmdate('Y-m-d H:i:s'), 'H:i:s'), 'counts' => $counts, 'tickets' => $tickets, 'site' => SiteSync::status() + ['last_result' => $pull['ok'] ? 'ok' : 'error']]);
