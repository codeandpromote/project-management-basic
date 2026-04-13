<?php
// ============================================================
//  HRMS · Task Manager  (Admin only)
// ============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
requireRole('admin');

$user = getCurrentUser();
$db   = getDB();

$success = '';
$error   = '';

// ── Create Task (POST) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'create_task') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Security token invalid. Please try again.';
    } else {
        $title     = trim($_POST['title']       ?? '');
        $desc      = trim($_POST['description'] ?? '');
        $assignee  = (int)($_POST['user_id']    ?? 0);
        $type      = $_POST['task_type']        ?? 'daily';
        $deadline  = $_POST['deadline']         ?? '';

        if (!$title || !$assignee || !$deadline) {
            $error = 'Title, assigned user, and deadline are required.';
        } elseif (!in_array($type, ['daily','weekly','monthly'], true)) {
            $error = 'Invalid task type.';
        } else {
            // Upload attachment
            $filePath = null;
            if (!empty($_FILES['attachment']['name'])) {
                $upload = handleFileUpload('attachment', 'tasks');
                if (!$upload['success']) {
                    $error = $upload['message'];
                } else {
                    $filePath = $upload['path'];
                }
            }

            if (!$error) {
                $db->prepare(
                    "INSERT INTO tasks (creator_id, user_id, title, description,
                     file_path, task_type, deadline, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')"
                )->execute([
                    $user['id'], $assignee, $title, $desc ?: null,
                    $filePath, $type, $deadline
                ]);
                $success = 'Task "' . htmlspecialchars($title, ENT_QUOTES) . '" assigned successfully.';
            }
        }
    }
}

// ── Fetch all staff users ─────────────────────────────────────
$staffUsers = $db->query(
    "SELECT id, name, role FROM users WHERE role != 'admin' AND is_active = 1 ORDER BY name"
)->fetchAll();

// ── Fetch all tasks (with filters) ───────────────────────────
$filterUser   = (int)($_GET['user_id']   ?? 0);
$filterType   = $_GET['task_type']       ?? '';
$filterStatus = $_GET['status']          ?? '';

$where  = [];
$params = [];

if ($filterUser) {
    $where[] = 't.user_id = ?';
    $params[] = $filterUser;
}
if ($filterType && in_array($filterType, ['daily','weekly','monthly'], true)) {
    $where[] = 't.task_type = ?';
    $params[] = $filterType;
}
if ($filterStatus && in_array($filterStatus, ['pending','in_progress','completed','overdue'], true)) {
    if ($filterStatus === 'overdue') {
        $where[] = "t.status != 'completed' AND t.deadline < NOW()";
    } else {
        $where[] = 't.status = ?';
        $params[] = $filterStatus;
    }
}

$sql = "SELECT t.*, u.name AS assignee_name, c.name AS creator_name
        FROM tasks t
        JOIN users u ON u.id = t.user_id
        JOIN users c ON c.id = t.creator_id"
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . ' ORDER BY t.created_at DESC';

$stTasks = $db->prepare($sql);
$stTasks->execute($params);
$allTasks = $stTasks->fetchAll();

function statusBadge(string $status): string {
    return match($status) {
        'pending'     => '<span class="badge bg-warning text-dark">Pending</span>',
        'in_progress' => '<span class="badge bg-info text-dark">In Progress</span>',
        'completed'   => '<span class="badge bg-success">Completed</span>',
        'overdue'     => '<span class="badge bg-danger">Overdue</span>',
        default       => '<span class="badge bg-secondary">' . h(ucfirst($status)) . '</span>',
    };
}

$pageTitle = 'Task Manager';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <h5 class="fw-bold mb-0">Task Manager</h5>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTaskModal">
    <i class="bi bi-plus-lg me-2"></i>Assign New Task
  </button>
</div>

<!-- Alerts -->
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible d-flex gap-2 align-items-center mb-4">
  <i class="bi bi-check-circle-fill"></i><div><?= h($success) ?></div>
  <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible d-flex gap-2 align-items-center mb-4">
  <i class="bi bi-exclamation-triangle-fill"></i><div><?= h($error) ?></div>
  <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Filter Bar -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body">
    <form method="GET" action="tasks.php" class="row g-2 align-items-end">
      <div class="col-sm-6 col-lg-3">
        <label class="form-label small fw-semibold">Employee</label>
        <select name="user_id" class="form-select form-select-sm">
          <option value="">All Employees</option>
          <?php foreach ($staffUsers as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $filterUser === (int)$u['id'] ? 'selected' : '' ?>>
            <?= h($u['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6 col-lg-3">
        <label class="form-label small fw-semibold">Task Type</label>
        <select name="task_type" class="form-select form-select-sm">
          <option value="">All Types</option>
          <option value="daily"   <?= $filterType==='daily'   ? 'selected':'' ?>>Daily</option>
          <option value="weekly"  <?= $filterType==='weekly'  ? 'selected':'' ?>>Weekly</option>
          <option value="monthly" <?= $filterType==='monthly' ? 'selected':'' ?>>Monthly</option>
        </select>
      </div>
      <div class="col-sm-6 col-lg-3">
        <label class="form-label small fw-semibold">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option value="pending"     <?= $filterStatus==='pending'     ? 'selected':'' ?>>Pending</option>
          <option value="in_progress" <?= $filterStatus==='in_progress' ? 'selected':'' ?>>In Progress</option>
          <option value="completed"   <?= $filterStatus==='completed'   ? 'selected':'' ?>>Completed</option>
          <option value="overdue"     <?= $filterStatus==='overdue'     ? 'selected':'' ?>>Overdue</option>
        </select>
      </div>
      <div class="col-sm-6 col-lg-3 d-flex gap-2">
        <button type="submit" class="btn btn-outline-primary btn-sm flex-grow-1">
          <i class="bi bi-funnel me-1"></i>Filter
        </button>
        <a href="tasks.php" class="btn btn-outline-secondary btn-sm">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Tasks Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Task</th>
            <th>Assigned To</th>
            <th>Type</th>
            <th>Deadline</th>
            <th>Status</th>
            <th class="text-center">Files</th>
            <th class="pe-3 text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($allTasks)): ?>
          <tr>
            <td colspan="7" class="text-center text-muted py-5">
              <i class="bi bi-inbox fs-1 d-block mb-2 opacity-25"></i>
              No tasks found.
            </td>
          </tr>
          <?php endif; ?>
          <?php foreach ($allTasks as $t):
            $isOverdue = $t['status'] !== 'completed'
                      && $t['deadline']
                      && strtotime($t['deadline']) < time();
            $dispStatus = $isOverdue ? 'overdue' : $t['status'];
          ?>
          <tr>
            <td class="ps-3">
              <div class="fw-semibold small"><?= h($t['title']) ?></div>
              <?php if ($t['description']): ?>
              <div class="text-muted" style="font-size:.72rem">
                <?= h(mb_strimwidth($t['description'], 0, 80, '…')) ?>
              </div>
              <?php endif; ?>
              <div class="text-muted" style="font-size:.7rem">
                by <?= h($t['creator_name']) ?>
              </div>
            </td>
            <td class="small"><?= h($t['assignee_name']) ?></td>
            <td><span class="badge bg-primary-subtle text-primary"><?= h(ucfirst($t['task_type'])) ?></span></td>
            <td class="small <?= $isOverdue ? 'text-danger fw-semibold' : '' ?>">
              <?= $t['deadline'] ? h(date('d M Y, h:i A', strtotime($t['deadline']))) : '—' ?>
            </td>
            <td><?= statusBadge($dispStatus) ?></td>
            <td class="text-center">
              <?php if ($t['file_path']): ?>
              <a href="download.php?path=<?= urlencode($t['file_path']) ?>" target="_blank"
                 class="btn btn-xs btn-outline-secondary me-1" title="Task attachment">
                <i class="bi bi-paperclip"></i>
              </a>
              <?php endif; ?>
              <?php if ($t['proof_file']): ?>
              <a href="download.php?path=<?= urlencode($t['proof_file']) ?>" target="_blank"
                 class="btn btn-xs btn-outline-success" title="Proof file">
                <i class="bi bi-file-check"></i>
              </a>
              <?php endif; ?>
              <?php if (!$t['file_path'] && !$t['proof_file']): ?>
              <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center pe-3">
              <button class="btn btn-xs btn-outline-danger btn-delete-task"
                      data-task-id="<?= $t['id'] ?>"
                      data-task-title="<?= h($t['title']) ?>">
                <i class="bi bi-trash3"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Create Task Modal -->
<div class="modal fade" id="createTaskModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header">
        <h6 class="modal-title fw-bold">
          <i class="bi bi-plus-circle-fill me-2 text-primary"></i>Assign New Task
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="tasks.php" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="create_task">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold small">Task Title <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="title"
                     placeholder="e.g., Submit weekly report" required maxlength="200">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small">Description</label>
              <textarea class="form-control" name="description" rows="3"
                        placeholder="Detailed instructions…"></textarea>
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold small">Assign To <span class="text-danger">*</span></label>
              <select name="user_id" class="form-select" required>
                <option value="">— Select employee —</option>
                <?php foreach ($staffUsers as $u): ?>
                <option value="<?= $u['id'] ?>"><?= h($u['name']) ?>
                  (<?= ucfirst(str_replace('_',' ',$u['role'])) ?>)
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold small">Task Type <span class="text-danger">*</span></label>
              <select name="task_type" class="form-select" required>
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold small">Deadline <span class="text-danger">*</span></label>
              <input type="datetime-local" class="form-control" name="deadline"
                     min="<?= date('Y-m-d\TH:i') ?>" required>
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold small">Attachment</label>
              <input type="file" class="form-control" name="attachment"
                     accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.zip">
              <div class="form-text">Max <?= MAX_FILE_MB ?> MB</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-send me-2"></i>Assign Task
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">
  <div id="mainToast" class="toast align-items-center border-0">
    <div class="d-flex">
      <div class="toast-body" id="mainToastBody"></div>
      <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<script>
window.HRMS = { csrfToken: document.querySelector('meta[name="csrf-token"]').content };

// Delete task
document.querySelectorAll('.btn-delete-task').forEach(btn => {
  btn.addEventListener('click', function() {
    const id    = this.dataset.taskId;
    const title = this.dataset.taskTitle;
    if (!confirm(`Delete task "${title}"? This cannot be undone.`)) return;
    const row = this.closest('tr');
    fetch('task_handler.php', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: `action=delete&task_id=${id}&csrf_token=${encodeURIComponent(HRMS.csrfToken)}`
    })
    .then(r => r.json())
    .then(d => {
      if (d.success) { row.remove(); showToast('Task deleted.', 'success'); }
      else           { showToast(d.message, 'danger'); }
    });
  });
});

function showToast(msg, type='success') {
  const el = document.getElementById('mainToast');
  el.className = `toast align-items-center text-bg-${type} border-0`;
  document.getElementById('mainToastBody').textContent = msg;
  bootstrap.Toast.getOrCreateInstance(el).show();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
