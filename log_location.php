<?php
// ============================================================
//  HRMS · Log My Location  (standalone page — no Bootstrap modals)
//  Works on any mobile browser. Auto-starts GPS on load.
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
requireLogin();

$user = getCurrentUser();
if (!$user) { logout(); }
if (!in_array($user['role'], ['field_worker', 'admin'], true)) {
    header('Location: dashboard.php');
    exit;
}

$db    = getDB();
$uid   = (int)$user['id'];
$today = date('Y-m-d');

// Fetch active tasks for the selector
$myActiveTasks = [];
try {
    $st = $db->prepare(
        "SELECT id, title FROM tasks
          WHERE user_id = ? AND status IN ('pending','in_progress')
          ORDER BY deadline ASC LIMIT 20"
    );
    $st->execute([$uid]);
    $myActiveTasks = $st->fetchAll();
} catch (PDOException $e) {
    // tasks table query failed — proceed without tasks
}

$pageTitle = 'Log My Location';
$csrf      = generateCSRF();
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* ── Page-level styles ──────────────────────────────────────── */
.loc-page-card {
  max-width: 520px;
  margin: 0 auto;
}
#gpsStatus {
  transition: background .3s;
}
#saveBtn:disabled {
  opacity: .55;
  cursor: not-allowed;
}
.spinner-border-sm { width:1rem; height:1rem; border-width:.15em; }
</style>

<div class="loc-page-card">

  <!-- Back link -->
  <a href="dashboard.php" class="btn btn-sm btn-outline-secondary mb-3">
    <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
  </a>

  <div class="card shadow-sm">
    <div class="card-header bg-success text-white fw-bold">
      <i class="bi bi-pin-map-fill me-2"></i>Log My Current Location
    </div>
    <div class="card-body">

      <!-- GPS status — same pattern as check-in -->
      <div id="gpsStatus" class="alert alert-info d-flex align-items-center gap-2 py-2 mb-3">
        <span id="gpsSpinner" class="spinner-border spinner-border-sm flex-shrink-0"></span>
        <span id="gpsText">Getting your GPS location&hellip;</span>
      </div>

      <!-- Task selector -->
      <div class="mb-3">
        <label for="taskSelect" class="form-label fw-semibold small mb-1">
          <i class="bi bi-list-task me-1"></i>Link to Task <span class="text-muted fw-normal">(Optional)</span>
        </label>
        <select id="taskSelect" class="form-select form-select-sm">
          <option value="">— No specific task —</option>
          <?php foreach ($myActiveTasks as $t): ?>
          <option value="<?= (int)$t['id'] ?>"><?= h($t['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Notes -->
      <div class="mb-3">
        <label for="notesInput" class="form-label fw-semibold small mb-1">
          <i class="bi bi-pencil me-1"></i>Notes <span class="text-muted fw-normal">(Optional)</span>
        </label>
        <input type="text" id="notesInput" class="form-control form-control-sm"
               placeholder="e.g. Arrived at site, Leaving warehouse…" maxlength="255">
      </div>

      <!-- Save / No-GPS buttons -->
      <div class="d-grid gap-2">
        <button id="saveBtn" class="btn btn-success fw-semibold" disabled>
          <i class="bi bi-check-circle me-1"></i>Save Location Log
        </button>
        <button id="noGpsBtn" class="btn btn-outline-secondary btn-sm" style="display:none">
          <i class="bi bi-wifi-off me-1"></i>Log Activity (No GPS)
        </button>
      </div>

      <!-- Result message (shown after save) -->
      <div id="resultMsg" class="alert mt-3" style="display:none"></div>

    </div><!-- /card-body -->
  </div><!-- /card -->

</div><!-- /loc-page-card -->

<script>
(function() {
  // ── State ──────────────────────────────────────────────────
  var lat = 0, lng = 0, acc = 0, noGps = false;
  var saving = false;

  // ── DOM refs ───────────────────────────────────────────────
  var gpsStatus  = document.getElementById('gpsStatus');
  var gpsSpinner = document.getElementById('gpsSpinner');
  var gpsText    = document.getElementById('gpsText');
  var saveBtn    = document.getElementById('saveBtn');
  var noGpsBtn   = document.getElementById('noGpsBtn');
  var taskSelect = document.getElementById('taskSelect');
  var notesInput = document.getElementById('notesInput');
  var resultMsg  = document.getElementById('resultMsg');

  // ── GPS status helpers ─────────────────────────────────────
  function setStatus(type, text, showSpinner) {
    gpsStatus.className = 'alert alert-' + type + ' d-flex align-items-center gap-2 py-2 mb-3';
    gpsText.textContent = text;
    gpsSpinner.style.display = showSpinner ? '' : 'none';
  }

  function enableNoGpsMode() {
    noGps = true;
    saveBtn.disabled = false;
    noGpsBtn.style.display = 'none';
    setStatus('warning', 'GPS unavailable — you can still log your activity without location.', false);
  }

  // ── Auto-start GPS on load (same as check-in) ─────────────
  function startGPS() {
    // Must be HTTPS (except localhost)
    if (location.protocol === 'http:' &&
        location.hostname !== 'localhost' &&
        location.hostname !== '127.0.0.1') {
      setStatus('warning', 'GPS requires HTTPS. You can still log activity without location.', false);
      noGpsBtn.style.display = '';
      return;
    }

    if (!navigator.geolocation) {
      setStatus('warning', 'GPS not available on this device.', false);
      noGpsBtn.style.display = '';
      return;
    }

    setStatus('info', 'Getting your GPS location\u2026', true);

    navigator.geolocation.getCurrentPosition(
      // Success
      function(pos) {
        lat = pos.coords.latitude;
        lng = pos.coords.longitude;
        acc = Math.round(pos.coords.accuracy);
        noGps = false;
        saveBtn.disabled = false;
        noGpsBtn.style.display = 'none';
        setStatus('success',
          'GPS ready \u2714  Accuracy: \u00b1' + acc + ' m', false);
      },
      // Error
      function(err) {
        var msg = 'GPS error';
        if (err.code === 1) { msg = 'Location permission denied.'; }
        else if (err.code === 2) { msg = 'GPS position unavailable.'; }
        else if (err.code === 3) { msg = 'GPS timed out.'; }
        setStatus('warning', msg + ' You can still log without GPS.', false);
        noGpsBtn.style.display = '';
      },
      { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
    );
  }

  // Start immediately when page loads
  startGPS();

  // ── Save handler ───────────────────────────────────────────
  function doSave(isNoGps) {
    if (saving) { return; }
    saving = true;
    saveBtn.disabled = true;
    noGpsBtn.disabled = true;
    saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving\u2026';

    // Build client timestamp in IST-like local format
    var now = new Date();
    var pad = function(n) { return n < 10 ? '0' + n : '' + n; };
    var clientTime = now.getFullYear() + '-' + pad(now.getMonth()+1) + '-' + pad(now.getDate())
      + ' ' + pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());

    var body = 'action=log_location'
      + '&csrf_token=' + encodeURIComponent('<?= addslashes($csrf) ?>')
      + '&lat=' + (isNoGps ? '0' : lat)
      + '&lng=' + (isNoGps ? '0' : lng)
      + '&accuracy=' + (isNoGps ? '0' : acc)
      + '&no_gps=' + (isNoGps ? '1' : '0')
      + '&task_id=' + encodeURIComponent(taskSelect.value)
      + '&notes=' + encodeURIComponent(notesInput.value)
      + '&client_time=' + encodeURIComponent(clientTime);

    fetch('location_handler.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success) {
        resultMsg.className = 'alert alert-success mt-3';
        var msgText = data.message || 'Location logged!';
        if (!isNoGps && data.maps_url) {
          msgText += ' <a href="' + data.maps_url + '" target="_blank" rel="noopener" class="alert-link">View on Maps</a>';
        }
        resultMsg.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>' + msgText;
        resultMsg.style.display = '';
        // Redirect back to dashboard after 1.5 s
        setTimeout(function() {
          window.location.href = 'dashboard.php';
        }, 1500);
      } else {
        resultMsg.className = 'alert alert-danger mt-3';
        resultMsg.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>'
          + (data.message || 'Failed to save. Please try again.');
        resultMsg.style.display = '';
        saveBtn.disabled = false;
        noGpsBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Save Location Log';
        saving = false;
      }
    })
    .catch(function() {
      resultMsg.className = 'alert alert-danger mt-3';
      resultMsg.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>Network error. Please try again.';
      resultMsg.style.display = '';
      saveBtn.disabled = false;
      noGpsBtn.disabled = false;
      saveBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Save Location Log';
      saving = false;
    });
  }

  saveBtn.addEventListener('click', function() { doSave(noGps); });
  noGpsBtn.addEventListener('click', function() { enableNoGpsMode(); });

})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
