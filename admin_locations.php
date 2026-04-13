<?php
// ============================================================
//  HRMS · Field Worker Location Tracker  (Admin only)
// ============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
requireRole('admin');

$user = getCurrentUser();
$db   = getDB();

// ── Haversine distance between two GPS points → km ───────────
function haversineKm($lat1, $lng1, $lat2, $lng2)
{
    $R    = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a    = sin($dLat / 2) * sin($dLat / 2)
          + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
          * sin($dLng / 2) * sin($dLng / 2);
    return $R * 2 * atan2(sqrt($a), sqrt(1.0 - $a));
}

// ── Build Google Maps route URL (max 10 stops) ────────────────
// Format: maps/dir/lat,lng/lat,lng/...  (no waypoints= param)
function buildMapsRouteUrl($gpsLogs, $max = 10)
{
    $count = count($gpsLogs);
    if ($count < 2) { return ''; }
    if ($count <= $max) {
        $selected = $gpsLogs;
    } else {
        $mid      = $max - 2;
        $step     = ($count - 2) / max(1, $mid);
        $selected = [$gpsLogs[0]];
        for ($i = 0; $i < $mid; $i++) {
            $idx        = (int) round(1 + $i * $step);
            $idx        = min($idx, $count - 2);
            $selected[] = $gpsLogs[$idx];
        }
        $selected[] = $gpsLogs[$count - 1];
    }
    $parts = [];
    foreach ($selected as $l) {
        $parts[] = $l['lat'] . ',' . $l['lng'];
    }
    return 'https://www.google.com/maps/dir/' . implode('/', $parts);
}

// ── Check table ───────────────────────────────────────────────
function locationTableExists(PDO $db)
{
    try { $db->query('SELECT 1 FROM location_logs LIMIT 1'); return true; }
    catch (PDOException $e) { return false; }
}

$tableReady = locationTableExists($db);

// ── Filters ───────────────────────────────────────────────────
$filterDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$filterUid  = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $filterDate = date('Y-m-d');
}

$fieldWorkers = [];
$allLogs      = [];
$grouped      = [];
$grandTotalKm = 0.0;
$hasAnyMap    = false;

if ($tableReady) {
    $fieldWorkers = $db->query(
        "SELECT id, name FROM users WHERE role = 'field_worker' AND is_active = 1 ORDER BY name"
    )->fetchAll(PDO::FETCH_ASSOC);

    $where  = ['ll.log_date = ?'];
    $params = [$filterDate];
    if ($filterUid) { $where[] = 'll.user_id = ?'; $params[] = $filterUid; }

    $stLogs = $db->prepare(
        "SELECT ll.*, u.name AS worker_name, t.title AS task_title
           FROM location_logs ll
           JOIN users u ON u.id = ll.user_id
           LEFT JOIN tasks t ON t.id = ll.task_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY ll.user_id ASC, ll.logged_at ASC"
    );
    $stLogs->execute($params);
    $allLogs = $stLogs->fetchAll(PDO::FETCH_ASSOC);

    // Group by user
    foreach ($allLogs as $row) {
        $wid = $row['user_id'];
        if (!isset($grouped[$wid])) {
            $grouped[$wid] = [
                'name'     => $row['worker_name'],
                'logs'     => [],
                'gpsLogs'  => [],
                'totalKm'  => 0.0,
            ];
        }
        $grouped[$wid]['logs'][] = $row;
    }

    // Per-worker: mark GPS flag, build GPS subset, calculate distances
    foreach ($grouped as $wid => $workerData) {
        // Step 1: mark has_gps and segment_km=null on each log
        $logs = [];
        foreach ($workerData['logs'] as $log) {
            $log['has_gps']    = !((float)$log['lat'] === 0.0 && (float)$log['lng'] === 0.0);
            $log['segment_km'] = null;
            $logs[] = $log;
        }

        // Step 2: build GPS-only array
        $gpsLogs = [];
        foreach ($logs as $log) {
            if ($log['has_gps']) {
                $gpsLogs[] = $log;
            }
        }

        // Step 3: calculate haversine distances and build segByLogId map
        $totalKm    = 0.0;
        $segByLogId = [];
        $gpsCount   = count($gpsLogs);
        for ($i = 1; $i < $gpsCount; $i++) {
            $km = haversineKm(
                (float)$gpsLogs[$i - 1]['lat'], (float)$gpsLogs[$i - 1]['lng'],
                (float)$gpsLogs[$i]['lat'],     (float)$gpsLogs[$i]['lng']
            );
            $totalKm += $km;
            $segByLogId[$gpsLogs[$i]['id']] = $km;
            $gpsLogs[$i]['segment_km'] = $km;
        }

        // Step 4: write segment_km back into the main logs array
        $annotatedLogs = [];
        foreach ($logs as $log) {
            if (isset($segByLogId[$log['id']])) {
                $log['segment_km'] = $segByLogId[$log['id']];
            }
            $annotatedLogs[] = $log;
        }

        $grouped[$wid]['logs']    = $annotatedLogs;
        $grouped[$wid]['gpsLogs'] = $gpsLogs;
        $grouped[$wid]['totalKm'] = $totalKm;
        $grandTotalKm += $totalKm;
        if ($gpsCount >= 2) { $hasAnyMap = true; }
    }
}

$totalLogs     = count($allLogs);
$activeWorkers = count($grouped);
$avgKm         = $activeWorkers > 0 ? $grandTotalKm / $activeWorkers : 0.0;

// ── CSV Export (must run before any HTML output) ─────────────
if ($tableReady && isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="locations_' . $filterDate . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Worker','Date','Time','Latitude','Longitude',
                   'Accuracy (m)','Moved Since Prev (km)','Task','Notes']);
    foreach ($grouped as $wid => $data) {
        foreach ($data['logs'] as $log) {
            fputcsv($out, [
                $data['name'],
                $log['log_date'],
                date('h:i A', strtotime($log['logged_at'])),
                $log['lat'], $log['lng'],
                $log['accuracy'] != '' ? $log['accuracy'] : '',
                $log['segment_km'] !== null ? number_format((float)$log['segment_km'], 3) : '',
                $log['task_title'] != '' ? $log['task_title'] : '',
                $log['notes'] != '' ? $log['notes'] : '',
            ]);
        }
        fputcsv($out, [
            '-- ' . $data['name'] . ' TOTAL --',
            $filterDate, '', '', '', '', '',
            number_format($data['totalKm'], 2) . ' km', '',
        ]);
        fputcsv($out, []);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Location Tracker';
include __DIR__ . '/includes/header.php';
?>

<!-- Leaflet CSS (no SRI hash — avoids CDN block issues) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.min.css">

<style>
.loc-map     { height: 260px; border-radius: .5rem; border: 1px solid #dee2e6; }
.km-badge    { font-size: .72rem; background: #e8f5e9; color: #2e7d32;
               border: 1px solid #c8e6c9; border-radius: 20px; padding: 2px 10px;
               font-weight: 600; white-space: nowrap; }
.seg-km      { font-size: .68rem; color: #6c757d; margin-left: 4px; }
.stat-icon   { width: 40px; height: 40px; border-radius: 10px;
               display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
.stat-value  { font-size: 1.4rem; font-weight: 700; line-height: 1; }
.stat-label  { font-size: .72rem; color: #6c757d; margin-top: 2px; }
.loc-dot     { width: 26px; height: 26px; border-radius: 50%;
               background: #0d6efd; color: #fff;
               display: flex; align-items: center; justify-content: center;
               font-size: .65rem; font-weight: 700; flex-shrink: 0; }
.loc-dot-first { background: #198754; }
.loc-dot-last  { background: #dc3545; }
.loc-dot-col { width: 28px; }
.loc-content { border-left: 2px dashed #dee2e6; margin-left: 13px; padding-left: 16px; }
.loc-entry:last-child .loc-content { border-left-color: transparent; }
.avatar-sm   { width: 32px; height: 32px; font-size: .8rem; }
</style>

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
<div class="alert alert-warning d-flex gap-3 align-items-start">
  <i class="bi bi-exclamation-triangle-fill fs-4 flex-shrink-0 mt-1"></i>
  <div>
    <div class="fw-bold mb-1">Database setup required</div>
    <p class="mb-2 small">The <code>location_logs</code> table is missing.
      Run <strong>schema_update.sql</strong> in phpMyAdmin to enable this page.</p>
    <ol class="small mb-0">
      <li>Open phpMyAdmin and select your HRMS database.</li>
      <li>Go to the <strong>Import</strong> tab.</li>
      <li>Upload <code>schema_update.sql</code> and click <strong>Go</strong>.</li>
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
          <option value="<?= (int)$fw['id'] ?>" <?= $filterUid === (int)$fw['id'] ? 'selected' : '' ?>>
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
      <div class="col-auto ms-auto d-flex gap-1">
        <?php
        $prev = date('Y-m-d', strtotime($filterDate . ' -1 day'));
        $next = date('Y-m-d', strtotime($filterDate . ' +1 day'));
        ?>
        <a href="?date=<?= $prev ?>&user_id=<?= $filterUid ?>"
           class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
        <span class="btn btn-sm btn-light disabled fw-semibold">
          <?= h(date('d M Y', strtotime($filterDate))) ?>
        </span>
        <?php if ($next <= date('Y-m-d')): ?>
        <a href="?date=<?= $next ?>&user_id=<?= $filterUid ?>"
           class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-right"></i></a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- ── Summary Stats ──────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-people-fill"></i></div>
        <div>
          <div class="stat-value"><?= $activeWorkers ?></div>
          <div class="stat-label">Workers Active</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-geo-fill"></i></div>
        <div>
          <div class="stat-value"><?= $totalLogs ?></div>
          <div class="stat-label">Total Pings</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-speedometer2"></i></div>
        <div>
          <div class="stat-value"><?= number_format($grandTotalKm, 1) ?></div>
          <div class="stat-label">Total KM Today</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon bg-info-subtle text-info"><i class="bi bi-person-walking"></i></div>
        <div>
          <div class="stat-value"><?= number_format($avgKm, 1) ?></div>
          <div class="stat-label">Avg KM / Worker</div>
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
    <?php if (!empty($fieldWorkers)): ?>
    <div class="mt-2 small">Field workers log locations via the
      <strong>Log My Current Location</strong> button on their dashboard.</div>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>

<!-- ── Per-worker cards ───────────────────────────────────── -->
<?php foreach ($grouped as $workerId => $data):
  $logs    = $data['logs'];
  $gpsLogs = $data['gpsLogs'];
  $totalKm = $data['totalKm'];
  $total   = count($logs);

  // Last GPS log for "Last Location" button
  $lastGpsLog = null;
  for ($ri = $total - 1; $ri >= 0; $ri--) {
      if ($logs[$ri]['has_gps']) { $lastGpsLog = $logs[$ri]; break; }
  }

  $routeUrl  = count($gpsLogs) >= 2 ? buildMapsRouteUrl($gpsLogs) : '';
  $gpsCount  = count($gpsLogs);

  // Build map point data for JS
  $mapPoints = [];
  foreach ($gpsLogs as $gl) {
      $mapPoints[] = [
          'lat'   => (float)$gl['lat'],
          'lng'   => (float)$gl['lng'],
          'time'  => date('h:i A', strtotime($gl['logged_at'])),
          'acc'   => (int)($gl['accuracy'] != '' ? $gl['accuracy'] : 0),
          'task'  => $gl['task_title'] != '' ? (string)$gl['task_title'] : '',
          'notes' => $gl['notes'] != '' ? (string)$gl['notes'] : '',
          'km'    => $gl['segment_km'] !== null ? round((float)$gl['segment_km'], 2) : 0,
      ];
  }
?>
<div class="card border-0 shadow-sm mb-4">
  <!-- Card header -->
  <div class="card-header bg-transparent border-bottom d-flex align-items-center
              justify-content-between flex-wrap gap-2 py-3">
    <div class="d-flex align-items-center gap-2">
      <div class="avatar-circle avatar-sm flex-shrink-0">
        <?= strtoupper(substr($data['name'], 0, 1)) ?>
      </div>
      <div>
        <div class="fw-semibold"><?= h($data['name']) ?></div>
        <div class="text-muted small d-flex align-items-center gap-2 flex-wrap">
          <?= $total ?> ping<?= $total !== 1 ? 's' : '' ?> &middot;
          <?= h(date('d M Y', strtotime($filterDate))) ?>
          <?php if ($totalKm > 0): ?>
          &middot; <span class="km-badge">
            <i class="bi bi-signpost-split me-1"></i><?= number_format($totalKm, 2) ?> km
          </span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <?php if ($lastGpsLog): ?>
      <a href="https://maps.google.com/?q=<?= (float)$lastGpsLog['lat'] ?>,<?= (float)$lastGpsLog['lng'] ?>"
         target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-geo-alt-fill me-1"></i>Last Location
      </a>
      <?php endif; ?>
      <?php if ($routeUrl): ?>
      <a href="<?= h($routeUrl) ?>" target="_blank" rel="noopener"
         class="btn btn-sm btn-success">
        <i class="bi bi-map-fill me-1"></i>Full Day Route
      </a>
      <?php endif; ?>
    </div>
  </div>

  <div class="card-body p-3">

    <!-- ── Route Map ─────────────────────────────────────────── -->
    <?php if ($gpsCount >= 2): ?>
    <div id="map-<?= (int)$workerId ?>" class="loc-map mb-3"></div>
    <?php elseif ($gpsCount === 1): ?>
    <div class="alert alert-light border small text-muted mb-3 py-2">
      <i class="bi bi-info-circle me-1"></i>Only 1 GPS point logged — need 2+ to draw a route.
    </div>
    <?php endif; ?>

    <!-- ── Distance summary ───────────────────────────────────── -->
    <?php if ($totalKm > 0): ?>
    <div class="alert alert-success d-flex align-items-center gap-2 py-2 mb-3 small">
      <i class="bi bi-speedometer2 fs-5 flex-shrink-0"></i>
      <div>
        <strong><?= h($data['name']) ?></strong> travelled
        <strong><?= number_format($totalKm, 2) ?> km</strong>
        across <?= $gpsCount ?> GPS points on <?= h(date('d M Y', strtotime($filterDate))) ?>.
        <?php if ($gpsCount < $total): ?>
        <span class="text-muted">(<?= $total - $gpsCount ?> ping(s) without GPS.)</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Timeline ───────────────────────────────────────────── -->
    <div class="location-timeline">
      <?php foreach ($logs as $idx => $log):
        $hasGps = (bool)$log['has_gps'];
      ?>
      <div class="d-flex gap-3 align-items-start mb-1">
        <div class="loc-dot-col text-center">
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
                <?php if ($log['segment_km'] !== null): ?>
                <span class="seg-km">
                  <i class="bi bi-arrow-right-short"></i>
                  +<?= number_format((float)$log['segment_km'], 2) ?> km
                </span>
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
              <?php if (!empty($log['task_title'])): ?>
              <div class="mt-1">
                <span class="badge bg-primary-subtle text-primary" style="font-size:.72rem">
                  <i class="bi bi-list-task me-1"></i><?= h($log['task_title']) ?>
                </span>
              </div>
              <?php endif; ?>
              <?php if (!empty($log['notes'])): ?>
              <div class="small text-muted fst-italic mt-1">"<?= h($log['notes']) ?>"</div>
              <?php endif; ?>
            </div>
            <?php if ($hasGps): ?>
            <a href="https://maps.google.com/?q=<?= (float)$log['lat'] ?>,<?= (float)$log['lng'] ?>"
               target="_blank" rel="noopener"
               class="btn btn-sm btn-outline-primary flex-shrink-0"
               style="font-size:.72rem;padding:2px 8px">
              <i class="bi bi-map me-1"></i>Map
            </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- ── Day total ──────────────────────────────────────────── -->
    <?php if ($totalKm > 0): ?>
    <div class="d-flex align-items-center gap-2 mt-1 mb-2 small text-muted fw-semibold
                border-top pt-2">
      <i class="bi bi-flag-fill text-danger"></i>
      Day total: <span class="text-success fw-bold"><?= number_format($totalKm, 2) ?> km</span>
      across <?= $gpsCount ?> GPS check-ins
    </div>
    <?php endif; ?>

    <!-- ── Google Maps route button ───────────────────────────── -->
    <?php if ($routeUrl): ?>
    <a href="<?= h($routeUrl) ?>" target="_blank" rel="noopener"
       class="btn btn-sm btn-outline-success w-100 mt-1">
      <i class="bi bi-signpost-split me-2"></i>View Full Day Route on Google Maps
      <?php if ($gpsCount > 10): ?>
      <span class="badge bg-secondary ms-1" style="font-size:.65rem">10 stops sampled</span>
      <?php endif; ?>
    </a>
    <?php endif; ?>

    <!-- Inject map point data for this worker -->
    <?php if ($gpsCount >= 2): ?>
    <script>
    window.HRMS_MAP_DATA = window.HRMS_MAP_DATA || {};
    window.HRMS_MAP_DATA[<?= (int)$workerId ?>] =
      <?= json_encode($mapPoints, JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <?php endif; ?>

  </div><!-- /card-body -->
</div><!-- /card -->
<?php endforeach; ?>
<?php endif; // allLogs ?>
<?php endif; // tableReady ?>

<!-- Leaflet JS — loaded once at end of page -->
<?php if ($hasAnyMap): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.min.js"></script>
<script>
(function () {
  if (typeof L === 'undefined' || typeof window.HRMS_MAP_DATA === 'undefined') { return; }

  var mapData = window.HRMS_MAP_DATA;

  Object.keys(mapData).forEach(function (wid) {
    var el = document.getElementById('map-' + wid);
    if (!el) { return; }

    var pts     = mapData[wid];
    var latlngs = [];
    for (var j = 0; j < pts.length; j++) {
      latlngs.push([pts[j].lat, pts[j].lng]);
    }

    var map = L.map(el, { zoomControl: true });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
      maxZoom: 19
    }).addTo(map);

    // Route polyline
    L.polyline(latlngs, { color: '#0d6efd', weight: 3, opacity: 0.85 }).addTo(map);

    // Circle markers
    for (var i = 0; i < pts.length; i++) {
      var p       = pts[i];
      var isFirst = i === 0;
      var isLast  = i === pts.length - 1;
      var color   = isFirst ? '#198754' : (isLast ? '#dc3545' : '#0d6efd');

      var popup = '<b>' + p.time + '</b>';
      if (p.acc)   { popup += ' <small style="color:#888">\u00b1' + p.acc + ' m</small>'; }
      if (p.km > 0){ popup += '<br><span style="color:#198754">+' + p.km + ' km from prev</span>'; }
      if (p.task)  { popup += '<br><b>Task:</b> ' + p.task; }
      if (p.notes) { popup += '<br><i>"' + p.notes + '"</i>'; }
      if (isFirst) { popup = '<span style="color:#198754">\u25cf Start</span><br>' + popup; }
      if (isLast)  { popup = '<span style="color:#dc3545">\u25a0 End</span><br>' + popup; }

      L.circleMarker([p.lat, p.lng], {
        radius: isFirst || isLast ? 9 : 6,
        fillColor: color,
        color: '#fff',
        weight: 2,
        opacity: 1,
        fillOpacity: 0.95
      }).bindPopup(popup).addTo(map);
    }

    map.fitBounds(L.latLngBounds(latlngs), { padding: [20, 20] });
  });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
