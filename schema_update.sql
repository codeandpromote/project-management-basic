-- ============================================================
--  HRMS Schema Update — Run AFTER schema.sql
--  Safe to run multiple times (uses IF NOT EXISTS).
--
--  What this adds:
--    1. id_front / id_back columns on the users table
--    2. location_logs table for field-worker GPS tracking
--
--  How to run:
--    phpMyAdmin → select hrms_db → Import tab → upload this file → Go
-- ============================================================

-- ── 1. ID document columns on users (safe to re-run) ─────────
-- MySQL 8.0+ supports ADD COLUMN IF NOT EXISTS.
-- If you are on MySQL 5.7, run only once.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `id_front` VARCHAR(255) DEFAULT NULL
    COMMENT 'Path to ID Front image' AFTER `is_active`,
  ADD COLUMN IF NOT EXISTS `id_back`  VARCHAR(255) DEFAULT NULL
    COMMENT 'Path to ID Back image'  AFTER `id_front`;

-- ── 2. Field-worker location logs ─────────────────────────────
CREATE TABLE IF NOT EXISTS `location_logs` (
  `id`        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`   INT UNSIGNED  NOT NULL,
  `log_date`  DATE          NOT NULL,
  `lat`       DECIMAL(10,8) NOT NULL,
  `lng`       DECIMAL(11,8) NOT NULL,
  `accuracy`  SMALLINT UNSIGNED DEFAULT NULL COMMENT 'GPS accuracy in metres',
  `task_id`   INT UNSIGNED  DEFAULT NULL,
  `notes`     VARCHAR(255)  DEFAULT NULL,
  `logged_at` DATETIME      NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_loc_user_date` (`user_id`, `log_date`),
  CONSTRAINT `fk_loc_user` FOREIGN KEY (`user_id`)
    REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_loc_task` FOREIGN KEY (`task_id`)
    REFERENCES `tasks`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Device binding & single-session columns on users ──────
-- device_id        : fingerprint of the device a field worker is bound to
-- device_bound_at  : when that binding was created
-- session_token    : current active session token; rotated on every login
--                    to enforce single-device active session for ALL roles
-- last_login_at    : bookkeeping
-- (Auto-migrated by db_connect.php on first request; this file is for
--  manual phpMyAdmin imports.)
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `device_id`       VARCHAR(64)  DEFAULT NULL
    COMMENT 'Bound device fingerprint (field workers)',
  ADD COLUMN IF NOT EXISTS `device_bound_at` DATETIME     DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `session_token`   VARCHAR(64)  DEFAULT NULL
    COMMENT 'Active session token; rotates on each login',
  ADD COLUMN IF NOT EXISTS `last_login_at`   DATETIME     DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `last_login_ip`   VARCHAR(45)  DEFAULT NULL
    COMMENT 'IP of the most recent successful login',
  ADD COLUMN IF NOT EXISTS `last_user_agent` VARCHAR(255) DEFAULT NULL;

-- ── 4. System event log (admin monitoring) ───────────────────
-- Stores login/logout, device bind/reject/reset, session-replace
-- and admin actions. Read by admin_system_monitor.php.
CREATE TABLE IF NOT EXISTS `system_log` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED  DEFAULT NULL COMMENT 'Subject of the event',
  `actor_id`    INT UNSIGNED  DEFAULT NULL COMMENT 'Admin who performed the action',
  `event_type`  VARCHAR(40)   NOT NULL,
  `details`     VARCHAR(500)  DEFAULT NULL,
  `ip_address`  VARCHAR(45)   DEFAULT NULL,
  `device_id`   VARCHAR(64)   DEFAULT NULL,
  `user_agent`  VARCHAR(255)  DEFAULT NULL,
  `created_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_user`  (`user_id`, `created_at`),
  KEY `idx_log_event` (`event_type`, `created_at`),
  KEY `idx_log_time`  (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
