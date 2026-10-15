-- =============================================================================
-- Les Dames du Parc — Contrôle des billets
-- Schéma MariaDB (10.4+ / compatible MySQL 8). Encodage utf8mb4, moteur InnoDB
-- (transactions et verrous de ligne indispensables à la validation atomique).
--
-- Import :  mariadb -u UTILISATEUR -p NOM_DE_LA_BASE < database/schema.sql
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -----------------------------------------------------------------------------
-- Comptes : administrateurs et contrôleurs
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  username        VARCHAR(60)      NOT NULL,
  display_name    VARCHAR(120)     NOT NULL,
  password_hash   VARCHAR(255)     NOT NULL,
  role            ENUM('admin','controller') NOT NULL DEFAULT 'controller',
  is_active       TINYINT(1)       NOT NULL DEFAULT 1,
  created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_login_at   DATETIME         NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Événements organisés par l'association
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS events (
  id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  name            VARCHAR(160)     NOT NULL,
  starts_at       DATETIME         NOT NULL,
  location        VARCHAR(200)     NULL,
  is_active       TINYINT(1)       NOT NULL DEFAULT 1,
  created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_events_starts_at (starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Historique des imports CSV
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS imports (
  id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED     NULL,
  event_id        INT UNSIGNED     NOT NULL,
  filename        VARCHAR(255)     NOT NULL,
  total_rows      INT UNSIGNED     NOT NULL DEFAULT 0,
  inserted_rows   INT UNSIGNED     NOT NULL DEFAULT 0,
  duplicate_rows  INT UNSIGNED     NOT NULL DEFAULT 0,
  error_rows      INT UNSIGNED     NOT NULL DEFAULT 0,
  report          TEXT             NULL COMMENT 'Rapport JSON : lignes en erreur et doublons',
  created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_imports_event (event_id),
  CONSTRAINT fk_imports_user  FOREIGN KEY (user_id)  REFERENCES users (id)  ON DELETE SET NULL,
  CONSTRAINT fk_imports_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Billets : jeton aléatoire (contenu du QR code), statut et validation
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tickets (
  id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  token           VARCHAR(64)      CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Jeton unique, sensible à la casse',
  event_id        INT UNSIGNED     NOT NULL,
  holder_name     VARCHAR(160)     NULL,
  reference       VARCHAR(80)      NULL COMMENT 'Référence externe (n° de commande, de membre…)',
  status          ENUM('valid','used','cancelled') NOT NULL DEFAULT 'valid',
  created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  used_at         DATETIME         NULL,
  used_by         INT UNSIGNED     NULL COMMENT 'Contrôleur ayant validé l''entrée',
  cancelled_at    DATETIME         NULL,
  import_id       INT UNSIGNED     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tickets_token (token),
  KEY idx_tickets_event_status (event_id, status),
  KEY idx_tickets_reference (reference),
  CONSTRAINT fk_tickets_event  FOREIGN KEY (event_id)  REFERENCES events (id)  ON DELETE RESTRICT,
  CONSTRAINT fk_tickets_user   FOREIGN KEY (used_by)   REFERENCES users (id)   ON DELETE SET NULL,
  CONSTRAINT fk_tickets_import FOREIGN KEY (import_id) REFERENCES imports (id) ON DELETE SET NULL,
  CONSTRAINT chk_tickets_used CHECK (status <> 'used' OR used_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Historique de toutes les tentatives de contrôle (acceptées ou refusées)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS check_logs (
  id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  ticket_id       INT UNSIGNED     NULL,
  event_id        INT UNSIGNED     NULL COMMENT 'Événement contrôlé à l''entrée',
  user_id         INT UNSIGNED     NULL,
  result          ENUM('valid','already_used','invalid','cancelled','wrong_event','error') NOT NULL,
  method          ENUM('scan','manual') NOT NULL DEFAULT 'scan',
  code_hint       VARCHAR(16)      NULL COMMENT 'Début du code présenté (jamais le code complet d''un billet inconnu)',
  code_hash       CHAR(64)         CHARACTER SET ascii NULL COMMENT 'SHA-256 du code présenté',
  ip_address      VARCHAR(45)      NULL,
  created_at      DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_checks_created (created_at),
  KEY idx_checks_event (event_id, created_at),
  KEY idx_checks_user (user_id, created_at),
  KEY idx_checks_ticket (ticket_id),
  KEY idx_checks_result (result),
  CONSTRAINT fk_checks_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE SET NULL,
  CONSTRAINT fk_checks_event  FOREIGN KEY (event_id)  REFERENCES events (id)  ON DELETE SET NULL,
  CONSTRAINT fk_checks_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Journal des opérations administratives sensibles
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
  id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED     NULL,
  action          VARCHAR(60)      NOT NULL,
  target          VARCHAR(120)     NULL,
  details         TEXT             NULL,
  ip_address      VARCHAR(45)      NULL,
  created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_created (created_at),
  KEY idx_audit_user (user_id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Tentatives de connexion (limitation anti force brute)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
  id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  username        VARCHAR(60)      NOT NULL,
  ip_address      VARCHAR(45)      NOT NULL,
  success         TINYINT(1)       NOT NULL DEFAULT 0,
  attempted_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_user (username, attempted_at),
  KEY idx_login_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
