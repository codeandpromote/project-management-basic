<?php
// ============================================================
//  HRMS · Field Worker Location Tracker  (Admin only)
// ============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
requireRole('admin');

$user = getCurrentUser();
$db   = getDB();

// ── Check that schema_update.sql has been run ─────────────────
// If the location_logs table is missing we show a friendly notice
// instead of a 500 error.
function locationTableExists(PDO $db): bool
{
    try {
        $db->query('SELECT 1 FROM location_logs LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

$tableReady = locationTableExists($db);

// ── Filters ───────────────────────────────────────────────────
$filterDate = $_GET['date']    ?? date('Y-m-d');
$filterUid  = (int)($_GET['user_id'] ?? 0);

// Sanitise date input
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $filterDate = date('Y-m-d');
}

$fieldWorkers = [];
$allLogs      = [];
$grouped      = [];

if ($tableReady) {
    // Field workers only
    $fieldWorkers = $db->query(
        "SELECT id, name FROM users WHERE role = 'field_worker' AND is_active = 1 ORDER BY name"
    )->fetchAll();

    // ── Fetch logs ────────────────────────────────────────────
    $where  = ['ll.log_date = ?'];
    $params = [$filterDate];
    if ($filterUid) {
        $where[]  = 'll.user_id = ?';
        $params[] = $filterUid;
    }

    $stLogs = $db->prepare(
        "SELECT ll.*, u.name AS worker_name, t.title AS task_title
           FROM location_logs ll
           JOIN users u ON u.id = ll.user_id
           LEFT JOIN tasks t ON t.id = ll.task_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY ll.user_id ASC, ll.logged_at ASC"
    );
    $stLogs->execute($params);
    $allLogs = $stLogs->fetchAll();

    // Group by user for timeline view
    foreach ($allLogs as $log) {
        $grouped[$log['user_id']]['name']   = $log['worker_name'];
        $grouped[$log['user_id']]['logs'][] = $log;
    }
}

$totalLogs     = count($allLogs);
$activeWorkers = count($grouped);

// ── CSV Export — must happen BEFORE any HTML output ──────────
if ($tableReady && isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="locations_' . $filterDate . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Worker','Date','Time','Latitude','Longitude',
                   'Accuracy (m)','Task','Notes']);
    foreach ($allLogs as $l) {
        fputcsv($out, [
            $l['worker_name'],
            $l['log_date'],
            date('h:i A', strtotime($l['logged_at'])),
            $l['lat'], $l['lng'],
            $l['accuracy'] ?? '',
            $l['task_title'] ?? '',
            $l['notes'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Location Tracker';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <h5 class="fw-bold mb-0">
    <i class="bi bi-geo-alt-fill me-2 text-success"></i>Field Worker Location Tracker
  </h5>
  <?php if ($tableReady): ?>
  <a href="?date=<?= h($filterDate) ?>&user_id=<?= $filterUid ?>&export=csv"
     class="btn btn-outline-success btn-sm">
    <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
  </a>
  <?php endif; ?>
</div>

<?php if (!$tableReady): ?>
<!-- ── Schema not updated yet ─────────────────────────────── -->
<div class="alert alert-warning d-flex gap-3 align-items-start">
  <i class="bi bi-exclamation-triangle-fill fs-4 flex-shrink-0 mt-1"></i>
  <div>
    <div class="fw-bold mb-1">Database setup required</div>
    <p class="mb-2 small">
      The <code>location_logs</code> table is missing. You need to run
      <strong>schema_update.sql</strong> in phpMyAdmin before using this page.
    </p>
    <ol class="small mb-0">
      <li>Open <strong>phpMyAdmin</strong> and select the <code>hrms_db</code> database.</li>
      <li>Go to the <strong>Import</strong> tab.</li>
      <li>Upload <code>schema_update.sql</code> and click <strong>Go</strong>.</li>
      <li>Reload this page.</li>
    </ol>
  </div>
</div>
<?php else: ?>

<!-- ── Filter Bar ─────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold">Date</label>
        <input type="date" class="form-control form-control-sm" name="date"
               value="<?= h($filterDate) ?>" max="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold">Field Worker</label>
        <select name="user_id" class="form-select form-select-sm">
          <option value="">All Field Workers</option>
          <?php foreach ($fieldWorkers as $fw): ?>
          <option value="<?= $fw['id'] ?>" <?= $filterUid === (int)$fw['id'] ? 'selected' : '' ?>>
            <?= h($fw['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm">
          <i class="bi bi-funnel me-1"></i>Filter
        </button>
        <a href="admin_locations.php" class="btn btn-outline-secondary btn-sm">Today</a>
      </div>
      <!-- Prev / current / next date nav -->
      <div class="col-auto ms-auto d-flex gap-1">
        <?php
        $prev = date('Y-m-d', strtotime($filterDate . ' -1 day'));
        $next = date('Y-m-d', strtotime($filterDate . ' +1 day'));
        ?>
        <a href="?date=<?= $prev ?>&user_id=<?= $filterUid ?>"
           class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-chevron-left"></i>
        </a>
        <span class="btn btn-sm btn-light disabled fw-semibold">
          <?= h(date('d M Y', strtotime($filterDate))) ?>
        </span>
        <?php if ($next <= date('Y-m-d')): ?>
        <a href="?date=<?= $next ?>&user_id=<?= $filterUid ?>"
           class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-chevron-right"></i>
        </a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-success-subtle text-success">
          <i class="bi bi-people-fill"></i>
        </div>
        <div>
          <div class="stat-value"><?= $activeWorkers ?></div>
          <div class="stat-label">Workers Active</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-primary-subtle text-primary">
          <i class="bi bi-geo-fill"></i>
        </div>
        <div>
          <div class="stat-value"><?= $totalLogs ?></div>
          <div class="stat-label">Total Pings</div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if (empty($allLogs)): ?>
<div class="card border-0 shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="bi bi-geo-alt fs-1 d-block mb-2 opacity-25"></i>
    No location logs for <strong><?= h(date('d M Y', strtotime($filterDate))) ?></strong>.
    <?php if (empty($fieldWorkers)): ?>
    <div class="mt-2 small">No active field workers found in the system.</div>
    <?php else: ?>
    <div class="mt-2 small">
      Field workers log locations from their dashboard using the
      <strong>Log My Current Location</strong> button.
    </div>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>

<!-- Per-worker timeline -->
<?php foreach ($grouped as $workerId => $data):
  $logs    = $data['logs'];
  $lastLog = end($logs);
?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-transparent border-bottom d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <div class="avatar-circle avatar-sm">
        <?= strtoupper(substr($data['name'], 0, 1)) ?>
      </div>
      <div>
        <div class="fw-semibold"><?= h($data['name']) ?></div>
        <div class="text-muted small">
          <?= count($logs) ?> ping<?= count($logs) > 1 ? 's' : '' ?> on
          <?= h(date('d M Y', strtotime($filterDate))) ?>
        </div>
      </div>
    </div>
    <?php
    // Find the most recent log that actually has GPS coordinates
    $lastGpsLog = null;
    foreach (array_reverse($logs) as $l) {
        if (!((float)$l['lat'] === 0.0 && (float)$l['lng'] === 0.0)) {
            $lastGpsLog = $l; break;
        }
    }
    ?>
    <?php if ($lastGpsLog): ?>
    <a href="https://maps.google.com/?q=<?= $lastGpsLog['lat'] ?>,<?= $lastGpsLog['lng'] ?>"
       target="_blank" class="btn btn-sm btn-outline-success">
      <i class="bi bi-map-fill me-1"></i>Last Location
    </a>
    <?php else: ?>
    <span class="btn btn-sm btn-outline-secondary disabled">
      <i class="bi bi-geo-alt me-1"></i>No GPS Data
    </span>
    <?php endif; ?>
  </div>

  <div class="card-body p-0">
    <div class="location-timeline p-3">
      <?php $total = count($logs); ?>
      <?php foreach ($logs as $idx => $log):
        $hasGps = !((float)$log['lat'] === 0.0 && (float)$log['lng'] === 0.0);
      ?>
      <div class="loc-entry d-flex gap-3 align-items-start
                  <?= $idx < $total - 1 ? 'loc-entry-connector' : '' ?>">

        <div class="loc-dot-col text-center flex-shrink-0">
          <div class="loc-dot
               <?= $idx === 0 ? 'loc-dot-first' : ($idx === $total - 1 ? 'loc-dot-last' : '') ?>"
               style="<?= !$hasGps ? 'background:#94A3B8' : '' ?>">
            <?= $idx + 1 ?>
          </div>
        </div>

        <div class="loc-content pb-3 flex-grow-1">
          <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
            <div>
              <div class="fw-semibold small">
                <i class="bi bi-clock me-1 text-muted"></i>
                <?= h(date('h:i A', strtotime($log['logged_at']))) ?>
                <?php if ($hasGps && $log['accuracy']): ?>
                <span class="text-muted fw-normal">(±<?= (int)$log['accuracy'] ?>m)</span>
                <?php elseif (!$hasGps): ?>
                <span class="badge bg-secondary ms-1">No GPS</span>
                <?php endif; ?>
              </div>
              <div class="text-muted small mt-1">
                <?php if ($hasGps): ?>
                <i class="bi bi-geo-alt-fill me-1"></i>
                <?= number_format((float)$log['lat'], 6) ?>,
                <?= number_format((float)$log['lng'], 6) ?>
                <?php else: ?>
                <i class="bi bi-slash-circle me-1"></i>Activity logged without GPS
                <?php endif; ?>
              </div>
              <?php if ($log['task_title']): ?>
              <div class="mt-1">
                <span class="badge bg-primary-subtle text-primary" style="font-size:.72rem">
                  <i class="bi bi-list-task me-1"></i><?= h($log['task_title']) ?>
                </span>
              </div>
              <?php endif; ?>
              <?php if ($log['notes']): ?>
              <div class="small text-muted fst-italic mt-1">
                "<?= h($log['notes']) ?>"
              </div>
              <?php endif; ?>
            </div>
            <?php if ($hasGps): ?>
            <a href="https://maps.google.com/?q=<?= $log['lat'] ?>,<?= $log['lng'] ?>"
               target="_blank" class="btn btn-xs btn-outline-primary flex-shrink-0">
              <i class="bi bi-map me-1"></i>Map
            </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Full-day route link (only for entries that have GPS) -->
    <?php
    $gpsLogs = array_filter($logs, fn($l) => !((float)$l['lat'] === 0.0 && (float)$l['lng'] === 0.0));
    if (count($gpsLogs) >= 2):
      $pts    = array_map(fn($l) => $l['lat'] . ',' . $l['lng'], $gpsLogs);
      $origin = array_shift($pts);
      $dest   = array_pop($pts);
      $via    = $pts ? '&waypoints=' . implode('|', array_slice($pts, 0, 8)) : '';
      $dirUrl = "https://www.google.com/maps/dir/{$origin}/{$dest}{$via}";
    ?>
    <div class="px-3 pb-3">
      <a href="<?= h($dirUrl) ?>" target="_blank"
         class="btn btn-sm btn-outline-success w-100">
        <i class="bi bi-signpost-split me-2"></i>View Full Day Route on Google Maps
      </a>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php endif; // tableReady ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
