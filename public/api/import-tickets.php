<?php
declare(strict_types=1);

use DDP\{App, Audit, Auth, Csv, Http};

require dirname(__DIR__, 2) . '/src/api.php';

/** Import d'une liste de billets (CSV) pour un événement : doublons et lignes incorrectes signalés. */
Http::requireMethod('POST');
$user = Auth::requireApi('admin');
Auth::requireCsrf();
$eventId = Http::int($_POST, 'event_id');
$st = App::db()->prepare('SELECT id FROM events WHERE id = ?');
$st->execute([$eventId ?? 0]);
if (!$eventId || !$st->fetch()) {
    Http::fail('Choisissez un événement.', 422);
}
$f = $_FILES['file'] ?? null;
if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    Http::fail($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ? 'Fichier trop volumineux.' : 'Aucun fichier reçu.', 422);
}
$report = Csv::import((string) $f['tmp_name'], (string) $f['name'], $eventId, $user['id']);
@unlink((string) $f['tmp_name']);
if (!$report['ok']) {
    Http::fail($report['error'], 422);
}
Audit::log('import_tickets', basename((string) $f['name']), null, ['event_id' => $eventId, 'inserted' => $report['inserted'], 'duplicates' => $report['duplicates'], 'errors' => $report['errors']]);
Http::json($report);
