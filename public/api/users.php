<?php
declare(strict_types=1);

use DDP\{App, Audit, Auth, Http, Users};

require dirname(__DIR__, 2) . '/src/api.php';

/**
 * Comptes (administrateurs).
 * GET : liste. POST { action: create|update|password|activate|deactivate|delete, … }.
 * Garde-fous : un administrateur ne peut ni se désactiver, ni se supprimer, ni retirer son propre rôle ; il reste toujours au moins
 * un administrateur actif.
 */
$me = Auth::requireApi('admin');
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    Http::json(['ok' => true, 'users' => Users::all(), 'me' => $me['id']]);
}
Http::requireMethod('POST');
Auth::requireCsrf();
$in = Http::body();
$action = Http::str($in, 'action', 20);
$db = App::db();

if ($action === 'create') {
    $username = mb_strtolower(Http::str($in, 'username', 60));
    $name = Http::str($in, 'display_name', 120);
    $role = Http::str($in, 'role', 20);
    $password = is_string($in['password'] ?? null) ? $in['password'] : '';
    if (!Users::validUsername($username)) {
        Http::fail('Identifiant : 3 à 60 caractères (minuscules, chiffres, point, tiret).', 422);
    }
    if ($name === '' || !isset(Users::ROLES[$role])) {
        Http::fail('Nom et rôle obligatoires.', 422);
    }
    if ($err = Users::passwordError($password)) {
        Http::fail($err, 422);
    }
    try {
        $id = Users::create($username, $name, $password, $role);
    } catch (\PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) {
            Http::fail('Cet identifiant existe déjà.', 409);
        }
        throw $e;
    }
    Audit::log('user_create', $username, null, ['role' => $role]);
    Http::json(['ok' => true, 'id' => $id, 'message' => 'Compte créé.']);
}

$id = Http::int($in, 'id');
$target = $id ? Users::find($id) : null;
if (!$target) {
    Http::fail('Compte introuvable.', 404);
}
$self = $target['id'] == $me['id'];
$lastAdmin = $target['role'] === 'admin' && (int) $target['is_active'] === 1 && Users::activeAdminCount() <= 1;

switch ($action) {
    case 'update':
        $name = Http::str($in, 'display_name', 120);
        $role = Http::str($in, 'role', 20);
        if ($name === '' || !isset(Users::ROLES[$role])) {
            Http::fail('Nom et rôle obligatoires.', 422);
        }
        if (($self || $lastAdmin) && $role !== 'admin') {
            Http::fail('Impossible de retirer le rôle administrateur à ce compte (vous-même ou dernier administrateur).', 409);
        }
        $db->prepare('UPDATE users SET display_name = ?, role = ? WHERE id = ?')->execute([$name, $role, $id]);
        Audit::log('user_update', $target['username'], null, ['role' => $role]);
        Http::json(['ok' => true, 'message' => 'Compte mis à jour.']);
    case 'password':
        $password = is_string($in['password'] ?? null) ? $in['password'] : '';
        if ($err = Users::passwordError($password)) {
            Http::fail($err, 422);
        }
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        Audit::log('user_password', $target['username']);
        Http::json(['ok' => true, 'message' => 'Mot de passe modifié.']);
    case 'activate':
    case 'deactivate':
        if ($action === 'deactivate' && ($self || $lastAdmin)) {
            Http::fail('Impossible de désactiver ce compte (vous-même ou dernier administrateur).', 409);
        }
        $db->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$action === 'activate' ? 1 : 0, $id]);
        Audit::log('user_' . $action, $target['username']);
        Http::json(['ok' => true, 'message' => $action === 'activate' ? 'Compte réactivé.' : 'Compte désactivé : il ne peut plus se connecter.']);
    case 'delete':
        if ($self || $lastAdmin) {
            Http::fail('Impossible de supprimer ce compte (vous-même ou dernier administrateur).', 409);
        }
        // Les contrôles et validations passés restent dans l'historique (contrôleur affiché vide).
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        Audit::log('user_delete', $target['username']);
        Http::json(['ok' => true, 'message' => 'Compte supprimé.']);
}
Http::fail('Action inconnue.', 422);
