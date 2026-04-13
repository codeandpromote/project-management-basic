<?php
// ============================================================
//  HRMS · Attendance AJAX Handler
//  Accepted POST actions: check_in | check_out | day_end
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json');
requireLogin();

// CSRF guard (token sent via fetch as form field)
if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Invalid CSRF token.']));
}

$user  = getCurrentUser();
if (!$user) {
    exit(json_encode(['success' => false, 'message' => 'Session expired.']));
}

$db     = getDB();
$uid    = (int)$user['id'];
$action = $_POST['action'] ?? '';

// ── Resolve device time ───────────────────────────────────────
// JS sends a pre-formatted local datetime string: "YYYY-MM-DD HH:MM:SS"
// built from new Date() in the browser, so it already reflects the
// user's device timezone — no UTC conversion needed.
// We validate the format and do a ±24 h sanity check against server
// time before accepting it.
function resolveClientTime(string $raw): string
{
    // Expect exactly: YYYY-MM-DD HH:MM:SS
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw)) {
        $ts = strtotime($raw);
        if ($ts !== false && abs(time() - $ts) < 86400) {
            return $raw; // use device time as-is
        }
    }
    return date('Y-m-d H:i:s'); // fallback to server time
}

// Derive today's date from the same client string so work_date
// also matches the device's local date (not the server's date).
$clientTimeRaw = $_POST['client_time'] ?? '';
$today = (preg_match('/^(\d{4}-\d{2}-\d{2})/', $clientTimeRaw, $m)) ? $m[1] : date('Y-m-d');

// ── Fetch today's record ──────────────────────────────────────
function getTodayRecord(PDO $db, int $uid, string $today): ?array
{
    $st = $db->prepare('SELECT * FROM attendance WHERE user_id = ? AND work_date = ? LIMIT 1');
    $st->execute([$uid, $today]);
    return $st->fetch() ?: null;
}

switch ($action) {

    // ── CHECK IN ──────────────────────────────────────────────
    case 'check_in':
        $existing = getTodayRecord($db, $uid, $today);
        if ($existing && $existing['check_in_time']) {
            exit(json_encode(['success' => false,
                'message' => 'You have already checked in today.']));
        }

        $lat = (float)($_POST['lat'] ?? 0.0);
        $lng = (float)($_POST['lng'] ?? 0.0);

        // Security validation
        $access = validate_access(
            $user['role'],
            (bool)$user['office_ip_restricted'],
            $lat,
            $lng
        );

        if (!$access['allowed']) {
            exit(json_encode(['success' => false, 'message' => $access['reason']]));
        }

        $ip       = getClientIp();
        $latLong  = ($lat || $lng) ? "{$lat},{$lng}" : null;
        $now      = resolveClientTime($_POST['client_time'] ?? '');

        if ($existing) {
            // Update existing row (e.g. previously inserted as absent)
            $st = $db->prepare(
                "UPDATE attendance SET check_in_time=?, ip_address=?, lat_long=?, status='present'
                 WHERE user_id=? AND work_date=?"
            );
            $st->execute([$now, $ip, $latLong, $uid, $today]);
        } else {
            $st = $db->prepare(
                "INSERT INTO attendance (user_id, work_date, check_in_time, ip_address, lat_long, status)
                 VALUES (?, ?, ?, ?, ?, 'present')"
            );
            $st->execute([$uid, $today, $now, $ip, $latLong]);
        }

        exit(json_encode([
            'success'       => true,
            'message'       => 'Checked in successfully at ' . date('h:i A', strtotime($now)),
            'check_in_time' => date('h:i A', strtotime($now)),
            'reason'        => $access['reason'],
        ]));

    // ── CHECK OUT ─────────────────────────────────────────────
    case 'check_out':
        $rec = getTodayRecord($db, $uid, $today);

        if (!$rec || !$rec['check_in_time']) {
            exit(json_encode(['success' => false, 'message' => 'You have not checked in yet.']));
        }
        if ($rec['check_out_time']) {
            exit(json_encode(['success' => false, 'message' => 'Already checked out.']));
        }

        // Server-side eligibility checks
        // 1. Day-end file + notes
        if (empty($rec['day_end_file']) || empty($rec['day_end_notes'])) {
            exit(json_encode([
                'success' => false,
                'message' => 'Please submit your day-end report (notes + file) before checking out.',
            ]));
        }

        // 2. All daily tasks completed
        $stPending = $db->prepare(
            "SELECT COUNT(*) FROM tasks
             WHERE user_id = ? AND task_type = 'daily'
               AND DATE(deadline) = ? AND status != 'completed'"
        );
        $stPending->execute([$uid, $today]);
        $pendingCount = (int)$stPending->fetchColumn();

        if ($pendingCount > 0) {
            exit(json_encode([
                'success' => false,
                'message' => "You have {$pendingCount} incomplete daily task(s). Complete them before checking out.",
            ]));
        }

        $now = resolveClientTime($_POST['client_time'] ?? '');
        $st  = $db->prepare(
            'UPDATE attendance SET check_out_time = ? WHERE user_id = ? AND work_date = ?'
        );
        $st->execute([$now, $uid, $today]);

        exit(json_encode([
            'success'        => true,
            'message'        => 'Checked out successfully at ' . date('h:i A', strtotime($now)),
            'check_out_time' => date('h:i A', strtotime($now)),
        ]));

    // ── DAY-END REPORT ────────────────────────────────────────
    case 'day_end':
        $rec = getTodayRecord($db, $uid, $today);

        if (!$rec || !$rec['check_in_time']) {
            exit(json_encode(['success' => false, 'message' => 'You have not checked in today.']));
        }
        if ($rec['check_out_time']) {
            exit(json_encode(['success' => false, 'message' => 'Cannot update after checkout.']));
        }

        $notes = trim($_POST['day_end_notes'] ?? '');
        if (empty($notes)) {
            exit(json_encode(['success' => false, 'message' => 'End-of-day notes are required.']));
        }

        // Handle file upload
        $filePath = $rec['day_end_file']; // keep existing if no new upload
        if (!empty($_FILES['day_end_file']['name'])) {
            $upload = handleFileUpload('day_end_file', 'day_end');
            if (!$upload['success']) {
                exit(json_encode(['success' => false, 'message' => $upload['message']]));
            }
            $filePath = $upload['path'];
        } elseif (empty($filePath)) {
            exit(json_encode(['success' => false, 'message' => 'Please upload your day-end file.']));
        }

        $st = $db->prepare(
            'UPDATE attendance SET day_end_notes = ?, day_end_file = ?
             WHERE user_id = ? AND work_date = ?'
        );
        $st->execute([$notes, $filePath, $uid, $today]);

        // Re-check checkout eligibility
        $stPending = $db->prepare(
            "SELECT COUNT(*) FROM tasks
             WHERE user_id = ? AND task_type = 'daily'
               AND DATE(deadline) = ? AND status != 'completed'"
        );
        $stPending->execute([$uid, $today]);
        $pendingCount = (int)$stPending->fetchColumn();

        exit(json_encode([
            'success'      => true,
            'message'      => 'Day-end report saved.',
            'can_checkout' => ($pendingCount === 0),
        ]));

    default:
        http_response_code(400);
        exit(json_encode(['success' => false, 'message' => 'Invalid action.']));
}
