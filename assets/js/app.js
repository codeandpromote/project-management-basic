/**
 * HRMS · Frontend Application
 * Handles: GPS check-in/out, task actions, day-end form, toast UI
 */
'use strict';

// ── Globals initialised by dashboard.php ────────────────────
// window.HRMS = { csrfToken, hasCheckedIn, hasCheckedOut, hasDayEndFile,
//                 hasDayEndNotes, allDailyDone, pendingDailyCount, role, ipRestricted }

// ── Bootstrap instances ────────────────────────────────────
let mainToastEl, mainToast, dayEndModalEl, dayEndModal, completeTaskModalEl, completeTaskModal;

document.addEventListener('DOMContentLoaded', function () {

  // Bootstrap toast — wrapped so a CDN failure does not kill all event listeners
  mainToastEl = document.getElementById('mainToast');
  if (mainToastEl) {
    try { mainToast = bootstrap.Toast.getOrCreateInstance(mainToastEl, { delay: 4000 }); }
    catch (e) { mainToast = null; }
  }

  // Bootstrap modals
  dayEndModalEl = document.getElementById('dayEndModal');
  if (dayEndModalEl) {
    try { dayEndModal = bootstrap.Modal.getOrCreateInstance(dayEndModalEl); }
    catch (e) { dayEndModal = null; }
  }
  completeTaskModalEl = document.getElementById('completeTaskModal');
  if (completeTaskModalEl) {
    try { completeTaskModal = bootstrap.Modal.getOrCreateInstance(completeTaskModalEl); }
    catch (e) { completeTaskModal = null; }
  }

  // ── Attendance: Check-In ─────────────────────────────────
  const btnCheckIn = document.getElementById('btnCheckIn');
  if (btnCheckIn) {
    btnCheckIn.addEventListener('click', handleCheckIn);
  }

  // ── Attendance: Check-Out ────────────────────────────────
  const btnCheckOut = document.getElementById('btnCheckOut');
  if (btnCheckOut) {
    btnCheckOut.addEventListener('click', handleCheckOut);
  }

  // ── Day-End Report Form ──────────────────────────────────
  const dayEndForm = document.getElementById('dayEndForm');
  if (dayEndForm) {
    dayEndForm.addEventListener('submit', handleDayEnd);
  }

  // ── Task: Start (in-progress) ────────────────────────────
  document.querySelectorAll('.btn-start-task').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.dataset.taskId;
      postAction('task_handler.php', { action: 'start', task_id: id })
        .then(data => {
          if (data.success) {
            showToast(data.message, 'info');
            updateTaskBadge(id, 'in_progress');
            btn.remove();
          } else {
            showToast(data.message, 'danger');
          }
        });
    });
  });

  // ── Task: Complete ───────────────────────────────────────
  document.querySelectorAll('.btn-complete-task').forEach(btn => {
    btn.addEventListener('click', () => {
      const id    = btn.dataset.taskId;
      const title = btn.dataset.taskTitle;
      if (completeTaskModalEl) {
        document.getElementById('completeTaskId').value    = id;
        document.getElementById('completeTaskTitle').textContent = `Task: ${title}`;
        // Reset form
        document.getElementById('completeTaskForm').reset();
        completeTaskModal.show();
      }
    });
  });

  const completeTaskForm = document.getElementById('completeTaskForm');
  if (completeTaskForm) {
    completeTaskForm.addEventListener('submit', handleTaskComplete);
  }

  // ── Field Worker: Log Location (inline panel, no modal) ─────
  var btnLogLoc = document.getElementById('btnLogLocation');
  if (btnLogLoc) {
    btnLogLoc.addEventListener('click', handleLocationLog);
  }
  var btnConfirmLoc = document.getElementById('btnConfirmLocation');
  if (btnConfirmLoc) {
    btnConfirmLoc.addEventListener('click', confirmLocationLog);
  }
  var btnCancelLoc = document.getElementById('btnCancelLocation');
  if (btnCancelLoc) {
    btnCancelLoc.addEventListener('click', function () {
      var panel = document.getElementById('locPanel');
      if (panel) { panel.style.display = 'none'; }
      var logBtn = document.getElementById('btnLogLocation');
      resetButton(logBtn, '<i class="bi bi-pin-map-fill me-2"></i>Log My Current Location');
    });
  }

});

// ============================================================
//  GPS & Check-In
// ============================================================
function handleCheckIn() {
  const btn = document.getElementById('btnCheckIn');
  setButtonLoading(btn, 'Getting location…');
  setGpsStatus('loading', '<i class="bi bi-geo-alt-fill me-1"></i>Requesting GPS location…');

  if (!navigator.geolocation) {
    setGpsStatus('error', 'Geolocation is not supported by your browser.');
    resetButton(btn, '<i class="bi bi-box-arrow-in-right me-2"></i>Check In');
    return;
  }

  navigator.geolocation.getCurrentPosition(
    (pos) => {
      const lat = pos.coords.latitude;
      const lng = pos.coords.longitude;
      const acc = Math.round(pos.coords.accuracy);
      setGpsStatus('ok', `<i class="bi bi-geo-alt-fill me-1"></i>Location acquired (±${acc}m)`);
      performCheckIn(lat, lng, btn);
    },
    (err) => {
      let msg = 'Unable to get location.';
      if (err.code === 1) msg = 'Location permission denied. Please enable location access in your browser.';
      if (err.code === 3) msg = 'Location request timed out.';
      setGpsStatus('denied', `<i class="bi bi-exclamation-triangle-fill me-1"></i>${msg}`);

      // Field workers / admins can proceed without GPS
      var role = (window.HRMS && window.HRMS.role) ? window.HRMS.role : '';
      if (role === 'field_worker' || role === 'admin') {
        if (confirm('Could not get GPS location. Proceed with check-in anyway?')) {
          performCheckIn(0, 0, btn);
        } else {
          resetButton(btn, '<i class="bi bi-box-arrow-in-right me-2"></i>Check In');
        }
      } else {
        showToast(msg, 'warning');
        resetButton(btn, '<i class="bi bi-box-arrow-in-right me-2"></i>Check In');
      }
    },
    { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
  );
}

function performCheckIn(lat, lng, btn) {
  postAction('attendance_handler.php', {
    action:      'check_in',
    lat,
    lng,
    client_time: deviceNow(),   // device local time as YYYY-MM-DD HH:MM:SS in IST
  }).then(data => {
    if (data.success) {
      showToast(data.message, 'success');
      // Update display
      const display = document.getElementById('checkInDisplay');
      if (display) display.textContent = data.check_in_time;
      // Reload to show correct UI state
      setTimeout(() => location.reload(), 1200);
    } else {
      setGpsStatus('error', `<i class="bi bi-x-circle-fill me-1"></i>${data.message}`);
      showToast(data.message, 'danger');
      resetButton(btn, '<i class="bi bi-box-arrow-in-right me-2"></i>Check In');
    }
  }).catch(() => {
    showToast('Network error. Please try again.', 'danger');
    resetButton(btn, '<i class="bi bi-box-arrow-in-right me-2"></i>Check In');
  });
}

// ============================================================
//  Check-Out
// ============================================================
function handleCheckOut() {
  const btn = document.getElementById('btnCheckOut');
  if (btn.disabled) return;
  if (!confirm('Are you sure you want to check out?')) return;

  setButtonLoading(btn, 'Checking out…');

  postAction('attendance_handler.php', { action: 'check_out', client_time: deviceNow() })
    .then(data => {
      if (data.success) {
        showToast(data.message, 'success');
        const display = document.getElementById('checkOutDisplay');
        if (display) display.textContent = data.check_out_time;
        setTimeout(() => location.reload(), 1200);
      } else {
        showToast(data.message, 'danger');
        resetButton(btn, '<i class="bi bi-box-arrow-right me-2"></i>Check Out');
      }
    }).catch(() => {
      showToast('Network error. Please try again.', 'danger');
      resetButton(btn, '<i class="bi bi-box-arrow-right me-2"></i>Check Out');
    });
}

// ============================================================
//  Day-End Report
// ============================================================
function handleDayEnd(e) {
  e.preventDefault();
  const form       = document.getElementById('dayEndForm');
  const submitBtn  = document.getElementById('dayEndSubmitBtn');
  const spinner    = document.getElementById('dayEndSpinner');
  const icon       = document.getElementById('dayEndIcon');

  submitBtn.disabled = true;
  spinner.classList.remove('d-none');
  icon.classList.add('d-none');

  const formData = new FormData(form);
  formData.append('action', 'day_end');
  formData.append('csrf_token', (window.HRMS && window.HRMS.csrfToken) ? window.HRMS.csrfToken : '');

  fetch('attendance_handler.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      submitBtn.disabled = false;
      spinner.classList.add('d-none');
      icon.classList.remove('d-none');

      if (data.success) {
        showToast(data.message, 'success');
        if (dayEndModal) { dayEndModal.hide(); }
        // Enable checkout button if eligible
        const btnOut = document.getElementById('btnCheckOut');
        if (btnOut && data.can_checkout) {
          btnOut.disabled = false;
          btnOut.title    = 'Check out now';
        }
        // Reload to refresh requirement indicators
        setTimeout(() => location.reload(), 1000);
      } else {
        showToast(data.message, 'danger');
      }
    }).catch(() => {
      submitBtn.disabled = false;
      spinner.classList.add('d-none');
      icon.classList.remove('d-none');
      showToast('Network error. Please try again.', 'danger');
    });
}

// ============================================================
//  Task Completion
// ============================================================
function handleTaskComplete(e) {
  e.preventDefault();
  const form     = document.getElementById('completeTaskForm');
  const taskId   = document.getElementById('completeTaskId').value;
  const submitBtn = form.querySelector('[type="submit"]');
  const origLabel = submitBtn.innerHTML;

  submitBtn.disabled = true;

  const fileInput = form.querySelector('input[name="proof_file"]');
  const rawFile   = fileInput && fileInput.files && fileInput.files[0];
  const needsCompress = rawFile && rawFile.type && rawFile.type.indexOf('image/') === 0
                        && rawFile.size >= 800 * 1024;

  if (needsCompress) {
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Compressing image…';
  }

  compressImageIfNeeded(rawFile).then(function (finalFile) {
    const formData = new FormData(form);
    formData.append('action', 'complete');
    formData.append('csrf_token', (window.HRMS && window.HRMS.csrfToken) ? window.HRMS.csrfToken : '');

    if (finalFile && rawFile && finalFile !== rawFile) {
      formData.set('proof_file', finalFile, finalFile.name || 'photo.jpg');
    }

    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Uploading…';

    return fetch('task_handler.php', { method: 'POST', body: formData });
  })
    .then(r => r.json())
    .then(data => {
      submitBtn.innerHTML = origLabel;
      submitBtn.disabled = false;

      if (data.success) {
        showToast(data.message, 'success');
        if (completeTaskModal) { completeTaskModal.hide(); }
        form.reset();

        // Update badge in the task list
        updateTaskBadge(taskId, 'completed');

        // Remove action buttons for that task
        const item = document.querySelector(`.task-item[data-task-id="${taskId}"]`);
        if (item) {
          item.querySelectorAll('.btn-complete-task, .btn-start-task').forEach(b => b.remove());
        }

        // Enable checkout button if eligible
        const btnOut = document.getElementById('btnCheckOut');
        if (btnOut && data.can_checkout) {
          btnOut.disabled = false;
          btnOut.title    = 'Check out now';
        }
      } else {
        showToast(data.message, 'danger');
      }
    }).catch(() => {
      submitBtn.innerHTML = origLabel;
      submitBtn.disabled = false;
      showToast('Network error. Please try again.', 'danger');
    });
}

// ============================================================
//  Image Compression (auto-shrink large photos before upload)
// ============================================================
// Non-image files pass through untouched.
// Small images (< 800 KB) pass through untouched.
// Large images are resized to max 1600px on the long edge
// and re-encoded as JPEG at ~75% quality.
// Falls back to the original file on any error.
function compressImageIfNeeded(file) {
  return new Promise(function (resolve) {
    if (!file) { resolve(file); return; }
    if (!file.type || file.type.indexOf('image/') !== 0) { resolve(file); return; }
    if (file.size < 800 * 1024) { resolve(file); return; }

    var MAX_DIM = 1600;
    var QUALITY = 0.75;

    var reader = new FileReader();
    reader.onload = function (ev) {
      var img = new Image();
      img.onload = function () {
        var w = img.width, h = img.height;
        if (w > MAX_DIM || h > MAX_DIM) {
          if (w >= h) { h = Math.round(h * MAX_DIM / w); w = MAX_DIM; }
          else        { w = Math.round(w * MAX_DIM / h); h = MAX_DIM; }
        }
        var canvas = document.createElement('canvas');
        canvas.width = w; canvas.height = h;
        var ctx = canvas.getContext('2d');
        if (!ctx) { resolve(file); return; }
        ctx.drawImage(img, 0, 0, w, h);

        var origName = file.name || 'photo.jpg';
        var base     = origName.replace(/\.[^.]+$/, '');
        var newName  = base + '.jpg';

        if (!canvas.toBlob) { resolve(file); return; }
        canvas.toBlob(function (blob) {
          if (!blob) { resolve(file); return; }
          if (blob.size >= file.size) { resolve(file); return; }
          try {
            resolve(new File([blob], newName, { type: 'image/jpeg' }));
          } catch (e) {
            // Old browsers without File constructor
            blob.name = newName;
            resolve(blob);
          }
        }, 'image/jpeg', QUALITY);
      };
      img.onerror = function () { resolve(file); };
      img.src = ev.target.result;
    };
    reader.onerror = function () { resolve(file); };
    reader.readAsDataURL(file);
  });
}

// ============================================================
//  Utilities
// ============================================================
// Returns "YYYY-MM-DD HH:MM:SS" in IST (UTC+5:30).
// Sent to PHP so the stored time always matches Indian Standard Time,
// regardless of the server's OS timezone or the user's device locale.
function deviceNow() {
  const pad = n => String(n).padStart(2, '0');
  const ist = new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Kolkata' }));
  return `${ist.getFullYear()}-${pad(ist.getMonth()+1)}-${pad(ist.getDate())} `
       + `${pad(ist.getHours())}:${pad(ist.getMinutes())}:${pad(ist.getSeconds())}`;
}

function postAction(url, data) {
  var csrfToken = (window.HRMS && window.HRMS.csrfToken) ? window.HRMS.csrfToken : '';
  var bodyData = Object.assign({}, data, { csrf_token: csrfToken });
  const body = new URLSearchParams(bodyData);
  return fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body,
  }).then(r => r.json());
}

function showToast(message, type = 'success') {
  if (!mainToastEl) return;
  const typeMap = {
    success: 'text-bg-success',
    danger:  'text-bg-danger',
    warning: 'text-bg-warning',
    info:    'text-bg-info',
  };
  mainToastEl.className = `toast align-items-center border-0 ${typeMap[type] !== undefined ? typeMap[type] : 'text-bg-secondary'}`;
  document.getElementById('mainToastBody').textContent = message;
  if (mainToast) { mainToast.show(); }
}

function setGpsStatus(cls, html) {
  const el = document.getElementById('gpsStatus');
  if (!el) return;
  el.className = `alert py-2 small mb-3 d-flex align-items-center gap-2 gps-${cls}`;
  document.getElementById('gpsStatusText').innerHTML = html;
}

function setButtonLoading(btn, text) {
  if (!btn) return;
  btn.disabled   = true;
  btn.innerHTML  = `<span class="spinner-border spinner-border-sm me-2"></span>${text}`;
}

function resetButton(btn, html) {
  if (!btn) return;
  btn.disabled  = false;
  btn.innerHTML = html;
}

function updateTaskBadge(taskId, status) {
  const item = document.querySelector(`.task-item[data-task-id="${taskId}"]`);
  if (!item) return;
  const badgeEl = item.querySelector('.badge');
  if (!badgeEl) return;

  const map = {
    pending:     ['bg-warning', 'text-dark', 'Pending'],
    in_progress: ['bg-info',    'text-dark', 'In Progress'],
    completed:   ['bg-success', '',          'Completed'],
    overdue:     ['bg-danger',  '',          'Overdue'],
  };
  var statusInfo = map[status] !== undefined ? map[status] : ['bg-secondary', '', status];
  var bg = statusInfo[0], txt = statusInfo[1], label = statusInfo[2];
  badgeEl.className = `badge ${bg} ${txt}`;
  badgeEl.textContent = label;

  if (status === 'completed') {
    item.classList.remove('task-overdue');
  }
}

// ============================================================
//  Field Worker Location Tracker  (inline panel — no Bootstrap modal)
//  Uses the same GPS approach as handleCheckIn so it works on all mobile browsers.
// ============================================================
var _pendingLat = 0, _pendingLng = 0, _pendingAcc = 0, _noGps = false;

function handleLocationLog() {
  var panel      = document.getElementById('locPanel');
  var logBtn     = document.getElementById('btnLogLocation');
  var confirmBtn = document.getElementById('btnConfirmLocation');

  if (!panel) { return; }

  // If panel already open, just close it
  if (panel.style.display !== 'none') {
    panel.style.display = 'none';
    resetButton(logBtn, '<i class="bi bi-pin-map-fill me-2"></i>Log My Current Location');
    return;
  }

  // Reset state
  _pendingLat = 0; _pendingLng = 0; _pendingAcc = 0; _noGps = false;
  var notesEl = document.getElementById('locNotes');
  var taskEl  = document.getElementById('locTaskSelect');
  if (notesEl) { notesEl.value = ''; }
  if (taskEl)  { taskEl.value  = ''; }
  if (confirmBtn) {
    confirmBtn.disabled  = true;
    confirmBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Save Log';
  }

  // Show panel and set button to loading (same as check-in)
  panel.style.display = '';
  setButtonLoading(logBtn, 'Getting location…');
  setLocPanelState('loading', '<span class="spinner-border spinner-border-sm me-1"></span> Getting your GPS location…');

  // ── Exact same GPS logic as handleCheckIn ──────────────────
  if (!navigator.geolocation) {
    setLocPanelState('warning', '<i class="bi bi-exclamation-triangle me-1"></i> GPS is not supported by your browser.');
    enableNoGpsMode(confirmBtn);
    resetButton(logBtn, '<i class="bi bi-pin-map-fill me-2"></i>Log My Current Location');
    return;
  }

  // Only block on actual HTTP (not HTTPS). Never use window.isSecureContext — it is
  // undefined on older mobile browsers, making !isSecureContext always true.
  var isInsecureHttp = location.protocol === 'http:'
                     && location.hostname !== 'localhost'
                     && location.hostname !== '127.0.0.1';
  if (isInsecureHttp) {
    setLocPanelState('warning', '<i class="bi bi-shield-exclamation me-1"></i> GPS is blocked over HTTP. You can still log your activity without GPS.');
    enableNoGpsMode(confirmBtn);
    resetButton(logBtn, '<i class="bi bi-pin-map-fill me-2"></i>Log My Current Location');
    return;
  }

  navigator.geolocation.getCurrentPosition(
    function (pos) {
      _pendingLat = pos.coords.latitude;
      _pendingLng = pos.coords.longitude;
      _pendingAcc = Math.round(pos.coords.accuracy);
      _noGps = false;
      setLocPanelState('success',
        '<i class="bi bi-geo-alt-fill me-1"></i> '
        + '<strong>' + _pendingLat.toFixed(6) + ', ' + _pendingLng.toFixed(6) + '</strong>'
        + ' <span class="text-muted">(±' + _pendingAcc + 'm)</span>');
      if (confirmBtn) {
        confirmBtn.disabled  = false;
        confirmBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Save Log';
      }
      resetButton(logBtn, '<i class="bi bi-pin-map-fill me-2"></i>Log My Current Location');
    },
    function (err) {
      var msg = 'Could not get GPS location.';
      if (err.code === 1) { msg = 'Location permission denied. Allow location in your browser/phone settings.'; }
      if (err.code === 2) { msg = 'GPS signal unavailable. Move to an open area and try again.'; }
      if (err.code === 3) { msg = 'Location request timed out. Try again.'; }
      setLocPanelState('danger', '<i class="bi bi-exclamation-triangle-fill me-1"></i> ' + msg);
      enableNoGpsMode(confirmBtn);
      resetButton(logBtn, '<i class="bi bi-pin-map-fill me-2"></i>Log My Current Location');
    },
    { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
  );
}

// When GPS fails, allow logging task/notes without coordinates
function enableNoGpsMode(confirmBtn) {
  _noGps = true;
  _pendingLat = 0; _pendingLng = 0; _pendingAcc = 0;
  if (confirmBtn) {
    confirmBtn.disabled  = false;
    confirmBtn.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Log Activity (No GPS)';
  }
}

function setLocPanelState(type, html) {
  var div = document.getElementById('locPanelStatus');
  var txt = document.getElementById('locPanelStatusText');
  var typeMap = { loading: 'alert-info', success: 'alert-success', danger: 'alert-danger', warning: 'alert-warning' };
  if (div) { div.className = 'alert ' + (typeMap[type] || 'alert-secondary') + ' py-2 small mb-3 d-flex align-items-center gap-2'; }
  if (txt) { txt.innerHTML = html; }
}

function confirmLocationLog() {
  if (!_noGps && _pendingLat === 0 && _pendingLng === 0) {
    showToast('No GPS coordinates yet — please wait or use "Log Activity (No GPS)".', 'warning');
    return;
  }

  var confirmBtn = document.getElementById('btnConfirmLocation');
  setButtonLoading(confirmBtn, 'Saving…');

  var taskEl  = document.getElementById('locTaskSelect');
  var notesEl = document.getElementById('locNotes');
  var taskId  = taskEl  ? taskEl.value  : '';
  var notes   = notesEl ? notesEl.value : '';

  postAction('location_handler.php', {
    action:      'log_location',
    lat:         _pendingLat,
    lng:         _pendingLng,
    accuracy:    _pendingAcc,
    task_id:     taskId,
    notes:       notes,
    no_gps:      _noGps ? '1' : '0',
    client_time: deviceNow(),
  }).then(function (data) {
    // Hide the inline panel
    var panel = document.getElementById('locPanel');
    if (panel) { panel.style.display = 'none'; }

    var label = _noGps
      ? '<i class="bi bi-pencil-square me-1"></i>Log Activity (No GPS)'
      : '<i class="bi bi-check-circle me-1"></i>Save Log';
    resetButton(confirmBtn, label);

    if (data.success) {
      showToast(data.message, 'success');
      var badge = document.getElementById('locCountBadge');
      if (badge) { badge.textContent = data.today_count; }
      prependLocationPill(data);
    } else {
      showToast(data.message, 'danger');
    }
  }).catch(function () {
    resetButton(confirmBtn, '<i class="bi bi-check-circle me-1"></i>Save Log');
    showToast('Network error. Please try again.', 'danger');
  });
}

function prependLocationPill(data) {
  const list  = document.getElementById('locList');
  const empty = document.getElementById('locEmptyMsg');
  if (empty) empty.remove();
  if (!list)  return;

  const hasGps  = parseFloat(data.lat) !== 0 || parseFloat(data.lng) !== 0;
  const mapsUrl = `https://maps.google.com/?q=${data.lat},${data.lng}`;

  const pill = document.createElement('div');
  pill.className = 'loc-pill d-flex align-items-start gap-2 mb-2';
  pill.innerHTML = `
    <div class="loc-pill-dot flex-shrink-0" style="${hasGps ? '' : 'background:#94A3B8'}"></div>
    <div class="flex-grow-1">
      <div class="small fw-semibold">${data.time}
        ${hasGps && data.accuracy
          ? `<span class="text-muted fw-normal">(±${data.accuracy}m)</span>`
          : '<span class="badge bg-secondary ms-1" style="font-size:.62rem">No GPS</span>'}
      </div>
      <div class="text-muted" style="font-size:.72rem">
        ${hasGps
          ? `${parseFloat(data.lat).toFixed(6)}, ${parseFloat(data.lng).toFixed(6)}`
          : 'Activity logged without GPS'}
      </div>
    </div>
    ${hasGps
      ? `<a href="${mapsUrl}" target="_blank" class="btn btn-xs btn-outline-success flex-shrink-0">
           <i class="bi bi-map"></i>
         </a>`
      : ''}`;
  list.prepend(pill);
}
