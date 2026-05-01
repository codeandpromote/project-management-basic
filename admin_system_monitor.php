<?php
// ============================================================
//  HRMS · System Monitor  (Admin only)
//  Active sessions · Device bindings · Health · System log
// ============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
requireRole('admin');

$user = getCurrentUser();
$db   = getDB();

// ── Helpers ──────────────────────────────────────────────────
function fmtBytes(float $b): string {
    if ($b <= 0) return '—';
    $u = ['B','KB','MB','GB','TB'];
    $i = 0;
    while ($b >= 1024 && $i < count($u) - 1) { $b /= 1024; $i++; }
    return number_format($b, 2) . ' ' . $u[$i];
}
function timeAgo(?string $dt): string {
    if (!$dt) return '—';
    $t = strtotime($dt);
    if (!$t) return '—';
    $diff = time() - $t;
    if ($diff < 60)        return $diff . 's ago';
    if ($diff < 3600)      return floor($diff/60)   . 'm ago';
    if ($diff < 86400)     return floor($diff/3600) . 'h ago';
    if ($diff < 86400*7)   return floor($diff/86400). 'd ago';
    return date('d M Y', $t);
}
function shortDevice(?string $d): string {
    if (!$d) return '—';
    return substr($d, 0, 8) . '…' . substr($d, -4);
}
function eventBadge(string $type): string {
    $map = [
        'login_success'    => ['bg-success',         'bi-box-arrow-in-right',  'Login OK'],
        'login_failed'     => ['bg-danger',          'bi-x-octagon-fill',      'Login Fail'],
        'logout'           => ['bg-secondary',       'bi-box-arrow-right',     'Logout'],
        'device_bound'     => ['bg-primary',         'bi-phone-fill',          'Device Bound'],
        'device_rejected'  => ['bg-warning text-dark','bi-shield-fill-x',      'Device Rejected'],
        'device_reset'     => ['bg-info text-dark',  'bi-phone-vibrate-fill',  'Device Reset'],
        'device_missing'   => ['bg-warning text-dark','bi-question-circle-fill','No Device ID'],
        'session_replaced' => ['bg-dark',            'bi-arrow-left-right',    'Session Replaced'],
    ];
    [$cls, $icon, $label] = $map[$type] ?? ['bg-secondary', 'bi-tag', $type];
    return '<span class="badge ' . $cls . '"><i class="bi ' . $icon . ' me-1"></i>' . h($label) . '</span>';
}

// ── Active sessions (any user with a non-null session_token) ──
$activeSessions = $db->query(
    "SELECT id, name, email, role, device_id, last_login_at,
            last_login_ip, last_user_agent, device_bound_at
       FROM users
      WHERE session_token IS NOT NULL
      ORDER BY last_login_at DESC"
)->fetchAll();

// ── Bound devices (field workers with device_id set) ─────────
$boundDevices = $db->query(
    "SELECT id, name, email, role, device_id, device_bound_at, last_login_ip
       FROM users
      WHERE device_id IS NOT NULL
      ORDER BY device_bound_at DESC"
)->fetchAll();

// ── User counts ──────────────────────────────────────────────
$counts = $db->query(
    "SELECT
       SUM(is_active = 1) AS active_users,
       SUM(is_active = 0) AS inactive_users,
       SUM(role = 'admin')        AS n_admin,
       SUM(role = 'office_staff') AS n_office,
       SUM(role = 'field_worker') AS n_field,
       SUM(session_token IS NOT NULL) AS n_active_sessions,
       SUM(role = 'field_worker' AND device_id IS NOT NULL) AS n_bound_field
     FROM users"
)->fetch();

// ── Today's activity ─────────────────────────────────────────
$today = date('Y-m-d');
$todayChecks = (int)$db->query(
    "SELECT COUNT(*) FROM attendance WHERE work_date = '" . $today . "'"
)->fetchColumn();

// ── DB size / row counts ─────────────────────────────────────
$dbSize = '—';
$tableStats = [];
try {
    $r = $db->prepare(
        "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS mb
           FROM information_schema.tables WHERE table_schema = ?"
    );
    $r->execute([DB_NAME]);
    $mb = (float)$r->fetchColumn();
    $dbSize = $mb > 0 ? number_format($mb, 2) . ' MB' : '—';

    $r = $db->prepare(
        "SELECT table_name AS t,
                table_rows  AS rows_approx,
                ROUND((data_length + index_length)/1024/1024, 2) AS mb
           FROM information_schema.tables
          WHERE table_schema = ?
          ORDER BY (data_length + index_length) DESC
          LIMIT 30"
    );
    $r->execute([DB_NAME]);
    $tableStats = $r->fetchAll();
} catch (PDOException $ignored) { /* shared host may deny */ }

// ── MySQL version ────────────────────────────────────────────
$mysqlVer = '—';
try { $mysqlVer = (string)$db->query('SELECT VERSION()')->fetchColumn(); }
catch (PDOException $ignored) {}

// ── Disk space (uploads dir) ─────────────────────────────────
$diskFree  = @disk_free_space(__DIR__);
$diskTotal = @disk_total_space(__DIR__);
$diskUsedPct = ($diskTotal && $diskFree)
    ? (int)round(100 * (1 - $diskFree / $diskTotal)) : 0;

// ── Memory & peak ────────────────────────────────────────────
$memUsed = memory_get_usage(true);
$memPeak = memory_get_peak_usage(true);

// ── Uploads folder size ──────────────────────────────────────
function dirSize(string $dir): int {
    if (!is_dir($dir)) return 0;
    $size = 0;
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $dir, FilesystemIterator::SKIP_DOTS
        ));
        foreach ($it as $f) {
            if ($f->isFile()) $size += $f->getSize();
        }
    } catch (Throwable $ignored) {}
    return $size;
}
$uploadsSize = dirSize(rtrim(UPLOAD_PATH, '/'));

// ── System log filters ───────────────────────────────────────
$filterEvent = $_GET['event'] ?? '';
$filterUser  = (int)($_GET['log_user'] ?? 0);
$filterFrom  = $_GET['from']  ?? '';
$filterTo    = $_GET['to']    ?? '';

$where  = [];
$params = [];
if ($filterEvent) {
    $where[] = 'sl.event_type = ?';
    $params[] = $filterEvent;
}
if ($filterUser) {
    $where[] = 'sl.user_id = ?';
    $params[] = $filterUser;
}
if ($filterFrom) {
    $where[] = 'sl.created_at >= ?';
    $params[] = $filterFrom . ' 00:00:00';
}
if ($filterTo) {
    $where[] = 'sl.created_at <= ?';
    $params[] = $filterTo . ' 23:59:59';
}

$logSql = 'SELECT sl.*, u.name AS user_name, u.email AS user_email,
                  a.name AS actor_name
             FROM system_log sl
        LEFT JOIN users u ON u.id = sl.user_id
        LEFT JOIN users a ON a.id = sl.actor_id'
       . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
       . ' ORDER BY sl.created_at DESC LIMIT 250';

$logRows = [];
try {
    $st = $db->prepare($logSql);
    $st->execute($params);
    $logRows = $st->fetchAll();
} catch (PDOException $e) {
    // table may not exist yet on a fresh shared host
}

// User dropdown for log filter
$logUsers = $db->query('SELECT id, name FROM users ORDER BY name')->fetchAll();

// Distinct event types seen in DB (for filter dropdown)
$logEvents = [];
try {
    $logEvents = $db->query(
        'SELECT DISTINCT event_type FROM system_log ORDER BY event_type'
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $ignored) {}

// ── POST: clear log ──────────────────────────────────────────
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && verifyCSRF($_POST['csrf_token'] ?? '')
    && ($_POST['form_action'] ?? '') === 'clear_log') {
    $days = max(0, (int)($_POST['older_than_days'] ?? 0));
    try {
        if ($days > 0) {
            $st = $db->prepare('DELETE FROM system_log WHERE created_at < (NOW() - INTERVAL ? DAY)');
            $st->execute([$days]);
            $flash = "Cleared log entries older than {$days} days.";
        } else {
            $db->exec('TRUNCATE TABLE system_log');
            $flash = 'System log cleared.';
        }
        logSystemEvent('log_cleared', null,
            $days > 0 ? "Cleared entries older than {$days} days" : 'Cleared all entries',
            null, (int)$user['id']);
        header('Location: admin_system_monitor.php?cleared=1');
        exit;
    } catch (PDOException $e) {
        $flash = 'Could not clear log: ' . $e->getMessage();
    }
}
if (($_GET['cleared'] ?? '') === '1') {
    $flash = 'System log cleared.';
}

$pageTitle = 'System Monitor';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <h5 class="fw-bold mb-0">
    <i class="bi bi-activity me-2 text-primary"></i>System Monitor
  </h5>
  <div class="d-flex gap-2">
    <a href="admin_system_monitor.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-clockwise me-1"></i>Refresh
    </a>
  </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-success alert-dismissible d-flex gap-2 mb-4">
  <i class="bi bi-check-circle-fill flex-shrink-0 mt-1"></i><div><?= h($flash) ?></div>
  <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Summary stat cards ─────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-broadcast-pin"></i></div>
        <div>
          <div class="stat-value"><?= (int)$counts['n_active_sessions'] ?></div>
          <div class="stat-label">Active Sessions</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-phone-fill"></i></div>
        <div>
          <div class="stat-value"><?= (int)$counts['n_bound_field'] ?>/<?= (int)$counts['n_field'] ?></div>
          <div class="stat-label">Bound Field Devices</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-clock-history"></i></div>
        <div>
          <div class="stat-value"><?= (int)$todayChecks ?></div>
          <div class="stat-label">Check-ins Today</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-info-subtle text-info"><i class="bi bi-database-fill"></i></div>
        <div>
          <div class="stat-value"><?= h($dbSize) ?></div>
          <div class="stat-label">DB Size</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── Tabs ───────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-3" role="tablist">
  <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-sessions">
    <i class="bi bi-broadcast-pin me-1"></i>Active Sessions
  </button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-devices">
    <i class="bi bi-phone-fill me-1"></i>Bound Devices
  </button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-health">
    <i class="bi bi-heart-pulse-fill me-1"></i>System Health
  </button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-log">
    <i class="bi bi-journal-text me-1"></i>System Log
  </button></li>
</ul>

<div class="tab-content">

  <!-- ╭─ Active Sessions ────────────────────────────────╮ -->
  <div class="tab-pane fade show active" id="tab-sessions">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-bottom-0 py-3">
        <h6 class="mb-0 fw-bold">
          <i class="bi bi-broadcast-pin me-2 text-success"></i>Currently Signed-In Users
          <span class="text-muted small fw-normal">— one device per account is enforced</span>
        </h6>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-3">User</th>
              <th>Role</th>
              <th>IP Address</th>
              <th>Device ID</th>
              <th>User Agent</th>
              <th>Last Login</th>
              <th class="pe-3 text-center">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($activeSessions)): ?>
              <tr><td colspan="7" class="text-center py-4 text-muted">
                <i class="bi bi-emoji-neutral fs-3 d-block mb-1 opacity-50"></i>
                No users signed in.
              </td></tr>
            <?php endif; ?>
            <?php foreach ($activeSessions as $s): ?>
            <tr>
              <td class="ps-3">
                <div class="fw-semibold small"><?= h($s['name']) ?></div>
                <div class="text-muted" style="font-size:.72rem"><?= h($s['email']) ?></div>
              </td>
              <td>
                <span class="badge bg-<?= $s['role']==='admin'?'danger':($s['role']==='office_staff'?'primary':'success') ?>">
                  <?= h(ucfirst(str_replace('_',' ',$s['role']))) ?>
                </span>
              </td>
              <td class="font-monospace small"><?= h($s['last_login_ip'] ?? '—') ?></td>
              <td class="font-monospace small" title="<?= h($s['device_id'] ?? '') ?>">
                <?= h(shortDevice($s['device_id'])) ?>
              </td>
              <td class="text-muted small text-truncate" style="max-width:280px"
                  title="<?= h($s['last_user_agent'] ?? '') ?>">
                <?= h(substr($s['last_user_agent'] ?? '—', 0, 60)) ?>
              </td>
              <td class="small">
                <div><?= h(date('d M Y · H:i', strtotime($s['last_login_at'] ?? 'now'))) ?></div>
                <div class="text-muted" style="font-size:.72rem"><?= h(timeAgo($s['last_login_at'])) ?></div>
              </td>
              <td class="text-center pe-3">
                <?php if ((int)$s['id'] !== (int)$user['id']): ?>
                <form method="POST" action="admin_users.php" class="d-inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="form_action" value="reset_device">
                  <input type="hidden" name="reset_id"    value="<?= (int)$s['id'] ?>">
                  <button type="submit" class="btn btn-xs btn-outline-danger"
                          onclick="return confirm('Force sign-out <?= h($s['name']) ?>?')"
                          title="Force sign-out & clear device">
                    <i class="bi bi-box-arrow-right"></i> Sign Out
                  </button>
                </form>
                <?php else: ?>
                  <span class="text-muted small">— you —</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ╭─ Bound Devices ──────────────────────────────────╮ -->
  <div class="tab-pane fade" id="tab-devices">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-bottom-0 py-3">
        <h6 class="mb-0 fw-bold">
          <i class="bi bi-phone-fill me-2 text-primary"></i>Device Bindings
          <span class="text-muted small fw-normal">— field workers locked to their device</span>
        </h6>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-3">User</th>
              <th>Role</th>
              <th>Device ID</th>
              <th>Bound On</th>
              <th>Last Known IP</th>
              <th class="pe-3 text-center">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($boundDevices)): ?>
              <tr><td colspan="6" class="text-center py-4 text-muted">
                No devices bound yet.
              </td></tr>
            <?php endif; ?>
            <?php foreach ($boundDevices as $d): ?>
            <tr>
              <td class="ps-3">
                <div class="fw-semibold small"><?= h($d['name']) ?></div>
                <div class="text-muted" style="font-size:.72rem"><?= h($d['email']) ?></div>
              </td>
              <td>
                <span class="badge bg-<?= $d['role']==='admin'?'danger':($d['role']==='office_staff'?'primary':'success') ?>">
                  <?= h(ucfirst(str_replace('_',' ',$d['role']))) ?>
                </span>
              </td>
              <td class="font-monospace small" title="<?= h($d['device_id']) ?>">
                <?= h(shortDevice($d['device_id'])) ?>
              </td>
              <td class="small">
                <?= $d['device_bound_at'] ? h(date('d M Y · H:i', strtotime($d['device_bound_at']))) : '—' ?>
                <div class="text-muted" style="font-size:.72rem"><?= h(timeAgo($d['device_bound_at'])) ?></div>
              </td>
              <td class="font-monospace small"><?= h($d['last_login_ip'] ?? '—') ?></td>
              <td class="text-center pe-3">
                <?php if ((int)$d['id'] !== (int)$user['id']): ?>
                <form method="POST" action="admin_users.php" class="d-inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="form_action" value="reset_device">
                  <input type="hidden" name="reset_id"    value="<?= (int)$d['id'] ?>">
                  <button type="submit" class="btn btn-xs btn-outline-info"
                          onclick="return confirm('Reset device for <?= h($d['name']) ?>? They will need to bind a new device on next login.')"
                          title="Reset device">
                    <i class="bi bi-phone-vibrate-fill"></i> Reset
                  </button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ╭─ System Health ──────────────────────────────────╮ -->
  <div class="tab-pane fade" id="tab-health">
    <div class="row g-3">
      <!-- Server -->
      <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-header bg-white border-bottom-0 py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-cpu-fill me-2 text-primary"></i>Server</h6>
          </div>
          <div class="card-body py-2">
            <table class="table table-sm mb-0">
              <tbody>
                <tr><th class="text-muted small fw-normal">PHP Version</th><td class="font-monospace small"><?= h(PHP_VERSION) ?></td></tr>
                <tr><th class="text-muted small fw-normal">SAPI</th><td class="font-monospace small"><?= h(PHP_SAPI) ?></td></tr>
                <tr><th class="text-muted small fw-normal">OS</th><td class="font-monospace small"><?= h(PHP_OS . ' (' . php_uname('m') . ')') ?></td></tr>
                <tr><th class="text-muted small fw-normal">Server Software</th><td class="font-monospace small"><?= h($_SERVER['SERVER_SOFTWARE'] ?? '—') ?></td></tr>
                <tr><th class="text-muted small fw-normal">Server Time</th><td class="font-monospace small"><?= h(date('Y-m-d H:i:s T')) ?></td></tr>
                <tr><th class="text-muted small fw-normal">Timezone</th><td class="font-monospace small"><?= h(date_default_timezone_get()) ?></td></tr>
                <tr><th class="text-muted small fw-normal">Memory Used</th><td class="font-monospace small"><?= h(fmtBytes((float)$memUsed)) ?></td></tr>
                <tr><th class="text-muted small fw-normal">Memory Peak</th><td class="font-monospace small"><?= h(fmtBytes((float)$memPeak)) ?></td></tr>
                <tr><th class="text-muted small fw-normal">memory_limit</th><td class="font-monospace small"><?= h(ini_get('memory_limit')) ?></td></tr>
                <tr><th class="text-muted small fw-normal">max_execution_time</th><td class="font-monospace small"><?= h(ini_get('max_execution_time')) ?>s</td></tr>
                <tr><th class="text-muted small fw-normal">upload_max_filesize</th><td class="font-monospace small"><?= h(ini_get('upload_max_filesize')) ?></td></tr>
                <tr><th class="text-muted small fw-normal">post_max_size</th><td class="font-monospace small"><?= h(ini_get('post_max_size')) ?></td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Application -->
      <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-header bg-white border-bottom-0 py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-gear-fill me-2 text-primary"></i>Application Config</h6>
          </div>
          <div class="card-body py-2">
            <table class="table table-sm mb-0">
              <tbody>
                <tr><th class="text-muted small fw-normal">Site Name</th><td class="font-monospace small"><?= h(SITE_NAME) ?></td></tr>
                <tr><th class="text-muted small fw-normal">Base URL</th><td class="font-monospace small"><?= h(BASE_URL) ?></td></tr>
                <tr><th class="text-muted small fw-normal">DEV_MODE</th>
                    <td><span class="badge bg-<?= DEV_MODE?'warning text-dark':'success' ?>">
                      <?= DEV_MODE?'ENABLED (bypasses IP/GPS)':'Production' ?>
                    </span></td></tr>
                <tr><th class="text-muted small fw-normal">Office IP</th><td class="font-monospace small"><?= h(OFFICE_IP) ?></td></tr>
                <tr><th class="text-muted small fw-normal">Office Lat/Lng</th><td class="font-monospace small"><?= h(OFFICE_LAT) ?>, <?= h(OFFICE_LNG) ?></td></tr>
                <tr><th class="text-muted small fw-normal">Geofence Radius</th><td class="font-monospace small"><?= (int)GEOFENCE_RADIUS ?>m</td></tr>
                <tr><th class="text-muted small fw-normal">Session Lifetime</th><td class="font-monospace small"><?= (int)SESSION_LIFETIME ?>s (<?= (int)(SESSION_LIFETIME/60) ?>m)</td></tr>
                <tr><th class="text-muted small fw-normal">Max File</th><td class="font-monospace small"><?= (int)MAX_FILE_MB ?> MB</td></tr>
                <tr><th class="text-muted small fw-normal">Uploads Folder Size</th><td class="font-monospace small"><?= h(fmtBytes((float)$uploadsSize)) ?></td></tr>
                <tr><th class="text-muted small fw-normal">Disk Free</th><td class="font-monospace small"><?= h(fmtBytes((float)($diskFree ?: 0))) ?> / <?= h(fmtBytes((float)($diskTotal ?: 0))) ?></td></tr>
                <tr><th class="text-muted small fw-normal">Disk Used</th><td>
                  <div class="progress" style="height:8px">
                    <div class="progress-bar bg-<?= $diskUsedPct>85?'danger':($diskUsedPct>70?'warning':'success') ?>"
                         style="width: <?= $diskUsedPct ?>%"></div>
                  </div>
                  <small class="text-muted"><?= $diskUsedPct ?>% used</small>
                </td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Database -->
      <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-header bg-white border-bottom-0 py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-database-fill me-2 text-primary"></i>Database</h6>
          </div>
          <div class="card-body py-2">
            <table class="table table-sm mb-0">
              <tbody>
                <tr><th class="text-muted small fw-normal">Host</th><td class="font-monospace small"><?= h(DB_HOST) ?></td></tr>
                <tr><th class="text-muted small fw-normal">Database</th><td class="font-monospace small"><?= h(DB_NAME) ?></td></tr>
                <tr><th class="text-muted small fw-normal">MySQL Version</th><td class="font-monospace small"><?= h($mysqlVer) ?></td></tr>
                <tr><th class="text-muted small fw-normal">Charset</th><td class="font-monospace small"><?= h(DB_CHARSET) ?></td></tr>
                <tr><th class="text-muted small fw-normal">Total Size</th><td class="font-monospace small"><?= h($dbSize) ?></td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Users summary -->
      <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-header bg-white border-bottom-0 py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-people-fill me-2 text-primary"></i>Users</h6>
          </div>
          <div class="card-body py-2">
            <table class="table table-sm mb-0">
              <tbody>
                <tr><th class="text-muted small fw-normal">Active</th><td class="font-monospace small"><?= (int)$counts['active_users'] ?></td></tr>
                <tr><th class="text-muted small fw-normal">Inactive</th><td class="font-monospace small"><?= (int)$counts['inactive_users'] ?></td></tr>
                <tr><th class="text-muted small fw-normal">Admins</th><td class="font-monospace small"><?= (int)$counts['n_admin'] ?></td></tr>
                <tr><th class="text-muted small fw-normal">Office Staff</th><td class="font-monospace small"><?= (int)$counts['n_office'] ?></td></tr>
                <tr><th class="text-muted small fw-normal">Field Workers</th><td class="font-monospace small"><?= (int)$counts['n_field'] ?></td></tr>
                <tr><th class="text-muted small fw-normal">Active Sessions</th><td class="font-monospace small"><?= (int)$counts['n_active_sessions'] ?></td></tr>
                <tr><th class="text-muted small fw-normal">Field Devices Bound</th><td class="font-monospace small"><?= (int)$counts['n_bound_field'] ?> / <?= (int)$counts['n_field'] ?></td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Table sizes -->
      <?php if (!empty($tableStats)): ?>
      <div class="col-12">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white border-bottom-0 py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-table me-2 text-primary"></i>Table Sizes</h6>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
              <thead class="table-light">
                <tr><th class="ps-3">Table</th><th class="text-end">Approx Rows</th><th class="text-end pe-3">Size (MB)</th></tr>
              </thead>
              <tbody>
                <?php foreach ($tableStats as $ts): ?>
                <tr>
                  <td class="ps-3 font-monospace small"><?= h($ts['t']) ?></td>
                  <td class="text-end small"><?= number_format((int)$ts['rows_approx']) ?></td>
                  <td class="text-end pe-3 small"><?= h(number_format((float)$ts['mb'], 2)) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ╭─ System Log ─────────────────────────────────────╮ -->
  <div class="tab-pane fade" id="tab-log">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
          <div class="col-sm-3">
            <label class="form-label small fw-semibold mb-1">Event Type</label>
            <select name="event" class="form-select form-select-sm">
              <option value="">All events</option>
              <?php foreach ($logEvents as $ev): ?>
              <option value="<?= h($ev) ?>" <?= $filterEvent===$ev?'selected':'' ?>><?= h($ev) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label small fw-semibold mb-1">User</label>
            <select name="log_user" class="form-select form-select-sm">
              <option value="0">All users</option>
              <?php foreach ($logUsers as $lu): ?>
              <option value="<?= (int)$lu['id'] ?>" <?= $filterUser===(int)$lu['id']?'selected':'' ?>>
                <?= h($lu['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-2">
            <label class="form-label small fw-semibold mb-1">From</label>
            <input type="date" class="form-control form-control-sm" name="from" value="<?= h($filterFrom) ?>">
          </div>
          <div class="col-sm-2">
            <label class="form-label small fw-semibold mb-1">To</label>
            <input type="date" class="form-control form-control-sm" name="to" value="<?= h($filterTo) ?>">
          </div>
          <div class="col-sm-2 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
            <a href="admin_system_monitor.php#tab-log" class="btn btn-sm btn-outline-secondary">Reset</a>
          </div>
        </form>
      </div>
    </div>

    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-bottom-0 py-3 d-flex flex-wrap gap-2 align-items-center">
        <h6 class="mb-0 fw-bold">
          <i class="bi bi-journal-text me-2 text-primary"></i>System Log
          <span class="text-muted small fw-normal">— most recent 250 entries</span>
        </h6>
        <form method="POST" class="ms-auto d-flex gap-1 align-items-center">
          <?= csrfField() ?>
          <input type="hidden" name="form_action" value="clear_log">
          <input type="number" min="0" name="older_than_days" placeholder="days"
                 class="form-control form-control-sm" style="width:80px">
          <button type="submit" class="btn btn-sm btn-outline-danger"
                  onclick="return confirm('Clear log entries? Leave days blank to clear ALL entries.')">
            <i class="bi bi-trash3 me-1"></i>Clear
          </button>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-3">When</th>
              <th>Event</th>
              <th>User</th>
              <th>IP</th>
              <th>Device</th>
              <th>Details</th>
              <th class="pe-3">Actor</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($logRows)): ?>
              <tr><td colspan="7" class="text-center py-4 text-muted">
                <i class="bi bi-inbox fs-3 d-block mb-1 opacity-50"></i>
                No log entries.
              </td></tr>
            <?php endif; ?>
            <?php foreach ($logRows as $r): ?>
            <tr>
              <td class="ps-3 small">
                <div><?= h(date('d M · H:i:s', strtotime($r['created_at']))) ?></div>
                <div class="text-muted" style="font-size:.7rem"><?= h(timeAgo($r['created_at'])) ?></div>
              </td>
              <td><?= eventBadge($r['event_type']) ?></td>
              <td class="small">
                <?php if ($r['user_name']): ?>
                  <div class="fw-semibold"><?= h($r['user_name']) ?></div>
                  <div class="text-muted" style="font-size:.7rem"><?= h($r['user_email']) ?></div>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="font-monospace small"><?= h($r['ip_address'] ?? '—') ?></td>
              <td class="font-monospace small" title="<?= h($r['device_id'] ?? '') ?>">
                <?= h(shortDevice($r['device_id'])) ?>
              </td>
              <td class="small text-truncate" style="max-width:340px"
                  title="<?= h(($r['details'] ?? '') . ($r['user_agent'] ? ' · ' . $r['user_agent'] : '')) ?>">
                <?= h($r['details'] ?? '—') ?>
              </td>
              <td class="pe-3 small">
                <?= $r['actor_name'] ? h($r['actor_name']) : '<span class="text-muted">—</span>' ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<script>
// Open the right tab if URL hash is set (e.g. #tab-log)
(function() {
  const hash = window.location.hash;
  if (hash && hash.startsWith('#tab-')) {
    const trigger = document.querySelector('[data-bs-target="' + hash + '"]');
    if (trigger) bootstrap.Tab.getOrCreateInstance(trigger).show();
  }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
