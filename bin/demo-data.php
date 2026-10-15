<?php
declare(strict_types=1);

/**
 * Jeu de données de démonstration (à n'utiliser que sur une base de test).
 *   php bin/demo-data.php
 * Crée 2 événements, 3 comptes (1 administrateur, 2 contrôleurs) et 80 billets (dont quelques billets déjà utilisés et annulés),
 * puis écrit la liste des codes dans storage/tmp/demo-billets.csv. Les mots de passe des comptes de démonstration sont générés
 * aléatoirement et affichés une seule fois (ou fixés par DDP_DEMO_PASSWORD).
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use DDP\{App, Csv, TicketCode, TicketService, Users};

$db = App::db();
if ((int) $db->query('SELECT COUNT(*) FROM tickets')->fetchColumn() > 0 && !in_array('--force', $argv, true)) {
    fwrite(STDERR, "La base contient déjà des billets. Relancez avec --force pour ajouter quand même les données de démonstration.\n");
    exit(1);
}
$pw = getenv('DDP_DEMO_PASSWORD') ?: null;
$accounts = [['demo.admin', 'Admin Démo', 'admin'], ['controle.porte1', 'Porte 1', 'controller'], ['controle.porte2', 'Porte 2', 'controller']];
$created = [];
foreach ($accounts as [$u, $n, $r]) {
    $st = $db->prepare('SELECT id FROM users WHERE username = ?');
    $st->execute([$u]);
    if ($st->fetch()) {
        continue;
    }
    $password = $pw ?: 'Demo-' . TicketCode::generate(6) . '-' . random_int(10, 99);
    $id = Users::create($u, $n, $password, $r);
    $created[] = [$u, $password, $r, $id];
}

$events = [
    ['Watch party : PSG - Barça', '2026-10-20 21:00:00', 'Salle des Dames, Paris'],
    ['Soirée des Dames', '2026-11-14 19:30:00', 'Paris 16e'],
];
$ids = [];
foreach ($events as [$name, $at, $loc]) {
    $db->prepare('INSERT INTO events (name, starts_at, location, is_active, created_at) VALUES (?, ?, ?, 1, UTC_TIMESTAMP())')->execute([$name, App::toUtc($at), $loc]);
    $ids[] = (int) $db->lastInsertId();
}
$names = ['Camille Martin', 'Léa Dubois', 'Inès Petit', 'Sarah Bernard', 'Chloé Robert', 'Manon Richard', 'Emma Durand', 'Jade Leroy', 'Louise Moreau', 'Alice Simon'];
$all = [];
foreach ($ids as $k => $eventId) {
    for ($i = 1; $i <= 40; $i++) {
        $t = TicketService::create($eventId, 1, $names[$i % count($names)] . ($i > 10 ? " $i" : ''), sprintf('DEMO-%d-%03d', $k + 1, $i))[0];
        $all[] = $t + ['event' => $events[$k][0], 'holder' => $names[$i % count($names)]];
    }
}
// Quelques billets déjà utilisés et annulés pour la démonstration.
$ctrlId = (int) $db->query("SELECT id FROM users WHERE username = 'controle.porte1'")->fetchColumn() ?: null;
foreach (array_slice($all, 0, 5) as $t) {
    $db->prepare("UPDATE tickets SET status = 'used', used_at = UTC_TIMESTAMP() - INTERVAL 20 MINUTE, used_by = ? WHERE id = ?")->execute([$ctrlId, $t['id']]);
    $db->prepare("INSERT INTO check_logs (ticket_id, event_id, user_id, result, method, code_hint, code_hash, ip_address, created_at) VALUES (?, ?, ?, 'valid', 'scan', ?, ?, 'demo', UTC_TIMESTAMP(3) - INTERVAL 20 MINUTE)")
        ->execute([$t['id'], $ids[0], $ctrlId, TicketCode::hint($t['token']), hash('sha256', $t['token'])]);
}
foreach (array_slice($all, 5, 3) as $t) {
    TicketService::setCancelled($t['id'], true);
}
$file = APP_ROOT . '/storage/tmp/demo-billets.csv';
$fh = fopen($file, 'wb');
fwrite($fh, "\xEF\xBB\xBF");
fputcsv($fh, ['id', 'evenement', 'titulaire', 'code', 'contenu_qr', 'statut'], ';', '"', '');
foreach ($all as $i => $t) {
    fputcsv($fh, array_map([Csv::class, 'cell'], [$t['id'], $t['event'], $t['holder'], $t['token'], TicketCode::qrPayload($t['token']), $i < 5 ? 'utilisé' : ($i < 8 ? 'annulé' : 'valide')]), ';', '"', '');
}
fclose($fh);

echo "Données de démonstration créées : " . count($ids) . " événements, " . count($all) . " billets.\n";
echo "Codes des billets : storage/tmp/demo-billets.csv (QR codes imprimables depuis Administration > Billets > Imprimer).\n";
foreach ($created as [$u, $p, $r]) {
    echo sprintf("  %-18s %-12s mot de passe : %s\n", $u, $r === 'admin' ? 'administrateur' : 'contrôleur', $pw ? '(DDP_DEMO_PASSWORD)' : $p);
}
