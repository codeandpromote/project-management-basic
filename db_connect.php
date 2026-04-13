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
    }

    return $pdo;
}
