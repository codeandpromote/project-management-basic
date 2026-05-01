<?php
// ============================================================
//  HRMS · Reports Center (admin only)
//  Download comprehensive CSV reports for any date range:
//  attendance / tasks / leads / lead-activities / locations
// ============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
requireRole('admin');

$user = getCurrentUser();
$db   = getDB();

// ── Preset → date range ──────────────────────────────────────
$preset = $_GET['preset'] ?? 'last7';
$today  = date('Y-m-d');

$presets = [
    'today'    => ['Today',            $today, $today],
    'yesterday'=> ['Yesterday',
                   date('Y-m-d', strtotime('-1 day')),
                   date('Y-m-d', strtotime('-1 day'))],
    'last7'    => ['Last 7 days',
                   date('Y-m-d', strtotime('-6 days')),  $today],
    'last30'   => ['Last 30 days',
                   date('Y-m-d', strtotime('-29 days')), $today],
    'last90'   => ['Last 90 days',
                   date('Y-m-d', strtotime('-89 days')), $today],
    'thismonth'=> ['This month',
                   date('Y-m-01'),
                   date('Y-m-t')],
    'lastmonth'=> ['Last month',
                   date('Y-m-01', strtotime('first day of last month')),
                   date('Y-m-t',  strtotime('last day of last month'))],
    'thisyear' => ['This year',
                   date('Y-01-01'),
                   date('Y-12-31')],
    'lastyear' => ['Last year',
                   date((int)date('Y') - 1 . '-01-01'),
                   date((int)date('Y') - 1 . '-12-31')],
    'custom'   => ['Custom', $today, $today],
];

if (!isset($presets[$preset])) { $preset = 'last7'; }
[$presetLabel, $startDate, $endDate] = $presets[$preset];

if ($preset === 'custom') {
    $s = $_GET['start'] ?? '';
    $e = $_GET['end']   ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) { $startDate = $s; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $e)) { $endDate   = $e; }
}
// Sanity swap if user reversed dates
if ($startDate > $endDate) { [$startDate, $endDate] = [$endDate, $startDate]; }

// ── Summary grouping (for the on-screen summary) ─────────────
$group = $_GET['group'] ?? 'day'; // day | month | year
if (!in_array($group, ['day','month','year'], true)) { $group = 'day'; }
$groupFmt = ['day' => '%Y-%m-%d', 'month' => '%Y-%m', 'year' => '%Y'][$group];

// ── Handle CSV download (before any output) ─────────────────
$download = $_GET['download'] ?? '';
if ($download !== '') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $download . '_' .
           $startDate . '_to_' . $endDate . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

    try {
        switch ($download) {

            case 'attendance': {
                fputcsv($out, ['Date','Employee','Role','Check-In','Check-Out',
                               'Hours','Status','Day-End Notes']);
                $st = $db->prepare(
                    "SELECT a.work_date, u.name, u.role, a.check_in_time, a.check_out_time,
                            a.status, a.day_end_notes
                       FROM attendance a JOIN users u ON u.id = a.user_id
                      WHERE a.work_date BETWEEN ? AND ?
                      ORDER BY a.work_date DESC, u.name ASC"
                );
                $st->execute([$startDate, $endDate]);
                foreach ($st->fetchAll() as $r) {
                    $in  = $r['check_in_time']  ? strtotime($r['check_in_time'])  : 0;
                    $out2= $r['check_out_time'] ? strtotime($r['check_out_time']) : 0;
                    $hrs = ($in && $out2) ? round(($out2 - $in) / 3600, 2) : '';
                    fputcsv($out, [
                        $r['work_date'], $r['name'],
                        ucfirst(str_replace('_',' ', $r['role'])),
                        $r['check_in_time']  ? date('h:i A', $in)   : '',
                        $r['check_out_time'] ? date('h:i A', $out2) : '',
                        $hrs, $r['status'], $r['day_end_notes'] ?? '',
                    ]);
                }
                break;
            }

            case 'tasks': {
                fputcsv($out, ['Task ID','Title','Type','Assigned To','Created By',
                               'Deadline','Status','Completed At','Linked Lead',
                               'Completion Notes','Proof File','Call Recording']);
                // call_recording column may be missing on older installs
                try {
                    $sql = "SELECT t.id, t.title, t.task_type, t.deadline, t.status,
                                   t.completed_at, t.completion_notes, t.lead_id,
                                   t.proof_file, t.call_recording,
                                   u.name AS assignee, c.name AS creator,
                                   l.name AS lead_name
                              FROM tasks t
                              LEFT JOIN users u ON u.id = t.user_id
                              LEFT JOIN users c ON c.id = t.creator_id
                              LEFT JOIN leads l ON l.id = t.lead_id
                             WHERE DATE(t.created_at) BETWEEN ? AND ?
                             ORDER BY t.created_at DESC";
                    $st = $db->prepare($sql);
                    $st->execute([$startDate, $endDate]);
                } catch (PDOException $e) {
                    $sql = "SELECT t.id, t.title, t.task_type, t.deadline, t.status,
                                   t.completed_at, t.completion_notes, t.lead_id,
                                   t.proof_file,
                                   u.name AS assignee, c.name AS creator,
                                   l.name AS lead_name
                              FROM tasks t
                              LEFT JOIN users u ON u.id = t.user_id
                              LEFT JOIN users c ON c.id = t.creator_id
                              LEFT JOIN leads l ON l.id = t.lead_id
                             WHERE DATE(t.created_at) BETWEEN ? AND ?
                             ORDER BY t.created_at DESC";
                    $st = $db->prepare($sql);
                    $st->execute([$startDate, $endDate]);
                }
                foreach ($st->fetchAll() as $r) {
                    fputcsv($out, [
                        $r['id'], $r['title'], $r['task_type'],
                        $r['assignee'] ?? '', $r['creator'] ?? '',
                        $r['deadline'] ?? '', $r['status'],
                        $r['completed_at'] ?? '',
                        $r['lead_name'] ? '#' . $r['lead_id'] . ' ' . $r['lead_name'] : '',
                        $r['completion_notes'] ?? '',
                        $r['proof_file'] ?? '',
                        $r['call_recording'] ?? '',
                    ]);
                }
                break;
            }

            case 'leads': {
                fputcsv($out, ['Lead ID','Name','Phone','Email','Company','Source',
                               'Status','Priority','Assigned To','Created At','Tags']);
                $st = $db->prepare(
                    "SELECT l.id, l.name, l.phone, l.email, l.company, l.source,
                            l.status, l.priority, l.tags, l.created_at,
                            u.name AS assignee
                       FROM leads l
                       LEFT JOIN users u ON u.id = l.assigned_to
                      WHERE l.is_deleted = 0
                        AND DATE(l.created_at) BETWEEN ? AND ?
                      ORDER BY l.created_at DESC"
                );
                $st->execute([$startDate, $endDate]);
                foreach ($st->fetchAll() as $r) {
                    fputcsv($out, [
                        $r['id'], $r['name'], $r['phone'], $r['email'] ?? '',
                        $r['company'] ?? '',
                        ucfirst(str_replace('_',' ', $r['source'])),
                        ucfirst(str_replace('_',' ', $r['status'])),
                        ucfirst($r['priority']),
                        $r['assignee'] ?? '', $r['created_at'],
                        $r['tags'] ?? '',
                    ]);
                }
                break;
            }

            case 'lead_activities': {
                fputcsv($out, ['Activity ID','When','Lead ID','Lead Name','User','Type',
                               'Outcome','Notes','Next Follow-up','Old → New Status']);
                $st = $db->prepare(
                    "SELECT a.id, a.activity_at, a.lead_id, l.name AS lead_name,
                            u.name AS user_name, a.activity_type, a.outcome, a.notes,
                            a.next_followup_date, a.old_status, a.new_status
                       FROM lead_activities a
                       LEFT JOIN leads l ON l.id = a.lead_id
                       LEFT JOIN users u ON u.id = a.user_id
                      WHERE DATE(a.activity_at) BETWEEN ? AND ?
                      ORDER BY a.activity_at DESC"
                );
                $st->execute([$startDate, $endDate]);
                foreach ($st->fetchAll() as $r) {
                    fputcsv($out, [
                        $r['id'], $r['activity_at'], $r['lead_id'],
                        $r['lead_name'] ?? '',
                        $r['user_name'] ?? '',
                        $r['activity_type'], $r['outcome'],
                        $r['notes'] ?? '',
                        $r['next_followup_date'] ?? '',
                        $r['old_status'] || $r['new_status']
                            ? ($r['old_status'] ?? '—') . ' → ' . ($r['new_status'] ?? '—')
                            : '',
                    ]);
                }
                break;
            }

            case 'locations': {
                fputcsv($out, ['Date','Time','Employee','Lat','Lng','Accuracy (m)',
                               'Linked Task','Linked Lead','Notes','Photo']);
                $st = $db->prepare(
                    "SELECT ll.log_date, ll.logged_at, u.name, ll.lat, ll.lng,
                            ll.accuracy, ll.notes, ll.photo,
                            t.title AS task_title, l.name AS lead_name
                       FROM location_logs ll
                       LEFT JOIN users u ON u.id = ll.user_id
                       LEFT JOIN tasks t ON t.id = ll.task_id
                       LEFT JOIN leads l ON l.id = ll.lead_id
                      WHERE ll.log_date BETWEEN ? AND ?
                      ORDER BY ll.logged_at DESC"
                );
                $st->execute([$startDate, $endDate]);
                foreach ($st->fetchAll() as $r) {
                    fputcsv($out, [
                        $r['log_date'], date('h:i A', strtotime($r['logged_at'])),
                        $r['name'] ?? '',
                        $r['lat'], $r['lng'],
                        $r['accuracy'] ?? '',
                        $r['task_title'] ?? '',
                        $r['lead_name'] ?? '',
                        $r['notes'] ?? '',
                        $r['photo'] ?? '',
                    ]);
                }
                break;
            }

            default:
                fputcsv($out, ['Unknown report type.']);
        }
    } catch (PDOException $e) {
        fputcsv($out, ['Error generating report.']);
    }
    fclose($out);
    exit;
}

// ── Summary counters for on-screen dashboard ────────────────
$summary = [];
try {
    $st = $db->prepare("SELECT COUNT(*) FROM attendance WHERE work_date BETWEEN ? AND ?");
    $st->execute([$startDate, $endDate]); $summary['attendance'] = (int)$st->fetchColumn();

    $st = $db->prepare("SELECT COUNT(*) FROM tasks WHERE DATE(created_at) BETWEEN ? AND ?");
    $st->execute([$startDate, $endDate]); $summary['tasks'] = (int)$st->fetchColumn();

    $st = $db->prepare("SELECT COUNT(*) FROM leads WHERE is_deleted = 0 AND DATE(created_at) BETWEEN ? AND ?");
    $st->execute([$startDate, $endDate]); $summary['leads'] = (int)$st->fetchColumn();

    $st = $db->prepare("SELECT COUNT(*) FROM lead_activities WHERE DATE(activity_at) BETWEEN ? AND ?");
    $st->execute([$startDate, $endDate]); $summary['activities'] = (int)$st->fetchColumn();

    $st = $db->prepare("SELECT COUNT(*) FROM location_logs WHERE log_date BETWEEN ? AND ?");
    $st->execute([$startDate, $endDate]); $summary['locations'] = (int)$st->fetchColumn();
} catch (PDOException $ignored) {
    $summary = array_fill_keys(['attendance','tasks','leads','activities','locations'], 0);
}

// Group-by breakdown for charts / preview
$breakdown = [];
try {
    $sql = "SELECT DATE_FORMAT(logged_at, ?) AS bucket, COUNT(*) AS cnt
              FROM location_logs
             WHERE log_date BETWEEN ? AND ?
             GROUP BY bucket ORDER BY bucket ASC";
    $st = $db->prepare($sql);
    $st->execute([$groupFmt, $startDate, $endDate]);
    $breakdown = $st->fetchAll();
} catch (PDOException $ignored) {}

$pageTitle = 'Reports Center';
include __DIR__ . '/includes/header.php';

function report_url(string $name, string $preset, string $start, string $end, string $group): string {
    return '?' . http_build_query([
        'download' => $name, 'preset' => $preset,
        'start'    => $start, 'end' => $end, 'group' => $group,
    ]);
}
?>

<style>
.report-card {
  cursor: pointer; transition: transform .1s, box-shadow .15s;
}
.report-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.12); }
.report-card .icon { width: 44px; height: 44px; border-radius: 10px;
                     display: flex; align-items: center; justify-content: center;
                     font-size: 1.3rem; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h5 class="fw-bold mb-0">
    <i class="bi bi-graph-up-arrow me-2 text-primary"></i>Reports Center
  </h5>
</div>

<!-- ── Preset selector ────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label small fw-semibold mb-1">Date Range</label>
        <select name="preset" class="form-select form-select-sm"
                onchange="document.getElementById('cr').style.display = this.value === 'custom' ? '' : 'none'">
          <?php foreach ($presets as $key => $info): ?>
          <option value="<?= $key ?>" <?= $preset === $key ? 'selected' : '' ?>><?= h($info[0]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div id="cr" class="col-md-4 row g-2" style="<?= $preset === 'custom' ? '' : 'display:none' ?>">
        <div class="col">
          <label class="form-label small fw-semibold mb-1">From</label>
          <input type="date" name="start" class="form-control form-control-sm"
                 value="<?= h($startDate) ?>" max="<?= date('Y-m-d') ?>">
        </div>
        <div class="col">
          <label class="form-label small fw-semibold mb-1">To</label>
          <input type="date" name="end" class="form-control form-control-sm"
                 value="<?= h($endDate) ?>" max="<?= date('Y-m-d') ?>">
        </div>
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-semibold mb-1">Group By</label>
        <select name="group" class="form-select form-select-sm">
          <option value="day"   <?= $group==='day'   ? 'selected':'' ?>>Day</option>
          <option value="month" <?= $group==='month' ? 'selected':'' ?>>Month</option>
          <option value="year"  <?= $group==='year'  ? 'selected':'' ?>>Year</option>
        </select>
      </div>
      <div class="col-md-auto">
        <button class="btn btn-primary btn-sm">
          <i class="bi bi-funnel me-1"></i>Apply
        </button>
      </div>
      <div class="col-12">
        <small class="text-muted">
          Showing data from <strong><?= h(date('d M Y', strtotime($startDate))) ?></strong>
          to <strong><?= h(date('d M Y', strtotime($endDate))) ?></strong>
          <?= $preset === 'custom' ? '(custom)' : '('.h($presetLabel).')' ?>.
        </small>
      </div>
    </form>
  </div>
</div>

<!-- ── Summary counts ─────────────────────────────────────── -->
<div class="row g-3 mb-3">
  <?php
  $reportCards = [
      ['name' => 'attendance',      'label' => 'Attendance Records',  'count' => $summary['attendance'], 'icon' => 'bi-calendar-check-fill', 'colour' => 'primary'],
      ['name' => 'tasks',           'label' => 'Tasks Created',       'count' => $summary['tasks'],      'icon' => 'bi-list-check',           'colour' => 'info'],
      ['name' => 'leads',           'label' => 'Leads Created',       'count' => $summary['leads'],      'icon' => 'bi-person-lines-fill',    'colour' => 'success'],
      ['name' => 'lead_activities', 'label' => 'Lead Activities',     'count' => $summary['activities'], 'icon' => 'bi-chat-dots-fill',       'colour' => 'warning'],
      ['name' => 'locations',       'label' => 'Location Logs',       'count' => $summary['locations'],  'icon' => 'bi-geo-alt-fill',         'colour' => 'danger'],
  ];
  foreach ($reportCards as $c):
    $url = report_url($c['name'], $preset, $startDate, $endDate, $group);
  ?>
  <div class="col-6 col-md-4 col-lg">
    <a href="<?= h($url) ?>" class="card border-0 shadow-sm report-card text-decoration-none text-dark h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="icon bg-<?= $c['colour'] ?>-subtle text-<?= $c['colour'] ?>">
          <i class="bi <?= $c['icon'] ?>"></i>
        </div>
        <div class="flex-grow-1">
          <div class="fw-bold fs-5"><?= (int)$c['count'] ?></div>
          <div class="small text-muted"><?= h($c['label']) ?></div>
          <div class="small text-primary mt-1">
            <i class="bi bi-download me-1"></i>Download CSV
          </div>
        </div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Grouped breakdown (location activity) ──────────────── -->
<?php if (!empty($breakdown)): ?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent border-bottom fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-bar-chart-line me-1 text-primary"></i>
    Location-log activity by <?= h($group) ?>
    <span class="badge bg-light text-dark ms-auto border"><?= count($breakdown) ?> buckets</span>
  </div>
  <div class="card-body">
    <table class="table table-sm table-striped mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th><?= h(ucfirst($group)) ?></th>
          <th style="width:60%">Volume</th>
          <th style="width:100px">Count</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $max = 0;
        foreach ($breakdown as $b) { if ($b['cnt'] > $max) { $max = (int)$b['cnt']; } }
        $max = max($max, 1);
        foreach ($breakdown as $b):
          $pct = round(($b['cnt'] / $max) * 100);
        ?>
        <tr>
          <td class="small fw-semibold"><?= h($b['bucket']) ?></td>
          <td>
            <div class="bg-primary-subtle" style="height:14px;width:<?= $pct ?>%;border-radius:4px"></div>
          </td>
          <td class="small"><?= (int)$b['cnt'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
