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
