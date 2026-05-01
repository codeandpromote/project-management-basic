<?php
// ============================================================
//  HRMS · Lead Pipeline (Kanban board)
// ============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
requireLogin();

$user = getCurrentUser();
if (!$user) { logout(); }

$db    = getDB();
$uid   = (int)$user['id'];
$role  = $user['role'];
$isAdmin = $role === 'admin';

// Scope
$scope = $isAdmin ? '' : ' AND (assigned_to = ' . $uid . ' OR creator_id = ' . $uid . ')';

$filterUid = (int)($_GET['user_id'] ?? 0);
if ($isAdmin && $filterUid) { $scope .= ' AND assigned_to = ' . $filterUid; }

$sql = "SELECT l.id, l.name, l.phone, l.company, l.priority, l.status,
               l.est_value, l.next_followup_date, u.name AS assignee_name
          FROM leads l
          LEFT JOIN users u ON u.id = l.assigned_to
         WHERE l.is_deleted = 0 {$scope}
         ORDER BY FIELD(l.priority,'hot','high','medium','low'), l.created_at DESC";

$leads = $db->query($sql)->fetchAll();

$cols = [
    'new'         => ['New', 'primary'],
    'contacted'   => ['Contacted', 'info'],
    'qualified'   => ['Qualified', 'secondary'],
    'meeting'     => ['Meeting', 'warning'],
    'negotiation' => ['Negotiation', 'warning'],
    'won'         => ['Won', 'success'],
    'lost'        => ['Lost', 'danger'],
];

$grouped = array_fill_keys(array_keys($cols), []);
foreach ($leads as $l) {
    if (isset($grouped[$l['status']])) {
        $grouped[$l['status']][] = $l;
    }
}

$allUsers = $db->query(
    "SELECT id, name FROM users WHERE is_active = 1 AND role != 'admin' ORDER BY name"
)->fetchAll();

$pageTitle = 'Lead Pipeline';
include __DIR__ . '/includes/header.php';
?>

<style>
.kanban-scroll { overflow-x: auto; padding-bottom: 8px; }
.kanban-row    { display: flex; gap: 10px; min-width: 1100px; }
.kanban-col    { flex: 0 0 240px; background: #f8f9fa; border-radius: 8px;
                 padding: 10px; max-height: 75vh; overflow-y: auto; }
.kanban-col h6 { font-size: .82rem; font-weight: 700; margin-bottom: 10px; }
.kanban-card   { background: #fff; border-radius: 6px; padding: 8px 10px;
                 margin-bottom: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.08);
                 border-left: 3px solid #ced4da; cursor: pointer; font-size: .78rem;
                 transition: transform .12s; }
.kanban-card:hover { transform: translateY(-1px); box-shadow: 0 2px 6px rgba(0,0,0,.15); }
.kanban-card.prio-hot    { border-left-color: #dc3545; }
.kanban-card.prio-high   { border-left-color: #fd7e14; }
.kanban-card.prio-medium { border-left-color: #6c757d; }
.kanban-card.prio-low    { border-left-color: #adb5bd; }
.kanban-card .fu { font-size: .7rem; margin-top: 3px; }
.kanban-col .col-count { font-size: .7rem; background: #e9ecef;
                         border-radius: 10px; padding: 1px 8px; margin-left: 5px; }
.kanban-card a { color: inherit; text-decoration: none; }
.kanban-col .col-value { font-size: .7rem; color: #6c757d; margin-top: 2px; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h5 class="fw-bold mb-0">
    <i class="bi bi-kanban me-2 text-primary"></i>Lead Pipeline
  </h5>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($isAdmin): ?>
    <form method="GET" class="d-flex gap-2">
      <select name="user_id" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">All Employees</option>
        <?php foreach ($allUsers as $u): ?>
        <option value="<?= (int)$u['id'] ?>" <?= $filterUid === (int)$u['id'] ? 'selected' : '' ?>>
          <?= h($u['name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
    <a href="leads.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-list me-1"></i>List View
    </a>
    <a href="lead_calendar.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-calendar-week me-1"></i>Calendar
    </a>
  </div>
</div>

<div class="kanban-scroll">
  <div class="kanban-row">
    <?php foreach ($cols as $key => $meta):
      $items = $grouped[$key];
    ?>
    <div class="kanban-col">
      <h6 class="d-flex align-items-center">
        <span class="badge bg-<?= $meta[1] ?> me-1"><?= count($items) ?></span>
        <?= h($meta[0]) ?>
      </h6>

      <?php if (empty($items)): ?>
      <div class="text-center small text-muted py-3">—</div>
      <?php else: foreach ($items as $l): ?>
      <div class="kanban-card prio-<?= h($l['priority']) ?>">
        <a href="lead_view.php?id=<?= (int)$l['id'] ?>">
          <div class="fw-semibold"><?= h($l['name']) ?></div>
          <?php if ($l['company']): ?>
          <div class="text-muted" style="font-size:.7rem"><?= h($l['company']) ?></div>
          <?php endif; ?>
          <?php if ($l['assignee_name']): ?>
          <div class="text-muted mt-1" style="font-size:.7rem">· <?= h($l['assignee_name']) ?></div>
          <?php endif; ?>
          <?php if ($l['next_followup_date']):
            $isOv = $l['next_followup_date'] < date('Y-m-d') && !in_array($l['status'], ['won','lost']);
            $isTd = $l['next_followup_date'] === date('Y-m-d');
          ?>
          <div class="fu <?= $isOv ? 'text-danger fw-bold' : ($isTd ? 'text-primary fw-bold' : 'text-muted') ?>">
            <i class="bi bi-alarm me-1"></i>
            <?= $isTd ? 'Today' : h(date('d M', strtotime($l['next_followup_date']))) ?>
          </div>
          <?php endif; ?>
        </a>
      </div>
      <?php endforeach; endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
