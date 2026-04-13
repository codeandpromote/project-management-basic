<?php
// ============================================================
//  HRMS · Auth & Security Functions
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_connect.php';

// ── Session bootstrap ─────────────────────────────────────────
function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // Detect HTTPS even behind reverse proxies (Hostinger Nginx, Cloudflare, etc.)
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (isset($_SERVER['HTTP_X_FORWARDED_SSL'])   && $_SERVER['HTTP_X_FORWARDED_SSL']   === 'on');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// ── CSRF helpers ──────────────────────────────────────────────
function generateCSRF(): string
{
    startSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRF(string $token): bool
{
    startSession();
    return !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(generateCSRF(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrfMeta(): string
{
    return '<meta name="csrf-token" content="'
        . htmlspecialchars(generateCSRF(), ENT_QUOTES, 'UTF-8') . '">';
}

// ── Auth guards ───────────────────────────────────────────────
function requireLogin(): void
{
    startSession();
    if (empty($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }
}

function requireRole(string ...$roles): void
{
    requireLogin();
    if (!in_array($_SESSION['user_role'] ?? '', $roles, true)) {
        http_response_code(403);
        exit('<h3>403 Forbidden</h3><p>You do not have permission to view this page.</p>');
    }
}

// ── Current user ──────────────────────────────────────────────
function getCurrentUser(): ?array
{
    startSession();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $st = getDB()->prepare(
        'SELECT id, name, email, role, office_ip_restricted
         FROM users WHERE id = ? AND is_active = 1 LIMIT 1'
    );
    $st->execute([$_SESSION['user_id']]);
    return $st->fetch() ?: null;
}

// ── Login ─────────────────────────────────────────────────────
function attemptLogin(string $email, string $password): array
{
    if (empty($email) || empty($password)) {
        return ['success' => false, 'message' => 'Email and password are required.'];
    }

    $st = getDB()->prepare(
        'SELECT id, name, email, password, role
         FROM users WHERE email = ? AND is_active = 1 LIMIT 1'
    );
    $st->execute([strtolower(trim($email))]);
    $user = $st->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    startSession();
    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];

    return ['success' => true, 'role' => $user['role']];
}

// ── Logout ────────────────────────────────────────────────────
function logout(): void
{
    startSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: index.php');
    exit;
}

// ============================================================
//  Core Security: validate_access()
//
//  Called before recording a check-in.
//  Returns: ['allowed' => bool, 'reason' => string]
//
//  Rules:
//   admin        → always allowed
//   field_worker → always allowed (no IP restriction), GPS logged
//   office_staff → IP must match OFFICE_IP
//                  AND GPS must be within GEOFENCE_RADIUS metres
// ============================================================
function validate_access(
    string $role,
    bool   $ipRestricted,
    float  $lat = 0.0,
    float  $lng = 0.0
): array {

    // Dev/test bypass
    if (DEV_MODE) {
        return ['allowed' => true, 'reason' => '[DEV MODE] Access bypassed.'];
    }

    // Admins always pass
    if ($role === 'admin') {
        return ['allowed' => true, 'reason' => 'Admin access granted.'];
    }

    // Field workers: no IP check, GPS is informational
    if ($role === 'field_worker') {
        return ['allowed' => true, 'reason' => 'Field worker: GPS coordinates logged.'];
    }

    // ── Office staff: IP check ────────────────────────────────
    if ($ipRestricted) {
        $clientIp = getClientIp();
        if ($clientIp !== OFFICE_IP) {
            return [
                'allowed' => false,
                'reason'  => "Check-in denied: unrecognised network ({$clientIp}). "
                           . 'Please connect to the office network.',
            ];
        }
    }

    // ── Geofence check ────────────────────────────────────────
    if ($lat !== 0.0 || $lng !== 0.0) {
        $dist = haversineDistance(OFFICE_LAT, OFFICE_LNG, $lat, $lng);
        if ($dist > GEOFENCE_RADIUS) {
            $d = (int) round($dist);
            return [
                'allowed' => false,
                'reason'  => "Check-in denied: you are {$d}m from the office "
                           . '(maximum allowed: ' . GEOFENCE_RADIUS . 'm).',
            ];
        }
    }

    return ['allowed' => true, 'reason' => 'Access granted.'];
}

// ── Haversine distance (metres) ───────────────────────────────
function haversineDistance(
    float $lat1, float $lon1,
    float $lat2, float $lon2
): float {
    $R    = 6371000.0; // Earth radius in metres
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $dPhi = deg2rad($lat2 - $lat1);
    $dLam = deg2rad($lon2 - $lon1);
    $a    = sin($dPhi / 2) ** 2
          + cos($phi1) * cos($phi2) * sin($dLam / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// ── IP detection (handles proxies) ───────────────────────────
function getClientIp(): string
{
    foreach (['HTTP_X_FORWARDED_FOR','HTTP_CLIENT_IP','REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            return trim(explode(',', $_SERVER[$key])[0]);
        }
    }
    return '0.0.0.0';
}

// ── Generic sanitiser ─────────────────────────────────────────
function h(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── Secure file upload helper ─────────────────────────────────
function handleFileUpload(string $inputName, string $subDir): array
{
    if (empty($_FILES[$inputName]['name'])) {
        return ['success' => false, 'path' => null, 'message' => 'No file selected.'];
    }

    $file    = $_FILES[$inputName];
    $origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($origExt, ALLOWED_EXT, true)) {
        return ['success' => false, 'path' => null,
                'message' => "File type .{$origExt} is not allowed."];
    }

    if ($file['size'] > MAX_FILE_BYTES) {
        return ['success' => false, 'path' => null,
                'message' => 'File exceeds maximum size of ' . MAX_FILE_MB . ' MB.'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'path' => null, 'message' => 'Upload error code ' . $file['error']];
    }

    $dir = UPLOAD_PATH . rtrim($subDir, '/') . '/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $safeName = bin2hex(random_bytes(16)) . '.' . $origExt;
    $dest     = $dir . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success' => false, 'path' => null, 'message' => 'Failed to save file.'];
    }

    return ['success' => true, 'path' => $subDir . '/' . $safeName, 'message' => 'Uploaded.'];
}

// ── Redirect helper ───────────────────────────────────────────
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}
