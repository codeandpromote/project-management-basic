<?php
// ============================================================
//  HRMS · Attendance Reports  (Admin only)
// ============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
requireRole('admin');

$user = getCurrentUser();
$db   = getDB();

// ── Filter inputs ─────────────────────────────────────────────
$preset    = $_GET['preset']    ?? 'today';   // today | week | month | custom
$startDate = $_GET['start']     ?? '';
$endDate   = $_GET['end']       ?? '';
$filterUid = (int)($_GET['user_id'] ?? 0);
$filterSt  = $_GET['status']    ?? '';

// Compute date range from preset
switch ($preset) {
    case 'week':
        $startDate = date('Y-m-d', strtotime('monday this week'));
        $endDate   = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'month':
        $startDate = date('Y-m-01');
        $endDate   = date('Y-m-t');
        break;
    case 'custom':
        // keep user-supplied values; fall back if empty
        if (!$startDate) $startDate = date('Y-m-d');
        if (!$endDate)   $endDate   = date('Y-m-d');
        break;
    default: // today
        $preset    = 'today';
        $startDate = $endDate = date('Y-m-d');
}

// ── Pull all active staff ─────────────────────────────────────
$staffUsers = $db->query(
    "SELECT id, name, role FROM users WHERE role != 'admin' AND is_active = 1 ORDER BY name"
)->fetchAll();

// ── Build attendance query ────────────────────────────────────
$where  = ['a.work_date BETWEEN ? AND ?'];
$params = [$startDate, $endDate];

if ($filterUid) {
    $where[] = 'u.id = ?';
    $params[] = $filterUid;
}

// Map "on_leave" filter separately (derived, not stored)
$joinLeave = true;

$sql = "
    SELECT
        u.id AS user_id, u.name, u.role,
        a.work_date, a.check_in_time, a.check_out_time,
        a.status, a.ip_address, a.lat_long, a.day_end_notes,
        lr.status AS leave_status
    FROM attendance a
    JOIN users u ON u.id = a.user_id
    LEFT JOIN leave_requests lr
           ON lr.user_id = a.user_id
          AND a.work_date BETWEEN lr.start_date AND lr.end_date
          AND lr.status = 'approved'
    WHERE " . implode(' AND ', $where) . "
    ORDER BY a.work_date DESC, u.name ASC
";

$stRec = $db->prepare($sql);
$stRec->execute($params);
$records = $stRec->fetchAll();

// Enrich status: if status = absent AND leave approved → on_leave
foreach ($records as &$rec) {
    if ($rec['status'] === 'absent' && $rec['leave_status'] === 'approved') {
        $rec['status'] = 'on_leave';
    }
}
unset($rec);

// Filter by status in PHP (simpler than SQL for the derived on_leave)
if ($filterSt) {
    $records = array_values(array_filter($records, fn($r) => $r['status'] === $filterSt));
}

// ── Summary counts ────────────────────────────────────────────
$summary = [
    'present'  => 0,
    'absent'   => 0,
    'on_leave' => 0,
    'half_day' => 0,
];
foreach ($records as $r) {
    if (isset($summary[$r['status']])) $summary[$r['status']]++;
}

// ── Status badge ──────────────────────────────────────────────
function attBadge(string $status): string {
    return match($status) {
        'present'  => '<span class="badge bg-success">Present</span>',
        'absent'   => '<span class="badge bg-danger">Absent</span>',
        'on_leave' => '<span class="badge bg-info text-dark">On Leave</span>',
        'half_day' => '<span class="badge bg-warning text-dark">Half Day</span>',
        default    => '<span class="badge bg-secondary">' . h(ucfirst($status)) . '</span>',
    };
}

// ── Duration helper ───────────────────────────────────────────
function workDuration(?string $in, ?string $out): string {
    if (!$in || !$out) return '—';
    $mins = (int)((strtotime($out) - strtotime($in)) / 60);
    return sprintf('%dh %02dm', intdiv($mins, 60), $mins % 60);
}

// ── CSV Export (must run BEFORE any HTML output) ─────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel renders accents / unicode correctly
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date','Employee','Role','Check-In','Check-Out','Hours Worked','Status','Day-End Notes','IP/Location']);
    foreach ($records as $r) {
        fputcsv($out, [
            $r['work_date'],
            $r['name'],
            ucfirst(str_replace('_',' ',$r['role'])),
            $r['check_in_time']  ? date('h:i A', strtotime($r['check_in_time']))  : '',
            $r['check_out_time'] ? date('h:i A', strtotime($r['check_out_time'])) : '',
            workDuration($r['check_in_time'], $r['check_out_time']),
            $r['status'],
            $r['day_end_notes'] ?? '',
            $r['lat_long'] ?? $r['ip_address'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Attendance Reports';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <h5 class="fw-bold mb-0">
    <i class="bi bi-bar-chart-line me-2 text-primary"></i>Attendance Reports
  </h5>
  <!-- CSV export -->
  <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>"
     class="btn btn-outline-success btn-sm">
    <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
  </a>
</div>

<!-- ── Filter Panel ──────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body">
    <form method="GET" action="admin_reports.php" class="row g-2 align-items-end">
      <!-- Date preset buttons -->
      <div class="col-12">
        <label class="form-label small fw-semibold">Quick Range</label>
        <div class="btn-group btn-group-sm w-100 w-sm-auto" role="group">
          <?php foreach (['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'custom' => 'Custom'] as $key => $label): ?>
          <a href="?preset=<?= $key ?>&user_id=<?= $filterUid ?>&status=<?= h($filterSt) ?>"
             class="btn btn-outline-primary <?= $preset === $key ? 'active' : '' ?>">
            <?= $label ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($preset === 'custom'): ?>
      <div class="col-sm-6 col-md-3">
        <label class="form-label small fw-semibold">Start Date</label>
        <input type="date" name="start" class="form-control form-control-sm"
               value="<?= h($startDate) ?>">
      </div>
      <div class="col-sm-6 col-md-3">
        <label class="form-label small fw-semibold">End Date</label>
        <input type="date" name="end" class="form-control form-control-sm"
               value="<?= h($endDate) ?>">
      </div>
      <input type="hidden" name="preset" value="custom">
      <?php else: ?>
      <input type="hidden" name="preset" value="<?= h($preset) ?>">
      <?php endif; ?>

      <div class="col-sm-6 col-md-3">
        <label class="form-label small fw-semibold">Employee</label>
        <select name="user_id" class="form-select form-select-sm">
          <option value="">All Employees</option>
          <?php foreach ($staffUsers as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $filterUid === (int)$u['id'] ? 'selected':'' ?>>
            <?= h($u['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-sm-6 col-md-2">
        <label class="form-label small fw-semibold">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All</option>
          <option value="present"  <?= $filterSt==='present'  ?'selected':''?>>Present</option>
          <option value="absent"   <?= $filterSt==='absent'   ?'selected':''?>>Absent</option>
          <option value="on_leave" <?= $filterSt==='on_leave' ?'selected':''?>>On Leave</option>
          <option value="half_day" <?= $filterSt==='half_day' ?'selected':''?>>Half Day</option>
        </select>
      </div>

      <div class="col-auto d-flex gap-2 align-self-end">
        <button type="submit" class="btn btn-primary btn-sm">
          <i class="bi bi-funnel me-1"></i>Apply
        </button>
        <a href="admin_reports.php" class="btn btn-outline-secondary btn-sm">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- ── Summary Cards ─────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <?php
  $summaryMeta = [
    'present'  => ['label'=>'Present',  'icon'=>'bi-person-check-fill', 'color'=>'success'],
    'absent'   => ['label'=>'Absent',   'icon'=>'bi-person-x-fill',     'color'=>'danger'],
    'on_leave' => ['label'=>'On Leave', 'icon'=>'bi-umbrella-fill',      'color'=>'info'],
    'half_day' => ['label'=>'Half Day', 'icon'=>'bi-clock-history',      'color'=>'warning'],
  ];
  foreach ($summaryMeta as $key => $meta): ?>
  <div class="col-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-<?= $meta['color'] ?>-subtle text-<?= $meta['color'] ?>">
          <i class="bi <?= $meta['icon'] ?>"></i>
        </div>
        <div>
          <div class="stat-value"><?= $summary[$key] ?></div>
          <div class="stat-label"><?= $meta['label'] ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Records Table ─────────────────────────────────────────── -->
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent border-bottom d-flex align-items-center justify-content-between">
    <span class="fw-semibold small">
      Records: <?= count($records) ?>
      &middot; <?= h(date('d M Y', strtotime($startDate))) ?>
      <?= $startDate !== $endDate ? ' &ndash; ' . h(date('d M Y', strtotime($endDate))) : '' ?>
    </span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" id="reportTable">
      <thead class="table-light">
        <tr>
          <th class="ps-3">Date</th>
          <th>Employee</th>
          <th>Role</th>
          <th>Check-In</th>
          <th>Check-Out</th>
          <th>Hours</th>
          <th>Status</th>
          <th>Day-End Notes</th>
          <th class="pe-3">Location</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($records)): ?>
        <tr>
          <td colspan="9" class="text-center py-5 text-muted">
            <i class="bi bi-search fs-1 d-block mb-2 opacity-25"></i>
            No attendance records found for the selected filters.
          </td>
        </tr>
        <?php endif; ?>
        <?php foreach ($records as $r): ?>
        <tr>
          <td class="ps-3 small fw-semibold">
            <?= h(date('d M Y', strtotime($r['work_date']))) ?>
          </td>
          <td class="small"><?= h($r['name']) ?></td>
          <td class="small text-muted"><?= h(ucfirst(str_replace('_',' ',$r['role']))) ?></td>
          <td class="small">
            <?= $r['check_in_time'] ? h(date('h:i A', strtotime($r['check_in_time']))) : '—' ?>
          </td>
          <td class="small">
            <?= $r['check_out_time'] ? h(date('h:i A', strtotime($r['check_out_time']))) : '—' ?>
          </td>
          <td class="small"><?= workDuration($r['check_in_time'], $r['check_out_time']) ?></td>
          <td><?= attBadge($r['status']) ?></td>
          <td class="small" style="max-width:260px">
            <?php if (!empty($r['day_end_notes'])): ?>
            <details>
              <summary class="text-primary" style="cursor:pointer;font-size:.78rem">
                <i class="bi bi-chat-text me-1"></i>View Notes
              </summary>
              <div class="mt-1 p-2 bg-light rounded text-dark" style="white-space:pre-wrap;font-size:.78rem">
                <?= h($r['day_end_notes']) ?>
              </div>
            </details>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="pe-3 small text-muted">
            <?php if ($r['lat_long']): ?>
            <a href="https://maps.google.com/?q=<?= h($r['lat_long']) ?>"
               target="_blank" class="text-primary text-decoration-none">
              <i class="bi bi-geo-alt-fill me-1"></i>Map
            </a>
            <?php else: ?>
            <?= $r['ip_address'] ? h($r['ip_address']) : '—' ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
