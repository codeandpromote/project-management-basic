<?php
// ============================================================
//  HRMS · Data Maintenance & Purge  (Admin only)
// ============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
requireRole('admin');

$user = getCurrentUser();
$db   = getDB();

$success = '';
$error   = '';
$preview = null;

// ── Preview or Execute ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Security token invalid.';
    } else {
        $months     = max(1, (int)($_POST['months'] ?? 3));
        $cutoffDate = date('Y-m-d', strtotime("-{$months} months"));
        $action     = $_POST['purge_action'] ?? 'preview';

        if ($action === 'preview') {
            // Count what would be deleted
            $stT = $db->prepare('SELECT COUNT(*) FROM tasks WHERE DATE(created_at) < ?');
            $stT->execute([$cutoffDate]);
            $stA = $db->prepare('SELECT COUNT(*) FROM attendance WHERE work_date < ?');
            $stA->execute([$cutoffDate]);
            $stL = $db->prepare('SELECT COUNT(*) FROM leave_requests WHERE DATE(created_at) < ?');
            $stL->execute([$cutoffDate]);

            $preview = [
                'months'      => $months,
                'cutoff'      => $cutoffDate,
                'tasks'       => (int)$stT->fetchColumn(),
                'attendance'  => (int)$stA->fetchColumn(),
                'leaves'      => (int)$stL->fetchColumn(),
            ];
        } elseif ($action === 'execute') {
            // Confirm token prevents direct URL execution
            if (($_POST['confirm_purge'] ?? '') !== 'PURGE') {
                $error = 'Type PURGE in the confirmation field to proceed.';
            } else {
                $deleted = [];

                // Delete tasks and their physical files
                $stTasks = $db->prepare(
                    'SELECT id, file_path, proof_file FROM tasks WHERE DATE(created_at) < ?'
                );
                $stTasks->execute([$cutoffDate]);
                $taskRows = $stTasks->fetchAll();
                foreach ($taskRows as $t) {
                    foreach (['file_path', 'proof_file'] as $col) {
                        if (!empty($t[$col])) {
                            $fp = UPLOAD_PATH . $t[$col];
                            if (file_exists($fp)) { unlink($fp); }
                        }
                    }
                }
                $stDel = $db->prepare('DELETE FROM tasks WHERE DATE(created_at) < ?');
                $stDel->execute([$cutoffDate]);
                $deleted['tasks'] = $stDel->rowCount();

                // Delete day-end files from attendance
                $stAFiles = $db->prepare(
                    'SELECT day_end_file FROM attendance WHERE work_date < ? AND day_end_file IS NOT NULL'
                );
                $stAFiles->execute([$cutoffDate]);
                foreach ($stAFiles->fetchAll() as $row) {
                    $fp = UPLOAD_PATH . $row['day_end_file'];
                    if (file_exists($fp)) { unlink($fp); }
                }
                $stDelA = $db->prepare('DELETE FROM attendance WHERE work_date < ?');
                $stDelA->execute([$cutoffDate]);
                $deleted['attendance'] = $stDelA->rowCount();

                // Delete leave requests
                $stDelL = $db->prepare('DELETE FROM leave_requests WHERE DATE(created_at) < ?');
                $stDelL->execute([$cutoffDate]);
                $deleted['leaves'] = $stDelL->rowCount();

                $success = sprintf(
                    'Purge complete: %d task(s), %d attendance record(s), and %d leave request(s) deleted (older than %s).',
                    $deleted['tasks'], $deleted['attendance'], $deleted['leaves'],
                    date('d M Y', strtotime($cutoffDate))
                );
            }
        }
    }
}

$pageTitle = 'Data Maintenance';
include __DIR__ . '/includes/header.php';
?>

<div class="mb-4">
  <h5 class="fw-bold mb-1"><i class="bi bi-trash3 me-2 text-danger"></i>Data Maintenance</h5>
  <p class="text-muted small mb-0">
    Permanently delete tasks, attendance records, and leave requests older than a specified number of months.
    This action is <strong>irreversible</strong>. Always back up your database first.
  </p>
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

<div class="row g-4">

  <!-- ── Preview Form ──────────────────────────────────────── -->
  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-transparent border-bottom fw-semibold">
        <i class="bi bi-eye me-2"></i>Step 1 — Preview Records
      </div>
      <div class="card-body">
        <form method="POST" action="data_purge.php">
          <?= csrfField() ?>
          <input type="hidden" name="purge_action" value="preview">
          <div class="mb-4">
            <label class="form-label fw-semibold">Delete records older than</label>
            <div class="input-group">
              <input type="number" class="form-control" name="months"
                     value="<?= isset($preview) ? (int)$preview['months'] : 3 ?>"
                     min="1" max="120" required>
              <span class="input-group-text">month(s)</span>
            </div>
            <div class="form-text">
              Cutoff: records created before
              <strong id="cutoffPreview"><?= date('d M Y', strtotime('-3 months')) ?></strong>
              will be deleted.
            </div>
          </div>
          <button type="submit" class="btn btn-outline-primary w-100">
            <i class="bi bi-search me-2"></i>Preview Affected Records
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Preview Results + Execute ────────────────────────── -->
  <div class="col-12 col-lg-7">
    <?php if ($preview): ?>
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-transparent border-bottom fw-semibold">
        <i class="bi bi-clipboard-data me-2 text-warning"></i>
        Preview — Records older than <?= h(date('d M Y', strtotime($preview['cutoff']))) ?>
      </div>
      <div class="card-body">
        <div class="row g-3 mb-4">
          <div class="col-4 text-center">
            <div class="fs-2 fw-bold text-danger"><?= $preview['tasks'] ?></div>
            <div class="small text-muted">Tasks<br>(+ files)</div>
          </div>
          <div class="col-4 text-center">
            <div class="fs-2 fw-bold text-danger"><?= $preview['attendance'] ?></div>
            <div class="small text-muted">Attendance<br>Records</div>
          </div>
          <div class="col-4 text-center">
            <div class="fs-2 fw-bold text-danger"><?= $preview['leaves'] ?></div>
            <div class="small text-muted">Leave<br>Requests</div>
          </div>
        </div>

        <?php
        $totalRecords = $preview['tasks'] + $preview['attendance'] + $preview['leaves'];
        ?>
        <?php if ($totalRecords === 0): ?>
        <div class="alert alert-info mb-0">
          <i class="bi bi-info-circle me-2"></i>
          No records match this cutoff date. Nothing to delete.
        </div>
        <?php else: ?>
        <div class="alert alert-danger mb-3">
          <i class="bi bi-exclamation-octagon-fill me-2"></i>
          <strong><?= $totalRecords ?> total record(s)</strong> will be permanently deleted,
          along with all associated uploaded files.
        </div>

        <!-- Execute form -->
        <form method="POST" action="data_purge.php">
          <?= csrfField() ?>
          <input type="hidden" name="purge_action" value="execute">
          <input type="hidden" name="months" value="<?= (int)$preview['months'] ?>">
          <div class="mb-3">
            <label class="form-label fw-semibold small">
              Type <code>PURGE</code> to confirm deletion
            </label>
            <input type="text" class="form-control" name="confirm_purge"
                   placeholder="PURGE" required autocomplete="off"
                   pattern="PURGE" title="Must type PURGE exactly">
          </div>
          <button type="submit" class="btn btn-danger w-100 fw-semibold"
                  onclick="return confirm('This will permanently delete <?= $totalRecords ?> records. Are you absolutely sure?')">
            <i class="bi bi-trash3-fill me-2"></i>Execute Purge
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php else: ?>
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex flex-column align-items-center justify-content-center text-muted py-5">
        <i class="bi bi-shield-check fs-1 mb-3 opacity-25"></i>
        <p class="mb-0 small text-center">
          Use the form on the left to preview records that would be deleted.<br>
          You will be asked to confirm before any data is removed.
        </p>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>

<script>
// Live cutoff date preview
const monthsInput  = document.querySelector('input[name="months"]');
const cutoffEl     = document.getElementById('cutoffPreview');
if (monthsInput && cutoffEl) {
  monthsInput.addEventListener('input', function() {
    const m    = parseInt(this.value) || 1;
    const date = new Date();
    date.setMonth(date.getMonth() - m);
    cutoffEl.textContent = date.toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'});
  });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
