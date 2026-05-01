<?php
// ============================================================
//  HRMS · Media Manager (admin only)
//  Unified view of every uploaded file across the system.
//  Lets admin preview, download, delete (single / bulk).
// ============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
requireRole('admin');

$user = getCurrentUser();
$db   = getDB();

// ── Filters ───────────────────────────────────────────────────
$fType = $_GET['type']   ?? '';   // task_file | task_proof | day_end | lead_attachment | location_photo
$fUid  = (int)($_GET['user_id'] ?? 0);
$fFrom = $_GET['from']   ?? '';
$fTo   = $_GET['to']     ?? '';
$dateOk = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d);
if ($fFrom && !$dateOk($fFrom)) { $fFrom = ''; }
if ($fTo   && !$dateOk($fTo))   { $fTo   = ''; }

$rows = [];

// ── Collect from tasks (file_path + proof_file + call_recording) ─
try {
    $sql = "
      SELECT 'task_file' AS type, t.id AS rec_id, t.file_path AS path,
             t.created_at AS ts, u.name AS uploader,
             CONCAT('Task #', t.id, ': ', t.title) AS label, t.id AS task_id
        FROM tasks t
        LEFT JOIN users u ON u.id = t.creator_id
       WHERE t.file_path IS NOT NULL AND t.file_path != ''
      UNION ALL
      SELECT 'task_proof', t.id, t.proof_file,
             t.completed_at, u.name,
             CONCAT('Proof · Task #', t.id, ': ', t.title), t.id
        FROM tasks t
        LEFT JOIN users u ON u.id = t.user_id
       WHERE t.proof_file IS NOT NULL AND t.proof_file != ''
    ";
    foreach ($db->query($sql)->fetchAll() as $r) { $rows[] = $r; }
} catch (PDOException $ignored) {}

// ── Call recordings (separate query — column may not exist on old installs) ──
try {
    $sql = "
      SELECT 'task_call_recording' AS type, t.id AS rec_id, t.call_recording AS path,
             t.completed_at AS ts, u.name AS uploader,
             CONCAT('Recording · Task #', t.id, ': ', t.title) AS label,
             t.id AS task_id
        FROM tasks t
        LEFT JOIN users u ON u.id = t.user_id
       WHERE t.call_recording IS NOT NULL AND t.call_recording != ''
    ";
    foreach ($db->query($sql)->fetchAll() as $r) { $rows[] = $r; }
} catch (PDOException $ignored) { /* column missing */ }

// ── Day-end files ────────────────────────────────────────────
try {
    $sql = "
      SELECT 'day_end' AS type, a.id AS rec_id, a.day_end_file AS path,
             a.check_out_time AS ts, u.name AS uploader,
             CONCAT('Day-end · ', u.name, ' · ', a.work_date) AS label,
             NULL AS task_id
        FROM attendance a
        JOIN users u ON u.id = a.user_id
       WHERE a.day_end_file IS NOT NULL AND a.day_end_file != ''
    ";
    foreach ($db->query($sql)->fetchAll() as $r) { $rows[] = $r; }
} catch (PDOException $ignored) {}

// ── Lead attachments ─────────────────────────────────────────
try {
    $sql = "
      SELECT 'lead_attachment' AS type, a.id AS rec_id, a.file_path AS path,
             a.uploaded_at AS ts, u.name AS uploader,
             CONCAT(IFNULL(a.file_label, 'Lead attachment'), ' · Lead #', a.lead_id, ': ', l.name) AS label,
             NULL AS task_id
        FROM lead_attachments a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN leads  l ON l.id = a.lead_id
    ";
    foreach ($db->query($sql)->fetchAll() as $r) { $rows[] = $r; }
} catch (PDOException $ignored) {}

// ── Location visit photos ────────────────────────────────────
try {
    $sql = "
      SELECT 'location_photo' AS type, ll.id AS rec_id, ll.photo AS path,
             ll.logged_at AS ts, u.name AS uploader,
             CONCAT('Visit photo · ', u.name, ' · ',
                    IFNULL(l.name, CONCAT('Log #', ll.id))) AS label,
             NULL AS task_id
        FROM location_logs ll
        LEFT JOIN users u ON u.id = ll.user_id
        LEFT JOIN leads l ON l.id = ll.lead_id
       WHERE ll.photo IS NOT NULL AND ll.photo != ''
    ";
    foreach ($db->query($sql)->fetchAll() as $r) { $rows[] = $r; }
} catch (PDOException $ignored) {}

// ── Apply filters ────────────────────────────────────────────
$rows = array_filter($rows, function ($r) use ($fType, $fFrom, $fTo) {
    if ($fType !== '' && $r['type'] !== $fType) { return false; }
    if ($r['ts']) {
        $d = substr($r['ts'], 0, 10);
        if ($fFrom && $d < $fFrom) { return false; }
        if ($fTo   && $d > $fTo)   { return false; }
    }
    return true;
});

// Sort by timestamp desc
usort($rows, fn($a, $b) => strcmp($b['ts'] ?? '', $a['ts'] ?? ''));

// Cap at 1000 rows (page will be heavy otherwise)
$totalRows = count($rows);
$rows = array_slice($rows, 0, 1000);

// Helpers
function fileKindIcon(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg','jpeg','png','gif','webp'], true))       { return 'bi-image'; }
    if (in_array($ext, ['mp3','m4a','wav','ogg','webm','amr','aac'], true)) { return 'bi-music-note-beamed text-primary'; }
    if (in_array($ext, ['pdf'], true))                                 { return 'bi-file-earmark-pdf text-danger'; }
    if (in_array($ext, ['doc','docx'], true))                          { return 'bi-file-earmark-word text-primary'; }
    if (in_array($ext, ['xls','xlsx','csv'], true))                    { return 'bi-file-earmark-excel text-success'; }
    if (in_array($ext, ['zip','rar','7z'], true))                      { return 'bi-file-earmark-zip'; }
    return 'bi-file-earmark';
}
function isImage(string $path): bool {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg','jpeg','png','gif','webp'], true);
}
function isAudio(string $path): bool {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, ['mp3','m4a','wav','ogg','webm','amr','aac'], true);
}
function typeLabel(string $type): string {
    return [
        'task_file'           => 'Task Attachment',
        'task_proof'          => 'Task Proof',
        'task_call_recording' => 'Call Recording',
        'day_end'             => 'Day-end File',
        'lead_attachment'     => 'Lead Attachment',
        'location_photo'      => 'Visit Photo',
    ][$type] ?? $type;
}
function typeBadgeClass(string $type): string {
    return [
        'task_file'           => 'bg-info-subtle text-info',
        'task_proof'          => 'bg-success-subtle text-success',
        'task_call_recording' => 'bg-primary-subtle text-primary',
        'day_end'             => 'bg-warning-subtle text-warning',
        'lead_attachment'     => 'bg-primary-subtle text-primary',
        'location_photo'      => 'bg-danger-subtle text-danger',
    ][$type] ?? 'bg-light text-dark';
}

$pageTitle = 'Media Manager';
include __DIR__ . '/includes/header.php';
?>

<style>
.media-thumb {
  width: 48px; height: 48px; object-fit: cover;
  border-radius: 6px; border: 1px solid #dee2e6; background: #f8f9fa;
}
.media-row.selected { background: #e3f2fd; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h5 class="fw-bold mb-0">
    <i class="bi bi-folder2-open me-2 text-primary"></i>Media Manager
  </h5>
  <span class="badge bg-light text-dark border">
    <?= $totalRows ?> file<?= $totalRows !== 1 ? 's' : '' ?>
    <?= $totalRows > 1000 ? ' (showing latest 1000)' : '' ?>
  </span>
</div>

<!-- Filter bar -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-6 col-md-3">
        <label class="form-label small fw-semibold mb-1">Type</label>
        <select name="type" class="form-select form-select-sm">
          <option value="">All types</option>
          <?php foreach (['task_file','task_proof','task_call_recording','day_end','lead_attachment','location_photo'] as $t): ?>
          <option value="<?= $t ?>" <?= $fType === $t ? 'selected' : '' ?>><?= h(typeLabel($t)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6 col-md-3">
        <label class="form-label small fw-semibold mb-1">From</label>
        <input type="date" name="from" class="form-control form-control-sm"
               value="<?= h($fFrom) ?>" max="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-sm-6 col-md-3">
        <label class="form-label small fw-semibold mb-1">To</label>
        <input type="date" name="to" class="form-control form-control-sm"
               value="<?= h($fTo) ?>" max="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-auto d-flex gap-2">
        <button class="btn btn-primary btn-sm" type="submit">
          <i class="bi bi-funnel me-1"></i>Filter
        </button>
        <a class="btn btn-outline-secondary btn-sm" href="admin_media.php">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Bulk action bar -->
<div class="card border-0 shadow-sm mb-3" id="bulkBar" style="display:none">
  <div class="card-body py-2 d-flex align-items-center gap-2 flex-wrap">
    <span class="fw-semibold small"><span id="selCount">0</span> selected</span>
    <button class="btn btn-sm btn-danger" id="btnBulkDelete">
      <i class="bi bi-trash3 me-1"></i>Delete Selected
    </button>
    <button class="btn btn-sm btn-link text-muted ms-auto" id="btnBulkClear">Clear</button>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="ps-3" style="width:32px"><input type="checkbox" id="selAll" class="form-check-input"></th>
          <th style="width:60px">Preview</th>
          <th>File</th>
          <th>Type</th>
          <th>Uploader</th>
          <th>When</th>
          <th class="pe-3" style="width:140px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
        <tr><td colspan="7" class="text-center text-muted py-5">
          <i class="bi bi-inbox fs-1 d-block opacity-25"></i>No media matches these filters.
        </td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
          $isImg = isImage($r['path']);
          $isAud = isAudio($r['path']);
          $downloadUrl = 'download.php?path=' . urlencode($r['path']);
          $fileBase = basename($r['path']);
          $selValue = $r['type'] . '|' . (int)$r['rec_id'];
        ?>
        <tr class="media-row" data-sel="<?= h($selValue) ?>">
          <td class="ps-3"><input type="checkbox" class="form-check-input row-check" value="<?= h($selValue) ?>"></td>
          <td>
            <?php if ($isImg): ?>
            <a href="<?= h($downloadUrl) ?>" target="_blank">
              <img src="<?= h($downloadUrl) ?>" class="media-thumb" alt="preview">
            </a>
            <?php elseif ($isAud): ?>
            <audio controls preload="none" style="height:34px;max-width:220px">
              <source src="<?= h($downloadUrl) ?>">
            </audio>
            <?php else: ?>
            <i class="bi <?= fileKindIcon($r['path']) ?> fs-3"></i>
            <?php endif; ?>
          </td>
          <td class="small">
            <div class="fw-semibold"><?= h($r['label'] ?? $fileBase) ?></div>
            <div class="text-muted" style="font-size:.7rem"><?= h($fileBase) ?></div>
          </td>
          <td>
            <span class="badge <?= typeBadgeClass($r['type']) ?>"><?= h(typeLabel($r['type'])) ?></span>
          </td>
          <td class="small"><?= h($r['uploader'] ?? '—') ?></td>
          <td class="small text-muted">
            <?= $r['ts'] ? h(date('d M Y, h:i A', strtotime($r['ts']))) : '—' ?>
          </td>
          <td class="pe-3">
            <div class="btn-group btn-group-sm">
              <a href="<?= h($downloadUrl) ?>" target="_blank"
                 class="btn btn-outline-primary" title="Open">
                <i class="bi bi-box-arrow-up-right"></i>
              </a>
              <button class="btn btn-outline-danger btn-del-media"
                      data-type="<?= h($r['type']) ?>"
                      data-id="<?= (int)$r['rec_id'] ?>"
                      data-label="<?= h($r['label'] ?? $fileBase) ?>" title="Delete">
                <i class="bi bi-trash"></i>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function () {
  var csrf = document.querySelector('meta[name="csrf-token"]').content;

  function showMsg(msg, cls) {
    var t = document.getElementById('mainToast'),
        b = document.getElementById('mainToastBody');
    if (t && b) {
      b.textContent = msg;
      t.className = 'toast align-items-center border-0 text-bg-' + (cls || 'primary');
      try { bootstrap.Toast.getOrCreateInstance(t).show(); } catch(e){ alert(msg); }
    } else { alert(msg); }
  }

  function post(action, fd) {
    fd.append('action', action);
    fd.append('csrf_token', csrf);
    return fetch('media_handler.php', { method:'POST', body: fd }).then(r => r.json());
  }

  // Individual delete
  document.querySelectorAll('.btn-del-media').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!confirm('Permanently delete this file?\n\n' + btn.dataset.label
                   + '\n\nThis cannot be undone.')) { return; }
      var fd = new FormData();
      fd.append('type', btn.dataset.type);
      fd.append('id',   btn.dataset.id);
      post('delete', fd).then(function (res) {
        showMsg(res.message, res.success ? 'success' : 'danger');
        if (res.success) {
          var row = btn.closest('tr'); if (row) { row.remove(); }
        }
      });
    });
  });

  // Bulk selection
  var bulkBar = document.getElementById('bulkBar');
  var selAll  = document.getElementById('selAll');
  var selCnt  = document.getElementById('selCount');
  var rowChecks = function () { return document.querySelectorAll('.row-check'); };

  function refresh() {
    var checked = document.querySelectorAll('.row-check:checked');
    bulkBar.style.display = checked.length > 0 ? '' : 'none';
    selCnt.textContent = checked.length;
    document.querySelectorAll('.media-row').forEach(function (r) {
      var cb = r.querySelector('.row-check');
      r.classList.toggle('selected', cb && cb.checked);
    });
  }
  if (selAll) {
    selAll.addEventListener('change', function () {
      rowChecks().forEach(function (cb) { cb.checked = selAll.checked; });
      refresh();
    });
  }
  rowChecks().forEach(function (cb) { cb.addEventListener('change', refresh); });

  document.getElementById('btnBulkClear').addEventListener('click', function () {
    rowChecks().forEach(function (cb) { cb.checked = false; });
    if (selAll) { selAll.checked = false; }
    refresh();
  });

  document.getElementById('btnBulkDelete').addEventListener('click', function () {
    var items = Array.prototype.map.call(
      document.querySelectorAll('.row-check:checked'),
      function (cb) { return cb.value; }
    );
    if (!items.length) { return; }
    if (!confirm('Permanently delete ' + items.length + ' file(s)?\n\nThis cannot be undone.')) { return; }
    var fd = new FormData();
    items.forEach(function (v) { fd.append('items[]', v); });
    post('bulk_delete', fd).then(function (res) {
      showMsg(res.message, res.success ? 'success' : 'danger');
      if (res.success) { setTimeout(function () { location.reload(); }, 600); }
    });
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
