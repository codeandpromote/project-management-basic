<?php
// ============================================================
//  HRMS · Dashboard
// ============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
requireLogin();

$user = getCurrentUser();
if (!$user) { logout(); }

$db    = getDB();
$today = date('Y-m-d');
$role  = $user['role'];
$uid   = (int)$user['id'];

// ── Today's attendance ────────────────────────────────────────
$stAtt = $db->prepare(
    'SELECT * FROM attendance WHERE user_id = ? AND work_date = ? LIMIT 1'
);
$stAtt->execute([$uid, $today]);
$todayAtt = $stAtt->fetch();

// ── Check approved leave for today ───────────────────────────
$stLeaveToday = $db->prepare(
    "SELECT id FROM leave_requests
     WHERE user_id = ? AND status = 'approved'
       AND ? BETWEEN start_date AND end_date LIMIT 1"
);
$stLeaveToday->execute([$uid, $today]);
$onLeaveToday = (bool)$stLeaveToday->fetch();

// ── Task fetch helper ─────────────────────────────────────────
// Show a task if:
//   • It is pending / in_progress (always visible until done)
//   • It is overdue (deadline passed, not completed)
//   • It was completed today (so the user can still see it in their day)
// No hard date-window filter — any task assigned to the user appears.
function fetchTasks(PDO $db, int $uid, string $type, string $today): array
{
    $st = $db->prepare(
        "SELECT t.*, u.name AS creator_name
           FROM tasks t JOIN users u ON u.id = t.creator_id
          WHERE t.user_id = ?
            AND t.task_type = ?
            AND (
                  t.status IN ('pending','in_progress')
               OR (t.status = 'completed' AND DATE(t.completed_at) = ?)
               OR (t.status != 'completed' AND t.deadline < NOW())
                )
          ORDER BY
            FIELD(t.status,'in_progress','pending','overdue','completed'),
            t.deadline ASC"
    );
    $st->execute([$uid, $type, $today]);
    return $st->fetchAll();
}

$dailyTasks   = fetchTasks($db, $uid, 'daily',   $today);
$weeklyTasks  = fetchTasks($db, $uid, 'weekly',  $today);
$monthlyTasks = fetchTasks($db, $uid, 'monthly', $today);

// ── Today's location logs (field workers) ────────────────────
$todayLocations = [];
$myActiveTasks  = [];
$locationReady  = false;
if ($role === 'field_worker') {
    try {
        $stLoc = $db->prepare(
            "SELECT ll.*, t.title AS task_title
               FROM location_logs ll
               LEFT JOIN tasks t ON t.id = ll.task_id
              WHERE ll.user_id = ? AND ll.log_date = ?
              ORDER BY ll.logged_at ASC"
        );
        $stLoc->execute([$uid, $today]);
        $todayLocations = $stLoc->fetchAll();
        $locationReady  = true;

        // Calculate total km travelled today using Haversine
        $myTotalKmToday = 0.0;
        $prevLat = null; $prevLng = null;
        foreach ($todayLocations as $loc) {
            $lat = (float)$loc['lat']; $lng = (float)$loc['lng'];
            if ($lat === 0.0 && $lng === 0.0) { continue; }
            if ($prevLat !== null) {
                $dLat = deg2rad($lat - $prevLat);
                $dLng = deg2rad($lng - $prevLng);
                $a = sin($dLat/2)**2 + cos(deg2rad($prevLat))*cos(deg2rad($lat))*sin($dLng/2)**2;
                $myTotalKmToday += 6371.0 * 2 * atan2(sqrt($a), sqrt(1-$a));
            }
            $prevLat = $lat; $prevLng = $lng;
        }

        // Active tasks for location tag
        $stMyTasks = $db->prepare(
            "SELECT id, title FROM tasks
              WHERE user_id = ? AND status IN ('pending','in_progress')
              ORDER BY deadline ASC LIMIT 20"
        );
        $stMyTasks->execute([$uid]);
        $myActiveTasks = $stMyTasks->fetchAll();
    } catch (PDOException $e) {
        $locationReady = false; // location_logs table not yet created
    }
}

// ── My recent leave requests ──────────────────────────────────
$stLeaves = $db->prepare(
    'SELECT * FROM leave_requests WHERE user_id = ?
     ORDER BY created_at DESC LIMIT 5'
);
$stLeaves->execute([$uid]);
$myLeaves = $stLeaves->fetchAll();

// ── Lead follow-ups (today + overdue) for this user ────────────
// Auto-reappear feature: any lead with next_followup_date <= today
// and the user is the assignee or creator shows up here.
$myFollowupsToday = [];
$myFollowupsOverdue = [];
try {
    $stFu = $db->prepare(
        "SELECT l.id, l.name, l.phone, l.company, l.priority, l.status, l.next_followup_date
           FROM leads l
          WHERE l.is_deleted = 0
            AND l.status NOT IN ('won','lost')
            AND (l.assigned_to = ? OR l.creator_id = ?)
            AND l.next_followup_date = ?
          ORDER BY FIELD(l.priority,'hot','high','medium','low')"
    );
    $stFu->execute([$uid, $uid, $today]);
    $myFollowupsToday = $stFu->fetchAll();

    $stOv = $db->prepare(
        "SELECT l.id, l.name, l.phone, l.company, l.priority, l.status, l.next_followup_date
           FROM leads l
          WHERE l.is_deleted = 0
            AND l.status NOT IN ('won','lost')
            AND (l.assigned_to = ? OR l.creator_id = ?)
            AND l.next_followup_date < ?
          ORDER BY l.next_followup_date ASC, FIELD(l.priority,'hot','high','medium','low')
          LIMIT 20"
    );
    $stOv->execute([$uid, $uid, $today]);
    $myFollowupsOverdue = $stOv->fetchAll();
} catch (PDOException $e) {
    // leads table may not be ready on first load — silent fallback
}

// ── Admin stats ───────────────────────────────────────────────
$stats = [];
if ($role === 'admin') {
    $stats['total_staff'] = (int)$db->query(
        "SELECT COUNT(*) FROM users WHERE role != 'admin' AND is_active = 1"
    )->fetchColumn();

    $stPres = $db->prepare(
        "SELECT COUNT(*) FROM attendance WHERE work_date = ? AND status = 'present'"
    );
    $stPres->execute([$today]);
    $stats['present_today'] = (int)$stPres->fetchColumn();

    $stats['pending_leaves'] = (int)$db->query(
        "SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'"
    )->fetchColumn();

    $stats['open_tasks'] = (int)$db->query(
        "SELECT COUNT(*) FROM tasks WHERE status IN ('pending','in_progress')"
    )->fetchColumn();

    $stats['in_process_tasks'] = (int)$db->query(
        "SELECT COUNT(*) FROM tasks WHERE status = 'in_progress'"
    )->fetchColumn();

    // ── Chart data (admin only) ────────────────────────────────
    $chartData = [
        'taskStatus'    => ['pending' => 0, 'in_progress' => 0, 'completed' => 0, 'overdue' => 0],
        'topEmployees'  => ['labels' => [], 'values' => []],
        'attendance'    => ['labels' => [], 'values' => []],
        'leadStatus'    => ['labels' => [], 'values' => []],
    ];

    // Task status distribution (treat not-completed + past-deadline as overdue)
    try {
        $taskRows = $db->query(
            "SELECT status,
                    SUM(CASE WHEN status != 'completed' AND deadline IS NOT NULL AND deadline < NOW()
                             THEN 1 ELSE 0 END) AS overdue_cnt,
                    COUNT(*) AS cnt
               FROM tasks
              GROUP BY status"
        )->fetchAll();
        $overdueTotal = 0;
        foreach ($taskRows as $r) {
            $st  = $r['status'];
            $cnt = (int)$r['cnt'];
            $od  = (int)$r['overdue_cnt'];
            if ($st === 'completed') {
                $chartData['taskStatus']['completed'] += $cnt;
            } else {
                $chartData['taskStatus'][$st] = ($chartData['taskStatus'][$st] ?? 0) + ($cnt - $od);
                $overdueTotal += $od;
            }
        }
        $chartData['taskStatus']['overdue'] = $overdueTotal;
    } catch (PDOException $ignored) {}

    // Top 5 employees by total tasks assigned
    try {
        $topRows = $db->query(
            "SELECT u.name, COUNT(t.id) AS cnt
               FROM users u
          LEFT JOIN tasks t ON t.user_id = u.id
              WHERE u.role != 'admin' AND u.is_active = 1
           GROUP BY u.id, u.name
           ORDER BY cnt DESC
              LIMIT 5"
        )->fetchAll();
        foreach ($topRows as $r) {
            $chartData['topEmployees']['labels'][] = $r['name'];
            $chartData['topEmployees']['values'][] = (int)$r['cnt'];
        }
    } catch (PDOException $ignored) {}

    // Attendance over last 14 days (present count per day)
    try {
        $stAttLine = $db->prepare(
            "SELECT work_date, COUNT(*) AS cnt
               FROM attendance
              WHERE status = 'present' AND work_date >= ?
           GROUP BY work_date
           ORDER BY work_date ASC"
        );
        $stAttLine->execute([date('Y-m-d', strtotime('-13 days'))]);
        $attMap = [];
        foreach ($stAttLine->fetchAll() as $r) {
            $attMap[$r['work_date']] = (int)$r['cnt'];
        }
        for ($i = 13; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $chartData['attendance']['labels'][] = date('d M', strtotime($d));
            $chartData['attendance']['values'][] = $attMap[$d] ?? 0;
        }
    } catch (PDOException $ignored) {}

    // Leads by status
    try {
        $leadRows = $db->query(
            "SELECT status, COUNT(*) AS cnt FROM leads GROUP BY status ORDER BY cnt DESC"
        )->fetchAll();
        foreach ($leadRows as $r) {
            $chartData['leadStatus']['labels'][] = ucfirst(str_replace('_', ' ', $r['status']));
            $chartData['leadStatus']['values'][] = (int)$r['cnt'];
        }
    } catch (PDOException $ignored) {}
}

// ── Check-out eligibility ─────────────────────────────────────
// Conditions: checked in + all daily tasks done + day-end file + day-end notes
$allDailyDone = !empty($dailyTasks) && !array_filter(
    $dailyTasks,
    fn($t) => $t['status'] !== 'completed'
) === true;
// simpler: count non-completed
$pendingDailyCount = count(array_filter(
    $dailyTasks,
    fn($t) => $t['status'] !== 'completed'
));
$allDailyDone   = ($pendingDailyCount === 0);
$hasCheckedIn   = !empty($todayAtt['check_in_time']);
$hasCheckedOut  = !empty($todayAtt['check_out_time']);
$hasDayEndFile  = !empty($todayAtt['day_end_file']);
$hasDayEndNotes = !empty($todayAtt['day_end_notes']);
$canCheckOut    = $hasCheckedIn && !$hasCheckedOut
               && ($allDailyDone || empty($dailyTasks))
               && $hasDayEndFile
               && $hasDayEndNotes;

// ── Status badge helper ───────────────────────────────────────
function statusBadge(string $status): string {
    return match($status) {
        'pending'    => '<span class="badge bg-warning text-dark">Pending</span>',
        'in_progress'=> '<span class="badge bg-info text-dark">In Progress</span>',
        'completed'  => '<span class="badge bg-success">Completed</span>',
        'overdue'    => '<span class="badge bg-danger">Overdue</span>',
        'approved'   => '<span class="badge bg-success">Approved</span>',
        'rejected'   => '<span class="badge bg-danger">Rejected</span>',
        default      => '<span class="badge bg-secondary">' . h(ucfirst($status)) . '</span>',
    };
}

$pageTitle = 'Dashboard';
include __DIR__ . '/includes/header.php';
?>

<!-- ── Admin Stats Row ──────────────────────────────────────── -->
<?php if ($role === 'admin'): ?>
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3">
    <div class="card stat-card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-primary-subtle text-primary">
          <i class="bi bi-people-fill"></i>
        </div>
        <div>
          <div class="stat-value"><?= $stats['total_staff'] ?></div>
          <div class="stat-label">Total Staff</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card stat-card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-success-subtle text-success">
          <i class="bi bi-person-check-fill"></i>
        </div>
        <div>
          <div class="stat-value"><?= $stats['present_today'] ?></div>
          <div class="stat-label">Present Today</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card stat-card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-warning-subtle text-warning">
          <i class="bi bi-calendar-x-fill"></i>
        </div>
        <div>
          <div class="stat-value"><?= $stats['pending_leaves'] ?></div>
          <div class="stat-label">Pending Leaves</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card stat-card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-danger-subtle text-danger">
          <i class="bi bi-list-task"></i>
        </div>
        <div>
          <div class="stat-value"><?= $stats['open_tasks'] ?></div>
          <div class="stat-label">Open Tasks</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <a href="tasks.php?status=in_progress" class="text-decoration-none text-reset">
      <div class="card stat-card border-0 shadow-sm h-100">
        <div class="card-body d-flex align-items-center gap-3">
          <div class="stat-icon bg-info-subtle text-info">
            <i class="bi bi-hourglass-split"></i>
          </div>
          <div>
            <div class="stat-value"><?= $stats['in_process_tasks'] ?></div>
            <div class="stat-label">In Process Jobs</div>
          </div>
        </div>
      </div>
    </a>
  </div>
</div>

<!-- ── Admin Charts Row ─────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-12 col-lg-6 col-xl-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent border-bottom fw-semibold d-flex align-items-center gap-2 py-2">
        <i class="bi bi-pie-chart-fill text-primary"></i>
        <span class="small">Task Status</span>
      </div>
      <div class="card-body d-flex align-items-center justify-content-center" style="min-height:240px">
        <canvas id="chartTaskStatus"></canvas>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-6 col-xl-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent border-bottom fw-semibold d-flex align-items-center gap-2 py-2">
        <i class="bi bi-bar-chart-fill text-success"></i>
        <span class="small">Top 5 Employees · Tasks Assigned</span>
      </div>
      <div class="card-body d-flex align-items-center justify-content-center" style="min-height:240px">
        <canvas id="chartTopEmployees"></canvas>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-6 col-xl-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent border-bottom fw-semibold d-flex align-items-center gap-2 py-2">
        <i class="bi bi-graph-up text-info"></i>
        <span class="small">Attendance · Last 14 Days</span>
      </div>
      <div class="card-body d-flex align-items-center justify-content-center" style="min-height:240px">
        <canvas id="chartAttendance"></canvas>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-6 col-xl-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent border-bottom fw-semibold d-flex align-items-center gap-2 py-2">
        <i class="bi bi-funnel-fill text-warning"></i>
        <span class="small">Leads by Status</span>
      </div>
      <div class="card-body d-flex align-items-center justify-content-center" style="min-height:240px">
        <canvas id="chartLeadStatus"></canvas>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script>
(function () {
  if (typeof Chart === 'undefined') { return; }
  var CD = <?= json_encode($chartData, JSON_UNESCAPED_UNICODE) ?>;

  // Pie · Task status
  var ts = CD.taskStatus;
  new Chart(document.getElementById('chartTaskStatus'), {
    type: 'pie',
    data: {
      labels: ['Pending', 'In Progress', 'Completed', 'Overdue'],
      datasets: [{
        data: [ts.pending, ts.in_progress, ts.completed, ts.overdue],
        backgroundColor: ['#ffc107', '#0dcaf0', '#198754', '#dc3545'],
        borderWidth: 1
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom', labels: { font: { size: 10 } } } }
    }
  });

  // Bar · Top employees
  new Chart(document.getElementById('chartTopEmployees'), {
    type: 'bar',
    data: {
      labels: CD.topEmployees.labels,
      datasets: [{
        label: 'Tasks',
        data: CD.topEmployees.values,
        backgroundColor: '#198754',
        borderRadius: 4
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      indexAxis: 'y',
      plugins: { legend: { display: false } },
      scales: {
        x: { beginAtZero: true, ticks: { precision: 0 } }
      }
    }
  });

  // Line · Attendance
  new Chart(document.getElementById('chartAttendance'), {
    type: 'line',
    data: {
      labels: CD.attendance.labels,
      datasets: [{
        label: 'Present',
        data: CD.attendance.values,
        borderColor: '#0dcaf0',
        backgroundColor: 'rgba(13,202,240,0.15)',
        fill: true, tension: 0.3, pointRadius: 3
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
  });

  // Doughnut · Leads
  new Chart(document.getElementById('chartLeadStatus'), {
    type: 'doughnut',
    data: {
      labels: CD.leadStatus.labels,
      datasets: [{
        data: CD.leadStatus.values,
        backgroundColor: ['#0d6efd','#0dcaf0','#6610f2','#ffc107','#fd7e14','#198754','#dc3545','#6c757d'],
        borderWidth: 1
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom', labels: { font: { size: 10 } } } }
    }
  });
})();
</script>
<?php endif; ?>

<div class="row g-4">

  <!-- ── Left column: Attendance ─────────────────────────────── -->
  <div class="col-12 col-xl-4">

    <!-- Attendance Card -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-transparent border-bottom fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-clock-history text-primary"></i> Today's Attendance
        <span class="ms-auto small text-muted"><?= date('d M Y') ?></span>
      </div>
      <div class="card-body">

        <?php if ($onLeaveToday): ?>
        <div class="alert alert-info d-flex align-items-center gap-2 mb-3 py-2">
          <i class="bi bi-umbrella-fill"></i>
          <span class="small">You are on <strong>approved leave</strong> today.</span>
        </div>
        <?php endif; ?>

        <!-- Status -->
        <div class="attendance-status-grid mb-3">
          <div class="att-time-block">
            <div class="att-time-label"><i class="bi bi-sunrise me-1"></i>Check-In</div>
            <div class="att-time-value" id="checkInDisplay">
              <?= $hasCheckedIn
                  ? date('h:i A', strtotime($todayAtt['check_in_time']))
                  : '— : —' ?>
            </div>
          </div>
          <div class="att-divider"><i class="bi bi-arrow-right"></i></div>
          <div class="att-time-block">
            <div class="att-time-label"><i class="bi bi-sunset me-1"></i>Check-Out</div>
            <div class="att-time-value" id="checkOutDisplay">
              <?= $hasCheckedOut
                  ? date('h:i A', strtotime($todayAtt['check_out_time']))
                  : '— : —' ?>
            </div>
          </div>
        </div>

        <!-- GPS status indicator -->
        <div id="gpsStatus" class="alert alert-secondary py-2 small mb-3 d-flex align-items-center gap-2">
          <i class="bi bi-geo-alt-fill"></i>
          <span id="gpsStatusText">Click Check-In to capture your location.</span>
        </div>

        <!-- Action buttons -->
        <div class="d-grid gap-2">
          <?php if (!$hasCheckedIn && !$onLeaveToday): ?>
          <button class="btn btn-success btn-lg fw-semibold" id="btnCheckIn">
            <i class="bi bi-box-arrow-in-right me-2"></i>Check In
          </button>
          <?php elseif ($hasCheckedIn && !$hasCheckedOut): ?>
            <div class="checkin-done-banner mb-2">
              <i class="bi bi-check-circle-fill text-success me-2"></i>
              Checked in at <strong><?= date('h:i A', strtotime($todayAtt['check_in_time'])) ?></strong>
            </div>

            <!-- Day-end report -->
            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#dayEndModal">
              <i class="bi bi-file-earmark-text me-2"></i>Submit Day-End Report
              <?php if ($hasDayEndFile && $hasDayEndNotes): ?>
              <span class="badge bg-success ms-1">Done</span>
              <?php endif; ?>
            </button>

            <!-- Check-out -->
            <button class="btn btn-danger btn-lg fw-semibold" id="btnCheckOut"
              <?= $canCheckOut ? '' : 'disabled' ?>
              title="<?= $canCheckOut ? 'Check out now' : 'Complete all daily tasks and submit day-end report first' ?>">
              <i class="bi bi-box-arrow-right me-2"></i>Check Out
            </button>

            <?php if (!$canCheckOut): ?>
            <div class="checkout-requirements small text-muted mt-1">
              <p class="mb-1 fw-semibold text-dark">Before checking out:</p>
              <ul class="mb-0 ps-3">
                <?php if ($pendingDailyCount > 0): ?>
                <li class="text-danger">
                  <?= $pendingDailyCount ?> daily task<?= $pendingDailyCount > 1 ? 's' : '' ?> still pending
                </li>
                <?php else: ?>
                <li class="text-success">All daily tasks complete ✓</li>
                <?php endif; ?>
                <li class="<?= $hasDayEndFile ? 'text-success' : 'text-danger' ?>">
                  Day-end file <?= $hasDayEndFile ? 'uploaded ✓' : 'required' ?>
                </li>
                <li class="<?= $hasDayEndNotes ? 'text-success' : 'text-danger' ?>">
                  End-of-day notes <?= $hasDayEndNotes ? 'provided ✓' : 'required' ?>
                </li>
              </ul>
            </div>
            <?php endif; ?>

          <?php elseif ($hasCheckedOut): ?>
          <div class="alert alert-success mb-0 py-2 small text-center">
            <i class="bi bi-check-circle-fill me-1"></i>
            Checked out at <strong><?= date('h:i A', strtotime($todayAtt['check_out_time'])) ?></strong>
          </div>
          <?php endif; ?>
        </div>

      </div>
    </div><!-- /attendance card -->

    <!-- Leave Summary Card -->
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-transparent border-bottom fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-calendar2-check text-warning"></i> My Leave Requests
        <a href="leave_module.php" class="ms-auto btn btn-sm btn-outline-primary">
          <i class="bi bi-plus-lg me-1"></i>New
        </a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($myLeaves)): ?>
        <p class="text-muted text-center py-4 mb-0 small">No leave requests yet.</p>
        <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($myLeaves as $leave): ?>
          <li class="list-group-item d-flex align-items-start gap-2 py-2">
            <div class="flex-grow-1">
              <div class="small fw-semibold"><?= h(ucfirst($leave['type'])) ?> Leave</div>
              <div class="text-muted" style="font-size:.72rem">
                <?= h(date('d M', strtotime($leave['start_date']))) ?>
                &ndash;
                <?= h(date('d M Y', strtotime($leave['end_date']))) ?>
              </div>
            </div>
            <?= statusBadge($leave['status']) ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Lead Follow-ups (all roles) ─────────────────────────── -->
    <?php if (!empty($myFollowupsOverdue) || !empty($myFollowupsToday)): ?>
    <div class="card border-0 shadow-sm mt-4">
      <div class="card-header bg-transparent border-bottom fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-alarm-fill text-warning"></i>Lead Follow-ups
        <?php if (!empty($myFollowupsToday)): ?>
        <span class="badge bg-primary ms-1"><?= count($myFollowupsToday) ?> today</span>
        <?php endif; ?>
        <?php if (!empty($myFollowupsOverdue)): ?>
        <span class="badge bg-danger ms-1"><?= count($myFollowupsOverdue) ?> overdue</span>
        <?php endif; ?>
        <a href="leads.php" class="btn btn-sm btn-outline-primary ms-auto">
          <i class="bi bi-arrow-right"></i>
        </a>
      </div>
      <div class="card-body">
        <?php if (!empty($myFollowupsOverdue)): ?>
        <h6 class="small fw-bold text-danger mb-2">
          <i class="bi bi-exclamation-triangle-fill me-1"></i>Overdue
        </h6>
        <?php foreach ($myFollowupsOverdue as $fu): ?>
        <a href="lead_view.php?id=<?= (int)$fu['id'] ?>"
           class="d-flex align-items-center gap-2 p-2 border rounded mb-1 text-decoration-none text-dark">
          <span class="badge bg-<?= $fu['priority'] === 'hot' ? 'danger' : ($fu['priority'] === 'high' ? 'warning' : 'secondary') ?>"><?= h($fu['priority']) ?></span>
          <div class="flex-grow-1">
            <div class="fw-semibold small"><?= h($fu['name']) ?></div>
            <div class="text-muted small">
              <i class="bi bi-telephone me-1"></i><?= h($fu['phone']) ?>
              <?php if ($fu['company']): ?> &middot; <?= h($fu['company']) ?><?php endif; ?>
            </div>
          </div>
          <div class="text-danger small fw-bold">
            <?= h(date('d M', strtotime($fu['next_followup_date']))) ?>
          </div>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($myFollowupsToday)): ?>
        <h6 class="small fw-bold text-primary mt-3 mb-2">
          <i class="bi bi-calendar-event me-1"></i>Today
        </h6>
        <?php foreach ($myFollowupsToday as $fu): ?>
        <a href="lead_view.php?id=<?= (int)$fu['id'] ?>"
           class="d-flex align-items-center gap-2 p-2 border rounded mb-1 text-decoration-none text-dark">
          <span class="badge bg-<?= $fu['priority'] === 'hot' ? 'danger' : ($fu['priority'] === 'high' ? 'warning' : 'secondary') ?>"><?= h($fu['priority']) ?></span>
          <div class="flex-grow-1">
            <div class="fw-semibold small"><?= h($fu['name']) ?></div>
            <div class="text-muted small">
              <i class="bi bi-telephone me-1"></i><?= h($fu['phone']) ?>
              <?php if ($fu['company']): ?> &middot; <?= h($fu['company']) ?><?php endif; ?>
            </div>
          </div>
          <span class="badge bg-primary">Today</span>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Location Tracker (field workers only) ──────────────── -->
    <?php if ($role === 'field_worker'): ?>
    <div class="card border-0 shadow-sm mt-4" id="locationTrackerCard">
      <div class="card-header bg-transparent border-bottom fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-geo-alt-fill text-success"></i> Location Tracker
        <?php if ($locationReady): ?>
        <span class="badge bg-success ms-1" id="locCountBadge"><?= count($todayLocations) ?></span>
        <span class="text-muted fw-normal small ms-1">pings today</span>
        <?php if (isset($myTotalKmToday) && $myTotalKmToday > 0): ?>
        <span class="badge bg-warning text-dark ms-2">
          <i class="bi bi-speedometer2 me-1"></i><?= number_format($myTotalKmToday, 2) ?> km
        </span>
        <?php endif; ?>
        <?php endif; ?>
      </div>
      <div class="card-body">

        <?php if (!$locationReady): ?>
        <!-- Schema not updated yet -->
        <div class="alert alert-warning d-flex gap-2 align-items-start small mb-0">
          <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
          <div>
            <div class="fw-bold mb-1">Location tracking not set up yet</div>
            Ask your admin to run <strong>schema_update.sql</strong> in phpMyAdmin to enable this feature.
          </div>
        </div>

        <?php else: ?>
        <!-- Direct link — no JS needed, guaranteed to work on any mobile browser -->
        <a href="log_location.php" class="btn btn-success w-100 fw-semibold">
          <i class="bi bi-pin-map-fill me-2"></i>Log My Current Location
        </a>

        <!-- Today's log list -->
        <div id="locList">
          <?php if (empty($todayLocations)): ?>
          <p class="text-muted text-center small py-2 mb-0" id="locEmptyMsg">
            No locations logged today yet.
          </p>
          <?php else: ?>
          <?php foreach (array_reverse($todayLocations) as $loc):
            $hasGps = !((float)$loc['lat'] === 0.0 && (float)$loc['lng'] === 0.0);
          ?>
          <div class="loc-pill d-flex align-items-start gap-2 mb-2">
            <div class="loc-pill-dot flex-shrink-0"
                 style="<?= $hasGps ? '' : 'background:#94A3B8' ?>"></div>
            <div class="flex-grow-1">
              <div class="small fw-semibold">
                <?= h(date('h:i A', strtotime($loc['logged_at']))) ?>
                <?php if ($hasGps && $loc['accuracy']): ?>
                <span class="text-muted fw-normal">(±<?= $loc['accuracy'] ?>m)</span>
                <?php elseif (!$hasGps): ?>
                <span class="badge bg-secondary ms-1" style="font-size:.62rem">No GPS</span>
                <?php endif; ?>
              </div>
              <div class="text-muted" style="font-size:.72rem">
                <?php if ($hasGps): ?>
                <?= number_format((float)$loc['lat'],6) ?>, <?= number_format((float)$loc['lng'],6) ?>
                <?php else: ?>
                Activity logged without GPS
                <?php endif; ?>
              </div>
              <?php if ($loc['task_title']): ?>
              <span class="badge bg-primary-subtle text-primary" style="font-size:.65rem">
                <i class="bi bi-list-task me-1"></i><?= h($loc['task_title']) ?>
              </span>
              <?php endif; ?>
              <?php if ($loc['notes']): ?>
              <div class="text-muted fst-italic" style="font-size:.7rem">"<?= h($loc['notes']) ?>"</div>
              <?php endif; ?>
            </div>
            <?php if ($hasGps): ?>
            <a href="https://maps.google.com/?q=<?= $loc['lat'] ?>,<?= $loc['lng'] ?>"
               target="_blank" class="btn btn-xs btn-outline-success flex-shrink-0">
              <i class="bi bi-map"></i>
            </a>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <?php endif; // locationReady ?>

      </div>
    </div>
    <?php endif; ?>

  </div><!-- /left col -->

  <!-- ── Right column: Tasks ─────────────────────────────────── -->
  <div class="col-12 col-xl-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-transparent border-bottom">
        <div class="d-flex align-items-center gap-2 mb-2">
          <i class="bi bi-list-check text-primary fs-5"></i>
          <span class="fw-semibold">My Tasks</span>
          <?php if ($role === 'admin'): ?>
          <a href="tasks.php" class="ms-auto btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Assign Task
          </a>
          <?php endif; ?>
        </div>
        <!-- Task type tabs -->
        <ul class="nav nav-tabs card-header-tabs" id="taskTabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab"
                    data-bs-target="#daily" type="button">
              Daily
              <?php $d = count(array_filter($dailyTasks, fn($t) => $t['status'] !== 'completed')); ?>
              <?php if ($d > 0): ?>
              <span class="badge bg-danger ms-1"><?= $d ?></span>
              <?php endif; ?>
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab"
                    data-bs-target="#weekly" type="button">
              Weekly
              <?php $w = count(array_filter($weeklyTasks, fn($t) => $t['status'] !== 'completed')); ?>
              <?php if ($w > 0): ?>
              <span class="badge bg-warning text-dark ms-1"><?= $w ?></span>
              <?php endif; ?>
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab"
                    data-bs-target="#monthly" type="button">
              Monthly
              <?php $m = count(array_filter($monthlyTasks, fn($t) => $t['status'] !== 'completed')); ?>
              <?php if ($m > 0): ?>
              <span class="badge bg-info text-dark ms-1"><?= $m ?></span>
              <?php endif; ?>
            </button>
          </li>
        </ul>
      </div>

      <div class="card-body tab-content p-0" id="taskTabsContent">

        <!-- Daily tab -->
        <div class="tab-pane fade show active" id="daily" role="tabpanel">
          <?php renderTaskList($dailyTasks, 'No daily tasks assigned for today.'); ?>
        </div>

        <!-- Weekly tab -->
        <div class="tab-pane fade" id="weekly" role="tabpanel">
          <?php renderTaskList($weeklyTasks, 'No weekly tasks for this week.'); ?>
        </div>

        <!-- Monthly tab -->
        <div class="tab-pane fade" id="monthly" role="tabpanel">
          <?php renderTaskList($monthlyTasks, 'No monthly tasks for this month.'); ?>
        </div>

      </div>
    </div>
  </div><!-- /right col -->

</div><!-- /row -->

<!-- =========================================================
     Modals
     ========================================================= -->

<!-- Task Detail Modal -->
<div class="modal fade" id="taskDetailModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow">
      <div class="modal-header border-bottom">
        <h6 class="modal-title fw-bold" id="tdTitle">Task Details</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex flex-wrap gap-2 mb-3">
          <span id="tdTypeBadge" class="badge bg-primary-subtle text-primary"></span>
          <span id="tdStatusBadge" class="badge"></span>
        </div>
        <div class="row g-3 small">
          <div class="col-sm-6">
            <div class="text-muted fw-semibold mb-1"><i class="bi bi-calendar3 me-1"></i>Deadline</div>
            <div id="tdDeadline" class="fw-semibold">—</div>
          </div>
          <div class="col-sm-6">
            <div class="text-muted fw-semibold mb-1"><i class="bi bi-person me-1"></i>Assigned By</div>
            <div id="tdCreator">—</div>
          </div>
        </div>
        <div class="mt-3" id="tdDescBlock" style="display:none">
          <div class="text-muted fw-semibold small mb-1"><i class="bi bi-text-left me-1"></i>Description</div>
          <div id="tdDesc" class="small border rounded p-2 bg-light" style="white-space:pre-wrap"></div>
        </div>
        <div class="mt-3" id="tdAttachBlock" style="display:none">
          <div class="text-muted fw-semibold small mb-1"><i class="bi bi-paperclip me-1"></i>Task Attachment</div>
          <a id="tdAttachLink" href="#" target="_blank" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-download me-1"></i>Download Attachment
          </a>
        </div>
        <div class="mt-3" id="tdCompletionBlock" style="display:none">
          <hr class="my-3">
          <div class="text-muted fw-semibold small mb-1"><i class="bi bi-check-circle me-1"></i>Completion Notes</div>
          <div id="tdCompletionNotes" class="small border rounded p-2 bg-light" style="white-space:pre-wrap"></div>
          <div class="mt-2 text-muted small" id="tdCompletedAt"></div>
        </div>
        <div class="mt-3" id="tdProofBlock" style="display:none">
          <div class="text-muted fw-semibold small mb-1"><i class="bi bi-file-check me-1"></i>Proof of Completion</div>
          <a id="tdProofLink" href="#" target="_blank" class="btn btn-sm btn-outline-success">
            <i class="bi bi-download me-1"></i>Download Proof
          </a>
        </div>
        <div class="mt-3" id="tdRecordingBlock" style="display:none">
          <div class="text-muted fw-semibold small mb-1">
            <i class="bi bi-mic-fill me-1 text-primary"></i>Call Recording
          </div>
          <audio id="tdRecordingAudio" controls preload="none" class="w-100 mb-2"></audio>
          <a id="tdRecordingLink" href="#" target="_blank" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-download me-1"></i>Download Recording
          </a>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Day-End Report Modal -->
<div class="modal fade" id="dayEndModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header border-bottom">
        <h6 class="modal-title fw-bold">
          <i class="bi bi-file-earmark-text me-2 text-primary"></i>Day-End Report
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="dayEndForm" enctype="multipart/form-data">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold small">End-of-Day Notes <span class="text-danger">*</span></label>
            <textarea class="form-control" id="dayEndNotes" name="day_end_notes"
                      rows="4" placeholder="Summarise what you accomplished today..."
                      required><?= h($todayAtt['day_end_notes'] ?? '') ?></textarea>
          </div>
          <div class="mb-0">
            <label class="form-label fw-semibold small">
              Upload Day-End File <span class="text-danger">*</span>
            </label>
            <?php if ($hasDayEndFile): ?>
            <div class="alert alert-success py-2 small mb-2">
              <i class="bi bi-check-circle-fill me-1"></i>
              File already uploaded. Upload again to replace.
            </div>
            <?php endif; ?>
            <input type="file" class="form-control" id="dayEndFile" name="day_end_file"
                   accept="image/jpeg,image/png,.pdf,.doc,.docx,.xls,.xlsx,.zip"
                   <?= $hasDayEndFile ? '' : 'required' ?>>
            <div class="form-text">
              PDF, Word, Excel, Image, ZIP — max <?= MAX_FILE_MB ?> MB.
              Large photos are auto-compressed before upload.
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="dayEndSubmitBtn">
            <span class="spinner-border spinner-border-sm me-1 d-none" id="dayEndSpinner"></span>
            <i class="bi bi-send-check me-1" id="dayEndIcon"></i>Submit Report
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Task Completion Modal -->
<div class="modal fade" id="completeTaskModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header border-bottom">
        <h6 class="modal-title fw-bold">
          <i class="bi bi-check2-circle me-2 text-success"></i>Complete Task
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="completeTaskForm" enctype="multipart/form-data">
        <input type="hidden" name="task_id" id="completeTaskId">
        <div class="modal-body">
          <p class="small text-muted mb-3" id="completeTaskTitle"></p>
          <div class="mb-3">
            <label class="form-label fw-semibold small">Completion Notes <span class="text-danger">*</span></label>
            <textarea class="form-control" name="completion_notes" rows="3"
                      placeholder="Describe what was done..." required></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold small">Proof File <span class="text-danger">*</span></label>
            <input type="file" class="form-control" name="proof_file"
                   accept="image/jpeg,image/png,.pdf,.doc,.docx,.zip"
                   capture="environment" required>
            <div class="form-text">
              Max <?= MAX_FILE_MB ?> MB. Large photos are auto-compressed before upload.
              <br><small class="text-muted">iPhone users: set Camera &rarr; Formats &rarr; Most Compatible.</small>
            </div>
          </div>
          <div class="mb-0">
            <label class="form-label fw-semibold small">
              <i class="bi bi-mic-fill me-1 text-primary"></i>Call Recording
              <span class="text-muted fw-normal">(Optional)</span>
            </label>
            <input type="file" class="form-control" name="call_recording"
                   accept="audio/*,.mp3,.m4a,.wav,.ogg,.amr,.aac,.webm">
            <div class="form-text">
              Attach the call recording with the client (optional). Max <?= MAX_FILE_MB ?> MB.
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">
            <i class="bi bi-check-lg me-1"></i>Mark Complete
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Location log panel is now inline inside the card — no Bootstrap modal needed -->

<!-- Global alert toast -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">
  <div id="mainToast" class="toast align-items-center border-0" role="alert">
    <div class="d-flex">
      <div class="toast-body" id="mainToastBody"></div>
      <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<script>
// ── Task detail modal ─────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
  var detailModal = document.getElementById('taskDetailModal');
  if (!detailModal) return;
  var bsModal = bootstrap.Modal.getOrCreateInstance(detailModal);

  var statusClasses = {
    pending:     'bg-warning text-dark',
    in_progress: 'bg-info text-dark',
    completed:   'bg-success',
    overdue:     'bg-danger',
  };

  document.querySelectorAll('.btn-task-detail').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var t;
      try { t = JSON.parse(this.dataset.task); } catch (e) { return; }

      document.getElementById('tdTitle').textContent         = t.title;
      document.getElementById('tdTypeBadge').textContent     = t.task_type.charAt(0).toUpperCase() + t.task_type.slice(1);
      document.getElementById('tdCreator').textContent       = t.creator_name;
      document.getElementById('tdDeadline').textContent      = t.deadline || '—';

      var sb = document.getElementById('tdStatusBadge');
      sb.textContent = t.status.replace('_', ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
      sb.className   = 'badge ' + (statusClasses[t.status] || 'bg-secondary');

      // Description
      var db = document.getElementById('tdDescBlock');
      if (t.description) {
        document.getElementById('tdDesc').textContent = t.description;
        db.style.display = '';
      } else {
        db.style.display = 'none';
      }

      // Task attachment
      var ab = document.getElementById('tdAttachBlock');
      if (t.file_path) {
        document.getElementById('tdAttachLink').href = 'download.php?path=' + encodeURIComponent(t.file_path);
        ab.style.display = '';
      } else {
        ab.style.display = 'none';
      }

      // Completion block
      var cb = document.getElementById('tdCompletionBlock');
      if (t.completion_notes) {
        document.getElementById('tdCompletionNotes').textContent = t.completion_notes;
        document.getElementById('tdCompletedAt').textContent = t.completed_at ? ('Completed on ' + t.completed_at) : '';
        cb.style.display = '';
      } else {
        cb.style.display = 'none';
      }

      // Proof file
      var pb = document.getElementById('tdProofBlock');
      if (t.proof_file) {
        document.getElementById('tdProofLink').href = 'download.php?path=' + encodeURIComponent(t.proof_file);
        pb.style.display = '';
      } else {
        pb.style.display = 'none';
      }

      // Call recording (optional audio proof)
      var rb = document.getElementById('tdRecordingBlock');
      if (rb) {
        if (t.call_recording) {
          var audio = document.getElementById('tdRecordingAudio');
          var link  = document.getElementById('tdRecordingLink');
          if (audio) { audio.src = 'download.php?path=' + encodeURIComponent(t.call_recording); audio.load(); }
          if (link)  { link.href  = 'download.php?path=' + encodeURIComponent(t.call_recording); }
          rb.style.display = '';
        } else {
          rb.style.display = 'none';
        }
      }

      bsModal.show();
    });
  });
});

// ── Page-level data for app.js ────────────────────────────────
window.HRMS = {
  csrfToken:       document.querySelector('meta[name="csrf-token"]').content,
  hasCheckedIn:    <?= $hasCheckedIn  ? 'true' : 'false' ?>,
  hasCheckedOut:   <?= $hasCheckedOut ? 'true' : 'false' ?>,
  hasDayEndFile:   <?= $hasDayEndFile  ? 'true' : 'false' ?>,
  hasDayEndNotes:  <?= $hasDayEndNotes ? 'true' : 'false' ?>,
  allDailyDone:    <?= $allDailyDone  ? 'true' : 'false' ?>,
  pendingDailyCount: <?= $pendingDailyCount ?>,
  role:            '<?= h($role) ?>',
  ipRestricted:    <?= $user['office_ip_restricted'] ? 'true' : 'false' ?>,
  isFieldWorker:   <?= $role === 'field_worker' ? 'true' : 'false' ?>,
};
</script>

<?php
// ── Render task list helper ───────────────────────────────────
function renderTaskList(array $tasks, string $emptyMsg): void
{
    if (empty($tasks)) {
        echo '<p class="text-muted text-center py-4 mb-0 small">' . h($emptyMsg) . '</p>';
        return;
    }
    echo '<div class="task-list">';
    foreach ($tasks as $t) {
        $isOverdue = $t['status'] !== 'completed'
                   && !empty($t['deadline'])
                   && strtotime($t['deadline']) < time();
        $status    = $isOverdue ? 'overdue' : $t['status'];
        // Encode task data for the detail modal
        $taskData = htmlspecialchars(json_encode([
            'id'               => (int)$t['id'],
            'title'            => $t['title'],
            'description'      => $t['description'] ?? '',
            'task_type'        => $t['task_type'],
            'status'           => $status,
            'deadline'         => $t['deadline'] ? date('d M Y, h:i A', strtotime($t['deadline'])) : '',
            'creator_name'     => $t['creator_name'],
            'file_path'        => $t['file_path'] ?? '',
            'proof_file'       => $t['proof_file'] ?? '',
            'call_recording'   => $t['call_recording'] ?? '',
            'completion_notes' => $t['completion_notes'] ?? '',
            'completed_at'     => $t['completed_at'] ? date('d M Y, h:i A', strtotime($t['completed_at'])) : '',
        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

        $leadId = isset($t['lead_id']) ? (int)$t['lead_id'] : 0;
        echo '<div class="task-item ' . ($isOverdue ? 'task-overdue' : '')
           . ($leadId ? ' task-lead-linked' : '') . '"
                   data-task-id="' . (int)$t['id'] . '">';
        echo '  <div class="task-item-body">';
        echo '    <button class="task-title btn-task-detail text-start p-0 border-0 bg-transparent w-100"
                          data-task=\'' . $taskData . '\'>';
        if ($leadId) {
            echo '<i class="bi bi-link-45deg text-primary me-1" title="Linked to a lead"></i>';
        }
        echo h($t['title']) . '</button>';
        if ($t['description']) {
            echo '    <div class="task-desc text-muted small">' . h(mb_strimwidth($t['description'], 0, 100, '…')) . '</div>';
        }
        echo '    <div class="task-meta small text-muted mt-1">';
        echo '      <i class="bi bi-person me-1"></i>' . h($t['creator_name']);
        if ($t['deadline']) {
            echo '  &nbsp;·&nbsp;<i class="bi bi-calendar3 me-1"></i>'
                . h(date('d M, h:i A', strtotime($t['deadline'])));
        }
        echo '    </div>';
        echo '  </div>';
        echo '  <div class="task-item-actions d-flex align-items-center gap-2 flex-shrink-0">';
        // Status badge
        echo statusBadge($status);
        // Open linked lead (if any)
        if ($leadId) {
            echo '<a href="lead_view.php?id=' . $leadId . '"
                     class="btn btn-sm btn-outline-primary" title="Open linked lead">
                    <i class="bi bi-person-lines-fill"></i>
                  </a>';
        }
        // Attachment download
        if ($t['file_path']) {
            echo '<a href="download.php?path=' . urlencode($t['file_path']) . '" target="_blank"
                     class="btn btn-sm btn-outline-secondary" title="Download attachment">
                    <i class="bi bi-paperclip"></i>
                  </a>';
        }
        // Actions — "Start Task" button removed.
        // Tasks only move from pending → in_progress when the worker
        // logs their location and tags it to that task.
        if (in_array($t['status'], ['pending','in_progress'], true)) {
            echo '<button class="btn btn-sm btn-success btn-complete-task"
                          data-task-id="' . (int)$t['id'] . '"
                          data-task-title="' . h($t['title']) . '"
                          title="Mark complete">
                    <i class="bi bi-check-lg"></i>
                  </button>';
        }
        if ($t['status'] === 'completed' && $t['proof_file']) {
            echo '<a href="download.php?path=' . urlencode($t['proof_file']) . '" target="_blank"
                     class="btn btn-sm btn-outline-success" title="Download proof">
                    <i class="bi bi-file-check"></i>
                  </a>';
        }
        echo '  </div>';
        echo '</div>';
    }
    echo '</div>';
}

include __DIR__ . '/includes/footer.php';
?>
