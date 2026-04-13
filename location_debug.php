<?php
// ============================================================
//  HRMS · Location Debug / Diagnostic (admin or field_worker)
// ============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
requireLogin();

$user = getCurrentUser();
$db   = getDB();
$role = $user['role'];

if (!in_array($role, ['admin', 'field_worker'], true)) {
    exit('<h3>403</h3>');
}

// ── Tests ─────────────────────────────────────────────────────
$tests = [];

// 1. Table exists?
try {
    $db->query('SELECT 1 FROM location_logs LIMIT 1');
    $tests['table'] = ['ok', 'location_logs table EXISTS'];
} catch (PDOException $e) {
    $tests['table'] = ['fail', 'location_logs table MISSING: ' . $e->getMessage()];
}

// 2. Can INSERT?
$canInsert = false;
if ($tests['table'][0] === 'ok') {
    try {
        $db->beginTransaction();
        $st = $db->prepare(
            "INSERT INTO location_logs (user_id, log_date, lat, lng, accuracy, logged_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $st->execute([$user['id'], date('Y-m-d'), 0.0, 0.0, null, date('Y-m-d H:i:s')]);
        $newId = $db->lastInsertId();
        $db->rollBack();
        $tests['insert'] = ['ok', "INSERT succeeded (rolled back), lastInsertId=$newId"];
        $canInsert = true;
    } catch (PDOException $e) {
        try { $db->rollBack(); } catch (Exception $ignored) {}
        $tests['insert'] = ['fail', 'INSERT failed: ' . $e->getMessage()];
    }
}

// 3. CSRF token
$csrf = generateCSRF();
$tests['csrf'] = ['ok', 'CSRF token: ' . substr($csrf, 0, 16) . '…'];

// 4. Session
$tests['session'] = ['ok', 'Session active, user_id=' . ($_SESSION['user_id'] ?? 'MISSING')];

// 5. HTTPS detection
$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['HTTP_X_FORWARDED_SSL'])   && $_SERVER['HTTP_X_FORWARDED_SSL']   === 'on');
$tests['https'] = [$isHttps ? 'ok' : 'warn',
    'HTTPS detected: ' . ($isHttps ? 'YES' : 'NO') .
    ' | HTTPS=' . ($_SERVER['HTTPS'] ?? 'unset') .
    ' | X-Forwarded-Proto=' . ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'unset')];

$pageTitle = 'Location Debug';
include __DIR__ . '/includes/header.php';
?>

<h5 class="fw-bold mb-3"><i class="bi bi-bug-fill me-2 text-danger"></i>Location Debug Panel</h5>
<p class="text-muted small mb-3">Visit this page on mobile to diagnose what's failing. Share a screenshot with your developer.</p>

<!-- ── Server-side tests ──────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header fw-semibold bg-transparent border-bottom">
    <i class="bi bi-server me-1"></i> Server Tests
  </div>
  <div class="card-body p-0">
    <ul class="list-group list-group-flush">
      <?php foreach ($tests as $key => $r): ?>
      <li class="list-group-item d-flex gap-2 small">
        <span class="badge <?= $r[0] === 'ok' ? 'bg-success' : ($r[0] === 'warn' ? 'bg-warning text-dark' : 'bg-danger') ?>" style="min-width:44px">
          <?= strtoupper($r[0]) ?>
        </span>
        <code><?= h($r[1]) ?></code>
      </li>
      <?php endforeach; ?>
      <li class="list-group-item small">
        <strong>Role:</strong> <?= h($role) ?> &nbsp;|&nbsp;
        <strong>User ID:</strong> <?= (int)$user['id'] ?> &nbsp;|&nbsp;
        <strong>PHP timezone:</strong> <?= date_default_timezone_get() ?>
      </li>
    </ul>
  </div>
</div>

<!-- ── Browser / JS tests ────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header fw-semibold bg-transparent border-bottom">
    <i class="bi bi-phone me-1"></i> Browser Tests (JavaScript)
  </div>
  <div class="card-body p-0">
    <ul class="list-group list-group-flush" id="jsTests">
      <li class="list-group-item small text-muted">Running…</li>
    </ul>
  </div>
</div>

<!-- ── Live AJAX test ─────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header fw-semibold bg-transparent border-bottom">
    <i class="bi bi-send me-1"></i> Live AJAX Test (No-GPS log)
  </div>
  <div class="card-body">
    <p class="small text-muted mb-2">Tapping the button below sends a real no-GPS location log to the server and shows the raw response.</p>
    <button class="btn btn-primary btn-sm" id="btnAjaxTest">
      <i class="bi bi-play-fill me-1"></i>Run AJAX Test
    </button>
    <pre id="ajaxResult" class="mt-3 p-2 bg-light border rounded small" style="white-space:pre-wrap;word-break:break-all;display:none"></pre>
  </div>
</div>

<!-- ── GPS test ───────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header fw-semibold bg-transparent border-bottom">
    <i class="bi bi-geo-alt me-1"></i> GPS Test
  </div>
  <div class="card-body">
    <button class="btn btn-success btn-sm" id="btnGpsTest">
      <i class="bi bi-geo-alt-fill me-1"></i>Test GPS
    </button>
    <div id="gpsResult" class="mt-2 small text-muted">Not tested yet.</div>
  </div>
</div>

<script>
(function () {
  // ── JS tests ───────────────────────────────────────────────
  var csrfToken = '<?= h($csrf) ?>';
  var results   = [];

  function addResult(status, msg) {
    results.push({ status: status, msg: msg });
  }

  // Nullish coalescing test
  try {
    var x = null;
    var y = x !== null && x !== undefined ? x : 'ok';
    addResult('ok', 'ES5 ternary (null coalesce): ok');
  } catch (e) { addResult('fail', 'ES5 ternary failed: ' + e); }

  // fetch
  if (typeof fetch !== 'undefined') {
    addResult('ok', 'fetch API: supported');
  } else {
    addResult('fail', 'fetch API: NOT supported — AJAX will fail');
  }

  // URLSearchParams
  if (typeof URLSearchParams !== 'undefined') {
    addResult('ok', 'URLSearchParams: supported');
  } else {
    addResult('fail', 'URLSearchParams: NOT supported');
  }

  // Bootstrap
  if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
    addResult('ok', 'Bootstrap 5 Modal: loaded');
  } else {
    addResult('fail', 'Bootstrap: NOT loaded — modals will not open');
  }

  // Geolocation
  if (navigator.geolocation) {
    addResult('ok', 'navigator.geolocation: available');
  } else {
    addResult('fail', 'navigator.geolocation: NOT available');
  }

  // Protocol
  addResult(
    location.protocol === 'https:' ? 'ok' : 'warn',
    'Protocol: ' + location.protocol + ' | host: ' + location.hostname
  );

  // window.isSecureContext
  addResult(
    'info',
    'window.isSecureContext: ' + window.isSecureContext + ' (type: ' + typeof window.isSecureContext + ')'
  );

  // UserAgent
  addResult('info', 'UA: ' + navigator.userAgent.substring(0, 120));

  // Render results
  var ul = document.getElementById('jsTests');
  ul.innerHTML = '';
  results.forEach(function (r) {
    var li = document.createElement('li');
    li.className = 'list-group-item d-flex gap-2 small';
    var badgeClass = r.status === 'ok' ? 'bg-success'
                   : r.status === 'fail' ? 'bg-danger'
                   : r.status === 'warn' ? 'bg-warning text-dark'
                   : 'bg-secondary';
    li.innerHTML = '<span class="badge ' + badgeClass + '" style="min-width:44px">' +
                   r.status.toUpperCase() + '</span><code>' +
                   r.msg.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</code>';
    ul.appendChild(li);
  });

  // ── AJAX test ───────────────────────────────────────────────
  document.getElementById('btnAjaxTest').addEventListener('click', function () {
    var btn = this;
    btn.disabled = true;
    btn.textContent = 'Sending…';
    var pre = document.getElementById('ajaxResult');
    pre.style.display = 'none';

    var bodyData = {
      action:      'log_location',
      lat:         '0',
      lng:         '0',
      accuracy:    '0',
      task_id:     '',
      notes:       'DEBUG TEST',
      no_gps:      '1',
      client_time: (function () {
        var d = new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Kolkata' }));
        var pad = function (n) { return String(n).padStart(2, '0'); };
        return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) + ' ' +
               pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
      })(),
      csrf_token:  csrfToken,
    };

    var params = new URLSearchParams(bodyData);
    fetch('location_handler.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    params,
    })
    .then(function (r) {
      var ct = r.headers.get('content-type') || '';
      return r.text().then(function (t) { return { status: r.status, ct: ct, body: t }; });
    })
    .then(function (res) {
      pre.style.display = 'block';
      pre.textContent = 'HTTP ' + res.status + '\nContent-Type: ' + res.ct + '\n\n' + res.body;
      btn.disabled = false;
      btn.textContent = 'Run AJAX Test';
    })
    .catch(function (err) {
      pre.style.display = 'block';
      pre.textContent = 'Network error: ' + err;
      btn.disabled = false;
      btn.textContent = 'Run AJAX Test';
    });
  });

  // ── GPS test ────────────────────────────────────────────────
  document.getElementById('btnGpsTest').addEventListener('click', function () {
    var out = document.getElementById('gpsResult');
    out.textContent = 'Requesting GPS…';
    if (!navigator.geolocation) {
      out.textContent = 'FAIL: navigator.geolocation not available';
      return;
    }
    navigator.geolocation.getCurrentPosition(
      function (pos) {
        out.textContent = 'SUCCESS: lat=' + pos.coords.latitude.toFixed(6) +
                          ', lng=' + pos.coords.longitude.toFixed(6) +
                          ', accuracy=±' + Math.round(pos.coords.accuracy) + 'm';
      },
      function (err) {
        var codes = { 1: 'PERMISSION_DENIED', 2: 'POSITION_UNAVAILABLE', 3: 'TIMEOUT' };
        out.textContent = 'ERROR ' + err.code + ' (' + (codes[err.code] || '?') + '): ' + err.message;
      },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
    );
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
