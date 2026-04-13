<?php
// ============================================================
//  HRMS · KPI Dashboard  (Admin only)
//  Per-employee metrics: tasks, attendance, punctuality, leaves
// ============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
requireRole('admin');

$user = getCurrentUser();
$db   = getDB();

// ── Filters ───────────────────────────────────────────────────
$filterUid   = (int)($_GET['user_id'] ?? 0);
$filterMonth = $_GET['month'] ?? date('Y-m'); // YYYY-MM
$monthStart  = $filterMonth . '-01';
$monthEnd    = date('Y-m-t', strtotime($monthStart));

$allStaff = $db->query(
    "SELECT id, name, role FROM users WHERE role != 'admin' AND is_active = 1 ORDER BY name"
)->fetchAll();

// ── If no user selected, show all-staff overview ──────────────
$selectedUser = null;
if ($filterUid) {
    $stU = $db->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $stU->execute([$filterUid]);
    $selectedUser = $stU->fetch();
}

// ── Compute KPI for a single user ─────────────────────────────
function computeKPI(PDO $db, int $uid, string $monthStart, string $monthEnd): array
{
    // ── Tasks ─────────────────────────────────────────────────
    $stTasks = $db->prepare(
        "SELECT status FROM tasks
          WHERE user_id = ? AND DATE(created_at) BETWEEN ? AND ?"
    );
    $stTasks->execute([$uid, $monthStart, $monthEnd]);
    $tasks = $stTasks->fetchAll();

    $taskTotal     = count($tasks);
    $taskCompleted = count(array_filter($tasks, fn($t) => $t['status'] === 'completed'));
    $taskPending   = count(array_filter($tasks, fn($t) => $t['status'] === 'pending'));
    $taskProgress  = count(array_filter($tasks, fn($t) => $t['status'] === 'in_progress'));
    $taskOverdue   = count(array_filter($tasks, fn($t) => $t['status'] === 'overdue'));
    $taskScore     = $taskTotal > 0 ? round(($taskCompleted / $taskTotal) * 100) : 0;

    // ── Attendance ────────────────────────────────────────────
    $stAtt = $db->prepare(
        "SELECT status, check_in_time, check_out_time
           FROM attendance
          WHERE user_id = ? AND work_date BETWEEN ? AND ?"
    );
    $stAtt->execute([$uid, $monthStart, $monthEnd]);
    $attRows = $stAtt->fetchAll();

    $attPresent  = count(array_filter($attRows, fn($a) => $a['status'] === 'present'));
    $attAbsent   = count(array_filter($attRows, fn($a) => $a['status'] === 'absent'));
    $attLeave    = count(array_filter($attRows, fn($a) => $a['status'] === 'on_leave'));
    $attHalfDay  = count(array_filter($attRows, fn($a) => $a['status'] === 'half_day'));
    $attTotal    = $attPresent + $attAbsent + $attLeave + $attHalfDay;

    // Count approved-leave days that have no attendance record
    $stApproved = $db->prepare(
        "SELECT DATEDIFF(LEAST(end_date,?), GREATEST(start_date,?)) + 1 AS days
           FROM leave_requests
          WHERE user_id = ? AND status = 'approved'
            AND end_date >= ? AND start_date <= ?"
    );
    $stApproved->execute([$monthEnd, $monthStart, $uid, $monthStart, $monthEnd]);
    $approvedLeaveDays = array_sum(array_column($stApproved->fetchAll(), 'days'));

    $attScore = $attTotal > 0
        ? round((($attPresent + $attHalfDay * 0.5 + $attLeave) / $attTotal) * 100)
        : 0;

    // ── Punctuality (on-time = check-in by 09:30) ────────────
    $onTime = 0; $totalCheckedIn = 0;
    foreach ($attRows as $a) {
        if (!$a['check_in_time']) continue;
        $totalCheckedIn++;
        $h = (int)date('H', strtotime($a['check_in_time']));
        $m = (int)date('i', strtotime($a['check_in_time']));
        if ($h < 9 || ($h === 9 && $m <= 30)) $onTime++;
    }
    $punctualityScore = $totalCheckedIn > 0 ? round(($onTime / $totalCheckedIn) * 100) : 0;

    // ── Avg work hours ────────────────────────────────────────
    $totalMins = 0; $daysWithBoth = 0;
    foreach ($attRows as $a) {
        if ($a['check_in_time'] && $a['check_out_time']) {
            $totalMins += (strtotime($a['check_out_time']) - strtotime($a['check_in_time'])) / 60;
            $daysWithBoth++;
        }
    }
    $avgHours = $daysWithBoth > 0 ? round($totalMins / $daysWithBoth / 60, 1) : 0;

    // ── Leave ────────────────────────────────────────────────
    $stLeaves = $db->prepare(
        "SELECT type, status FROM leave_requests
          WHERE user_id = ? AND ((start_date BETWEEN ? AND ?) OR (end_date BETWEEN ? AND ?))"
    );
    $stLeaves->execute([$uid, $monthStart, $monthEnd, $monthStart, $monthEnd]);
    $leaves = $stLeaves->fetchAll();
    $leaveApproved = count(array_filter($leaves, fn($l) => $l['status'] === 'approved'));
    $leavePending  = count(array_filter($leaves, fn($l) => $l['status'] === 'pending'));
    $leaveRejected = count(array_filter($leaves, fn($l) => $l['status'] === 'rejected'));

    // ── Overall score (weighted) ──────────────────────────────
    $overallScore = (int)round($taskScore * 0.40 + $attScore * 0.35 + $punctualityScore * 0.25);

    $rating = match(true) {
        $overallScore >= 90 => ['label' => 'Excellent',         'color' => 'success'],
        $overallScore >= 75 => ['label' => 'Good',              'color' => 'primary'],
        $overallScore >= 60 => ['label' => 'Average',           'color' => 'warning'],
        default             => ['label' => 'Needs Improvement', 'color' => 'danger'],
    };

    return compact(
        'taskTotal','taskCompleted','taskPending','taskProgress','taskOverdue','taskScore',
        'attPresent','attAbsent','attLeave','attHalfDay','attTotal','attScore',
        'onTime','totalCheckedIn','punctualityScore','avgHours',
        'leaveApproved','leavePending','leaveRejected','approvedLeaveDays',
        'overallScore','rating'
    );
}

// Pre-compute KPIs for all staff (overview table)
$allKPIs = [];
foreach ($allStaff as $s) {
    $allKPIs[$s['id']] = computeKPI($db, $s['id'], $monthStart, $monthEnd);
}

// Single-user detailed KPI
$kpi = $filterUid && $selectedUser ? computeKPI($db, $filterUid, $monthStart, $monthEnd) : null;

// ── CSV Export ────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="kpi_' . $filterMonth . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Employee','Role','Month',
        'Tasks Assigned','Tasks Completed','Task Score (%)',
        'Days Present','Days Absent','On Leave','Attendance Score (%)',
        'Punctuality Score (%)','Avg Work Hours',
        'Leaves Approved','Overall Score (%)','Rating']);
    foreach ($allStaff as $s) {
        $k = $allKPIs[$s['id']];
        fputcsv($out, [
            $s['name'], ucfirst(str_replace('_',' ',$s['role'])), $filterMonth,
            $k['taskTotal'], $k['taskCompleted'], $k['taskScore'],
            $k['attPresent'], $k['attAbsent'], $k['attLeave'], $k['attScore'],
            $k['punctualityScore'], $k['avgHours'],
            $k['leaveApproved'], $k['overallScore'], $k['rating']['label'],
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'KPI Dashboard';
include __DIR__ . '/includes/header.php';
?>

<!-- Load Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

<!-- Header -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <h5 class="fw-bold mb-0">
    <i class="bi bi-bar-chart-fill me-2 text-primary"></i>KPI Dashboard
  </h5>
  <a href="?month=<?= h($filterMonth) ?>&user_id=<?= $filterUid ?>&export=csv"
     class="btn btn-outline-success btn-sm">
    <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
  </a>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold">Month</label>
        <input type="month" class="form-control form-control-sm" name="month"
               value="<?= h($filterMonth) ?>" max="<?= date('Y-m') ?>">
      </div>
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold">Employee (Detailed View)</label>
        <select name="user_id" class="form-select form-select-sm">
          <option value="">All Employees (Overview)</option>
          <?php foreach ($allStaff as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $filterUid === (int)$s['id'] ? 'selected':'' ?>>
            <?= h($s['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary btn-sm">
          <i class="bi bi-bar-chart me-1"></i>Generate
        </button>
      </div>
    </form>
  </div>
</div>

<?php if ($kpi && $selectedUser): ?>
<!-- ============================================================
     DETAILED VIEW — Single Employee
     ============================================================ -->

<!-- Score header -->
<div class="card border-0 shadow-sm mb-4 overflow-hidden">
  <div class="card-body p-4">
    <div class="row align-items-center g-4">
      <div class="col-auto">
        <div class="kpi-avatar"><?= strtoupper(substr($selectedUser['name'],0,1)) ?></div>
      </div>
      <div class="col">
        <h5 class="fw-bold mb-0"><?= h($selectedUser['name']) ?></h5>
        <div class="text-muted small"><?= ucfirst(str_replace('_',' ',$selectedUser['role'])) ?>
          &middot; <?= date('F Y', strtotime($monthStart)) ?>
        </div>
        <span class="badge bg-<?= $kpi['rating']['color'] ?> mt-1 px-3 py-1 fs-6">
          <?= $kpi['rating']['label'] ?>
        </span>
      </div>
      <div class="col-auto text-center">
        <div class="kpi-score-ring">
          <svg viewBox="0 0 120 120" width="120" height="120">
            <circle cx="60" cy="60" r="52" fill="none" stroke="#E2E8F0" stroke-width="10"/>
            <circle cx="60" cy="60" r="52" fill="none"
                    stroke="<?= match($kpi['rating']['color']) {
                        'success'=>'#22C55E','primary'=>'#4F46E5',
                        'warning'=>'#F59E0B',default=>'#EF4444'} ?>"
                    stroke-width="10" stroke-linecap="round"
                    stroke-dasharray="<?= round(2*M_PI*52*$kpi['overallScore']/100, 1) ?> 327"
                    stroke-dashoffset="82" transform="rotate(-90 60 60)"/>
            <text x="60" y="55" text-anchor="middle" font-size="22" font-weight="700" fill="#1E293B">
              <?= $kpi['overallScore'] ?>%
            </text>
            <text x="60" y="72" text-anchor="middle" font-size="10" fill="#64748B">
              Overall
            </text>
          </svg>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Metric score bars -->
<div class="row g-3 mb-4">
  <?php
  $metrics = [
    ['label'=>'Task Completion', 'score'=>$kpi['taskScore'],        'icon'=>'bi-list-check',    'color'=>'primary'],
    ['label'=>'Attendance',      'score'=>$kpi['attScore'],         'icon'=>'bi-calendar-check','color'=>'success'],
    ['label'=>'Punctuality',     'score'=>$kpi['punctualityScore'], 'icon'=>'bi-clock-fill',    'color'=>'info'],
  ];
  foreach ($metrics as $m): ?>
  <div class="col-12 col-md-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="small fw-semibold"><i class="bi <?= $m['icon'] ?> me-1 text-<?= $m['color'] ?>"></i>
            <?= $m['label'] ?>
          </span>
          <span class="fw-bold text-<?= $m['color'] ?>"><?= $m['score'] ?>%</span>
        </div>
        <div class="progress" style="height:8px">
          <div class="progress-bar bg-<?= $m['color'] ?>"
               style="width:<?= $m['score'] ?>%"></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Charts row -->
<div class="row g-4 mb-4">

  <!-- Task Breakdown Pie -->
  <div class="col-12 col-md-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent fw-semibold small border-bottom">
        <i class="bi bi-pie-chart-fill me-1 text-primary"></i>Task Breakdown
        <span class="text-muted fw-normal">(<?= date('M Y', strtotime($monthStart)) ?>)</span>
      </div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <?php if ($kpi['taskTotal'] === 0): ?>
        <p class="text-muted small">No tasks assigned this month.</p>
        <?php else: ?>
        <canvas id="taskPieChart" height="220"></canvas>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Attendance Breakdown Pie -->
  <div class="col-12 col-md-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent fw-semibold small border-bottom">
        <i class="bi bi-pie-chart-fill me-1 text-success"></i>Attendance Breakdown
      </div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <?php if ($kpi['attTotal'] === 0): ?>
        <p class="text-muted small">No attendance records this month.</p>
        <?php else: ?>
        <canvas id="attPieChart" height="220"></canvas>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<!-- Stats grid -->
<div class="row g-3 mb-4">
  <?php
  $statCards = [
    ['v'=>$kpi['taskCompleted'],     'l'=>'Tasks Completed',   'i'=>'bi-check-circle-fill',  'c'=>'success'],
    ['v'=>$kpi['taskOverdue'],       'l'=>'Tasks Overdue',     'i'=>'bi-exclamation-circle', 'c'=>'danger'],
    ['v'=>$kpi['attPresent'],        'l'=>'Days Present',      'i'=>'bi-person-check-fill',  'c'=>'primary'],
    ['v'=>$kpi['attAbsent'],         'l'=>'Days Absent',       'i'=>'bi-person-x-fill',      'c'=>'danger'],
    ['v'=>$kpi['approvedLeaveDays'], 'l'=>'Leave Days Taken',  'i'=>'bi-umbrella-fill',      'c'=>'info'],
    ['v'=>$kpi['avgHours'] . 'h',    'l'=>'Avg Work Hours',   'i'=>'bi-clock-history',      'c'=>'warning'],
    ['v'=>$kpi['onTime'],            'l'=>'On-Time Check-Ins', 'i'=>'bi-alarm',              'c'=>'success'],
    ['v'=>$kpi['leaveApproved'],     'l'=>'Leaves Approved',   'i'=>'bi-calendar-check',    'c'=>'primary'],
  ];
  foreach ($statCards as $sc): ?>
  <div class="col-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-<?= $sc['c'] ?>-subtle text-<?= $sc['c'] ?>">
          <i class="bi <?= $sc['i'] ?>"></i>
        </div>
        <div>
          <div class="stat-value"><?= $sc['v'] ?></div>
          <div class="stat-label"><?= $sc['l'] ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Chart.js init (single employee) -->
<script>
const chartDefaults = {
  plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 16, font: { size: 12 } } } },
  responsive: true, maintainAspectRatio: false,
};

<?php if ($kpi['taskTotal'] > 0): ?>
new Chart(document.getElementById('taskPieChart'), {
  type: 'doughnut',
  data: {
    labels: ['Completed','In Progress','Pending','Overdue'],
    datasets: [{
      data: [<?= $kpi['taskCompleted'] ?>, <?= $kpi['taskProgress'] ?>,
             <?= $kpi['taskPending'] ?>, <?= $kpi['taskOverdue'] ?>],
      backgroundColor: ['#22C55E','#38BDF8','#F59E0B','#EF4444'],
      borderWidth: 2, borderColor: '#fff',
    }]
  },
  options: { ...chartDefaults, cutout: '65%' }
});
<?php endif; ?>

<?php if ($kpi['attTotal'] > 0): ?>
new Chart(document.getElementById('attPieChart'), {
  type: 'doughnut',
  data: {
    labels: ['Present','Absent','On Leave','Half Day'],
    datasets: [{
      data: [<?= $kpi['attPresent'] ?>, <?= $kpi['attAbsent'] ?>,
             <?= $kpi['attLeave'] ?>,   <?= $kpi['attHalfDay'] ?>],
      backgroundColor: ['#22C55E','#EF4444','#38BDF8','#F59E0B'],
      borderWidth: 2, borderColor: '#fff',
    }]
  },
  options: { ...chartDefaults, cutout: '65%' }
});
<?php endif; ?>
</script>

<?php else: ?>
<!-- ============================================================
     OVERVIEW TABLE — All Employees
     ============================================================ -->

<!-- Monthly bar chart — all employees overall score -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-transparent fw-semibold border-bottom small">
    <i class="bi bi-bar-chart-fill me-1 text-primary"></i>
    Overall Performance Score — <?= date('F Y', strtotime($monthStart)) ?>
  </div>
  <div class="card-body" style="height:260px">
    <canvas id="overviewBarChart"></canvas>
  </div>
</div>

<!-- All-employee KPI table -->
<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="ps-3">Employee</th>
          <th class="text-center">Tasks ✓</th>
          <th class="text-center">Task Score</th>
          <th class="text-center">Present Days</th>
          <th class="text-center">Att. Score</th>
          <th class="text-center">Punctuality</th>
          <th class="text-center">Avg Hours</th>
          <th class="text-center">Overall</th>
          <th class="pe-3 text-center">Rating</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($allStaff as $s):
          $k = $allKPIs[$s['id']];
        ?>
        <tr>
          <td class="ps-3">
            <a href="?month=<?= h($filterMonth) ?>&user_id=<?= $s['id'] ?>"
               class="d-flex align-items-center gap-2 text-decoration-none text-dark">
              <div class="avatar-circle avatar-sm"><?= strtoupper(substr($s['name'],0,1)) ?></div>
              <div>
                <div class="fw-semibold small"><?= h($s['name']) ?></div>
                <div class="text-muted" style="font-size:.7rem">
                  <?= ucfirst(str_replace('_',' ',$s['role'])) ?>
                </div>
              </div>
            </a>
          </td>
          <td class="text-center small"><?= $k['taskCompleted'] ?>/<?= $k['taskTotal'] ?></td>
          <td class="text-center">
            <div class="d-flex align-items-center justify-content-center gap-1">
              <div class="progress flex-grow-1" style="height:6px;max-width:60px">
                <div class="progress-bar bg-primary" style="width:<?= $k['taskScore'] ?>%"></div>
              </div>
              <span class="small fw-semibold"><?= $k['taskScore'] ?>%</span>
            </div>
          </td>
          <td class="text-center small"><?= $k['attPresent'] ?></td>
          <td class="text-center">
            <div class="d-flex align-items-center justify-content-center gap-1">
              <div class="progress flex-grow-1" style="height:6px;max-width:60px">
                <div class="progress-bar bg-success" style="width:<?= $k['attScore'] ?>%"></div>
              </div>
              <span class="small fw-semibold"><?= $k['attScore'] ?>%</span>
            </div>
          </td>
          <td class="text-center small fw-semibold text-info"><?= $k['punctualityScore'] ?>%</td>
          <td class="text-center small"><?= $k['avgHours'] ?>h</td>
          <td class="text-center">
            <span class="fw-bold fs-6 text-<?= $k['rating']['color'] ?>"><?= $k['overallScore'] ?>%</span>
          </td>
          <td class="text-center pe-3">
            <span class="badge bg-<?= $k['rating']['color'] ?>"><?= $k['rating']['label'] ?></span>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($allStaff)): ?>
        <tr><td colspan="9" class="text-center py-5 text-muted">No staff found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
new Chart(document.getElementById('overviewBarChart'), {
  type: 'bar',
  data: {
    labels: [<?= implode(',', array_map(fn($s) => '"' . addslashes($s['name']) . '"', $allStaff)) ?>],
    datasets: [
      {
        label: 'Task Score (%)',
        data: [<?= implode(',', array_map(fn($s) => $allKPIs[$s['id']]['taskScore'], $allStaff)) ?>],
        backgroundColor: 'rgba(79,70,229,0.75)', borderRadius: 6,
      },
      {
        label: 'Attendance Score (%)',
        data: [<?= implode(',', array_map(fn($s) => $allKPIs[$s['id']]['attScore'], $allStaff)) ?>],
        backgroundColor: 'rgba(34,197,94,0.75)', borderRadius: 6,
      },
      {
        label: 'Punctuality Score (%)',
        data: [<?= implode(',', array_map(fn($s) => $allKPIs[$s['id']]['punctualityScore'], $allStaff)) ?>],
        backgroundColor: 'rgba(56,189,248,0.75)', borderRadius: 6,
      },
      {
        label: 'Overall Score (%)',
        data: [<?= implode(',', array_map(fn($s) => $allKPIs[$s['id']]['overallScore'], $allStaff)) ?>],
        backgroundColor: 'rgba(245,158,11,0.85)', borderRadius: 6, borderWidth: 0,
      },
    ]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    scales: {
      y: { beginAtZero: true, max: 100, grid: { color: '#F1F5F9' },
           ticks: { callback: v => v + '%' } },
      x: { grid: { display: false } }
    },
    plugins: {
      legend: { position: 'bottom', labels: { boxWidth: 12, padding: 16, font: { size: 11 } } },
      tooltip: { callbacks: { label: ctx => ` ${ctx.dataset.label}: ${ctx.raw}%` } }
    }
  }
});
</script>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
