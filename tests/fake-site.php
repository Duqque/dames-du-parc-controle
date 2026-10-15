<?php
declare(strict_types=1);

/**
 * Faux site lesdamesduparc.com pour les tests (serveur intégré de PHP) : imite GET /api/scanner/tickets et
 * POST /api/scanner/checkin, avec un état modifiable par les tests (/__state) et une panne simulée (/__down).
 *   FAKE_SITE_STATE=/tmp/etat.json FAKE_SITE_KEY=… php -S 127.0.0.1:8092 tests/fake-site.php
 */
$stateFile = getenv('FAKE_SITE_STATE') ?: sys_get_temp_dir() . '/ddp-fake-site.json';
$key = getenv('FAKE_SITE_KEY') ?: '';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : ['events' => [], 'tickets' => [], 'revoked' => [], 'down' => false, 'checkins' => []];
$save = static function () use (&$state, $stateFile): void {
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
};
header('Content-Type: application/json');

if ($path === '/__state') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $state = json_decode((string) file_get_contents('php://input'), true) + ['checkins' => [], 'down' => false];
        $save();
    }
    echo json_encode($state);
    return;
}
if ($state['down'] ?? false) {
    http_response_code(503);
    echo json_encode(['error' => 'en panne']);
    return;
}
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== "Bearer $key") {
    http_response_code(401);
    echo json_encode(['error' => 'Clé d\'accès invalide.']);
    return;
}
if ($path === '/api/scanner/tickets') {
    echo json_encode(['generatedAt' => gmdate('c'), 'events' => $state['events'], 'tickets' => $state['tickets'], 'revoked' => $state['revoked']]);
    return;
}
if ($path === '/api/scanner/checkin') {
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $code = preg_replace('/\D/', '', (string) ($in['code'] ?? ''));
    foreach ($state['revoked'] as $r) {
        if ($r['code'] === $code) {
            echo json_encode(['result' => 'revoked', 'reason' => $r['reason']]);
            return;
        }
    }
    foreach ($state['tickets'] as &$t) {
        if ($t['code'] === $code) {
            if ($t['status'] !== 'valid') {
                echo json_encode(['result' => 'cancelled']);
                return;
            }
            if ($t['attended']) {
                echo json_encode(['result' => 'already', 'attendedAt' => $t['attendedAt']]);
                return;
            }
            $t['attended'] = true;
            $t['attendedAt'] = $in['scannedAt'] ?? gmdate('c');
            $state['checkins'][] = ['code' => $code, 'controller' => $in['controller'] ?? null, 'scannedAt' => $t['attendedAt']];
            $save();
            echo json_encode(['result' => 'present', 'attendedAt' => $t['attendedAt']]);
            return;
        }
    }
    echo json_encode(['result' => 'unknown']);
    return;
}
http_response_code(404);
echo json_encode(['error' => 'introuvable']);
