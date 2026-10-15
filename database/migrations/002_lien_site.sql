-- Migration 002 : lien avec lesdamesduparc.com (billets du site, présences), contrôle depuis la liste.
-- À appliquer une fois sur une base créée avec une version précédente de schema.sql :
--   mariadb -u ddp_controle -p ddp_controle < database/migrations/002_lien_site.sql
-- (une base créée avec le schema.sql actuel contient déjà ces colonnes.)

ALTER TABLE events
  ADD COLUMN address VARCHAR(255) NULL AFTER location,
  ADD COLUMN external_id VARCHAR(80) NULL AFTER address,
  ADD UNIQUE KEY uq_events_external (external_id);

ALTER TABLE tickets
  ADD COLUMN places SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER reference,
  ADD COLUMN birth_date DATE NULL AFTER places,
  ADD COLUMN member_hash CHAR(64) CHARACTER SET ascii NULL AFTER birth_date,
  ADD COLUMN external_id VARCHAR(64) NULL AFTER member_hash,
  ADD COLUMN cancel_reason VARCHAR(40) NULL AFTER status,
  ADD COLUMN site_pending TINYINT(1) NOT NULL DEFAULT 0 AFTER import_id,
  ADD COLUMN site_error VARCHAR(255) NULL AFTER site_pending,
  ADD KEY idx_tickets_member (member_hash),
  ADD KEY idx_tickets_external (external_id),
  ADD KEY idx_tickets_pending (site_pending);

ALTER TABLE check_logs MODIFY method ENUM('scan','manual','list') NOT NULL DEFAULT 'scan';

CREATE TABLE IF NOT EXISTS app_state (
  k               VARCHAR(60)      NOT NULL,
  v               TEXT             NULL,
  updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
