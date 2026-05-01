<?php
// ============================================================
//  HRMS · Database Connection  (PDO singleton)
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function getDB(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        DB_HOST, DB_NAME, DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_FOUND_ROWS   => true,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        error_log('[HRMS DB] ' . $e->getMessage());
        http_response_code(500);
        // Return JSON for AJAX callers, plain text otherwise
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            exit(json_encode(['success' => false, 'message' => 'Database unavailable.']));
        }
        exit('<h3>Service Unavailable</h3><p>Could not connect to the database. Please contact your administrator.</p>');
    }

    // Align MySQL session timezone with PHP's Asia/Kolkata so NOW(),
    // CURRENT_TIMESTAMP and PHP's date() all agree. Fails silently on
    // shared hosts that disallow SET time_zone — PHP-side date() still works.
    try {
        $pdo->exec("SET time_zone = '+05:30'");
    } catch (PDOException $ignored) { /* keep default */ }

    // ── Auto-migrate: create location_logs and id columns if missing ──
    // Two attempts: with foreign keys first, then without (for shared hosting).
    $tableCreated = false;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `location_logs` (
              `id`        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
              `user_id`   INT UNSIGNED  NOT NULL,
              `log_date`  DATE          NOT NULL,
              `lat`       DECIMAL(10,8) NOT NULL,
              `lng`       DECIMAL(11,8) NOT NULL,
              `accuracy`  SMALLINT UNSIGNED DEFAULT NULL,
              `task_id`   INT UNSIGNED  DEFAULT NULL,
              `notes`     VARCHAR(255)  DEFAULT NULL,
              `logged_at` DATETIME      NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_loc_user_date` (`user_id`, `log_date`),
              CONSTRAINT `fk_loc_user` FOREIGN KEY (`user_id`)
                REFERENCES `users`(`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_loc_task` FOREIGN KEY (`task_id`)
                REFERENCES `tasks`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $tableCreated = true;
    } catch (PDOException $e1) {
        // Foreign key constraints may fail on some shared hosts — try without them
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `location_logs` (
                  `id`        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                  `user_id`   INT UNSIGNED  NOT NULL,
                  `log_date`  DATE          NOT NULL,
                  `lat`       DECIMAL(10,8) NOT NULL,
                  `lng`       DECIMAL(11,8) NOT NULL,
                  `accuracy`  SMALLINT UNSIGNED DEFAULT NULL,
                  `task_id`   INT UNSIGNED  DEFAULT NULL,
                  `notes`     VARCHAR(255)  DEFAULT NULL,
                  `logged_at` DATETIME      NOT NULL,
                  PRIMARY KEY (`id`),
                  KEY `idx_loc_user_date` (`user_id`, `log_date`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $tableCreated = true;
        } catch (PDOException $ignored) {
            // Still failed — app continues; pages handle missing table gracefully.
        }
    }

    if ($tableCreated) {
        // Add id_front / id_back columns if they don't exist yet
        foreach (['id_front', 'id_back'] as $col) {
            try {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `{$col}` VARCHAR(255) DEFAULT NULL");
            } catch (PDOException $ignored) {
                // Column already exists — ignore
            }
        }
        // Add lead_id column to location_logs so workers can tag GPS logs to a lead
        try {
            $pdo->exec("ALTER TABLE `location_logs` ADD COLUMN `lead_id` INT UNSIGNED DEFAULT NULL");
        } catch (PDOException $ignored) { /* already exists */ }
        // Add lead_id column to tasks so a daily task can be linked back to a lead
        try {
            $pdo->exec("ALTER TABLE `tasks` ADD COLUMN `lead_id` INT UNSIGNED DEFAULT NULL");
        } catch (PDOException $ignored) { /* already exists */ }
        // Add photo column to location_logs for mandatory photo-on-visit
        try {
            $pdo->exec("ALTER TABLE `location_logs` ADD COLUMN `photo` VARCHAR(255) DEFAULT NULL");
        } catch (PDOException $ignored) { /* already exists */ }
        // Add call_recording column to tasks (optional audio proof on completion)
        try {
            $pdo->exec("ALTER TABLE `tasks` ADD COLUMN `call_recording` VARCHAR(255) DEFAULT NULL");
        } catch (PDOException $ignored) { /* already exists */ }
    }

    // ── Lead Management tables ────────────────────────────────
    // Four tables: leads, lead_activities, lead_attachments, lead_products.
    // Two-attempt pattern (with FK → without FK) matches shared-host reality.
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `lead_products` (
              `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `name`       VARCHAR(150) NOT NULL,
              `description` TEXT DEFAULT NULL,
              `base_price` DECIMAL(12,2) DEFAULT 0,
              `is_active`  TINYINT(1) DEFAULT 1,
              `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_prod_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $ignored) {}

    $leadsCreated = false;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `leads` (
              `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `name`              VARCHAR(150) NOT NULL,
              `phone`             VARCHAR(30)  NOT NULL,
              `email`             VARCHAR(150) DEFAULT NULL,
              `company`           VARCHAR(150) DEFAULT NULL,
              `designation`       VARCHAR(100) DEFAULT NULL,
              `address`           TEXT         DEFAULT NULL,
              `pincode`           VARCHAR(10)  DEFAULT NULL,
              `source`            ENUM('walk_in','phone','referral','website','social','cold_call','exhibition','other') DEFAULT 'other',
              `interest`          VARCHAR(255) DEFAULT NULL,
              `product_id`        INT UNSIGNED DEFAULT NULL,
              `est_value`         DECIMAL(12,2) DEFAULT 0,
              `priority`          ENUM('low','medium','high','hot') DEFAULT 'medium',
              `status`            ENUM('new','contacted','qualified','meeting','negotiation','won','lost') DEFAULT 'new',
              `assigned_to`       INT UNSIGNED DEFAULT NULL,
              `creator_id`        INT UNSIGNED NOT NULL,
              `notes`             TEXT         DEFAULT NULL,
              `tags`              VARCHAR(255) DEFAULT NULL,
              `next_followup_date` DATE        DEFAULT NULL,
              `last_activity_at`  DATETIME     DEFAULT NULL,
              `won_at`            DATETIME     DEFAULT NULL,
              `lost_reason`       VARCHAR(255) DEFAULT NULL,
              `lost_at`           DATETIME     DEFAULT NULL,
              `is_deleted`        TINYINT(1) DEFAULT 0,
              `created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
              `updated_at`        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_lead_assigned`   (`assigned_to`, `status`),
              KEY `idx_lead_followup`   (`next_followup_date`, `is_deleted`),
              KEY `idx_lead_phone`      (`phone`),
              KEY `idx_lead_created`    (`created_at`),
              CONSTRAINT `fk_lead_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL,
              CONSTRAINT `fk_lead_creator`  FOREIGN KEY (`creator_id`)  REFERENCES `users`(`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_lead_product`  FOREIGN KEY (`product_id`)  REFERENCES `lead_products`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $leadsCreated = true;
    } catch (PDOException $e) {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `leads` (
                  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `name`              VARCHAR(150) NOT NULL,
                  `phone`             VARCHAR(30)  NOT NULL,
                  `email`             VARCHAR(150) DEFAULT NULL,
                  `company`           VARCHAR(150) DEFAULT NULL,
                  `designation`       VARCHAR(100) DEFAULT NULL,
                  `address`           TEXT         DEFAULT NULL,
                  `pincode`           VARCHAR(10)  DEFAULT NULL,
                  `source`            ENUM('walk_in','phone','referral','website','social','cold_call','exhibition','other') DEFAULT 'other',
                  `interest`          VARCHAR(255) DEFAULT NULL,
                  `product_id`        INT UNSIGNED DEFAULT NULL,
                  `est_value`         DECIMAL(12,2) DEFAULT 0,
                  `priority`          ENUM('low','medium','high','hot') DEFAULT 'medium',
                  `status`            ENUM('new','contacted','qualified','meeting','negotiation','won','lost') DEFAULT 'new',
                  `assigned_to`       INT UNSIGNED DEFAULT NULL,
                  `creator_id`        INT UNSIGNED NOT NULL,
                  `notes`             TEXT         DEFAULT NULL,
                  `tags`              VARCHAR(255) DEFAULT NULL,
                  `next_followup_date` DATE        DEFAULT NULL,
                  `last_activity_at`  DATETIME     DEFAULT NULL,
                  `won_at`            DATETIME     DEFAULT NULL,
                  `lost_reason`       VARCHAR(255) DEFAULT NULL,
                  `lost_at`           DATETIME     DEFAULT NULL,
                  `is_deleted`        TINYINT(1) DEFAULT 0,
                  `created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
                  `updated_at`        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_lead_assigned`   (`assigned_to`, `status`),
                  KEY `idx_lead_followup`   (`next_followup_date`, `is_deleted`),
                  KEY `idx_lead_phone`      (`phone`),
                  KEY `idx_lead_created`    (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $leadsCreated = true;
        } catch (PDOException $ignored) {}
    }

    // ── Device-binding & single-session columns on users ─────
    // device_id        — fingerprint of the device a field worker is bound to
    // device_bound_at  — when the binding was created
    // session_token    — current active session token; rotated on every login,
    //                    enforces single-device active session for all roles
    // last_login_at    — bookkeeping
    // last_login_ip    — IP of the most recent successful login
    // last_user_agent  — browser/OS string of the most recent login
    foreach ([
        ['device_id',       'VARCHAR(64)  DEFAULT NULL'],
        ['device_bound_at', 'DATETIME     DEFAULT NULL'],
        ['session_token',   'VARCHAR(64)  DEFAULT NULL'],
        ['last_login_at',   'DATETIME     DEFAULT NULL'],
        ['last_login_ip',   'VARCHAR(45)  DEFAULT NULL'],
        ['last_user_agent', 'VARCHAR(255) DEFAULT NULL'],
    ] as [$col, $type]) {
        try {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `{$col}` {$type}");
        } catch (PDOException $ignored) { /* already exists */ }
    }

    // ── System event log (admin monitoring) ──────────────────
    // Logs login/logout, device bind/reject/reset, session-replace, etc.
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `system_log` (
              `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
              `user_id`     INT UNSIGNED  DEFAULT NULL,
              `actor_id`    INT UNSIGNED  DEFAULT NULL,
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $ignored) { /* already exists or denied */ }

    if ($leadsCreated) {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `lead_activities` (
                  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `lead_id`           INT UNSIGNED NOT NULL,
                  `user_id`           INT UNSIGNED NOT NULL,
                  `activity_type`     ENUM('call','visit','meeting','message','note','status_change','reassigned') DEFAULT 'note',
                  `outcome`           ENUM('connected','not_connected','interested','not_interested','pending','converted','rejected') DEFAULT 'pending',
                  `notes`             TEXT DEFAULT NULL,
                  `next_followup_date` DATE DEFAULT NULL,
                  `next_followup_time` TIME DEFAULT NULL,
                  `old_status`        VARCHAR(20) DEFAULT NULL,
                  `new_status`        VARCHAR(20) DEFAULT NULL,
                  `activity_at`       DATETIME DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_act_lead`   (`lead_id`, `activity_at`),
                  KEY `idx_act_user`   (`user_id`, `activity_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (PDOException $ignored) {}

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `lead_attachments` (
                  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `lead_id`     INT UNSIGNED NOT NULL,
                  `user_id`     INT UNSIGNED NOT NULL,
                  `file_path`   VARCHAR(255) NOT NULL,
                  `file_label`  VARCHAR(100) DEFAULT NULL,
                  `uploaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_att_lead` (`lead_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (PDOException $ignored) {}
    }

    return $pdo;
}
