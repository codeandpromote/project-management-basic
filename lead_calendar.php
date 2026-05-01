<?php
// ============================================================
//  HRMS · Lead Follow-up Calendar
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

// Month navigation
$ym = $_GET['m'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) { $ym = date('Y-m'); }
$startDate = $ym . '-01';
$endDate   = date('Y-m-t', strtotime($startDate));
$prevMonth = date('Y-m', strtotime($startDate . ' -1 month'));
$nextMonth = date('Y-m', strtotime($startDate . ' +1 month'));

$filterUid = (int)($_GET['user_id'] ?? 0);

// Scope
$scope  = ' AND l.is_deleted = 0';
$params = [$startDate, $endDate];
if (!$isAdmin) {
    $scope .= ' AND (l.assigned_to = ? OR l.creator_id = ?)';
    $params[] = $uid; $params[] = $uid;
}
if ($isAdmin && $filterUid) {
    $scope   .= ' AND l.assigned_to = ?';
    $params[] = $filterUid;
}

$sql = "SELECT l.id, l.name, l.phone, l.status, l.priority, l.next_followup_date,
               u.name AS assignee_name
          FROM leads l
          LEFT JOIN users u ON u.id = l.assigned_to
         WHERE l.next_followup_date BETWEEN ? AND ? {$scope}
         ORDER BY l.next_followup_date ASC, l.priority ASC";
$st = $db->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

// Index by date
$byDate = [];
foreach ($rows as $r) { $byDate[$r['next_followup_date']][] = $r; }

// Build calendar grid
$firstDay      = (int)date('N', strtotime($startDate)); // 1=Mon..7=Sun
$daysInMonth   = (int)date('t', strtotime($startDate));
$leadingBlanks = $firstDay - 1; // Mon = 0 blanks; Sun = 6

$allUsers = $db->query(
    "SELECT id, name FROM users WHERE is_active = 1 AND role != 'admin' ORDER BY name"
)->fetchAll();

$pageTitle = 'Lead Calendar';
include __DIR__ . '/includes/header.php';
?>

<style>
.cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
.cal-head { font-size: .72rem; text-transform: uppercase; font-weight: 700;
            color: #6c757d; padding: 6px 4px; text-align: center; }
.cal-cell { background: #fff; border: 1px solid #e9ecef; border-radius: 6px;
            min-height: 90px; padding: 4px 6px; font-size: .72rem;
            position: relative; }
.cal-cell.today { border-color: #0d6efd; background: #e3f2fd; }
.cal-cell.past  { background: #fafafa; }
.cal-date { font-size: .75rem; font-weight: 700; color: #495057; }
.cal-dot  { display: inline-block; width: 6px; height: 6px; border-radius: 50%;
            margin-right: 3px; vertical-align: middle; }
.cal-item { display: block; color: #212529; text-decoration: none;
            padding: 2px 4px; border-radius: 3px; margin-top: 2px;
            background: #f1f3f5; }
.cal-item:hover { background: #dee2e6; }
.cal-item.prio-hot    { border-left: 3px solid #dc3545; }
.cal-item.prio-high   { border-left: 3px solid #fd7e14; }
.cal-item.prio-medium { border-left: 3px solid #6c757d; }
.cal-item.prio-low    { border-left: 3px solid #adb5bd; }
.cal-count { position: absolute; top: 4px; right: 6px; background: #0d6efd;
             color: white; font-size: .62rem; border-radius: 10px;
             padding: 0 5px; font-weight: 700; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h5 class="fw-bold mb-0">
    <i class="bi bi-calendar-week me-2 text-primary"></i>Lead Follow-up Calendar
  </h5>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($isAdmin): ?>
    <form method="GET" class="d-flex gap-2">
      <input type="hidden" name="m" value="<?= h($ym) ?>">
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
      <i class="bi bi-list me-1"></i>List
    </a>
    <a href="lead_pipeline.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-kanban me-1"></i>Pipeline
    </a>
  </div>
</div>

<div class="d-flex align-items-center justify-content-center gap-3 mb-3">
  <a href="?m=<?= $prevMonth ?>&user_id=<?= $filterUid ?>" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i>
  </a>
  <h6 class="fw-bold mb-0"><?= h(date('F Y', strtotime($startDate))) ?></h6>
  <a href="?m=<?= $nextMonth ?>&user_id=<?= $filterUid ?>" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-right"></i>
  </a>
  <a href="?m=<?= date('Y-m') ?>&user_id=<?= $filterUid ?>" class="btn btn-sm btn-outline-primary">Today</a>
</div>

<div class="cal-grid">
  <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d): ?>
  <div class="cal-head"><?= $d ?></div>
  <?php endforeach; ?>

  <?php for ($i = 0; $i < $leadingBlanks; $i++): ?>
  <div class="cal-cell past"></div>
  <?php endfor; ?>

  <?php for ($day = 1; $day <= $daysInMonth; $day++):
    $date = sprintf('%s-%02d', $ym, $day);
    $items = $byDate[$date] ?? [];
    $isToday = $date === date('Y-m-d');
    $isPast  = $date < date('Y-m-d');
  ?>
  <div class="cal-cell <?= $isToday ? 'today' : ($isPast ? 'past' : '') ?>">
    <div class="cal-date">
      <?= $day ?>
      <?php if (count($items) > 0): ?>
      <span class="cal-count"><?= count($items) ?></span>
      <?php endif; ?>
    </div>
    <?php foreach (array_slice($items, 0, 4) as $it): ?>
    <a class="cal-item prio-<?= h($it['priority']) ?>" href="lead_view.php?id=<?= (int)$it['id'] ?>"
       title="<?= h($it['name'] . ' · ' . $it['phone']) ?>">
      <?= h(mb_substr($it['name'], 0, 16)) ?>
    </a>
    <?php endforeach; ?>
    <?php if (count($items) > 4): ?>
    <div class="text-muted small mt-1">+<?= count($items) - 4 ?> more</div>
    <?php endif; ?>
  </div>
  <?php endfor; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
