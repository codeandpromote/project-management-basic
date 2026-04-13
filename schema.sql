-- ============================================================
--  HRMS Database Schema
--  Engine: InnoDB | Charset: utf8mb4 | Collation: unicode_ci
--
--  phpMyAdmin: Create a database named "hrms_db" first,
--  select it, then import this file.
--
--  CLI:  mysql -u root -p hrms_db < schema.sql
-- ============================================================

-- ─────────────────────────────────────────────────────────────
--  users
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `users` (
  `id`                   INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `name`                 VARCHAR(100)     NOT NULL,
  `email`                VARCHAR(150)     NOT NULL,
  `password`             VARCHAR(255)     NOT NULL,
  `role`                 ENUM('admin','office_staff','field_worker') NOT NULL DEFAULT 'office_staff',
  `office_ip_restricted` TINYINT(1)       NOT NULL DEFAULT 1
                           COMMENT '1 = enforce IP check at check-in',
  `is_active`            TINYINT(1)       NOT NULL DEFAULT 1,
  `created_at`           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
                           ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
--  attendance
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `attendance` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED NOT NULL,
  `work_date`      DATE         NOT NULL,
  `check_in_time`  DATETIME     DEFAULT NULL,
  `check_out_time` DATETIME     DEFAULT NULL,
  `ip_address`     VARCHAR(45)  DEFAULT NULL,
  `lat_long`       VARCHAR(100) DEFAULT NULL,
  `status`         ENUM('present','absent','on_leave','half_day') NOT NULL DEFAULT 'present',
  `day_end_notes`  TEXT         DEFAULT NULL,
  `day_end_file`   VARCHAR(255) DEFAULT NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_date` (`user_id`, `work_date`),
  CONSTRAINT `fk_att_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
--  tasks
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `tasks` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `creator_id`       INT UNSIGNED NOT NULL,
  `user_id`          INT UNSIGNED NOT NULL,
  `title`            VARCHAR(200) NOT NULL,
  `description`      TEXT         DEFAULT NULL,
  `file_path`        VARCHAR(255) DEFAULT NULL,
  `status`           ENUM('pending','in_progress','completed','overdue') NOT NULL DEFAULT 'pending',
  `task_type`        ENUM('daily','weekly','monthly')                    NOT NULL DEFAULT 'daily',
  `deadline`         DATETIME     DEFAULT NULL,
  `proof_file`       VARCHAR(255) DEFAULT NULL,
  `completion_notes` TEXT         DEFAULT NULL,
  `completed_at`     DATETIME     DEFAULT NULL,
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                       ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tasks_user`     (`user_id`),
  KEY `idx_tasks_deadline` (`deadline`),
  CONSTRAINT `fk_task_creator` FOREIGN KEY (`creator_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_task_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
--  leave_requests
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `leave_requests` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `start_date`   DATE         NOT NULL,
  `end_date`     DATE         NOT NULL,
  `type`         ENUM('annual','sick','personal','maternity','paternity','emergency','unpaid') NOT NULL,
  `reason`       TEXT         NOT NULL,
  `status`       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_remark` TEXT         DEFAULT NULL,
  `reviewed_by`  INT UNSIGNED DEFAULT NULL,
  `reviewed_at`  DATETIME     DEFAULT NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_leave_user`   (`user_id`),
  KEY `idx_leave_status` (`status`),
  CONSTRAINT `fk_leave_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_leave_reviewer` FOREIGN KEY (`reviewed_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
--  Seed Data  (default password for all seeds: "password")
--  Hash = password_hash('password', PASSWORD_BCRYPT, ['cost'=>10])
-- ─────────────────────────────────────────────────────────────
INSERT INTO `users` (`name`, `email`, `password`, `role`, `office_ip_restricted`) VALUES
('System Admin',  'admin@hrms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin',        0),
('Jane Office',   'jane@hrms.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'office_staff',  1),
('John Field',    'john@hrms.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'field_worker',  0);
