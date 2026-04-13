<?php
// ============================================================
//  HRMS · Application Configuration
//  Edit the values below for your environment.
// ============================================================

// ── Timezone ──────────────────────────────────────────────────
date_default_timezone_set('Asia/Kolkata'); // IST = UTC+5:30

// ── Database ─────────────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_NAME',    'hrms_db');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');

// ── Office Network & Geofence ─────────────────────────────────
// Set OFFICE_IP to your public/LAN office IP.
// Set DEV_MODE = true to bypass IP + GPS checks during local dev.
define('DEV_MODE',        false);
define('OFFICE_IP',       '203.0.113.10');  // ← replace with real office IP
define('OFFICE_LAT',       40.712800);       // ← replace with real office latitude
define('OFFICE_LNG',      -74.006000);       // ← replace with real office longitude
define('GEOFENCE_RADIUS',  100);             // metres

// ── Application ───────────────────────────────────────────────
define('SITE_NAME',   'HRMS Portal');
define('BASE_URL',    'http://localhost/hrms_software/');
define('UPLOAD_PATH', __DIR__ . '/uploads/');
define('MAX_FILE_MB',  5);
define('MAX_FILE_BYTES', MAX_FILE_MB * 1024 * 1024);
define('ALLOWED_EXT', ['pdf','doc','docx','xls','xlsx','png','jpg','jpeg','zip','txt']);

// ── Session ───────────────────────────────────────────────────
define('SESSION_LIFETIME', 7200); // seconds (2 h)
