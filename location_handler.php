<?php
// ============================================================
//  HRMS · Field Worker Location Logger  (AJAX)
//  POST action: log_location
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json');
requireLogin();

if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Invalid CSRF token.']));
}

$user = getCurrentUser();
if (!$user) {
    exit(json_encode(['success' => false, 'message' => 'Session expired.']));
}

// Only field workers (and admins) can log locations
if (!in_array($user['role'], ['field_worker', 'admin'], true)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Location logging is for field workers only.']));
}

$db     = getDB();
$action = $_POST['action'] ?? '';

if ($action !== 'log_location') {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Invalid action.']));
}

$lat      = (float)($_POST['lat']      ?? 0);
$lng      = (float)($_POST['lng']      ?? 0);
$accuracy = (int)($_POST['accuracy']   ?? 0);
$taskId   = (int)($_POST['task_id']    ?? 0)  ?: null;
$notes    = trim($_POST['notes']       ?? '');
$noGps    = ($_POST['no_gps']          ?? '0') === '1';

// Coordinate validation — skip for deliberate no-GPS submissions
if (!$noGps) {
    if ($lat === 0.0 && $lng === 0.0) {
        exit(json_encode(['success' => false, 'message' => 'Invalid GPS coordinates. Use "Log Activity (No GPS)" if GPS is unavailable.']));
    }
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        exit(json_encode(['success' => false, 'message' => 'GPS coordinates out of range.']));
    }
} else {
    // No-GPS mode: store zero coordinates as sentinel
    $lat = 0.0;
    $lng = 0.0;
    $accuracy = 0;
}

// Use device time if provided, else server time
$clientTimeRaw = $_POST['client_time'] ?? '';
if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $clientTimeRaw)) {
    $loggedAt = $clientTimeRaw;
    $logDate  = substr($clientTimeRaw, 0, 10);
} else {
    $loggedAt = date('Y-m-d H:i:s');
    $logDate  = date('Y-m-d');
}

// Validate task_id belongs to this user (if provided)
if ($taskId) {
    $stTask = $db->prepare('SELECT id FROM tasks WHERE id = ? AND user_id = ? LIMIT 1');
    $stTask->execute([$taskId, $user['id']]);
    if (!$stTask->fetch()) {
        $taskId = null; // silently ignore invalid task reference
    }
}

try {
    // Rate limit: max 1 log per minute per user.
    // Use PHP-generated IST timestamp so the comparison stays in the same timezone
    // as logged_at (stored in IST). MySQL NOW() is UTC on most installs, which
    // would cause a 5:30-hour mismatch and block all logs after the first one.
    $oneMinuteAgo = date('Y-m-d H:i:s', time() - 60);
    $stRecent = $db->prepare(
        'SELECT id FROM location_logs
         WHERE user_id = ? AND logged_at > ?
         LIMIT 1'
    );
    $stRecent->execute([$user['id'], $oneMinuteAgo]);
    if ($stRecent->fetch()) {
        exit(json_encode([
            'success' => false,
            'message' => 'Please wait at least 1 minute between location logs.',
        ]));
    }

    $db->prepare(
        'INSERT INTO location_logs (user_id, log_date, lat, lng, accuracy, task_id, notes, logged_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $user['id'], $logDate, $lat, $lng,
        $accuracy ?: null, $taskId,
        $notes ?: null, $loggedAt,
    ]);

    // Build Google Maps link
    $mapsUrl = "https://maps.google.com/?q={$lat},{$lng}";

    // Count today's logs
    $stCount = $db->prepare(
        'SELECT COUNT(*) FROM location_logs WHERE user_id = ? AND log_date = ?'
    );
    $stCount->execute([$user['id'], $logDate]);
    $todayCount = (int)$stCount->fetchColumn();

    exit(json_encode([
        'success'     => true,
        'message'     => $noGps ? 'Activity logged (no GPS).' : 'Location logged successfully.',
        'time'        => date('h:i A', strtotime($loggedAt)),
        'lat'         => $lat,
        'lng'         => $lng,
        'accuracy'    => $accuracy,
        'no_gps'      => $noGps,
        'maps_url'    => $noGps ? '' : $mapsUrl,
        'today_count' => $todayCount,
    ]));
} catch (PDOException $e) {
    http_response_code(500);
    exit(json_encode([
        'success' => false,
        'message' => 'Database error: location_logs table may be missing. Ask your admin to run schema_update.sql.',
    ]));
}
