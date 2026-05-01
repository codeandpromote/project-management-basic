<?php
// ============================================================
//  HRMS · Lead Management — Listing Page
// ============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
requireLogin();

$user = getCurrentUser();
if (!$user) { logout(); }

$db    = getDB();
$uid   = (int)$user['id'];
$role  = $user['role'];
$today = date('Y-m-d');
$isAdmin = $role === 'admin';

// ── Filters from query string ─────────────────────────────────
$fStatus   = $_GET['status']   ?? '';
$fPriority = $_GET['priority'] ?? '';
$fSource   = $_GET['source']   ?? '';
$fUser     = (int)($_GET['user_id'] ?? 0);
$fFollow   = $_GET['followup'] ?? '';   // today | overdue | week | ''
$fSearch   = trim($_GET['q']   ?? '');
$fStale    = !empty($_GET['stale']);
$fCreated  = $_GET['created'] ?? '';    // today | 7d | 30d | thismonth | lastmonth | custom
$fStart    = $_GET['start']   ?? '';
$fEnd      = $_GET['end']     ?? '';
// Validate custom dates (server-side safety)
$dateOk = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if ($fStart && !$dateOk($fStart)) { $fStart = ''; }
if ($fEnd   && !$dateOk($fEnd))   { $fEnd   = ''; }

// ── Build WHERE ───────────────────────────────────────────────
$where  = ['l.is_deleted = 0'];
$params = [];

// Scope: non-admins see only their own leads
if (!$isAdmin) {
    $where[]  = '(l.assigned_to = ? OR l.creator_id = ?)';
    $params[] = $uid; $params[] = $uid;
}

if ($fStatus   !== '') { $where[] = 'l.status = ?';   $params[] = $fStatus; }
if ($fPriority !== '') { $where[] = 'l.priority = ?'; $params[] = $fPriority; }
if ($fSource   !== '') { $where[] = 'l.source = ?';   $params[] = $fSource; }
if ($isAdmin && $fUser) {
    $where[] = 'l.assigned_to = ?'; $params[] = $fUser;
}
if ($fFollow === 'today') {
    $where[] = 'l.next_followup_date = ?';       $params[] = $today;
} elseif ($fFollow === 'overdue') {
    $where[] = 'l.next_followup_date < ? AND l.status NOT IN (\'won\',\'lost\')';
    $params[] = $today;
} elseif ($fFollow === 'week') {
    $where[] = 'l.next_followup_date BETWEEN ? AND ?';
    $params[] = $today;
    $params[] = date('Y-m-d', strtotime('+7 days'));
}
if ($fStale) {
    // No activity in 7+ days and still open
    $where[] = "l.status NOT IN ('won','lost')";
    $where[] = "(l.last_activity_at IS NULL OR l.last_activity_at < DATE_SUB(NOW(), INTERVAL 7 DAY))";
}

// ── Created / Assigned date-range filter ─────────────────────
if ($fCreated === 'today') {
    $where[]  = 'DATE(l.created_at) = ?';
    $params[] = $today;
} elseif ($fCreated === '7d') {
    $where[]  = 'l.created_at >= ?';
    $params[] = date('Y-m-d 00:00:00', strtotime('-6 days'));   // inclusive today = 7 days
} elseif ($fCreated === '30d') {
    $where[]  = 'l.created_at >= ?';
    $params[] = date('Y-m-d 00:00:00', strtotime('-29 days'));
} elseif ($fCreated === 'thismonth') {
    $where[]  = 'l.created_at >= ?';
    $params[] = date('Y-m-01 00:00:00');
} elseif ($fCreated === 'lastmonth') {
    $where[]  = 'l.created_at >= ? AND l.created_at < ?';
    $params[] = date('Y-m-01 00:00:00', strtotime('first day of last month'));
    $params[] = date('Y-m-01 00:00:00');
} elseif ($fCreated === 'custom' && $fStart && $fEnd) {
    $where[]  = 'DATE(l.created_at) BETWEEN ? AND ?';
    $params[] = $fStart;
    $params[] = $fEnd;
}
if ($fSearch !== '') {
    $where[]  = '(l.name LIKE ? OR l.phone LIKE ? OR l.company LIKE ? OR l.email LIKE ?)';
    $wild = '%' . $fSearch . '%';
    $params[] = $wild; $params[] = $wild; $params[] = $wild; $params[] = $wild;
}

$sql = "SELECT l.*, u.name AS assignee_name, p.name AS product_name
          FROM leads l
          LEFT JOIN users u ON u.id = l.assigned_to
          LEFT JOIN lead_products p ON p.id = l.product_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY
           FIELD(l.status,'new','contacted','qualified','meeting','negotiation','won','lost'),
           FIELD(l.priority,'hot','high','medium','low'),
           l.created_at DESC";

$stL = $db->prepare($sql);
$stL->execute($params);
$leads = $stL->fetchAll();

// ── Reference data ────────────────────────────────────────────
$allUsers = $db->query(
    "SELECT id, name, role FROM users
      WHERE is_active = 1 AND role != 'admin'
      ORDER BY name"
)->fetchAll();

$products = $db->query(
    'SELECT id, name FROM lead_products WHERE is_active = 1 ORDER BY name'
)->fetchAll();

// ── Summary / KPI counts (respecting filter scope for employees) ─
$scopeSql = $isAdmin ? '' : '(assigned_to = ' . $uid . ' OR creator_id = ' . $uid . ') AND ';
$countSql = "SELECT
    SUM(CASE WHEN status = 'new'         THEN 1 ELSE 0 END) AS new_cnt,
    SUM(CASE WHEN status IN ('contacted','qualified','meeting','negotiation') THEN 1 ELSE 0 END) AS active_cnt,
    SUM(CASE WHEN next_followup_date = CURDATE() THEN 1 ELSE 0 END) AS today_cnt,
    SUM(CASE WHEN next_followup_date < CURDATE() AND status NOT IN ('won','lost') THEN 1 ELSE 0 END) AS overdue_cnt,
    SUM(CASE WHEN status = 'won'  THEN 1 ELSE 0 END) AS won_cnt,
    SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) AS lost_cnt
  FROM leads WHERE {$scopeSql}is_deleted = 0";
$kpi = $db->query($countSql)->fetch() ?: [];

// ── CSV export (before header output) ─────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID','Name','Phone','Email','Company','Designation','Source','Interest',
                   'Priority','Status','Assigned To','Next Follow-Up','Created At','Tags','Notes']);
    foreach ($leads as $l) {
        fputcsv($out, [
            $l['id'], $l['name'], $l['phone'], $l['email'] ?? '',
            $l['company'] ?? '', $l['designation'] ?? '',
            ucfirst(str_replace('_',' ', $l['source'])),
            $l['interest'] ?? '',
            ucfirst($l['priority']),
            ucfirst(str_replace('_',' ', $l['status'])),
            $l['assignee_name'] ?? '',
            $l['next_followup_date'] ?? '',
            $l['created_at'],
            $l['tags'] ?? '',
            $l['notes'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// Status / priority badge helpers
function statusBadge(string $s): string {
    $map = [
      'new'         => ['New', 'primary'],
      'contacted'   => ['Contacted', 'info'],
      'qualified'   => ['Qualified', 'secondary'],
      'meeting'     => ['Meeting', 'warning'],
      'negotiation' => ['Negotiation', 'warning'],
      'won'         => ['Won', 'success'],
      'lost'        => ['Lost', 'danger'],
    ];
    $m = $map[$s] ?? ['Unknown','secondary'];
    return '<span class="badge bg-' . $m[1] . '">' . h($m[0]) . '</span>';
}
function priorityBadge(string $p): string {
    $map = ['hot'=>'danger','high'=>'warning','medium'=>'secondary','low'=>'light text-dark'];
    $c = $map[$p] ?? 'secondary';
    return '<span class="badge bg-' . $c . '">' . h(ucfirst($p)) . '</span>';
}

$pageTitle = 'Lead Management';
include __DIR__ . '/includes/header.php';
?>

<style>
.lead-kpi .val  { font-size: 1.3rem; font-weight: 700; line-height: 1; }
.lead-kpi .lbl  { font-size: .7rem;  color: #6c757d; margin-top: 3px; }
.lead-row.stale { background: #fff8e1; }
.lead-row.today { background: #e3f2fd; }
.lead-row.overdue { background: #ffebee; }
.badge-tag { font-size: .65rem; background:#f1f3f5; color:#495057; border-radius:12px;
             padding:1px 8px; margin-right:3px; }
/* Ensure the modal footer (Save button) is always visible even on short screens */
#createLeadModal .modal-body { max-height: calc(100vh - 200px); overflow-y: auto; }

/* ── Inline status selector (badge-style native select) ──── */
.status-select {
  font-size: .72rem;
  padding: 3px 22px 3px 10px;
  border-radius: 10rem;
  border: 0;
  font-weight: 700;
  color: #fff;
  cursor: pointer;
  -webkit-appearance: none;
     -moz-appearance: none;
          appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 16 16'%3E%3Cpath fill='%23fff' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708Z'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 6px center;
  background-size: 8px;
  transition: filter .15s;
}
.status-select:hover  { filter: brightness(.92); }
.status-select:focus  { outline: 2px solid rgba(13,110,253,.35); outline-offset: 1px; }
.status-select.status-new         { background-color: #0d6efd; }
.status-select.status-qualified   { background-color: #6c757d; }
.status-select.status-won         { background-color: #198754; }
.status-select.status-lost        { background-color: #dc3545; }
.status-select.status-contacted,
.status-select.status-meeting,
.status-select.status-negotiation {
  color: #000;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 16 16'%3E%3Cpath fill='%23000' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708Z'/%3E%3C/svg%3E");
}
.status-select.status-contacted   { background-color: #0dcaf0; }
.status-select.status-meeting     { background-color: #ffc107; }
.status-select.status-negotiation { background-color: #fd7e14; color: #fff;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 16 16'%3E%3Cpath fill='%23fff' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708Z'/%3E%3C/svg%3E"); }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h5 class="fw-bold mb-0">
    <i class="bi bi-people-fill me-2 text-primary"></i>Lead Management
  </h5>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($isAdmin): ?>
    <a href="admin_lead_products.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-box-seam me-1"></i>Products
    </a>
    <a href="lead_import.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-upload me-1"></i>Import CSV
    </a>
    <?php endif; ?>
    <a href="lead_pipeline.php" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-kanban me-1"></i>Pipeline
    </a>
    <a href="lead_calendar.php" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-calendar-week me-1"></i>Calendar
    </a>
    <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>"
       class="btn btn-outline-success btn-sm">
      <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export
    </a>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createLeadModal">
      <i class="bi bi-plus-lg me-1"></i>Add Lead
    </button>
  </div>
</div>

<!-- ── KPI Strip ──────────────────────────────────────────── -->
<div class="row g-2 mb-3 lead-kpi">
  <div class="col-6 col-md">
    <a href="?status=new" class="card text-decoration-none text-dark shadow-sm">
      <div class="card-body py-2 px-3">
        <div class="val text-primary"><?= (int)($kpi['new_cnt'] ?? 0) ?></div>
        <div class="lbl">New Leads</div>
      </div>
    </a>
  </div>
  <div class="col-6 col-md">
    <a href="?followup=today" class="card text-decoration-none text-dark shadow-sm">
      <div class="card-body py-2 px-3">
        <div class="val text-info"><?= (int)($kpi['today_cnt'] ?? 0) ?></div>
        <div class="lbl">Today's Follow-ups</div>
      </div>
    </a>
  </div>
  <div class="col-6 col-md">
    <a href="?followup=overdue" class="card text-decoration-none text-dark shadow-sm">
      <div class="card-body py-2 px-3">
        <div class="val text-danger"><?= (int)($kpi['overdue_cnt'] ?? 0) ?></div>
        <div class="lbl">Overdue</div>
      </div>
    </a>
  </div>
  <div class="col-6 col-md">
    <a href="?stale=1" class="card text-decoration-none text-dark shadow-sm">
      <div class="card-body py-2 px-3">
        <div class="val text-warning"><?= (int)($kpi['active_cnt'] ?? 0) ?></div>
        <div class="lbl">Active</div>
      </div>
    </a>
  </div>
  <div class="col-6 col-md">
    <a href="?status=won" class="card text-decoration-none text-dark shadow-sm">
      <div class="card-body py-2 px-3">
        <div class="val text-success"><?= (int)($kpi['won_cnt'] ?? 0) ?></div>
        <div class="lbl">Won</div>
      </div>
    </a>
  </div>
</div>

<!-- ── Filter Bar ─────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label small fw-semibold mb-1">Search</label>
        <input type="search" name="q" class="form-control form-control-sm"
               placeholder="Name, phone, company, email…" value="<?= h($fSearch) ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All</option>
          <?php foreach (['new','contacted','qualified','meeting','negotiation','won','lost'] as $s): ?>
          <option value="<?= $s ?>" <?= $fStatus === $s ? 'selected' : '' ?>>
            <?= ucfirst(str_replace('_',' ',$s)) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Priority</label>
        <select name="priority" class="form-select form-select-sm">
          <option value="">All</option>
          <?php foreach (['hot','high','medium','low'] as $p): ?>
          <option value="<?= $p ?>" <?= $fPriority === $p ? 'selected' : '' ?>>
            <?= ucfirst($p) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Source</label>
        <select name="source" class="form-select form-select-sm">
          <option value="">All</option>
          <?php foreach (['walk_in','phone','referral','website','social','cold_call','exhibition','other'] as $sr): ?>
          <option value="<?= $sr ?>" <?= $fSource === $sr ? 'selected' : '' ?>>
            <?= ucfirst(str_replace('_',' ',$sr)) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($isAdmin): ?>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Assigned To</label>
        <select name="user_id" class="form-select form-select-sm">
          <option value="">Anyone</option>
          <?php foreach ($allUsers as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= $fUser === (int)$u['id'] ? 'selected' : '' ?>>
            <?= h($u['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Created</label>
        <select name="created" id="fCreatedSel" class="form-select form-select-sm"
                onchange="document.getElementById('fCustomRange').style.display = this.value === 'custom' ? '' : 'none'">
          <?php $opts = [
            '' => 'All time',
            'today' => 'Today',
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            'thismonth' => 'This month',
            'lastmonth' => 'Last month',
            'custom' => 'Custom range…',
          ]; ?>
          <?php foreach ($opts as $val => $lbl): ?>
          <option value="<?= $val ?>" <?= $fCreated === $val ? 'selected' : '' ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-auto d-flex gap-2">
        <button class="btn btn-primary btn-sm" type="submit">
          <i class="bi bi-funnel me-1"></i>Filter
        </button>
        <a class="btn btn-outline-secondary btn-sm" href="leads.php">Reset</a>
      </div>
      <!-- Custom date range — shown only when "Custom range" is selected -->
      <div class="col-12" id="fCustomRange" style="<?= $fCreated === 'custom' ? '' : 'display:none' ?>">
        <div class="row g-2">
          <div class="col-sm-6 col-md-3">
            <label class="form-label small fw-semibold mb-1">From</label>
            <input type="date" name="start" class="form-control form-control-sm"
                   value="<?= h($fStart) ?>" max="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-sm-6 col-md-3">
            <label class="form-label small fw-semibold mb-1">To</label>
            <input type="date" name="end" class="form-control form-control-sm"
                   value="<?= h($fEnd) ?>" max="<?= date('Y-m-d') ?>">
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Bulk Action Bar (admin) ────────────────────────────── -->
<?php if ($isAdmin && !empty($leads)): ?>
<div class="card border-0 shadow-sm mb-3" id="bulkBar" style="display:none">
  <div class="card-body py-2 d-flex align-items-center gap-2 flex-wrap">
    <span class="fw-semibold small"><span id="selCount">0</span> selected</span>
    <select class="form-select form-select-sm" id="bulkAssignTo" style="max-width:200px">
      <option value="">Reassign to…</option>
      <?php foreach ($allUsers as $u): ?>
      <option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option>
      <?php endforeach; ?>
      <option value="0">— Unassign —</option>
    </select>
    <button class="btn btn-sm btn-outline-primary" id="btnBulkAssign">Apply Assign</button>
    <select class="form-select form-select-sm" id="bulkStatus" style="max-width:200px">
      <option value="">Change status…</option>
      <?php foreach (['contacted','qualified','meeting','negotiation','won','lost'] as $s): ?>
      <option value="<?= $s ?>"><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-outline-primary" id="btnBulkStatus">Apply Status</button>
    <button class="btn btn-sm btn-link text-muted ms-auto" id="btnBulkClear">Clear</button>
  </div>
</div>
<?php endif; ?>

<!-- ── Leads Table ────────────────────────────────────────── -->
<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <?php if ($isAdmin): ?>
          <th class="ps-3" style="width:32px">
            <input type="checkbox" id="selAll" class="form-check-input">
          </th>
          <?php endif; ?>
          <th>Name</th>
          <th>Contact</th>
          <th>Source / Interest</th>
          <th>Priority</th>
          <th>Status</th>
          <th>Assignee</th>
          <th>Next Follow-up</th>
          <th class="pe-3">Quick Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($leads)): ?>
        <tr>
          <td colspan="<?= $isAdmin ? 9 : 8 ?>" class="text-center py-5 text-muted">
            <i class="bi bi-inboxes fs-1 d-block mb-2 opacity-25"></i>
            No leads match your filters.
            <div class="mt-2">
              <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createLeadModal">
                <i class="bi bi-plus-lg me-1"></i>Add your first lead
              </button>
            </div>
          </td>
        </tr>
        <?php endif; ?>
        <?php foreach ($leads as $l):
          $isToday    = $l['next_followup_date'] === $today;
          $isOverdue  = $l['next_followup_date'] && $l['next_followup_date'] < $today
                        && !in_array($l['status'], ['won','lost'], true);
          $isStale    = !in_array($l['status'], ['won','lost'], true)
                        && (empty($l['last_activity_at'])
                            || strtotime($l['last_activity_at']) < strtotime('-7 days'));
          $rowClass   = $isOverdue ? 'overdue' : ($isToday ? 'today' : ($isStale ? 'stale' : ''));
        ?>
        <tr class="lead-row <?= $rowClass ?>" data-lead-id="<?= (int)$l['id'] ?>">
          <?php if ($isAdmin): ?>
          <td class="ps-3">
            <input type="checkbox" class="form-check-input row-check" value="<?= (int)$l['id'] ?>">
          </td>
          <?php endif; ?>
          <td>
            <a href="lead_view.php?id=<?= (int)$l['id'] ?>"
               class="fw-semibold text-decoration-none"><?= h($l['name']) ?></a>
            <?php if ($l['company']): ?>
            <div class="small text-muted"><?= h($l['company']) ?></div>
            <?php endif; ?>
            <?php if ($l['tags']): ?>
            <div class="mt-1">
              <?php foreach (array_filter(array_map('trim', explode(',', $l['tags']))) as $tag): ?>
              <span class="badge-tag"><?= h($tag) ?></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </td>
          <td class="small">
            <div><i class="bi bi-telephone me-1"></i><?= h($l['phone']) ?></div>
            <?php if ($l['email']): ?>
            <div class="text-muted"><i class="bi bi-envelope me-1"></i><?= h($l['email']) ?></div>
            <?php endif; ?>
          </td>
          <td class="small">
            <?= h(ucfirst(str_replace('_',' ', $l['source']))) ?>
            <?php if ($l['product_name'] || $l['interest']): ?>
            <div class="text-muted"><?= h($l['product_name'] ?: $l['interest']) ?></div>
            <?php endif; ?>
          </td>
          <td><?= priorityBadge($l['priority']) ?></td>
          <td>
            <!-- Native select renders above the table — no clipping -->
            <select class="status-select status-<?= h($l['status']) ?>"
                    data-lead-id="<?= (int)$l['id'] ?>"
                    data-current="<?= h($l['status']) ?>"
                    data-lead-name="<?= h($l['name']) ?>"
                    title="Change status">
              <?php foreach (['new','contacted','qualified','meeting','negotiation','won','lost'] as $s): ?>
              <option value="<?= $s ?>" <?= $l['status'] === $s ? 'selected' : '' ?>>
                <?= ucfirst(str_replace('_',' ', $s)) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </td>
          <td class="small"><?= $l['assignee_name'] ? h($l['assignee_name']) : '<span class="text-muted">Unassigned</span>' ?></td>
          <td class="small">
            <?php if ($l['next_followup_date']): ?>
              <?php if ($isOverdue): ?>
              <span class="text-danger fw-bold">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <?= h(date('d M', strtotime($l['next_followup_date']))) ?>
              </span>
              <?php elseif ($isToday): ?>
              <span class="text-primary fw-bold">Today</span>
              <?php else: ?>
              <?= h(date('d M', strtotime($l['next_followup_date']))) ?>
              <?php endif; ?>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="pe-3">
            <div class="btn-group btn-group-sm">
              <a href="lead_view.php?id=<?= (int)$l['id'] ?>"
                 class="btn btn-outline-primary" title="Open">
                <i class="bi bi-eye"></i>
              </a>
              <a href="tel:<?= h($l['phone']) ?>" class="btn btn-outline-success" title="Call">
                <i class="bi bi-telephone-fill"></i>
              </a>
              <button type="button" class="btn btn-outline-warning btn-quick-followup"
                      data-lead-id="<?= (int)$l['id'] ?>"
                      data-lead-name="<?= h($l['name']) ?>"
                      data-current-date="<?= h($l['next_followup_date'] ?? '') ?>"
                      data-current-status="<?= h($l['status']) ?>"
                      title="Schedule follow-up">
                <i class="bi bi-alarm"></i>
              </button>
              <?php if ($isAdmin): ?>
              <button class="btn btn-outline-danger btn-delete-lead"
                      data-lead-id="<?= (int)$l['id'] ?>"
                      data-lead-name="<?= h($l['name']) ?>" title="Delete">
                <i class="bi bi-trash"></i>
              </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer bg-white py-2 small text-muted">
    Showing <?= count($leads) ?> lead<?= count($leads) !== 1 ? 's' : '' ?>.
  </div>
</div>

<!-- ── Create Lead Modal ──────────────────────────────────── -->
<div class="modal fade" id="createLeadModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow">
      <div class="modal-header border-bottom">
        <h6 class="modal-title fw-bold">
          <i class="bi bi-person-plus-fill me-2 text-primary"></i>New Lead
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="createLeadForm">
        <div class="modal-body">
          <!-- Duplicate warning (injected by JS) -->
          <div id="dupWarn" class="alert alert-warning d-none small"></div>

          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Name <span class="text-danger">*</span></label>
              <input name="name" class="form-control form-control-sm" required maxlength="150">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Phone <span class="text-danger">*</span></label>
              <input name="phone" class="form-control form-control-sm" required maxlength="30"
                     inputmode="tel" placeholder="+91…">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Email</label>
              <input name="email" type="email" class="form-control form-control-sm" maxlength="150">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Company</label>
              <input name="company" class="form-control form-control-sm" maxlength="150">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Designation</label>
              <input name="designation" class="form-control form-control-sm" maxlength="100">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Pincode</label>
              <input name="pincode" class="form-control form-control-sm" maxlength="10">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small">Address</label>
              <textarea name="address" rows="2" class="form-control form-control-sm"></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Source</label>
              <select name="source" class="form-select form-select-sm">
                <?php foreach (['walk_in','phone','referral','website','social','cold_call','exhibition','other'] as $sr): ?>
                <option value="<?= $sr ?>"><?= ucfirst(str_replace('_',' ',$sr)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Priority</label>
              <select name="priority" class="form-select form-select-sm">
                <option value="medium" selected>Medium</option>
                <option value="hot">Hot</option>
                <option value="high">High</option>
                <option value="low">Low</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Product / Service</label>
              <select name="product_id" class="form-select form-select-sm">
                <option value="">— None —</option>
                <?php foreach ($products as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small">Interest / Requirement</label>
              <input name="interest" class="form-control form-control-sm" maxlength="255"
                     placeholder="Free text if not in the list">
            </div>
            <?php if ($isAdmin): ?>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Assign To</label>
              <select name="assigned_to" class="form-select form-select-sm">
                <option value="">— Unassigned —</option>
                <?php foreach ($allUsers as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php else: ?>
            <input type="hidden" name="assigned_to" value="<?= $uid ?>">
            <?php endif; ?>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Tags (comma-separated)</label>
              <input name="tags" class="form-control form-control-sm" maxlength="255"
                     placeholder="VIP, Referral, Warranty">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small">Initial Notes</label>
              <textarea name="notes" rows="3" class="form-control form-control-sm"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i>Save Lead
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Shared Quick Follow-up Modal ───────────────────────── -->
<div class="modal fade" id="quickFollowModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header">
        <h6 class="modal-title fw-bold">
          <i class="bi bi-alarm me-1 text-warning"></i>
          Schedule Follow-up <span id="qfLeadName" class="text-muted fw-normal ms-1"></span>
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="quickFollowForm">
        <input type="hidden" name="lead_id" id="qfLeadId">
        <input type="hidden" name="activity_type" value="note">
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label fw-semibold small">Next Follow-up Date</label>
            <input type="date" name="next_followup_date" id="qfDate"
                   class="form-control form-control-sm" min="<?= date('Y-m-d') ?>" required>
            <div class="form-text" style="font-size:.72rem">
              The lead will reappear on your dashboard on this date automatically.
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold small">Notes (optional)</label>
            <textarea name="notes" id="qfNotes" rows="2" class="form-control form-control-sm"
                      placeholder="What's the next step?"></textarea>
          </div>
          <div class="mb-0">
            <label class="form-label fw-semibold small">Change Status (optional)</label>
            <select name="new_status" id="qfStatus" class="form-select form-select-sm">
              <option value="">— Keep current status —</option>
              <?php foreach (['contacted','qualified','meeting','negotiation','won','lost'] as $s): ?>
              <option value="<?= $s ?>"><?= ucfirst(str_replace('_',' ',$s)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning">
            <i class="bi bi-check-lg me-1"></i>Save
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  var csrf = document.querySelector('meta[name="csrf-token"]').content;

  function postForm(action, formData) {
    formData.append('action', action);
    formData.append('csrf_token', csrf);
    return fetch('lead_handler.php', { method:'POST', body: formData })
      .then(function (r) { return r.json(); });
  }

  function showMsg(msg, cls) {
    var t = document.getElementById('mainToast'),
        b = document.getElementById('mainToastBody');
    if (t && b) {
      b.textContent = msg;
      t.className = 'toast align-items-center border-0 text-bg-' + (cls || 'primary');
      try { bootstrap.Toast.getOrCreateInstance(t).show(); } catch (e) { alert(msg); }
    } else { alert(msg); }
  }

  // ── Create form: duplicate check on phone blur ─────────────
  var createForm = document.getElementById('createLeadForm');
  if (createForm) {
    var phoneInput = createForm.querySelector('input[name="phone"]');
    var dupBox = document.getElementById('dupWarn');
    phoneInput.addEventListener('blur', function () {
      var fd = new FormData();
      fd.append('phone', phoneInput.value.trim());
      fd.append('email', createForm.email.value.trim());
      fd.append('name',  createForm.name.value.trim());
      postForm('check_duplicate', fd).then(function (res) {
        if (res.success && res.matches && res.matches.length > 0) {
          var html = '<b>Possible duplicate(s):</b><ul class="mb-0 ps-3">';
          res.matches.forEach(function (m) {
            html += '<li><a href="lead_view.php?id=' + m.id + '" target="_blank">' +
                    m.name + '</a> &middot; ' + m.phone + ' &middot; ' + m.status + '</li>';
          });
          html += '</ul>';
          dupBox.innerHTML = html;
          dupBox.classList.remove('d-none');
        } else {
          dupBox.classList.add('d-none');
        }
      });
    });

    createForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = createForm.querySelector('[type="submit"]');
      btn.disabled = true;
      postForm('create', new FormData(createForm)).then(function (res) {
        btn.disabled = false;
        if (res.success) {
          showMsg(res.message, 'success');
          setTimeout(function () { location.href = 'lead_view.php?id=' + res.lead_id; }, 500);
        } else {
          showMsg(res.message, 'danger');
        }
      }).catch(function () {
        btn.disabled = false;
        showMsg('Network error.', 'danger');
      });
    });
  }

  // ── Delete lead (admin) ────────────────────────────────────
  document.querySelectorAll('.btn-delete-lead').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!confirm('Delete lead "' + btn.dataset.leadName + '"? This cannot be undone.')) { return; }
      var fd = new FormData();
      fd.append('lead_id', btn.dataset.leadId);
      postForm('delete', fd).then(function (res) {
        if (res.success) {
          var row = btn.closest('tr'); if (row) { row.remove(); }
          showMsg(res.message, 'success');
        } else {
          showMsg(res.message, 'danger');
        }
      });
    });
  });

  // ── Bulk select (admin) ────────────────────────────────────
  var bulkBar  = document.getElementById('bulkBar');
  var selAll   = document.getElementById('selAll');
  var selCount = document.getElementById('selCount');
  var rowChecks = function () { return document.querySelectorAll('.row-check'); };

  function updateSelection() {
    if (!bulkBar) { return; }
    var checked = document.querySelectorAll('.row-check:checked').length;
    bulkBar.style.display = checked > 0 ? '' : 'none';
    if (selCount) { selCount.textContent = checked; }
  }

  if (selAll) {
    selAll.addEventListener('change', function () {
      rowChecks().forEach(function (cb) { cb.checked = selAll.checked; });
      updateSelection();
    });
  }
  rowChecks().forEach(function (cb) {
    cb.addEventListener('change', updateSelection);
  });

  function selectedIds() {
    return Array.prototype.map.call(
      document.querySelectorAll('.row-check:checked'),
      function (cb) { return cb.value; }
    );
  }

  var btnAssign = document.getElementById('btnBulkAssign');
  if (btnAssign) {
    btnAssign.addEventListener('click', function () {
      var ids = selectedIds(); if (!ids.length) { return; }
      var to = document.getElementById('bulkAssignTo').value;
      if (to === '') { showMsg('Pick an assignee.', 'warning'); return; }
      var fd = new FormData();
      ids.forEach(function (id) { fd.append('ids[]', id); });
      fd.append('assigned_to', to);
      postForm('bulk_assign', fd).then(function (res) {
        showMsg(res.message, res.success ? 'success' : 'danger');
        if (res.success) { setTimeout(function () { location.reload(); }, 500); }
      });
    });
  }
  var btnStatus = document.getElementById('btnBulkStatus');
  if (btnStatus) {
    btnStatus.addEventListener('click', function () {
      var ids = selectedIds(); if (!ids.length) { return; }
      var st = document.getElementById('bulkStatus').value;
      if (!st) { showMsg('Pick a status.', 'warning'); return; }
      var fd = new FormData();
      ids.forEach(function (id) { fd.append('ids[]', id); });
      fd.append('status', st);
      postForm('bulk_status', fd).then(function (res) {
        showMsg(res.message, res.success ? 'success' : 'danger');
        if (res.success) { setTimeout(function () { location.reload(); }, 500); }
      });
    });
  }
  var btnClear = document.getElementById('btnBulkClear');
  if (btnClear) {
    btnClear.addEventListener('click', function () {
      rowChecks().forEach(function (cb) { cb.checked = false; });
      if (selAll) { selAll.checked = false; }
      updateSelection();
    });
  }

  // ── Inline status change (native select) ──────────────────
  document.querySelectorAll('.status-select').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var newSt   = sel.value;
      var current = sel.dataset.current;
      if (newSt === current) { return; }
      if (!confirm('Change status of "' + sel.dataset.leadName + '" to "' + newSt + '"?')) {
        sel.value = current;   // revert
        return;
      }
      sel.disabled = true;
      var fd = new FormData();
      fd.append('lead_id', sel.dataset.leadId);
      fd.append('activity_type', 'status_change');
      fd.append('new_status', newSt);
      postForm('add_activity', fd).then(function (res) {
        showMsg(res.message, res.success ? 'success' : 'danger');
        if (res.success) {
          setTimeout(function () { location.reload(); }, 400);
        } else {
          sel.value = current;
          sel.disabled = false;
        }
      }).catch(function () {
        showMsg('Network error.', 'danger');
        sel.value = current;
        sel.disabled = false;
      });
    });
  });

  // ── Quick follow-up modal (mobile-safe lazy init) ─────────
  // On some mobile browsers Bootstrap.Modal.getOrCreateInstance throws at
  // page-load if the JS isn't ready. We build it lazily on first click and
  // fall back to the lead detail page if it still fails.
  var _qfModal = null;
  function getQuickFollowModal() {
    if (_qfModal) { return _qfModal; }
    var el = document.getElementById('quickFollowModal');
    if (!el || typeof bootstrap === 'undefined' || !bootstrap.Modal) { return null; }
    try { _qfModal = bootstrap.Modal.getOrCreateInstance(el); return _qfModal; }
    catch (e) { return null; }
  }
  document.querySelectorAll('.btn-quick-followup').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var m = getQuickFollowModal();
      if (!m) {
        // Fallback on browsers where Bootstrap modal fails — jump to detail page
        window.location = 'lead_view.php?id=' + encodeURIComponent(btn.dataset.leadId);
        return;
      }
      document.getElementById('qfLeadId').value         = btn.dataset.leadId;
      document.getElementById('qfLeadName').textContent = '— ' + btn.dataset.leadName;
      document.getElementById('qfDate').value           = btn.dataset.currentDate || '';
      document.getElementById('qfNotes').value          = '';
      document.getElementById('qfStatus').value         = '';
      m.show();
    });
  });
  var qfForm = document.getElementById('quickFollowForm');
  if (qfForm) {
    qfForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = qfForm.querySelector('[type="submit"]');
      btn.disabled = true;
      postForm('add_activity', new FormData(qfForm)).then(function (res) {
        btn.disabled = false;
        showMsg(res.message, res.success ? 'success' : 'danger');
        if (res.success) {
          if (_qfModal) { _qfModal.hide(); }
          setTimeout(function () { location.reload(); }, 400);
        }
      }).catch(function () { btn.disabled = false; showMsg('Network error.', 'danger'); });
    });
  }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
