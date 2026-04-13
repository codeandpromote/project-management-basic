<?php
// ============================================================
//  HRMS · Employee Task History  (Admin only)
// ============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
requireRole('admin');

$user = getCurrentUser();
$db   = getDB();

// ── Filters ───────────────────────────────────────────────────
$filterUid    = (int)($_GET['user_id']   ?? 0);
$filterType   = $_GET['task_type']       ?? '';
$filterStatus = $_GET['status']          ?? '';
$filterFrom   = $_GET['date_from']       ?? date('Y-m-d', strtotime('-30 days'));
$filterTo     = $_GET['date_to']         ?? date('Y-m-d');
$filterMode   = $_GET['date_mode']       ?? 'deadline'; // deadline | completed_at

// Sanitise dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) $filterFrom = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo))   $filterTo   = date('Y-m-d');
if (!in_array($filterMode, ['deadline', 'completed_at'], true)) $filterMode = 'deadline';

// ── All staff for dropdown ────────────────────────────────────
$staffUsers = $db->query(
    "SELECT id, name, role FROM users WHERE role != 'admin' AND is_active = 1 ORDER BY name"
)->fetchAll();

// ── Build query ───────────────────────────────────────────────
$where  = [];
$params = [];

if ($filterUid) {
    $where[]  = 't.user_id = ?';
    $params[] = $filterUid;
}
if ($filterType && in_array($filterType, ['daily','weekly','monthly'], true)) {
    $where[]  = 't.task_type = ?';
    $params[] = $filterType;
}
if ($filterStatus && in_array($filterStatus, ['pending','in_progress','completed','overdue'], true)) {
    if ($filterStatus === 'overdue') {
        $where[] = "t.status != 'completed' AND t.deadline < NOW()";
    } else {
        $where[]  = 't.status = ?';
        $params[] = $filterStatus;
    }
}

// Date range on deadline or completion date
$dateCol = $filterMode === 'completed_at' ? 'DATE(t.completed_at)' : 'DATE(t.deadline)';
$where[]  = "{$dateCol} BETWEEN ? AND ?";
$params[] = $filterFrom;
$params[] = $filterTo;

$orderCol = $filterMode === 'completed_at' ? 't.completed_at' : 't.deadline';

$sql = "SELECT t.*, u.name AS assignee_name, c.name AS creator_name
          FROM tasks t
          JOIN users u ON u.id = t.user_id
          JOIN users c ON c.id = t.creator_id"
     . ' WHERE ' . implode(' AND ', $where)
     . " ORDER BY {$orderCol} DESC, u.name ASC";

$stTasks = $db->prepare($sql);
$stTasks->execute($params);
$allTasks = $stTasks->fetchAll();

// ── Summary counts ────────────────────────────────────────────
$counts = ['completed' => 0, 'in_progress' => 0, 'pending' => 0, 'overdue' => 0];
foreach ($allTasks as $t) {
    $isOverdue = $t['status'] !== 'completed' && $t['deadline'] && strtotime($t['deadline']) < time();
    $key = $isOverdue ? 'overdue' : $t['status'];
    if (isset($counts[$key])) $counts[$key]++;
}

// ── CSV export — before HTML ──────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="task_history_' . $filterFrom . '_to_' . $filterTo . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Employee','Task','Type','Status','Deadline','Completed At','Completion Notes','Has Proof']);
    foreach ($allTasks as $t) {
        $isOverdue  = $t['status'] !== 'completed' && $t['deadline'] && strtotime($t['deadline']) < time();
        $dispStatus = $isOverdue ? 'Overdue' : ucfirst(str_replace('_', ' ', $t['status']));
        fputcsv($out, [
            $t['assignee_name'],
            $t['title'],
            ucfirst($t['task_type']),
            $dispStatus,
            $t['deadline']     ? date('d M Y h:i A', strtotime($t['deadline']))     : '',
            $t['completed_at'] ? date('d M Y h:i A', strtotime($t['completed_at'])) : '',
            $t['completion_notes'] ?? '',
            $t['proof_file'] ? 'Yes' : 'No',
        ]);
    }
    fclose($out);
    exit;
}

function statusBadge(string $status): string {
    return match($status) {
        'pending'     => '<span class="badge bg-warning text-dark">Pending</span>',
        'in_progress' => '<span class="badge bg-info text-dark">In Progress</span>',
        'completed'   => '<span class="badge bg-success">Completed</span>',
        'overdue'     => '<span class="badge bg-danger">Overdue</span>',
        default       => '<span class="badge bg-secondary">' . h(ucfirst($status)) . '</span>',
    };
}

$pageTitle = 'Task History';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <h5 class="fw-bold mb-0">
    <i class="bi bi-clock-history me-2 text-primary"></i>Employee Task History
  </h5>
  <a href="?user_id=<?= $filterUid ?>&task_type=<?= h($filterType) ?>&status=<?= h($filterStatus) ?>&date_from=<?= h($filterFrom) ?>&date_to=<?= h($filterTo) ?>&date_mode=<?= h($filterMode) ?>&export=csv"
     class="btn btn-outline-success btn-sm">
    <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
  </a>
</div>

<!-- ── Filter Bar ─────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-6 col-lg-3">
        <label class="form-label small fw-semibold">Employee</label>
        <select name="user_id" class="form-select form-select-sm">
          <option value="">All Employees</option>
          <?php foreach ($staffUsers as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $filterUid === (int)$u['id'] ? 'selected' : '' ?>>
            <?= h($u['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6 col-lg-2">
        <label class="form-label small fw-semibold">Task Type</label>
        <select name="task_type" class="form-select form-select-sm">
          <option value="">All Types</option>
          <option value="daily"   <?= $filterType === 'daily'   ? 'selected' : '' ?>>Daily</option>
          <option value="weekly"  <?= $filterType === 'weekly'  ? 'selected' : '' ?>>Weekly</option>
          <option value="monthly" <?= $filterType === 'monthly' ? 'selected' : '' ?>>Monthly</option>
        </select>
      </div>
      <div class="col-sm-6 col-lg-2">
        <label class="form-label small fw-semibold">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option value="completed"   <?= $filterStatus === 'completed'   ? 'selected' : '' ?>>Completed</option>
          <option value="pending"     <?= $filterStatus === 'pending'     ? 'selected' : '' ?>>Pending</option>
          <option value="in_progress" <?= $filterStatus === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
          <option value="overdue"     <?= $filterStatus === 'overdue'     ? 'selected' : '' ?>>Overdue</option>
        </select>
      </div>
      <div class="col-sm-6 col-lg-2">
        <label class="form-label small fw-semibold">Filter By Date Of</label>
        <select name="date_mode" class="form-select form-select-sm">
          <option value="deadline"     <?= $filterMode === 'deadline'     ? 'selected' : '' ?>>Deadline</option>
          <option value="completed_at" <?= $filterMode === 'completed_at' ? 'selected' : '' ?>>Completion Date</option>
        </select>
      </div>
      <div class="col-sm-6 col-lg-3">
        <label class="form-label small fw-semibold">Date Range</label>
        <div class="input-group input-group-sm">
          <input type="date" class="form-control form-control-sm" name="date_from"
                 value="<?= h($filterFrom) ?>" max="<?= date('Y-m-d') ?>">
          <span class="input-group-text">to</span>
          <input type="date" class="form-control form-control-sm" name="date_to"
                 value="<?= h($filterTo) ?>" max="<?= date('Y-m-d') ?>">
        </div>
      </div>
      <div class="col-12 col-lg-auto d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm">
          <i class="bi bi-funnel me-1"></i>Filter
        </button>
        <a href="admin_task_history.php" class="btn btn-outline-secondary btn-sm">Reset</a>
      </div>
      <!-- Quick date presets -->
      <div class="col-12 d-flex flex-wrap gap-1 pt-1">
        <span class="small text-muted me-1 align-self-center">Quick:</span>
        <?php
        $presets = [
            'Today'      => [date('Y-m-d'), date('Y-m-d')],
            'Yesterday'  => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
            'Last 7 days'=> [date('Y-m-d', strtotime('-7 days')), date('Y-m-d')],
            'Last 30 days'=>[date('Y-m-d', strtotime('-30 days')), date('Y-m-d')],
            'This Month' => [date('Y-m-01'), date('Y-m-d')],
        ];
        foreach ($presets as $label => [$from, $to]):
        ?>
        <a href="?user_id=<?= $filterUid ?>&task_type=<?= h($filterType) ?>&status=<?= h($filterStatus) ?>&date_mode=<?= h($filterMode) ?>&date_from=<?= $from ?>&date_to=<?= $to ?>"
           class="btn btn-xs btn-outline-secondary <?= ($filterFrom === $from && $filterTo === $to) ? 'active' : '' ?>">
          <?= $label ?>
        </a>
        <?php endforeach; ?>
      </div>
    </form>
  </div>
</div>

<!-- ── Summary Cards ──────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-check-circle-fill"></i></div>
        <div>
          <div class="stat-value"><?= $counts['completed'] ?></div>
          <div class="stat-label">Completed</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-danger-subtle text-danger"><i class="bi bi-clock-fill"></i></div>
        <div>
          <div class="stat-value"><?= $counts['overdue'] ?></div>
          <div class="stat-label">Overdue</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-info-subtle text-info"><i class="bi bi-play-circle-fill"></i></div>
        <div>
          <div class="stat-value"><?= $counts['in_progress'] ?></div>
          <div class="stat-label">In Progress</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-hourglass-split"></i></div>
        <div>
          <div class="stat-value"><?= $counts['pending'] ?></div>
          <div class="stat-label">Pending</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── Task Table ──────────────────────────────────────────── -->
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent border-bottom d-flex align-items-center gap-2">
    <i class="bi bi-table text-primary"></i>
    <span class="fw-semibold">
      <?= count($allTasks) ?> task<?= count($allTasks) !== 1 ? 's' : '' ?> found
    </span>
    <span class="text-muted small ms-1">
      (<?= $filterMode === 'completed_at' ? 'completed' : 'deadline' ?> between
       <strong><?= h(date('d M Y', strtotime($filterFrom))) ?></strong> and
       <strong><?= h(date('d M Y', strtotime($filterTo))) ?></strong>)
    </span>
  </div>
  <div class="card-body p-0">
    <?php if (empty($allTasks)): ?>
    <div class="text-center text-muted py-5">
      <i class="bi bi-inbox fs-1 d-block mb-2 opacity-25"></i>
      No tasks match the selected filters.
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Task</th>
            <th>Employee</th>
            <th>Type</th>
            <th>Status</th>
            <th>Deadline</th>
            <th>Completed At</th>
            <th>Completion Notes</th>
            <th class="text-center pe-3">Files</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($allTasks as $t):
            $isOverdue  = $t['status'] !== 'completed'
                       && $t['deadline']
                       && strtotime($t['deadline']) < time();
            $dispStatus = $isOverdue ? 'overdue' : $t['status'];
          ?>
          <tr class="<?= $isOverdue ? 'table-danger' : ($t['status'] === 'completed' ? 'table-success bg-opacity-25' : '') ?>">
            <td class="ps-3" style="max-width:220px">
              <div class="fw-semibold small"><?= h($t['title']) ?></div>
              <?php if ($t['description']): ?>
              <div class="text-muted" style="font-size:.72rem">
                <?= h(mb_strimwidth($t['description'], 0, 80, '…')) ?>
              </div>
              <?php endif; ?>
            </td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="avatar-circle avatar-sm flex-shrink-0">
                  <?= strtoupper(substr($t['assignee_name'], 0, 1)) ?>
                </div>
                <div class="small"><?= h($t['assignee_name']) ?></div>
              </div>
            </td>
            <td>
              <span class="badge bg-primary-subtle text-primary"><?= ucfirst($t['task_type']) ?></span>
            </td>
            <td><?= statusBadge($dispStatus) ?></td>
            <td class="small <?= $isOverdue ? 'text-danger fw-semibold' : '' ?>">
              <?= $t['deadline'] ? h(date('d M Y', strtotime($t['deadline']))) : '—' ?>
              <?php if ($t['deadline']): ?>
              <div class="text-muted fw-normal" style="font-size:.7rem">
                <?= h(date('h:i A', strtotime($t['deadline']))) ?>
              </div>
              <?php endif; ?>
            </td>
            <td class="small">
              <?php if ($t['completed_at']): ?>
              <span class="text-success fw-semibold"><?= h(date('d M Y', strtotime($t['completed_at']))) ?></span>
              <div class="text-muted" style="font-size:.7rem">
                <?= h(date('h:i A', strtotime($t['completed_at']))) ?>
              </div>
              <?php else: ?>
              <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td style="max-width:200px">
              <?php if ($t['completion_notes']): ?>
              <div class="small text-muted fst-italic" style="font-size:.75rem">
                "<?= h(mb_strimwidth($t['completion_notes'], 0, 100, '…')) ?>"
              </div>
              <?php else: ?>
              <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center pe-3">
              <?php if ($t['file_path']): ?>
              <a href="download.php?path=<?= urlencode($t['file_path']) ?>" target="_blank"
                 class="btn btn-xs btn-outline-secondary me-1" title="Task attachment">
                <i class="bi bi-paperclip"></i>
              </a>
              <?php endif; ?>
              <?php if ($t['proof_file']): ?>
              <a href="download.php?path=<?= urlencode($t['proof_file']) ?>" target="_blank"
                 class="btn btn-xs btn-outline-success" title="Proof of completion">
                <i class="bi bi-file-check"></i>
              </a>
              <?php endif; ?>
              <?php if (!$t['file_path'] && !$t['proof_file']): ?>
              <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
