<?php
/**
 * Configuration d'exemple — à copier en config/config.php puis à compléter.
 *
 *   cp config/config.example.php config/config.php
 *
 * config/config.php contient des secrets : il ne doit jamais être versionné ni placé dans un dossier accessible depuis le web
 * (le dossier config/ est protégé par un .htaccess « Require all denied » et se trouve hors de public/).
 */
return [
    // Base de données MariaDB
    'db' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'ddp_controle',
        'user'     => 'ddp_controle',
        'password' => 'CHANGER_MOI',
        'charset'  => 'utf8mb4',
    ],

    // Adresse publique de l'application (HTTPS obligatoire en production), sans « / » final.
    'app_url' => 'https://controle.lesdamesduparc.com',

    // Fuseau horaire d'affichage (les dates sont stockées en UTC).
    'timezone' => 'Europe/Paris',

    // Secret servant à signer le contenu des QR codes (HMAC-SHA256). 64 caractères aléatoires :
    //   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
    // Le changer invalide la signature des QR codes déjà distribués (la saisie manuelle du code reste possible).
    'qr_secret' => 'REMPLACER_PAR_64_CARACTERES_HEXADECIMAUX_ALEATOIRES',

    // Lien avec le site lesdamesduparc.com : les billets vendus sur le site sont chargés dans l'application, et chaque entrée validée
    // est enregistrée comme « présente » sur le site. La clé doit être identique à SCANNER_API_KEY dans la configuration du site
    // (64 caractères aléatoires : openssl rand -hex 32). Laisser 'url' vide pour utiliser l'application sans le site.
    'site' => [
        'url'          => 'https://lesdamesduparc.com',
        'api_key'      => 'REMPLACER_PAR_LA_CLE_SCANNER_API_KEY_DU_SITE',
        'sync_seconds' => 30,   // délai minimal entre deux chargements des billets du site
        'timeout'      => 6,    // secondes
    ],

    // Session
    'session' => [
        'name'            => 'DDPCTRL',
        'secure_cookie'   => true,   // true en production (HTTPS). false uniquement pour un test local en http://localhost
        'idle_timeout'    => 4 * 3600,   // déconnexion après 4 h d'inactivité
        'absolute_timeout'=> 14 * 3600,  // et au plus tard 14 h après la connexion (une soirée de contrôle)
    ],

    // Limitation des tentatives de connexion (fenêtre glissante)
    'login_limit' => [
        'window_minutes'  => 15,
        'max_per_user'    => 5,
        'max_per_ip'      => 20,
    ],

    // Import CSV
    'import' => [
        'max_bytes' => 5 * 1024 * 1024,
        'max_rows'  => 20000,
    ],

    // Installation par le navigateur (public/install.php) : renseigner un jeton long et aléatoire UNIQUEMENT le temps de créer
    // le premier compte administrateur, puis le remettre à '' (la page est de toute façon bloquée dès qu'un administrateur existe).
    'install_token' => '',

    // Adresses IP des mandataires (proxy, répartiteur de charge) dont l'en-tête X-Forwarded-For est digne de confiance.
    'trusted_proxies' => [],

    // Mode développement : messages d'erreur détaillés. Toujours false en production.
    'debug' => false,
];
