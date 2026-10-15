<?php
declare(strict_types=1);

/**
 * Tests de recette : sécurité, validation des billets, double scan, contrôles simultanés, imports et exports CSV.
 *
 *   BASE_URL=https://controle.example.org php tests/run-tests.php
 *
 * À lancer sur une base de TEST (jamais en production) : le script crée ses propres comptes, événement et billets (préfixe
 * « test- »), puis les supprime à la fin. L'application doit être servie par Apache à l'adresse BASE_URL.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use DDP\{App, TicketCode, TicketService, Users};

$BASE = rtrim(getenv('BASE_URL') ?: (string) App::config('app_url'), '/');
$db = App::db();
$pass = 0;
$fail = 0;
$section = '';

function section(string $s): void { global $section; $section = $s; echo "\n== $s ==\n"; }
function ok(bool $cond, string $label, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  OK    $label\n"; }
    else { $fail++; echo "  ÉCHEC $label" . ($detail !== '' ? "  →  $detail" : '') . "\n"; }
}

/** Client HTTP minimal avec cookies (une instance = un navigateur / un contrôleur). */
final class Client
{
    public array $cookies = [];
    public string $csrf = '';
    public function __construct(private string $base, private string $ip) {}

    public function req(string $method, string $path, array|string|null $body = null, array $headers = [], bool $json = true, bool $csrf = true): array
    {
        $ch = $this->handle($method, $path, $body, $headers, $json, $csrf);
        $raw = curl_exec($ch);
        return $this->parse($ch, (string) $raw);
    }

    public function handle(string $method, string $path, array|string|null $body = null, array $headers = [], bool $json = true, bool $csrf = true)
    {
        $ch = curl_init($this->base . $path);
        $h = array_merge(['Accept: application/json', 'X-Forwarded-For: ' . $this->ip], $headers);
        if ($csrf && $method !== 'GET' && $this->csrf !== '') $h[] = 'X-CSRF-Token: ' . $this->csrf;
        if ($this->cookies) $h[] = 'Cookie: ' . implode('; ', array_map(fn ($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        if (is_array($body) && $json) { $h[] = 'Content-Type: application/json'; $body = json_encode($body); }
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        return $ch;
    }

    public function parse($ch, string $raw): array
    {
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $head = substr($raw, 0, $hs);
        $bodyText = substr($raw, $hs);
        preg_match_all('/^Set-Cookie:\s*([^=]+)=([^;\r\n]*)(.*)$/mi', $head, $m, PREG_SET_ORDER);
        foreach ($m as $c) {
            if ($c[2] === '' || str_contains(strtolower($c[3]), 'expires=thu, 01 jan 1970') || preg_match('/max-age=0/i', $c[3])) unset($this->cookies[$c[1]]);
            else $this->cookies[$c[1]] = $c[2];
        }
        $data = json_decode($bodyText, true);
        if (is_array($data) && is_string($data['csrf'] ?? null)) $this->csrf = $data['csrf'];
        return ['status' => $status, 'data' => is_array($data) ? $data : null, 'headers' => $head, 'body' => $bodyText];
    }

    public function start(): void { $this->req('GET', '/api/session.php'); }
    public function login(string $u, string $p): array { $this->start(); return $this->req('POST', '/api/login.php', ['username' => $u, 'password' => $p]); }
}

// ---------------------------------------------------------------------------------------------------------------------------
// Jeu d'essai
// ---------------------------------------------------------------------------------------------------------------------------
$suffix = substr(bin2hex(random_bytes(3)), 0, 6);
$pw = 'Test-' . bin2hex(random_bytes(6)) . '9';
$ids = [];
foreach (['admin' => 'admin', 'ctrl1' => 'controller', 'ctrl2' => 'controller'] as $k => $role) {
    $ids[$k] = Users::create("test-$k-$suffix", "Test $k", $pw, $role);
}
$db->prepare('INSERT INTO events (name, starts_at, location, is_active) VALUES (?, UTC_TIMESTAMP(), ?, 1)')->execute(["test-événement-$suffix", 'Test']);
$eventId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO events (name, starts_at, location, is_active) VALUES (?, UTC_TIMESTAMP(), ?, 1)')->execute(["test-autre-$suffix", 'Test']);
$otherEventId = (int) $db->lastInsertId();
$tk = TicketService::create($eventId, 120, 'Titulaire Test', "test-$suffix");
$other = TicketService::create($otherEventId, 1)[0];
$next = 0;
$take = function () use (&$next, $tk) { return $tk[$next++]; };

$cleanup = function () use ($db, $ids, $eventId, $otherEventId, $suffix) {
    $db->prepare('DELETE c FROM check_logs c JOIN tickets t ON t.id = c.ticket_id WHERE t.event_id IN (?, ?)')->execute([$eventId, $otherEventId]);
    $db->prepare('DELETE FROM check_logs WHERE event_id IN (?, ?) OR user_id IN (?, ?, ?)')->execute([$eventId, $otherEventId, $ids['admin'], $ids['ctrl1'], $ids['ctrl2']]);
    $db->prepare('DELETE FROM tickets WHERE event_id IN (?, ?)')->execute([$eventId, $otherEventId]);
    $db->prepare('DELETE FROM imports WHERE event_id IN (?, ?)')->execute([$eventId, $otherEventId]);
    $db->prepare('DELETE FROM events WHERE id IN (?, ?)')->execute([$eventId, $otherEventId]);
    $db->prepare("DELETE FROM audit_logs WHERE target LIKE ? OR user_id IN (?, ?, ?)")->execute(["%$suffix%", $ids['admin'], $ids['ctrl1'], $ids['ctrl2']]);
    $db->prepare('DELETE FROM users WHERE username LIKE ?')->execute(["test-%-$suffix"]);
    $db->prepare('DELETE FROM login_attempts WHERE username LIKE ?')->execute(["test-%-$suffix"]);
};
register_shutdown_function($cleanup);

$admin = new Client($BASE, '10.0.0.1');
$c1 = new Client($BASE, '10.0.0.2');
$c2 = new Client($BASE, '10.0.0.3');
$anon = new Client($BASE, '10.0.0.4');

// ---------------------------------------------------------------------------------------------------------------------------
section('Accès protégés (sans connexion)');
foreach (['/api/dashboard.php', '/api/checks.php', '/api/tickets.php', '/api/users.php', '/api/export-checks.php', '/api/ticket-status.php?code=X'] as $p) {
    ok($anon->req('GET', $p)['status'] === 401, "GET $p refusé (401)");
}
$anon->start();
ok($anon->req('POST', '/api/validate-ticket.php', ['code' => $tk[0]['token']])['status'] === 401, 'Contrôle d\'un billet refusé sans connexion (401)');
$r = $anon->req('GET', '/admin/');
ok($r['status'] === 303 && str_contains($r['headers'], 'Location: /login.php'), 'Page d\'administration → redirection vers la connexion');
$r = $anon->req('GET', '/scan.php');
ok($r['status'] === 303, 'Écran de contrôle → redirection vers la connexion');
ok($anon->req('GET', '/install.php')['status'] === 404, 'Page d\'installation bloquée (jeton absent ou administrateur existant)');

section('Connexion et sessions');
$x = new Client($BASE, '10.0.1.1');
$x->start();
$r = $x->req('POST', '/api/login.php', ['username' => "test-ctrl1-$suffix", 'password' => $pw], [], true, false);
ok($r['status'] === 403, 'Connexion sans jeton CSRF refusée (403)');
$r = $x->req('POST', '/api/login.php', ['username' => "test-ctrl1-$suffix", 'password' => $pw], ['Origin: https://site-malveillant.example']);
ok($r['status'] === 403, 'Connexion depuis une autre origine refusée (403)');
$r = $x->req('POST', '/api/login.php', ['username' => "test-ctrl1-$suffix", 'password' => 'mauvais-mot-de-passe1']);
ok($r['status'] === 401 && ($r['data']['error'] ?? '') === 'Identifiant ou mot de passe incorrect.', 'Mauvais mot de passe : 401, message générique');
$r = $x->req('POST', '/api/login.php', ['username' => "inconnu-$suffix", 'password' => 'mauvais-mot-de-passe1']);
ok($r['status'] === 401 && ($r['data']['error'] ?? '') === 'Identifiant ou mot de passe incorrect.', 'Identifiant inconnu : même message (pas d\'énumération des comptes)');
$r = $x->req('POST', '/api/login.php', ['username' => "inconnu-$suffix", 'password' => "abc\0def12345"]);
ok($r['status'] === 401, 'Mot de passe contenant un octet nul : refus normal (401), pas d\'erreur serveur');
$r = $c1->login("test-ctrl1-$suffix", $pw);
ok($r['status'] === 200 && ($r['data']['redirect'] ?? '') === '/scan.php', 'Contrôleur connecté, dirigé vers l\'écran de contrôle');
ok((bool) preg_match('/Set-Cookie: DDPCTRL=[^\r\n]*HttpOnly[^\r\n]*SameSite=Strict/i', $r['headers']), 'Cookie de session HttpOnly et SameSite=Strict');
ok(App::config('session.secure_cookie') === false || str_contains($r['headers'], 'secure'), 'Cookie Secure (si HTTPS configuré)');
$pre = $c1->cookies;
$before = $x->cookies;
$c2->login("test-ctrl2-$suffix", $pw);
$admin->login("test-admin-$suffix", $pw);
ok(($admin->req('GET', '/api/dashboard.php')['status']) === 200, 'Administrateur connecté : tableau de bord accessible');

// Régénération de l'identifiant de session à la connexion
$y = new Client($BASE, '10.0.1.2');
$y->start();
$sidBefore = $y->cookies['DDPCTRL'] ?? '';
$y->login("test-ctrl2-$suffix", $pw);
ok($sidBefore !== '' && ($y->cookies['DDPCTRL'] ?? '') !== $sidBefore, 'Identifiant de session régénéré après la connexion');

section('Limitation des tentatives de connexion');
$z = new Client($BASE, '10.0.2.' . random_int(1, 200));
$z->start();
$codes = [];
for ($i = 0; $i < 6; $i++) {
    $codes[] = $z->req('POST', '/api/login.php', ['username' => "test-admin-$suffix", 'password' => "faux-$i-abcdef1"])['status'];
}
ok($codes[0] === 401 && $codes[5] === 429, '5 échecs puis blocage (429)', implode(',', $codes));
$r = $z->req('POST', '/api/login.php', ['username' => "test-admin-$suffix", 'password' => $pw]);
ok($r['status'] === 429, 'Même le bon mot de passe est refusé pendant le blocage');
$db->prepare('DELETE FROM login_attempts WHERE username = ?')->execute(["test-admin-$suffix"]);

section('Droits des rôles');
foreach (['/api/dashboard.php', '/api/checks.php', '/api/users.php', '/api/tickets.php', '/api/export-tickets.php', '/api/audit.php'] as $p) {
    ok($c1->req('GET', $p)['status'] === 403, "Contrôleur : GET $p refusé (403)");
}
ok($c1->req('POST', '/api/users.php', ['action' => 'create', 'username' => 'pirate', 'display_name' => 'x', 'role' => 'admin', 'password' => 'Abcdefgh123'])['status'] === 403, 'Contrôleur : création de compte refusée');
ok($c1->req('POST', '/api/tickets.php', ['action' => 'cancel', 'id' => $tk[0]['id']])['status'] === 403, 'Contrôleur : annulation de billet refusée');
$r = $c1->req('GET', '/admin/');
ok($r['status'] === 303 && str_contains($r['headers'], 'Location: /scan.php'), 'Contrôleur : pages d\'administration → écran de contrôle');

section('Contrôle des billets');
$t = $take();
$r = $c1->req('POST', '/api/validate-ticket.php', ['code' => TicketCode::qrPayload($t['token']), 'event_id' => $eventId, 'method' => 'scan']);
ok(($r['data']['result'] ?? '') === 'valid', 'Billet valide (QR signé) : accès autorisé', json_encode($r['data']));
$row = $db->query('SELECT status, used_at, used_by FROM tickets WHERE id = ' . $t['id'])->fetch();
ok($row['status'] === 'used' && $row['used_at'] !== null && (int) $row['used_by'] === $ids['ctrl1'], 'Statut « utilisé », heure et contrôleur enregistrés en base');
$r = $c2->req('POST', '/api/validate-ticket.php', ['code' => TicketCode::qrPayload($t['token']), 'event_id' => $eventId, 'method' => 'scan']);
ok(($r['data']['result'] ?? '') === 'already_used' && !empty($r['data']['used_at']) && ($r['data']['used_by'] ?? '') === 'Test ctrl1', 'Second scan : « déjà utilisé » avec l\'heure et le contrôleur du premier contrôle', json_encode($r['data']));
$t2 = $take();
$r = $c1->req('POST', '/api/validate-ticket.php', ['code' => $t2['token'], 'event_id' => $eventId, 'method' => 'manual']);
ok(($r['data']['result'] ?? '') === 'valid', 'Saisie manuelle du code : billet valide');
$tg = $take();
$r = $c1->req('POST', '/api/validate-ticket.php', ['code' => ' ' . implode(' ', str_split($tg['token'], 4)) . ' ', 'event_id' => $eventId, 'method' => 'manual']);
ok(($r['data']['result'] ?? '') === 'valid', 'Saisie manuelle par groupes de 4 caractères (espaces ignorés) : billet valide');
$r = $c1->req('POST', '/api/validate-ticket.php', ['code' => strtolower($t2['token']), 'event_id' => $eventId, 'method' => 'manual']);
ok(($r['data']['result'] ?? '') === 'invalid', 'Code en minuscules (casse différente) : refusé, jetons sensibles à la casse');
$r = $c1->req('POST', '/api/validate-ticket.php', ['code' => TicketCode::generate(), 'event_id' => $eventId, 'method' => 'manual']);
ok(($r['data']['result'] ?? '') === 'invalid', 'Billet inconnu : refusé');
$t3 = $take();
$forged = 'DDP1.' . $t3['token'] . '.AAAAAAAAAAAAAAAA';
$r = $c1->req('POST', '/api/validate-ticket.php', ['code' => $forged, 'event_id' => $eventId]);
ok(($r['data']['result'] ?? '') === 'invalid' && str_contains($r['data']['message'] ?? '', 'Signature'), 'QR code à signature falsifiée : refusé (« signature invalide »)');
ok($db->query('SELECT status FROM tickets WHERE id = ' . $t3['id'])->fetchColumn() === 'valid', '… et le billet authentique reste valide');
$t4 = $take();
TicketService::setCancelled($t4['id'], true);
$r = $c1->req('POST', '/api/validate-ticket.php', ['code' => $t4['token'], 'event_id' => $eventId]);
ok(($r['data']['result'] ?? '') === 'cancelled', 'Billet annulé : refusé');
$r = $c1->req('POST', '/api/validate-ticket.php', ['code' => $other['token'], 'event_id' => $eventId]);
ok(($r['data']['result'] ?? '') === 'wrong_event' && $db->query('SELECT status FROM tickets WHERE id = ' . $other['id'])->fetchColumn() === 'valid', 'Billet d\'un autre événement : refusé, non consommé');
$r = $c1->req('POST', '/api/validate-ticket.php', ['code' => "\0\0 \0", 'event_id' => $eventId]);
ok($r['status'] === 422 && ($r['data']['ok'] ?? true) === false, 'Code vide après nettoyage (caractères nuls) : refusé (422)');
foreach (["' OR '1'='1", "1; DROP TABLE tickets; --", '<script>alert(1)</script>', str_repeat('A', 300)] as $evil) {
    $r = $c1->req('POST', '/api/validate-ticket.php', ['code' => $evil, 'event_id' => $eventId]);
    ok(in_array($r['data']['result'] ?? '', ['invalid'], true) && $r['status'] === 200, 'Code malveillant refusé proprement : ' . substr(json_encode($evil), 0, 30));
}
ok((int) $db->query('SELECT COUNT(*) FROM tickets')->fetchColumn() > 0, 'Table des billets intacte après les tentatives d\'injection SQL');
$r = $c1->req('POST', '/api/validate-ticket.php', ['code' => $take()['token'], 'event_id' => $eventId], [], true, false);
ok($r['status'] === 403, 'Contrôle sans jeton CSRF refusé (403)');
$r = $c1->req('GET', '/api/ticket-status.php?code=' . urlencode($tk[0]['token']));
ok(($r['data']['ticket']['status'] ?? '') === 'used' && !isset($r['data']['ticket']['token']), 'Consultation du statut par un contrôleur, sans modification ni divulgation du code');

section('Double entrée : scans simultanés de plusieurs contrôleurs');
$mh = curl_multi_init();
$rounds = 10;
$parallel = 16;
$allGood = true;
$details = [];
for ($round = 0; $round < $rounds; $round++) {
    $t = $take();
    $handles = [];
    for ($i = 0; $i < $parallel; $i++) {
        $cl = $i % 2 ? $c1 : $c2;
        $h = $cl->handle('POST', '/api/validate-ticket.php', ['code' => TicketCode::qrPayload($t['token']), 'event_id' => $eventId, 'method' => 'scan']);
        $handles[] = [$h, $cl];
        curl_multi_add_handle($mh, $h);
    }
    do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 0.05); } while ($running && $st === CURLM_OK);
    $results = [];
    foreach ($handles as [$h, $cl]) {
        $res = $cl->parse($h, (string) curl_multi_getcontent($h));
        $results[] = $res['data']['result'] ?? ('http' . $res['status']);
        curl_multi_remove_handle($mh, $h);
    }
    $counts = array_count_values($results);
    $logValid = (int) $db->query("SELECT COUNT(*) FROM check_logs WHERE ticket_id = {$t['id']} AND result = 'valid'")->fetchColumn();
    if (($counts['valid'] ?? 0) !== 1 || ($counts['already_used'] ?? 0) !== $parallel - 1 || $logValid !== 1) {
        $allGood = false;
        $details[] = json_encode($counts) . " journal=$logValid";
    }
}
ok($allGood, "$rounds billets × $parallel scans simultanés (2 contrôleurs) : exactement 1 entrée validée par billet, les autres « déjà utilisé »", implode(' | ', $details));

// Charge : 60 billets différents contrôlés en même temps (aucun blocage mutuel, aucun refus à tort).
$handles = [];
$batch = [];
for ($i = 0; $i < 60; $i++) {
    $t = $take();
    $batch[] = $t['id'];
    $cl = $i % 2 ? $c1 : $c2;
    $h = $cl->handle('POST', '/api/validate-ticket.php', ['code' => $t['token'], 'event_id' => $eventId, 'method' => 'manual']);
    $handles[] = [$h, $cl];
    curl_multi_add_handle($mh, $h);
}
$t0 = microtime(true);
do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 0.05); } while ($running && $st === CURLM_OK);
$elapsed = microtime(true) - $t0;
$res = [];
foreach ($handles as [$h, $cl]) {
    $res[] = $cl->parse($h, (string) curl_multi_getcontent($h))['data']['result'] ?? 'x';
    curl_multi_remove_handle($mh, $h);
}
$valid = array_count_values($res)['valid'] ?? 0;
ok($valid === 60, "60 billets différents contrôlés simultanément : 60 entrées valides en " . round($elapsed, 2) . ' s', json_encode(array_count_values($res)));
ok((int) $db->query('SELECT COUNT(*) FROM tickets WHERE status = \'used\' AND id IN (' . implode(',', $batch) . ')')->fetchColumn() === 60, '… et 60 billets « utilisés » en base');

section('Persistance');
$before = $db->query('SELECT status FROM tickets WHERE id = ' . $tk[0]['id'])->fetchColumn();
$c1->req('POST', '/api/logout.php', []);
ok($c1->req('GET', '/api/ticket-status.php?code=' . $tk[0]['token'])['status'] === 401, 'Déconnexion : l\'ancienne session ne donne plus accès');
App::resetDb();
$db = App::db();
ok($db->query('SELECT status FROM tickets WHERE id = ' . $tk[0]['id'])->fetchColumn() === 'used' && $before === 'used', 'Statut conservé après déconnexion et nouvelle connexion à la base');
$c1->login("test-ctrl1-$suffix", $pw);
$r = $c1->req('POST', '/api/validate-ticket.php', ['code' => $tk[0]['token'], 'event_id' => $eventId]);
ok(($r['data']['result'] ?? '') === 'already_used', 'Nouvelle session : le billet déjà utilisé reste refusé');

section('Statistiques');
$r = $admin->req('GET', '/api/dashboard.php?event_id=' . $eventId);
$real = $db->query("SELECT COUNT(*) total, SUM(status='valid') valid, SUM(status='used') used, SUM(status='cancelled') cancelled FROM tickets WHERE event_id = $eventId")->fetch();
$s = $r['data']['stats']['tickets'] ?? [];
ok($s === ['total' => (int) $real['total'], 'valid' => (int) $real['valid'], 'used' => (int) $real['used'], 'cancelled' => (int) $real['cancelled']], 'Compteurs identiques aux données réelles de MariaDB', json_encode($s));
$logs = (int) $db->query("SELECT COUNT(*) FROM check_logs WHERE event_id = $eventId")->fetchColumn();
ok(($r['data']['stats']['checks']['total'] ?? -1) === $logs, "Nombre de contrôles journalisés exact ($logs)");
$r = $admin->req('GET', '/api/checks.php?event_id=' . $eventId . '&result=already_used&user_id=' . $ids['ctrl2']);
ok(($r['data']['total'] ?? -1) === (int) $db->query("SELECT COUNT(*) FROM check_logs WHERE event_id = $eventId AND result = 'already_used' AND user_id = {$ids['ctrl2']}")->fetchColumn(), 'Filtres de l\'historique (événement, résultat, contrôleur)');
$r = $admin->req('GET', '/api/tickets.php?q=' . $tk[0]['id']);
ok(($r['data']['rows'][0]['id'] ?? 0) === $tk[0]['id'], 'Recherche d\'un billet par son numéro');

section('Import CSV');
$csv = function (string $name, string $content) use ($admin, $eventId) {
    $tmp = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($tmp, $content);
    $r = $admin->req('POST', '/api/import-tickets.php', ['event_id' => (string) $eventId, 'file' => new CURLFile($tmp, 'text/csv', $name)], [], false);
    unlink($tmp);
    return $r;
};
$codeA = 'TESTIMP' . strtoupper($suffix) . 'A';
$codeB = 'TESTIMP' . strtoupper($suffix) . 'B';
$content = "code;nom;reference\n$codeA;Camille;R1\n$codeB;Léa;R2\n;Inès;R3\n$codeA;Doublon;R4\nabc;Trop court;R5\nTESTIMPFORMULE$suffix;=HYPERLINK(\"http://x\");R6\n{$tk[0]['token']};Déjà en base;R7\n";
$r = $csv('billets.csv', $content);
$d = $r['data'] ?? [];
ok(($d['inserted'] ?? -1) === 3 && ($d['duplicates'] ?? -1) === 2 && ($d['errors'] ?? -1) === 2 && ($d['generated'] ?? -1) === 1, 'Import : 3 billets importés (dont 1 code généré), 2 doublons (fichier et base), 2 lignes incorrectes', json_encode($d));
ok(count($d['lines'] ?? []) === 4 && ($d['lines'][0]['line'] ?? 0) === 5, 'Rapport ligne par ligne des doublons et erreurs', json_encode($d['lines'] ?? []));
$r = $csv('billets.csv', $content);
ok(($r['data']['inserted'] ?? -1) === 1 && ($r['data']['duplicates'] ?? -1) === 4, 'Réimport du même fichier : les codes existants sont des doublons', json_encode($r['data']));
ok($csv('billets.php', $content)['status'] === 422, 'Fichier .php refusé');
ok($csv('image.csv', "\x89PNG\r\n\x1a\n" . random_bytes(200))['status'] === 422, 'Fichier binaire déguisé en .csv refusé');
ok($csv('vide.csv', "code;nom\n")['status'] === 422, 'Fichier sans données refusé');
ok($csv('entete.csv', "colonne1;colonne2\na;b\n")['status'] === 422, 'En-tête non reconnu refusé');
$tmp = tempnam(sys_get_temp_dir(), 'csv');
file_put_contents($tmp, $content);
$r = $c2->req('POST', '/api/import-tickets.php', ['event_id' => (string) $eventId, 'file' => new CURLFile($tmp, 'text/csv', 'x.csv')], [], false);
unlink($tmp);
ok($r['status'] === 403, 'Import refusé à un contrôleur');

section('Export CSV');
$db->prepare("INSERT INTO tickets (token, event_id, holder_name, reference, status) VALUES (?, ?, '=1+2', '@SUM(A1)', 'valid')")->execute(['TESTFORMULA' . strtoupper($suffix), $eventId]);
$r = $admin->req('GET', '/api/export-tickets.php?event_id=' . $eventId);
ok(str_contains($r['headers'], 'text/csv') && (bool) preg_match('/;"?\'=1\+2"?;"?\'@SUM\(A1\)"?;/', $r['body']), 'Formules neutralisées à l\'export (préfixe apostrophe)');
$r = $admin->req('GET', '/api/export-checks.php?event_id=' . $eventId);
ok(str_contains($r['headers'], 'attachment') && substr_count($r['body'], "\n") > 10, 'Export de l\'historique des contrôles (CSV)');

section('Comptes');
$r = $admin->req('POST', '/api/users.php', ['action' => 'create', 'username' => "test-new-$suffix", 'display_name' => 'Nouveau', 'role' => 'controller', 'password' => 'court']);
ok($r['status'] === 422, 'Mot de passe trop faible refusé');
$r = $admin->req('POST', '/api/users.php', ['action' => 'create', 'username' => "test-new-$suffix", 'display_name' => 'Nouveau', 'role' => 'controller', 'password' => $pw]);
$newId = (int) ($r['data']['id'] ?? 0);
ok($newId > 0, 'Création d\'un compte contrôleur');
$hash = $db->query("SELECT password_hash FROM users WHERE id = $newId")->fetchColumn();
ok(str_starts_with((string) $hash, '$2y$') || str_starts_with((string) $hash, '$argon2'), 'Mot de passe stocké haché (password_hash), jamais en clair');
$n = new Client($BASE, '10.0.3.1');
ok($n->login("test-new-$suffix", $pw)['status'] === 200, 'Le nouveau compte peut se connecter');
ok($admin->req('POST', '/api/users.php', ['action' => 'deactivate', 'id' => $newId])['status'] === 200, 'Désactivation du compte');
ok($n->req('GET', '/api/ticket-status.php?code=' . $tk[0]['token'])['status'] === 401, 'Compte désactivé : sa session ouverte perd l\'accès immédiatement');
ok((new Client($BASE, '10.0.3.2'))->login("test-new-$suffix", $pw)['status'] === 401, 'Compte désactivé : connexion refusée');
ok($admin->req('POST', '/api/users.php', ['action' => 'delete', 'id' => $ids['admin']])['status'] === 409, 'Un administrateur ne peut pas supprimer son propre compte');
ok($admin->req('POST', '/api/users.php', ['action' => 'delete', 'id' => $newId])['status'] === 200, 'Suppression d\'un compte');

section('Panne : accès refusé par défaut');
// Base indisponible : on remplace la connexion par une base vide (aucune table) et on contrôle un billet.
$ref = new ReflectionProperty(App::class, 'pdo');
$ref->setValue(null, new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
$res = TicketService::validate($take()['token'], $ids['ctrl1'], $eventId, 'scan');
ok($res['result'] === 'error' && str_contains($res['message'], 'NON autorisé'), 'Erreur de base de données : résultat « erreur technique », accès non autorisé');
App::resetDb();
$db = App::db();

section('En-têtes de sécurité');
$r = $anon->req('GET', '/login.php');
foreach (['Content-Security-Policy', 'X-Frame-Options: DENY', 'X-Content-Type-Options: nosniff', 'Referrer-Policy: no-referrer', 'Permissions-Policy: camera=(self)'] as $h) {
    ok(stripos($r['headers'], $h) !== false, "En-tête $h");
}
ok(stripos($r['headers'], 'X-Powered-By') === false, 'Version de PHP non divulguée');
ok($anon->req('GET', '/.htaccess')['status'] === 403, 'Fichiers cachés interdits (/.htaccess)');

echo "\n$pass réussis, $fail échoués\n";
exit($fail ? 1 : 0);
