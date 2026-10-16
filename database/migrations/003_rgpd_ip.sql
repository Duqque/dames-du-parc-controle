-- RGPD : plus aucune adresse IP conservée en clair (Les Dames du Parc, contrôle des billets).
-- Depuis cette version, l'application n'enregistre qu'une empreinte chiffrée de l'adresse (« ip~… »).
-- Ce script masque les adresses déjà enregistrées. À importer une seule fois (phpMyAdmin > Importer, ou mariadb … < ce fichier).

UPDATE check_logs SET ip_address = NULL WHERE ip_address IS NOT NULL AND ip_address NOT LIKE 'ip~%' AND ip_address <> 'cli';
UPDATE audit_logs SET ip_address = NULL WHERE ip_address IS NOT NULL AND ip_address NOT LIKE 'ip~%' AND ip_address <> 'cli';
UPDATE audit_logs SET details = JSON_REMOVE(details, '$.ip') WHERE details IS NOT NULL AND JSON_VALID(details) AND JSON_EXISTS(details, '$.ip');
-- Tentatives de connexion : simples compteurs de sécurité de courte durée, effacés.
DELETE FROM login_attempts WHERE ip_address NOT LIKE 'ip~%';
