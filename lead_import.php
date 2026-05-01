<?php
// ============================================================
//  HRMS · Lead CSV Import (admin only)
// ============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
requireRole('admin');

$user = getCurrentUser();
$db   = getDB();
$uid  = (int)$user['id'];

$pageTitle = 'Import Leads';

$VALID_SOURCES  = ['walk_in','phone','referral','website','social','cold_call','exhibition','other'];
$VALID_PRIORITY = ['low','medium','high','hot'];

$resultMsg = '';
$report    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $resultMsg = '<div class="alert alert-danger">Invalid session token. Please reload.</div>';
    } elseif (empty($_FILES['csv']['tmp_name']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $resultMsg = '<div class="alert alert-danger">Please choose a CSV file.</div>';
    } else {
        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$fh) {
            $resultMsg = '<div class="alert alert-danger">Could not read file.</div>';
        } else {
            // Skip UTF-8 BOM
            $bom = fread($fh, 3);
            if ($bom !== "\xEF\xBB\xBF") { rewind($fh); }

            $headers = fgetcsv($fh);
            if (!$headers) {
                $resultMsg = '<div class="alert alert-danger">File is empty or not a valid CSV.</div>';
            } else {
                $headers = array_map(function ($h) {
                    return strtolower(trim((string)$h));
                }, $headers);

                $col = array_flip($headers);
                $required = ['name','phone'];
                $missing  = array_diff($required, array_keys($col));
                if ($missing) {
                    $resultMsg = '<div class="alert alert-danger">Missing required columns: <code>'
                               . h(implode(', ', $missing)) . '</code></div>';
                } else {
                    $inserted = 0; $skipped = 0; $dupes = 0; $errors = [];
                    $ins = $db->prepare(
                        "INSERT INTO leads
                           (name, phone, email, company, designation, address, pincode,
                            source, interest, priority, notes, tags, assigned_to, creator_id, status)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'new')"
                    );
                    $dupe = $db->prepare(
                        'SELECT id FROM leads WHERE is_deleted = 0 AND phone = ? LIMIT 1'
                    );
                    $rowNum = 1;
                    while (($row = fgetcsv($fh)) !== false) {
                        $rowNum++;
                        $get = function ($key) use ($row, $col) {
                            return isset($col[$key]) && isset($row[$col[$key]])
                                ? trim((string)$row[$col[$key]]) : '';
                        };
                        $name  = $get('name');
                        $phone = $get('phone');
                        if ($name === '' || $phone === '') { $skipped++; continue; }
                        if (mb_strlen($name) > 150 || mb_strlen($phone) > 30) {
                            $errors[] = "Row $rowNum: name or phone too long";
                            $skipped++; continue;
                        }

                        // Dedupe on phone
                        $dupe->execute([$phone]);
                        if ($dupe->fetch()) { $dupes++; continue; }

                        $email = $get('email');
                        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $email = ''; }

                        $source = strtolower(str_replace([' ','-'], '_', $get('source')));
                        if (!in_array($source, $VALID_SOURCES, true)) { $source = 'other'; }

                        $priority = strtolower($get('priority'));
                        if (!in_array($priority, $VALID_PRIORITY, true)) { $priority = 'medium'; }

                        try {
                            $ins->execute([
                                $name, $phone,
                                $email ?: null,
                                $get('company')     ?: null,
                                $get('designation') ?: null,
                                $get('address')     ?: null,
                                $get('pincode')     ?: null,
                                $source,
                                $get('interest')    ?: null,
                                $priority,
                                $get('notes')       ?: null,
                                $get('tags')        ?: null,
                                null,               // assigned_to — blank, admin reassigns later
                                $uid,
                            ]);
                            $inserted++;
                        } catch (PDOException $e) {
                            $errors[] = "Row $rowNum: DB error";
                            $skipped++;
                        }
                    }
                    fclose($fh);
                    $report = compact('inserted','skipped','dupes','errors');
                }
            }
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h5 class="fw-bold mb-0">
    <i class="bi bi-upload me-2 text-primary"></i>Import Leads from CSV
  </h5>
  <a href="leads.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i>Back to Leads
  </a>
</div>

<?= $resultMsg ?>

<?php if ($report): ?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <h6 class="fw-bold">Import Complete</h6>
    <ul class="small mb-2">
      <li><strong><?= (int)$report['inserted'] ?></strong> leads imported</li>
      <li><strong><?= (int)$report['dupes'] ?></strong> duplicates skipped (same phone number)</li>
      <li><strong><?= (int)$report['skipped'] ?></strong> rows skipped (missing / invalid)</li>
    </ul>
    <?php if (!empty($report['errors'])): ?>
    <details class="mt-2">
      <summary class="small text-muted">Show errors (<?= count($report['errors']) ?>)</summary>
      <div class="mt-2 bg-light p-2 rounded small" style="max-height:200px; overflow:auto">
        <?php foreach ($report['errors'] as $e): ?>
        <div>- <?= h($e) ?></div>
        <?php endforeach; ?>
      </div>
    </details>
    <?php endif; ?>
    <a href="leads.php" class="btn btn-primary btn-sm mt-2">View Leads</a>
  </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
  <div class="card-body">
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= h(generateCSRF()) ?>">
      <div class="mb-3">
        <label class="form-label fw-semibold">CSV File</label>
        <input type="file" name="csv" class="form-control" accept=".csv,text/csv" required>
      </div>
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-cloud-upload me-1"></i>Upload &amp; Import
      </button>
    </form>
  </div>
</div>

<div class="alert alert-info mt-3 small">
  <strong>CSV format:</strong> First row must be headers. Supported columns (case-insensitive):
  <code>name</code> *, <code>phone</code> *, <code>email</code>, <code>company</code>,
  <code>designation</code>, <code>address</code>, <code>pincode</code>,
  <code>source</code>, <code>interest</code>, <code>priority</code>,
  <code>notes</code>, <code>tags</code>.
  <br>Rows with duplicate phone numbers are skipped. All imported leads start as status
  <strong>New</strong> and unassigned — you can bulk-assign them from the Leads page.
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
