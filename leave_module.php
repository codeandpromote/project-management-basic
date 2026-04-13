<?php
// ============================================================
//  HRMS · Leave Management
//  Employees: submit requests, view history
//  Admin: review, approve/reject with remarks
// ============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
requireLogin();

$user = getCurrentUser();
$db   = getDB();
$uid  = (int)$user['id'];
$role = $user['role'];

$success = '';
$error   = '';

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Security token invalid.';
    } else {
        $formAction = $_POST['form_action'] ?? '';

        // ── Employee: Submit Leave Request ────────────────────
        if ($formAction === 'request_leave') {
            $startDate = $_POST['start_date'] ?? '';
            $endDate   = $_POST['end_date']   ?? '';
            $type      = $_POST['type']       ?? '';
            $reason    = trim($_POST['reason'] ?? '');
            $validTypes = ['annual','sick','personal','maternity','paternity','emergency','unpaid'];

            if (!$startDate || !$endDate || !$type || !$reason) {
                $error = 'All fields are required.';
            } elseif (!in_array($type, $validTypes, true)) {
                $error = 'Invalid leave type.';
            } elseif ($endDate < $startDate) {
                $error = 'End date cannot be before start date.';
            } else {
                // Check for overlapping pending/approved requests
                $stOverlap = $db->prepare(
                    "SELECT id FROM leave_requests
                     WHERE user_id = ? AND status IN ('pending','approved')
                       AND NOT (end_date < ? OR start_date > ?) LIMIT 1"
                );
                $stOverlap->execute([$uid, $startDate, $endDate]);
                if ($stOverlap->fetch()) {
                    $error = 'You already have a leave request overlapping those dates.';
                } else {
                    $db->prepare(
                        "INSERT INTO leave_requests
                         (user_id, start_date, end_date, type, reason, status)
                         VALUES (?, ?, ?, ?, ?, 'pending')"
                    )->execute([$uid, $startDate, $endDate, $type, $reason]);
                    $success = 'Leave request submitted successfully.';
                }
            }
        }

        // ── Admin: Approve / Reject ───────────────────────────
        if ($formAction === 'review_leave' && $role === 'admin') {
            $leaveId  = (int)($_POST['leave_id']    ?? 0);
            $decision = $_POST['decision']           ?? '';
            $remark   = trim($_POST['admin_remark'] ?? '');

            if (!$leaveId || !in_array($decision, ['approved','rejected'], true)) {
                $error = 'Invalid review data.';
            } elseif (empty($remark)) {
                $error = 'Admin remark is required.';
            } else {
                $now = date('Y-m-d H:i:s');
                $db->prepare(
                    "UPDATE leave_requests
                     SET status=?, admin_remark=?, reviewed_by=?, reviewed_at=?
                     WHERE id=? AND status='pending'"
                )->execute([$decision, $remark, $uid, $now, $leaveId]);
                $success = 'Leave request ' . $decision . '.';
            }
        }
    }
}

// ── Fetch data ────────────────────────────────────────────────
if ($role === 'admin') {
    // All leave requests
    $stAll = $db->query(
        "SELECT lr.*, u.name AS employee_name, r.name AS reviewer_name
           FROM leave_requests lr
           JOIN users u ON u.id = lr.user_id
           LEFT JOIN users r ON r.id = lr.reviewed_by
          ORDER BY FIELD(lr.status,'pending','approved','rejected'), lr.created_at DESC"
    );
    $allLeaves = $stAll->fetchAll();
} else {
    // Own requests only
    $stMy = $db->prepare(
        "SELECT lr.*, r.name AS reviewer_name
           FROM leave_requests lr
           LEFT JOIN users r ON r.id = lr.reviewed_by
          WHERE lr.user_id = ?
          ORDER BY lr.created_at DESC"
    );
    $stMy->execute([$uid]);
    $myLeaves = $stMy->fetchAll();
}

function leaveBadge(string $status): string {
    return match($status) {
        'pending'  => '<span class="badge bg-warning text-dark">Pending</span>',
        'approved' => '<span class="badge bg-success">Approved</span>',
        'rejected' => '<span class="badge bg-danger">Rejected</span>',
        default    => '<span class="badge bg-secondary">' . h(ucfirst($status)) . '</span>',
    };
}

$pageTitle = 'Leave Management';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <h5 class="fw-bold mb-0">
    <i class="bi bi-calendar2-check me-2 text-primary"></i>
    <?= $role === 'admin' ? 'Review Leave Requests' : 'My Leave Requests' ?>
  </h5>
  <?php if ($role !== 'admin'): ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#requestLeaveModal">
    <i class="bi bi-plus-lg me-2"></i>Request Leave
  </button>
  <?php endif; ?>
</div>

<!-- Alerts -->
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible d-flex gap-2 mb-4">
  <i class="bi bi-check-circle-fill flex-shrink-0 mt-1"></i><div><?= h($success) ?></div>
  <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible d-flex gap-2 mb-4">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i><div><?= h($error) ?></div>
  <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Admin view ─────────────────────────────────────────────── -->
<?php if ($role === 'admin'): ?>

<!-- Tabs: Pending first -->
<?php
$pending  = array_filter($allLeaves, fn($l) => $l['status'] === 'pending');
$reviewed = array_filter($allLeaves, fn($l) => $l['status'] !== 'pending');
?>
<ul class="nav nav-tabs mb-3" id="leaveTabs">
  <li class="nav-item">
    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-pending">
      Pending
      <?php if ($c = count($pending)): ?>
      <span class="badge bg-danger ms-1"><?= $c ?></span>
      <?php endif; ?>
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-reviewed">
      Reviewed (<?= count($reviewed) ?>)
    </button>
  </li>
</ul>

<div class="tab-content">

  <!-- Pending -->
  <div class="tab-pane fade show active" id="tab-pending">
    <?php if (empty($pending)): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-check2-all fs-1 d-block mb-2 opacity-25"></i>
        No pending leave requests.
      </div>
    </div>
    <?php else: ?>
    <div class="row g-3">
      <?php foreach ($pending as $leave): ?>
      <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm border-start border-4 border-warning">
          <div class="card-body">
            <div class="d-flex align-items-start justify-content-between mb-2">
              <div>
                <div class="fw-semibold"><?= h($leave['employee_name']) ?></div>
                <div class="small text-muted">
                  <?= h(ucfirst($leave['type'])) ?> Leave &middot;
                  <?= h(date('d M Y', strtotime($leave['start_date']))) ?>
                  &ndash;
                  <?= h(date('d M Y', strtotime($leave['end_date']))) ?>
                  <?php
                    $days = (int)((strtotime($leave['end_date']) - strtotime($leave['start_date'])) / 86400) + 1;
                  ?>
                  <span class="badge bg-secondary ms-1"><?= $days ?> day<?= $days > 1 ? 's' : '' ?></span>
                </div>
              </div>
              <?= leaveBadge($leave['status']) ?>
            </div>
            <div class="small text-muted border-top pt-2 mt-2">
              <strong class="text-dark">Reason:</strong> <?= h($leave['reason']) ?>
            </div>
            <!-- Review form -->
            <form method="POST" action="leave_module.php" class="mt-3">
              <?= csrfField() ?>
              <input type="hidden" name="form_action" value="review_leave">
              <input type="hidden" name="leave_id" value="<?= (int)$leave['id'] ?>">
              <div class="mb-2">
                <label class="form-label small fw-semibold">Admin Remark <span class="text-danger">*</span></label>
                <textarea class="form-control form-control-sm" name="admin_remark"
                          rows="2" placeholder="Enter your decision rationale…" required></textarea>
              </div>
              <div class="d-flex gap-2">
                <button type="submit" name="decision" value="approved"
                        class="btn btn-sm btn-success flex-grow-1"
                        onclick="return confirm('Approve this leave?')">
                  <i class="bi bi-check-circle me-1"></i>Approve
                </button>
                <button type="submit" name="decision" value="rejected"
                        class="btn btn-sm btn-danger flex-grow-1"
                        onclick="return confirm('Reject this leave?')">
                  <i class="bi bi-x-circle me-1"></i>Reject
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Reviewed -->
  <div class="tab-pane fade" id="tab-reviewed">
    <div class="card border-0 shadow-sm">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-3">Employee</th>
              <th>Type</th>
              <th>Dates</th>
              <th>Status</th>
              <th>Reviewer</th>
              <th class="pe-3">Remark</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($reviewed)): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No reviewed requests yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($reviewed as $l): ?>
            <tr>
              <td class="ps-3 small fw-semibold"><?= h($l['employee_name']) ?></td>
              <td class="small"><?= h(ucfirst($l['type'])) ?></td>
              <td class="small">
                <?= h(date('d M', strtotime($l['start_date']))) ?>
                &ndash;
                <?= h(date('d M Y', strtotime($l['end_date']))) ?>
              </td>
              <td><?= leaveBadge($l['status']) ?></td>
              <td class="small"><?= h($l['reviewer_name'] ?? '—') ?></td>
              <td class="pe-3 small text-muted"><?= h($l['admin_remark'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /tab-content -->

<?php else: // ── Employee view ──────────────────────────────────── ?>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="ps-3">Type</th>
          <th>Dates</th>
          <th>Days</th>
          <th>Reason</th>
          <th>Status</th>
          <th class="pe-3">Admin Remark</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($myLeaves)): ?>
        <tr>
          <td colspan="6" class="text-center py-5 text-muted">
            <i class="bi bi-calendar-x fs-1 d-block mb-2 opacity-25"></i>
            You have not submitted any leave requests yet.
          </td>
        </tr>
        <?php endif; ?>
        <?php foreach ($myLeaves as $l):
          $days = (int)((strtotime($l['end_date']) - strtotime($l['start_date'])) / 86400) + 1;
        ?>
        <tr>
          <td class="ps-3 fw-semibold small"><?= h(ucfirst($l['type'])) ?></td>
          <td class="small">
            <?= h(date('d M Y', strtotime($l['start_date']))) ?>
            &ndash;
            <?= h(date('d M Y', strtotime($l['end_date']))) ?>
          </td>
          <td><span class="badge bg-secondary"><?= $days ?></span></td>
          <td class="small text-muted"><?= h(mb_strimwidth($l['reason'], 0, 60, '…')) ?></td>
          <td><?= leaveBadge($l['status']) ?></td>
          <td class="pe-3 small text-muted fst-italic">
            <?= $l['admin_remark'] ? h($l['admin_remark']) : '—' ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>

<!-- ── Leave Request Modal (employees) ─────────────────────── -->
<?php if ($role !== 'admin'): ?>
<div class="modal fade" id="requestLeaveModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header">
        <h6 class="modal-title fw-bold">
          <i class="bi bi-calendar-plus me-2 text-primary"></i>Request Leave
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="leave_module.php">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="request_leave">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-sm-6">
              <label class="form-label fw-semibold small">Start Date <span class="text-danger">*</span></label>
              <input type="date" class="form-control" name="start_date"
                     min="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold small">End Date <span class="text-danger">*</span></label>
              <input type="date" class="form-control" name="end_date"
                     min="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small">Leave Type <span class="text-danger">*</span></label>
              <select name="type" class="form-select" required>
                <option value="">— Select type —</option>
                <option value="annual">Annual Leave</option>
                <option value="sick">Sick Leave</option>
                <option value="personal">Personal Leave</option>
                <option value="maternity">Maternity Leave</option>
                <option value="paternity">Paternity Leave</option>
                <option value="emergency">Emergency Leave</option>
                <option value="unpaid">Unpaid Leave</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small">Reason <span class="text-danger">*</span></label>
              <textarea class="form-control" name="reason" rows="3"
                        placeholder="Brief explanation of your leave reason…" required></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-send me-2"></i>Submit Request
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
