# Création du premier compte administrateur

Il n'existe aucun compte à l'installation. Trois méthodes, au choix.

## Méthode 1 — En ligne de commande (recommandée, accès SSH)

```bash
cd /chemin/vers/controle-ddp
php bin/create-admin.php presidente "Présidente de l'association"
```

Le mot de passe est demandé deux fois, sans s'afficher. Règles : 10 caractères minimum, au moins une lettre et un chiffre.
La même commande permet de recréer un administrateur de secours si tous les comptes administrateurs sont perdus.

## Méthode 2 — Par le navigateur (hébergement sans SSH)

1. Dans `config/config.php`, renseignez un jeton long et aléatoire :
   `'install_token' => 'a8f3…(au moins 24 caractères)',`
2. Ouvrez `https://controle.lesdamesduparc.com/install.php?token=a8f3…`
3. Remplissez le formulaire : le compte administrateur est créé.
4. **Remettez immédiatement `'install_token' => ''`** dans la configuration.

La page répond « introuvable » sans le bon jeton, et se bloque d'elle-même dès qu'un administrateur existe.

## Méthode 3 — Directement en SQL (dernier recours)

```bash
php -r 'echo password_hash("Mot-De-Passe-Solide-2026", PASSWORD_DEFAULT), PHP_EOL;'
```

```sql
INSERT INTO users (username, display_name, password_hash, role, is_active)
VALUES ('presidente', 'Présidente', '$2y$12$…(empreinte générée ci-dessus)', 'admin', 1);
```

## Ensuite

Connectez-vous sur `/login.php`, puis, dans **Comptes**, créez un compte **contrôleur** nominatif par personne chargée de
l'accueil : chaque entrée validée est ainsi rattachée à la personne qui l'a contrôlée. Après l'événement, désactivez les
comptes temporaires (ils ne peuvent plus se connecter, leur historique reste consultable).
