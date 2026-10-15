<?php
declare(strict_types=1);

/**
 * Création d'un compte administrateur en ligne de commande (premier compte, ou secours).
 *   php bin/create-admin.php <identifiant> "<Nom affiché>"
 * Le mot de passe est demandé sans être affiché (ou lu depuis la variable d'environnement DDP_ADMIN_PASSWORD).
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use DDP\{Audit, Users};

[$script, $username, $name] = $argv + [null, null, null];
$username = mb_strtolower(trim((string) $username));
if (!$username || !$name || !Users::validUsername($username)) {
    fwrite(STDERR, "Usage : php bin/create-admin.php <identifiant> \"<Nom affiché>\"\nIdentifiant : 3 à 60 caractères (minuscules, chiffres, . _ -).\n");
    exit(1);
}
$password = getenv('DDP_ADMIN_PASSWORD') ?: '';
if ($password === '') {
    fwrite(STDOUT, 'Mot de passe (10 caractères min., lettres et chiffres) : ');
    system('stty -echo 2>/dev/null');
    $password = rtrim((string) fgets(STDIN), "\r\n");
    system('stty echo 2>/dev/null');
    fwrite(STDOUT, PHP_EOL . 'Confirmation : ');
    system('stty -echo 2>/dev/null');
    $confirm = rtrim((string) fgets(STDIN), "\r\n");
    system('stty echo 2>/dev/null');
    fwrite(STDOUT, PHP_EOL);
    if ($confirm !== $password) {
        fwrite(STDERR, "Les deux mots de passe ne correspondent pas.\n");
        exit(1);
    }
}
if ($err = Users::passwordError($password)) {
    fwrite(STDERR, $err . PHP_EOL);
    exit(1);
}
try {
    $id = Users::create($username, $name, $password, 'admin');
} catch (PDOException $e) {
    fwrite(STDERR, ($e->errorInfo[1] ?? 0) === 1062 ? "Cet identifiant existe déjà.\n" : "Erreur base de données : " . $e->getMessage() . "\n");
    exit(1);
}
Audit::log('user_create', $username, null, ['role' => 'admin', 'via' => 'cli']);
echo "Administrateur « $username » créé (n° $id). Connectez-vous sur la page /login.php.\n";
