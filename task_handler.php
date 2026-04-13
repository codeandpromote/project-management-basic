<?php
// ============================================================
//  HRMS · Task AJAX Handler
//  POST actions: start | complete
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

$user   = getCurrentUser();
$db     = getDB();
$uid    = (int)$user['id'];
$today  = date('Y-m-d');
$action = $_POST['action'] ?? '';
$taskId = (int)($_POST['task_id'] ?? 0);

if ($taskId <= 0) {
    exit(json_encode(['success' => false, 'message' => 'Invalid task ID.']));
}

// Fetch task — ensure it belongs to this user (or admin)
$stTask = $db->prepare('SELECT * FROM tasks WHERE id = ? LIMIT 1');
$stTask->execute([$taskId]);
$task = $stTask->fetch();

if (!$task) {
    exit(json_encode(['success' => false, 'message' => 'Task not found.']));
}

// Non-admins can only touch their own tasks
if ($user['role'] !== 'admin' && (int)$task['user_id'] !== $uid) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Permission denied.']));
}

switch ($action) {

    // ── Mark In-Progress ─────────────────────────────────────
    case 'start':
        if ($task['status'] !== 'pending') {
            exit(json_encode(['success' => false,
                'message' => 'Task cannot be started (status: ' . $task['status'] . ').']));
        }
        $db->prepare("UPDATE tasks SET status='in_progress' WHERE id=?")
           ->execute([$taskId]);
        exit(json_encode(['success' => true, 'message' => 'Task started.', 'status' => 'in_progress']));

    // ── Complete Task ─────────────────────────────────────────
    case 'complete':
        if ($task['status'] === 'completed') {
            exit(json_encode(['success' => false, 'message' => 'Task already completed.']));
        }

        $notes = trim($_POST['completion_notes'] ?? '');
        if (empty($notes)) {
            exit(json_encode(['success' => false, 'message' => 'Completion notes are required.']));
        }

        // Proof file upload
        $upload = handleFileUpload('proof_file', 'proofs');
        if (!$upload['success']) {
            exit(json_encode(['success' => false, 'message' => $upload['message']]));
        }

        $now = date('Y-m-d H:i:s');
        $db->prepare(
            "UPDATE tasks SET status='completed', completion_notes=?,
             proof_file=?, completed_at=?, updated_at=? WHERE id=?"
        )->execute([$notes, $upload['path'], $now, $now, $taskId]);

        // Re-count pending daily tasks using the same logic as fetchTasks() on the dashboard:
        // any daily task that is pending/in_progress or overdue (not completed).
        $stPend = $db->prepare(
            "SELECT COUNT(*) FROM tasks
             WHERE user_id = ? AND task_type = 'daily'
               AND (
                     status IN ('pending','in_progress')
                  OR (status != 'completed' AND deadline < NOW())
                   )"
        );
        $stPend->execute([$uid]);
        $stillPending = (int)$stPend->fetchColumn();

        // Also check day-end file/notes
        $stAtt = $db->prepare(
            'SELECT day_end_file, day_end_notes FROM attendance
             WHERE user_id=? AND work_date=? LIMIT 1'
        );
        $stAtt->execute([$uid, $today]);
        $att = $stAtt->fetch();
        $canCheckout = ($stillPending === 0)
                    && !empty($att['day_end_file'])
                    && !empty($att['day_end_notes']);

        exit(json_encode([
            'success'      => true,
            'message'      => 'Task marked as complete.',
            'status'       => 'completed',
            'can_checkout' => $canCheckout,
        ]));

    // ── Admin: Delete Task ────────────────────────────────────
    case 'delete':
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            exit(json_encode(['success' => false, 'message' => 'Admin only.']));
        }
        // Remove file if exists
        if ($task['file_path'] && file_exists(UPLOAD_PATH . $task['file_path'])) {
            unlink(UPLOAD_PATH . $task['file_path']);
        }
        if ($task['proof_file'] && file_exists(UPLOAD_PATH . $task['proof_file'])) {
            unlink(UPLOAD_PATH . $task['proof_file']);
        }
        $db->prepare('DELETE FROM tasks WHERE id=?')->execute([$taskId]);
        exit(json_encode(['success' => true, 'message' => 'Task deleted.']));

    default:
        http_response_code(400);
        exit(json_encode(['success' => false, 'message' => 'Unknown action.']));
}
