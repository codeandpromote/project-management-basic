<?php
// ============================================================
//  HRMS · Lead Management AJAX Handler
//  POST actions:
//   create | update | delete | bulk_assign | bulk_status
//   add_activity | attach_file | delete_attachment
//   check_duplicate | product_save | product_delete
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
if (!$user) { exit(json_encode(['success' => false, 'message' => 'Session expired.'])); }

$db     = getDB();
$uid    = (int)$user['id'];
$role   = $user['role'];
$action = $_POST['action'] ?? '';

// ── Role gate: all logged-in users can work with leads ───────────
// Employees can add and update their own; admin can do anything.

// ── Helper: can this user edit this lead? ────────────────────────
function canEditLead(array $lead, array $user): bool
{
    if ($user['role'] === 'admin')            { return true; }
    if ((int)$lead['assigned_to'] === (int)$user['id']) { return true; }
    if ((int)$lead['creator_id']  === (int)$user['id']) { return true; }
    return false;
}
// Admin-only: delete, bulk-reassign, manage products
function requireAdmin(array $user): void
{
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit(json_encode(['success' => false, 'message' => 'Admin only.']));
    }
}

// ── Allowed ENUM values ──────────────────────────────────────────
$VALID_SOURCES   = ['walk_in','phone','referral','website','social','cold_call','exhibition','other'];
$VALID_PRIORITY  = ['low','medium','high','hot'];
$VALID_STATUS    = ['new','contacted','qualified','meeting','negotiation','won','lost'];
$VALID_ACT_TYPE  = ['call','visit','meeting','message','note','status_change','reassigned'];
$VALID_OUTCOME   = ['connected','not_connected','interested','not_interested','pending','converted','rejected'];

// ── Sanitise / pick helper ───────────────────────────────────────
function pick(array $allowed, string $value, string $default): string
{
    return in_array($value, $allowed, true) ? $value : $default;
}

// ── Helper: create (or refresh) a follow-up task for a lead ───
// Policy: ONE task per lead per calendar date.
//   - If a non-completed task for the same lead on the same date exists,
//     we update its deadline/description instead of creating a duplicate.
//   - Otherwise we insert a new task.
// Returns ['created' => bool, 'updated' => bool, 'task_id' => int|null]
function upsertFollowupTask(
    PDO $db, int $leadId, int $creatorUid,
    string $fuDate, string $fuTime, string $notes
): array {
    // Wide try-catch so no exception ever bubbles up and breaks the JSON response
    try {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fuDate)) {
            return ['created' => false, 'updated' => false, 'task_id' => null];
        }

        // Look up the lead so we can copy name + assignee
        $stL = $db->prepare('SELECT id, name, phone, assigned_to FROM leads WHERE id = ? LIMIT 1');
        $stL->execute([$leadId]);
        $lead = $stL->fetch();
        if (!$lead) {
            return ['created' => false, 'updated' => false, 'task_id' => null];
        }

        $assignee = (int)($lead['assigned_to'] ?: $creatorUid);

        // Deadline = follow-up date + time (default 18:00 if no time)
        $timeStr  = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $fuTime) ? $fuTime : '18:00:00';
        if (strlen($timeStr) === 5) { $timeStr .= ':00'; }
        $deadline = $fuDate . ' ' . $timeStr;

        $leadName  = (string)($lead['name']  ?? '');
        $leadPhone = (string)($lead['phone'] ?? '');
        $title = 'Follow-up: ' . mb_substr($leadName, 0, 100);
        $desc  = trim(($notes !== '' ? $notes . "\n\n" : '') . 'Phone: ' . $leadPhone);

        // Is there already a non-completed task for this lead on this date?
        $stEx = $db->prepare(
            "SELECT id FROM tasks
              WHERE lead_id = ? AND status != 'completed'
                AND DATE(deadline) = ?
              ORDER BY id DESC LIMIT 1"
        );
        $stEx->execute([$leadId, $fuDate]);
        $existingId = (int)$stEx->fetchColumn();

        if ($existingId) {
            $db->prepare(
                "UPDATE tasks SET deadline = ?, description = ?, updated_at = NOW()
                  WHERE id = ?"
            )->execute([$deadline, $desc, $existingId]);
            return ['created' => false, 'updated' => true, 'task_id' => $existingId];
        }

        $db->prepare(
            "INSERT INTO tasks
              (title, description, task_type, deadline, user_id, creator_id, lead_id, status, created_at, updated_at)
             VALUES (?, ?, 'daily', ?, ?, ?, ?, 'pending', NOW(), NOW())"
        )->execute([$title, $desc, $deadline, $assignee, $creatorUid, $leadId]);

        return ['created' => true, 'updated' => false, 'task_id' => (int)$db->lastInsertId()];
    } catch (Throwable $e) {
        error_log('[HRMS upsertFollowupTask] ' . $e->getMessage());
        return ['created' => false, 'updated' => false, 'task_id' => null];
    }
}

// ── Helper: auto-complete a lead's linked task when lead closes ──
function autoCompleteLinkedTask(PDO $db, int $leadId, int $uid, string $notes): bool
{
    try {
        $stT = $db->prepare(
            "SELECT id, user_id FROM tasks
              WHERE lead_id = ? AND status != 'completed'
              ORDER BY id DESC LIMIT 1"
        );
        $stT->execute([$leadId]);
        $task = $stT->fetch();
        if (!$task) { return false; }

        // Grab the most recent attachment as proof file (if any)
        $stA = $db->prepare(
            "SELECT file_path FROM lead_attachments
              WHERE lead_id = ? ORDER BY id DESC LIMIT 1"
        );
        $stA->execute([$leadId]);
        $proof = $stA->fetchColumn() ?: null;

        $now = date('Y-m-d H:i:s');
        $db->prepare(
            "UPDATE tasks SET status = 'completed',
               completion_notes = ?, proof_file = COALESCE(proof_file, ?),
               completed_at = ?, updated_at = ?
             WHERE id = ?"
        )->execute([
            $notes !== '' ? $notes : 'Auto-closed via lead status change',
            $proof, $now, $now, $task['id'],
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('[HRMS autoCompleteLinkedTask] ' . $e->getMessage());
        return false;
    }
}

switch ($action) {

    // ── Create lead ───────────────────────────────────────────
    case 'create': {
        $name     = trim($_POST['name']        ?? '');
        $phone    = trim($_POST['phone']       ?? '');
        $email    = trim($_POST['email']       ?? '');
        $company  = trim($_POST['company']     ?? '');
        $desig    = trim($_POST['designation'] ?? '');
        $address  = trim($_POST['address']     ?? '');
        $pincode  = trim($_POST['pincode']     ?? '');
        $source   = pick($VALID_SOURCES,  $_POST['source']   ?? '', 'other');
        $interest = trim($_POST['interest']    ?? '');
        $prodId   = (int)($_POST['product_id'] ?? 0) ?: null;
        $estVal   = (float)($_POST['est_value']?? 0);
        $priority = pick($VALID_PRIORITY, $_POST['priority'] ?? '', 'medium');
        $assignTo = (int)($_POST['assigned_to']?? 0) ?: null;
        $notes    = trim($_POST['notes']       ?? '');
        $tags     = trim($_POST['tags']        ?? '');

        if ($name === '' || $phone === '') {
            exit(json_encode(['success' => false, 'message' => 'Name and phone are required.']));
        }
        if (mb_strlen($name) > 150 || mb_strlen($phone) > 30) {
            exit(json_encode(['success' => false, 'message' => 'Name or phone too long.']));
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            exit(json_encode(['success' => false, 'message' => 'Invalid email address.']));
        }

        // Non-admins: force self-assign (cannot assign to anyone else)
        if ($role !== 'admin') {
            $assignTo = $uid;
        } elseif ($assignTo) {
            // Admin: verify assignee exists and is active
            $stV = $db->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
            $stV->execute([$assignTo]);
            if (!$stV->fetch()) { $assignTo = null; }
        }

        if ($estVal < 0) { $estVal = 0; }

        try {
            $db->prepare(
                "INSERT INTO leads
                   (name, phone, email, company, designation, address, pincode,
                    source, interest, product_id, est_value, priority, status,
                    assigned_to, creator_id, notes, tags)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'new',?,?,?,?)"
            )->execute([
                $name, $phone, $email ?: null, $company ?: null, $desig ?: null,
                $address ?: null, $pincode ?: null, $source, $interest ?: null,
                $prodId, $estVal, $priority, $assignTo, $uid,
                $notes ?: null, $tags ?: null,
            ]);
            $newId = (int)$db->lastInsertId();
            exit(json_encode(['success' => true, 'message' => 'Lead created.', 'lead_id' => $newId]));
        } catch (PDOException $e) {
            exit(json_encode(['success' => false, 'message' => 'Could not save. Please try again.']));
        }
    }

    // ── Update lead ───────────────────────────────────────────
    case 'update': {
        $leadId = (int)($_POST['lead_id'] ?? 0);
        if ($leadId <= 0) { exit(json_encode(['success' => false, 'message' => 'Invalid lead.'])); }

        $stL = $db->prepare('SELECT * FROM leads WHERE id = ? AND is_deleted = 0 LIMIT 1');
        $stL->execute([$leadId]);
        $lead = $stL->fetch();
        if (!$lead) { exit(json_encode(['success' => false, 'message' => 'Lead not found.'])); }
        if (!canEditLead($lead, $user)) {
            http_response_code(403);
            exit(json_encode(['success' => false, 'message' => 'Permission denied.']));
        }

        $name     = trim($_POST['name']        ?? $lead['name']);
        $phone    = trim($_POST['phone']       ?? $lead['phone']);
        $email    = trim($_POST['email']       ?? '');
        $company  = trim($_POST['company']     ?? '');
        $desig    = trim($_POST['designation'] ?? '');
        $address  = trim($_POST['address']     ?? '');
        $pincode  = trim($_POST['pincode']     ?? '');
        $source   = pick($VALID_SOURCES,  $_POST['source']   ?? $lead['source'],   $lead['source']);
        $interest = trim($_POST['interest']    ?? '');
        $prodId   = (int)($_POST['product_id'] ?? 0) ?: null;
        $estVal   = (float)($_POST['est_value']?? 0);
        $priority = pick($VALID_PRIORITY, $_POST['priority'] ?? $lead['priority'], $lead['priority']);
        $notes    = trim($_POST['notes']       ?? '');
        $tags     = trim($_POST['tags']        ?? '');

        if ($name === '' || $phone === '') {
            exit(json_encode(['success' => false, 'message' => 'Name and phone are required.']));
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            exit(json_encode(['success' => false, 'message' => 'Invalid email address.']));
        }

        // Reassignment: admin only
        $assignTo = (int)$lead['assigned_to'] ?: null;
        if ($role === 'admin' && isset($_POST['assigned_to'])) {
            $newAssign = (int)$_POST['assigned_to'] ?: null;
            if ($newAssign) {
                $stV = $db->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
                $stV->execute([$newAssign]);
                if ($stV->fetch()) { $assignTo = $newAssign; }
            } else {
                $assignTo = null;
            }
        }

        if ($estVal < 0) { $estVal = 0; }

        try {
            $db->prepare(
                "UPDATE leads SET
                   name=?, phone=?, email=?, company=?, designation=?, address=?, pincode=?,
                   source=?, interest=?, product_id=?, est_value=?, priority=?, assigned_to=?,
                   notes=?, tags=?
                 WHERE id=?"
            )->execute([
                $name, $phone, $email ?: null, $company ?: null, $desig ?: null,
                $address ?: null, $pincode ?: null, $source, $interest ?: null,
                $prodId, $estVal, $priority, $assignTo,
                $notes ?: null, $tags ?: null, $leadId,
            ]);
            exit(json_encode(['success' => true, 'message' => 'Lead updated.']));
        } catch (PDOException $e) {
            exit(json_encode(['success' => false, 'message' => 'Could not save changes.']));
        }
    }

    // ── Delete (soft) ─────────────────────────────────────────
    case 'delete': {
        requireAdmin($user);
        $leadId = (int)($_POST['lead_id'] ?? 0);
        if ($leadId <= 0) { exit(json_encode(['success' => false, 'message' => 'Invalid lead.'])); }
        $db->prepare('UPDATE leads SET is_deleted = 1 WHERE id = ?')->execute([$leadId]);
        exit(json_encode(['success' => true, 'message' => 'Lead deleted.']));
    }

    // ── Add follow-up activity ────────────────────────────────
    case 'add_activity': {
        $leadId = (int)($_POST['lead_id'] ?? 0);
        if ($leadId <= 0) { exit(json_encode(['success' => false, 'message' => 'Invalid lead.'])); }

        $stL = $db->prepare('SELECT * FROM leads WHERE id = ? AND is_deleted = 0 LIMIT 1');
        $stL->execute([$leadId]);
        $lead = $stL->fetch();
        if (!$lead) { exit(json_encode(['success' => false, 'message' => 'Lead not found.'])); }
        if (!canEditLead($lead, $user)) {
            http_response_code(403);
            exit(json_encode(['success' => false, 'message' => 'Permission denied.']));
        }

        $type    = pick($VALID_ACT_TYPE, $_POST['activity_type'] ?? '', 'note');
        $outcome = pick($VALID_OUTCOME,  $_POST['outcome']       ?? '', 'pending');
        $notes   = trim($_POST['notes'] ?? '');
        $fuDate  = trim($_POST['next_followup_date'] ?? '');
        $fuTime  = trim($_POST['next_followup_time'] ?? '');
        $newStatus = trim($_POST['new_status'] ?? '');

        // Validate follow-up date
        if ($fuDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fuDate)) { $fuDate = ''; }
        if ($fuTime !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $fuTime)) { $fuTime = ''; }
        if ($fuTime !== '' && strlen($fuTime) === 5) { $fuTime .= ':00'; }

        // Handle status change
        $statusChanged = false;
        $oldStatus = $lead['status'];
        $nextStatus = $lead['status'];
        if ($newStatus !== '' && in_array($newStatus, $VALID_STATUS, true) && $newStatus !== $oldStatus) {
            $nextStatus = $newStatus;
            $statusChanged = true;
        }

        $db->beginTransaction();
        try {
            // Insert activity
            $db->prepare(
                "INSERT INTO lead_activities
                   (lead_id, user_id, activity_type, outcome, notes,
                    next_followup_date, next_followup_time, old_status, new_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $leadId, $uid, $type, $outcome,
                $notes !== '' ? $notes : null,
                $fuDate !== '' ? $fuDate : null,
                $fuTime !== '' ? $fuTime : null,
                $statusChanged ? $oldStatus : null,
                $statusChanged ? $nextStatus : null,
            ]);

            // Update lead denormalized fields
            $sets   = ['last_activity_at = NOW()'];
            $params = [];
            if ($fuDate !== '') { $sets[] = 'next_followup_date = ?'; $params[] = $fuDate; }
            if ($statusChanged) {
                $sets[] = 'status = ?'; $params[] = $nextStatus;
                if ($nextStatus === 'won')  { $sets[] = 'won_at = NOW()'; }
                if ($nextStatus === 'lost') { $sets[] = 'lost_at = NOW()'; }
            }
            $params[] = $leadId;
            $db->prepare('UPDATE leads SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

            // If status changed, log a separate status_change activity too (audit trail)
            if ($statusChanged && $type !== 'status_change') {
                $db->prepare(
                    "INSERT INTO lead_activities
                       (lead_id, user_id, activity_type, outcome, old_status, new_status)
                     VALUES (?, ?, 'status_change', 'pending', ?, ?)"
                )->execute([$leadId, $uid, $oldStatus, $nextStatus]);
            }

            $db->commit();

            // After commit: if a next follow-up date is set, auto-create/refresh
            // a task for that date linked to this lead.
            $taskResult = ['created' => false, 'updated' => false, 'task_id' => null];
            if ($fuDate !== '' && !in_array($nextStatus, ['won','lost'], true)) {
                $taskResult = upsertFollowupTask(
                    $db, $leadId, $uid, $fuDate, $fuTime, $notes
                );
            }

            // If lead closed (won/lost), auto-complete any linked task
            $taskAutoClosed = false;
            if ($statusChanged && in_array($nextStatus, ['won','lost'], true)) {
                $taskAutoClosed = autoCompleteLinkedTask(
                    $db, $leadId, $uid, $notes !== '' ? $notes : ''
                );
            }

            $msg = 'Follow-up logged.';
            if ($taskResult['created']) {
                $msg .= ' Task created for ' . date('d M Y', strtotime($fuDate)) . '.';
            } elseif ($taskResult['updated']) {
                $msg .= ' Existing task for that day updated.';
            }
            if ($taskAutoClosed) { $msg .= ' Linked daily task auto-completed.'; }

            exit(json_encode([
                'success' => true, 'message' => $msg,
                'task_auto_closed' => $taskAutoClosed,
                'followup_task'    => $taskResult,
            ]));
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('[HRMS add_activity] ' . $e->getMessage());
            exit(json_encode([
                'success' => false,
                'message' => 'Could not save follow-up: ' . $e->getMessage(),
            ]));
        }
    }

    // ── Attach file to lead ───────────────────────────────────
    case 'attach_file': {
        $leadId = (int)($_POST['lead_id'] ?? 0);
        if ($leadId <= 0) { exit(json_encode(['success' => false, 'message' => 'Invalid lead.'])); }

        $stL = $db->prepare('SELECT * FROM leads WHERE id = ? AND is_deleted = 0 LIMIT 1');
        $stL->execute([$leadId]);
        $lead = $stL->fetch();
        if (!$lead) { exit(json_encode(['success' => false, 'message' => 'Lead not found.'])); }
        if (!canEditLead($lead, $user)) {
            http_response_code(403);
            exit(json_encode(['success' => false, 'message' => 'Permission denied.']));
        }

        $upload = handleFileUpload('lead_file', 'leads');
        if (!$upload['success']) {
            exit(json_encode(['success' => false, 'message' => $upload['message']]));
        }

        $label = trim($_POST['file_label'] ?? '');
        $db->prepare(
            "INSERT INTO lead_attachments (lead_id, user_id, file_path, file_label)
             VALUES (?, ?, ?, ?)"
        )->execute([$leadId, $uid, $upload['path'], $label !== '' ? $label : null]);

        exit(json_encode(['success' => true, 'message' => 'File attached.', 'path' => $upload['path']]));
    }

    // ── Delete attachment ─────────────────────────────────────
    case 'delete_attachment': {
        $attId = (int)($_POST['attachment_id'] ?? 0);
        if ($attId <= 0) { exit(json_encode(['success' => false, 'message' => 'Invalid.'])); }

        $stA = $db->prepare(
            'SELECT a.*, l.assigned_to, l.creator_id
               FROM lead_attachments a JOIN leads l ON l.id = a.lead_id
              WHERE a.id = ? LIMIT 1'
        );
        $stA->execute([$attId]);
        $att = $stA->fetch();
        if (!$att) { exit(json_encode(['success' => false, 'message' => 'Attachment not found.'])); }

        $fakeLead = ['assigned_to' => $att['assigned_to'], 'creator_id' => $att['creator_id']];
        if (!canEditLead($fakeLead, $user)) {
            http_response_code(403);
            exit(json_encode(['success' => false, 'message' => 'Permission denied.']));
        }

        // Delete file
        $full = UPLOAD_PATH . $att['file_path'];
        if (is_file($full)) { @unlink($full); }
        $db->prepare('DELETE FROM lead_attachments WHERE id = ?')->execute([$attId]);
        exit(json_encode(['success' => true, 'message' => 'Attachment removed.']));
    }

    // ── Bulk: reassign many leads ─────────────────────────────
    case 'bulk_assign': {
        requireAdmin($user);
        $ids = $_POST['ids'] ?? [];
        $to  = (int)($_POST['assigned_to'] ?? 0);
        if (!is_array($ids) || empty($ids)) {
            exit(json_encode(['success' => false, 'message' => 'No leads selected.']));
        }
        $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
        if (empty($ids)) { exit(json_encode(['success' => false, 'message' => 'No valid IDs.'])); }
        if ($to) {
            $stV = $db->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
            $stV->execute([$to]);
            if (!$stV->fetch()) { exit(json_encode(['success' => false, 'message' => 'Invalid assignee.'])); }
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$to ?: null], $ids);
        $db->prepare("UPDATE leads SET assigned_to = ? WHERE id IN ($place) AND is_deleted = 0")
           ->execute($params);
        exit(json_encode(['success' => true, 'message' => count($ids) . ' leads reassigned.']));
    }

    // ── Bulk: change status ───────────────────────────────────
    case 'bulk_status': {
        $ids    = $_POST['ids'] ?? [];
        $status = pick($VALID_STATUS, $_POST['status'] ?? '', '');
        if ($status === '' || !is_array($ids) || empty($ids)) {
            exit(json_encode(['success' => false, 'message' => 'Invalid selection.']));
        }
        $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
        if (empty($ids)) { exit(json_encode(['success' => false, 'message' => 'No valid IDs.'])); }

        // Non-admins can only touch their own leads
        if ($role !== 'admin') {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $stO = $db->prepare(
                "SELECT id FROM leads
                  WHERE id IN ($place) AND is_deleted = 0
                    AND (assigned_to = ? OR creator_id = ?)"
            );
            $stO->execute(array_merge($ids, [$uid, $uid]));
            $ids = array_column($stO->fetchAll(), 'id');
            if (empty($ids)) { exit(json_encode(['success' => false, 'message' => 'No leads editable.'])); }
        }

        $place = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$status], $ids);
        $db->prepare("UPDATE leads SET status = ? WHERE id IN ($place) AND is_deleted = 0")
           ->execute($params);

        // Log a status_change activity on each
        $st = $db->prepare(
            "INSERT INTO lead_activities (lead_id, user_id, activity_type, new_status)
             VALUES (?, ?, 'status_change', ?)"
        );
        foreach ($ids as $lid) { $st->execute([$lid, $uid, $status]); }

        // If closed (won/lost), auto-complete the linked task on each
        $autoClosed = 0;
        if (in_array($status, ['won','lost'], true)) {
            foreach ($ids as $lid) {
                if (autoCompleteLinkedTask($db, $lid, $uid, '')) { $autoClosed++; }
            }
        }
        $msg = count($ids) . ' leads updated.';
        if ($autoClosed > 0) { $msg .= " $autoClosed linked task(s) auto-completed."; }
        exit(json_encode(['success' => true, 'message' => $msg]));
    }

    // ── Duplicate detection ──────────────────────────────────
    case 'check_duplicate': {
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $name  = trim($_POST['name']  ?? '');
        if ($phone === '' && $email === '' && $name === '') {
            exit(json_encode(['success' => true, 'matches' => []]));
        }
        $where = []; $params = [];
        if ($phone !== '') { $where[] = 'phone = ?'; $params[] = $phone; }
        if ($email !== '') { $where[] = 'email = ?'; $params[] = $email; }
        if ($name  !== '') { $where[] = 'name LIKE ?'; $params[] = $name; }
        $sql = 'SELECT id, name, phone, email, status, assigned_to
                  FROM leads
                 WHERE is_deleted = 0 AND (' . implode(' OR ', $where) . ')
                 LIMIT 5';
        $st = $db->prepare($sql);
        $st->execute($params);
        exit(json_encode(['success' => true, 'matches' => $st->fetchAll()]));
    }

    // ── Admin: product catalog save / delete ─────────────────
    case 'product_save': {
        requireAdmin($user);
        $pid   = (int)($_POST['id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $price = (float)($_POST['base_price'] ?? 0);
        $active = !empty($_POST['is_active']) ? 1 : 0;

        if ($name === '') { exit(json_encode(['success' => false, 'message' => 'Name required.'])); }
        if (mb_strlen($name) > 150) { exit(json_encode(['success' => false, 'message' => 'Name too long.'])); }
        if ($price < 0) { $price = 0; }

        if ($pid > 0) {
            $db->prepare(
                'UPDATE lead_products SET name=?, description=?, base_price=?, is_active=? WHERE id=?'
            )->execute([$name, $desc ?: null, $price, $active, $pid]);
        } else {
            $db->prepare(
                'INSERT INTO lead_products (name, description, base_price, is_active)
                 VALUES (?, ?, ?, ?)'
            )->execute([$name, $desc ?: null, $price, $active]);
            $pid = (int)$db->lastInsertId();
        }
        exit(json_encode(['success' => true, 'message' => 'Product saved.', 'id' => $pid]));
    }

    case 'product_delete': {
        requireAdmin($user);
        $pid = (int)($_POST['id'] ?? 0);
        if ($pid <= 0) { exit(json_encode(['success' => false, 'message' => 'Invalid.'])); }
        // Soft retire — deactivate instead of delete (to preserve lead FK)
        $db->prepare('UPDATE lead_products SET is_active = 0 WHERE id = ?')->execute([$pid]);
        exit(json_encode(['success' => true, 'message' => 'Product retired.']));
    }

    // ── Admin: assign a lead as today's daily task ───────────
    case 'assign_task': {
        requireAdmin($user);
        $leadId = (int)($_POST['lead_id'] ?? 0);
        if ($leadId <= 0) { exit(json_encode(['success' => false, 'message' => 'Invalid lead.'])); }

        $stL = $db->prepare('SELECT * FROM leads WHERE id = ? AND is_deleted = 0 LIMIT 1');
        $stL->execute([$leadId]);
        $lead = $stL->fetch();
        if (!$lead) { exit(json_encode(['success' => false, 'message' => 'Lead not found.'])); }
        if (empty($lead['assigned_to'])) {
            exit(json_encode(['success' => false,
                'message' => 'Assign the lead to an employee first.']));
        }

        // Already has a non-completed linked task?
        $stExists = $db->prepare(
            "SELECT id FROM tasks
              WHERE lead_id = ? AND status != 'completed'
              ORDER BY id DESC LIMIT 1"
        );
        $stExists->execute([$leadId]);
        if ($existingId = $stExists->fetchColumn()) {
            exit(json_encode([
                'success' => false,
                'message' => 'A daily task is already linked to this lead.',
                'existing_task_id' => (int)$existingId,
            ]));
        }

        // Build title / description
        $title = 'Visit lead: ' . mb_substr($lead['name'], 0, 100);
        $parts = [];
        if ($lead['company'])  { $parts[] = 'Company: ' . $lead['company']; }
        if ($lead['phone'])    { $parts[] = 'Phone: '   . $lead['phone']; }
        if ($lead['address'])  { $parts[] = 'Address: ' . $lead['address']; }
        if ($lead['interest']) { $parts[] = 'Interest: '. $lead['interest']; }
        if ($lead['notes'])    { $parts[] = "\nNotes:\n" . $lead['notes']; }
        $description = implode("\n", $parts);

        // Deadline = today 18:00 local
        $deadline = date('Y-m-d') . ' 18:00:00';

        try {
            $db->prepare(
                "INSERT INTO tasks
                  (title, description, task_type, deadline, user_id, creator_id, lead_id, status, created_at, updated_at)
                 VALUES (?, ?, 'daily', ?, ?, ?, ?, 'pending', NOW(), NOW())"
            )->execute([
                $title, $description ?: null, $deadline,
                (int)$lead['assigned_to'], $uid, $leadId,
            ]);
            $newTaskId = (int)$db->lastInsertId();

            // Log an activity on the lead
            $db->prepare(
                "INSERT INTO lead_activities
                   (lead_id, user_id, activity_type, outcome, notes)
                 VALUES (?, ?, 'note', 'pending', ?)"
            )->execute([
                $leadId, $uid,
                'Assigned as today\'s daily task (deadline ' . date('h:i A', strtotime($deadline)) . ').',
            ]);
            $db->prepare('UPDATE leads SET last_activity_at = NOW() WHERE id = ?')
               ->execute([$leadId]);

            exit(json_encode([
                'success' => true,
                'message' => 'Lead assigned as today\'s task.',
                'task_id' => $newTaskId,
            ]));
        } catch (PDOException $e) {
            exit(json_encode([
                'success' => false,
                'message' => 'Could not create task. The tasks table may be missing lead_id column — reload an admin page once to auto-migrate.',
            ]));
        }
    }

    default:
        http_response_code(400);
        exit(json_encode(['success' => false, 'message' => 'Unknown action.']));
}
