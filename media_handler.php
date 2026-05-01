<?php
// ============================================================
//  HRMS · Media file delete handler (admin only)
//  POST actions: delete | bulk_delete
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json');
requireRole('admin');

if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Invalid CSRF token.']));
}

$db     = getDB();
$action = $_POST['action'] ?? '';

// Allowed media categories
// type     = DB target
// kind     = id (single-row) | col (null-out column)
// table    = SQL table
// pathCol  = column holding the path
// idCol    = PK column name
$ALLOWED_TYPES = [
    'task_file'          => ['table' => 'tasks',            'pathCol' => 'file_path',      'idCol' => 'id', 'null_path' => true],
    'task_proof'         => ['table' => 'tasks',            'pathCol' => 'proof_file',     'idCol' => 'id', 'null_path' => true],
    'task_call_recording'=> ['table' => 'tasks',            'pathCol' => 'call_recording', 'idCol' => 'id', 'null_path' => true],
    'day_end'            => ['table' => 'attendance',       'pathCol' => 'day_end_file',   'idCol' => 'id', 'null_path' => true],
    'lead_attachment'    => ['table' => 'lead_attachments', 'pathCol' => 'file_path',      'idCol' => 'id', 'null_path' => false], // delete row
    'location_photo'     => ['table' => 'location_logs',    'pathCol' => 'photo',          'idCol' => 'id', 'null_path' => true],
];

/**
 * Delete a single media record.
 *   $type = one of keys in $ALLOWED_TYPES
 *   $id   = primary-key id of that row
 * Returns ['ok'=>bool, 'msg'=>string, 'path'=>string|null]
 */
function deleteMedia(PDO $db, array $allowed, string $type, int $id): array
{
    if (!isset($allowed[$type])) {
        return ['ok' => false, 'msg' => 'Unknown media type.', 'path' => null];
    }
    if ($id <= 0) {
        return ['ok' => false, 'msg' => 'Invalid id.', 'path' => null];
    }
    $cfg = $allowed[$type];

    $sel = $db->prepare("SELECT {$cfg['pathCol']} FROM {$cfg['table']} WHERE {$cfg['idCol']} = ? LIMIT 1");
    $sel->execute([$id]);
    $path = $sel->fetchColumn();
    if (!$path) { return ['ok' => false, 'msg' => 'File not found.', 'path' => null]; }

    // Safely delete file under UPLOAD_PATH only (defense in depth vs path traversal)
    $full    = UPLOAD_PATH . $path;
    $realRoot = realpath(UPLOAD_PATH);
    $realFile = realpath($full);
    if ($realRoot && $realFile && strpos($realFile, $realRoot) === 0 && is_file($realFile)) {
        @unlink($realFile);
    }

    if (!empty($cfg['null_path'])) {
        $db->prepare("UPDATE {$cfg['table']} SET {$cfg['pathCol']} = NULL WHERE {$cfg['idCol']} = ?")
           ->execute([$id]);
    } else {
        $db->prepare("DELETE FROM {$cfg['table']} WHERE {$cfg['idCol']} = ?")
           ->execute([$id]);
    }

    return ['ok' => true, 'msg' => 'Deleted.', 'path' => $path];
}

switch ($action) {

    case 'delete': {
        $type = $_POST['type']  ?? '';
        $id   = (int)($_POST['id'] ?? 0);
        $res = deleteMedia($db, $ALLOWED_TYPES, $type, $id);
        exit(json_encode(['success' => $res['ok'], 'message' => $res['msg']]));
    }

    case 'bulk_delete': {
        $items = $_POST['items'] ?? [];    // array of "type|id" strings
        if (!is_array($items) || empty($items)) {
            exit(json_encode(['success' => false, 'message' => 'No items selected.']));
        }
        $deleted = 0; $failed = 0;
        foreach ($items as $pair) {
            if (!is_string($pair)) { $failed++; continue; }
            $bits = explode('|', $pair, 2);
            if (count($bits) !== 2) { $failed++; continue; }
            $res = deleteMedia($db, $ALLOWED_TYPES, $bits[0], (int)$bits[1]);
            if ($res['ok']) { $deleted++; } else { $failed++; }
        }
        exit(json_encode([
            'success' => $deleted > 0,
            'message' => "$deleted deleted" . ($failed > 0 ? ", $failed failed" : '') . '.',
            'deleted' => $deleted,
            'failed'  => $failed,
        ]));
    }

    default:
        http_response_code(400);
        exit(json_encode(['success' => false, 'message' => 'Unknown action.']));
}
