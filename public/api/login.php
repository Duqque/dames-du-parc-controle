<?php
declare(strict_types=1);

use DDP\{Auth, Http};

require dirname(__DIR__, 2) . '/src/api.php';

Http::requireMethod('POST');
Auth::requireCsrf();
$in = Http::body();
$username = Http::str($in, 'username', 60);
$password = is_string($in['password'] ?? null) ? mb_substr($in['password'], 0, 200) : '';
if ($username === '' || $password === '') {
    Http::fail('Saisissez votre identifiant et votre mot de passe.', 422);
}
[$ok, $message, $user, $status] = Auth::attempt($username, $password);
if (!$ok) {
    Http::fail($message, $status);
}
Http::json([
    'ok' => true,
    'user' => ['name' => $user['display_name'], 'role' => $user['role']],
    'csrf' => Auth::csrfToken(),
    'redirect' => $user['role'] === 'admin' ? '/admin/' : '/scan.php',
]);
