<?php
// ============================================================
//  HRMS · Lead Detail — timeline, activity log, attachments
// ============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
requireLogin();

$user = getCurrentUser();
if (!$user) { logout(); }

$db    = getDB();
$uid   = (int)$user['id'];
$role  = $user['role'];
$isAdmin = $role === 'admin';

$leadId = (int)($_GET['id'] ?? 0);
if ($leadId <= 0) { header('Location: leads.php'); exit; }

$stL = $db->prepare(
    'SELECT l.*, u.name AS assignee_name, c.name AS creator_name,
            p.name AS product_name, p.base_price
       FROM leads l
       LEFT JOIN users u ON u.id = l.assigned_to
       LEFT JOIN users c ON c.id = l.creator_id
       LEFT JOIN lead_products p ON p.id = l.product_id
      WHERE l.id = ? AND l.is_deleted = 0
      LIMIT 1'
);
$stL->execute([$leadId]);
$lead = $stL->fetch();
if (!$lead) { header('Location: leads.php'); exit; }

// Access check — non-admins need to be creator or assignee
$canView = $isAdmin
    || (int)$lead['assigned_to'] === $uid
    || (int)$lead['creator_id']  === $uid;

if (!$canView) {
    http_response_code(403);
    exit('<h3>403 Forbidden</h3><p>You do not have permission to view this lead.</p>');
}

$canEdit = $isAdmin
    || (int)$lead['assigned_to'] === $uid
    || (int)$lead['creator_id']  === $uid;

// Activity timeline
$stA = $db->prepare(
    'SELECT a.*, u.name AS user_name
       FROM lead_activities a
       LEFT JOIN users u ON u.id = a.user_id
      WHERE a.lead_id = ?
      ORDER BY a.activity_at DESC'
);
$stA->execute([$leadId]);
$activities = $stA->fetchAll();

// Attachments
$stAt = $db->prepare(
    'SELECT a.*, u.name AS user_name
       FROM lead_attachments a
       LEFT JOIN users u ON u.id = a.user_id
      WHERE a.lead_id = ?
      ORDER BY a.uploaded_at DESC'
);
$stAt->execute([$leadId]);
$attachments = $stAt->fetchAll();

// Linked daily task (if any)
$linkedTask = null;
try {
    $stLT = $db->prepare(
        "SELECT t.*, u.name AS assignee_name
           FROM tasks t
           LEFT JOIN users u ON u.id = t.user_id
          WHERE t.lead_id = ?
          ORDER BY t.id DESC LIMIT 1"
    );
    $stLT->execute([$leadId]);
    $linkedTask = $stLT->fetch() ?: null;
} catch (PDOException $e) { /* lead_id column may not exist yet */ }

// Visit history from location_logs (GPS tagged to this lead)
$visits = [];
try {
    $stV = $db->prepare(
        'SELECT ll.*, u.name AS user_name
           FROM location_logs ll
           LEFT JOIN users u ON u.id = ll.user_id
          WHERE ll.lead_id = ?
          ORDER BY ll.logged_at DESC LIMIT 50'
    );
    $stV->execute([$leadId]);
    $visits = $stV->fetchAll();
} catch (PDOException $e) { /* lead_id column may not exist yet */ }

$products = $db->query(
    'SELECT id, name FROM lead_products WHERE is_active = 1 ORDER BY name'
)->fetchAll();
$allUsers = $db->query(
    "SELECT id, name FROM users WHERE is_active = 1 AND role != 'admin' ORDER BY name"
)->fetchAll();

// Badges
function leadStatusBadge(string $s): string {
    $map = [
      'new'=>['New','primary'], 'contacted'=>['Contacted','info'],
      'qualified'=>['Qualified','secondary'], 'meeting'=>['Meeting','warning'],
      'negotiation'=>['Negotiation','warning'], 'won'=>['Won','success'],
      'lost'=>['Lost','danger'],
    ];
    $m = $map[$s] ?? ['Unknown','secondary'];
    return '<span class="badge bg-' . $m[1] . ' fs-6">' . h($m[0]) . '</span>';
}
function leadPriorityBadge(string $p): string {
    $map = ['hot'=>'danger','high'=>'warning','medium'=>'secondary','low'=>'light text-dark'];
    return '<span class="badge bg-' . ($map[$p] ?? 'secondary') . '">' . h(ucfirst($p)) . '</span>';
}
function activityIcon(string $type): string {
    $map = [
      'call'=>'bi-telephone-fill text-success',
      'visit'=>'bi-geo-alt-fill text-primary',
      'meeting'=>'bi-people-fill text-info',
      'message'=>'bi-chat-dots-fill text-secondary',
      'note'=>'bi-pencil-fill text-muted',
      'status_change'=>'bi-arrow-repeat text-warning',
      'reassigned'=>'bi-person-check-fill text-dark',
    ];
    return $map[$type] ?? 'bi-circle';
}

$pageTitle = 'Lead: ' . $lead['name'];
include __DIR__ . '/includes/header.php';
?>

<style>
.timeline      { list-style: none; padding-left: 0; margin: 0; }
.timeline li   { position: relative; padding-left: 34px; padding-bottom: 18px;
                 border-left: 2px dashed #dee2e6; margin-left: 12px; }
.timeline li:last-child { border-left-color: transparent; }
.timeline .t-icon { position: absolute; left: -14px; top: 0;
                    width: 28px; height: 28px; border-radius: 50%;
                    background: #fff; border: 2px solid #dee2e6;
                    display: flex; align-items: center; justify-content: center;
                    font-size: .85rem; }
.lead-field-label { font-size: .7rem; text-transform: uppercase;
                    color: #6c757d; letter-spacing: .5px; }
.badge-tag { font-size: .65rem; background:#f1f3f5; color:#495057;
             border-radius:12px; padding:1px 8px; margin-right:3px; }
/* Keep the Save Changes / Log Follow-up footer visible on short screens */
#editLeadModal .modal-body,
#activityModal .modal-body { max-height: calc(100vh - 200px); overflow-y: auto; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <a href="leads.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i>Back
    </a>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="tel:<?= h($lead['phone']) ?>" class="btn btn-sm btn-success">
      <i class="bi bi-telephone-fill me-1"></i>Call
    </a>
    <?php
    $canAssignTask = $isAdmin
        && !empty($lead['assigned_to'])
        && !in_array($lead['status'], ['won','lost'], true)
        && (!$linkedTask || $linkedTask['status'] === 'completed');
    if ($canAssignTask): ?>
    <button class="btn btn-sm btn-warning" id="btnAssignTask">
      <i class="bi bi-calendar-check me-1"></i>Assign as Today's Task
    </button>
    <?php endif; ?>
    <?php if ($canEdit): ?>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#activityModal">
      <i class="bi bi-plus-lg me-1"></i>Log Follow-up
    </button>
    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editLeadModal">
      <i class="bi bi-pencil me-1"></i>Edit
    </button>
    <?php endif; ?>
  </div>
</div>

<?php if ($linkedTask):
  $taskStatus = $linkedTask['status'];
  $tsMap = ['pending'=>['Pending','warning'],'in_progress'=>['In Progress','info'],
            'completed'=>['Completed','success'],'overdue'=>['Overdue','danger']];
  $tsInfo = $tsMap[$taskStatus] ?? ['Unknown','secondary'];
?>
<div class="alert alert-<?= $tsInfo[1] ?>-subtle border-<?= $tsInfo[1] ?> d-flex align-items-center gap-2 py-2 mb-3">
  <i class="bi bi-link-45deg fs-5 text-<?= $tsInfo[1] ?>"></i>
  <div class="flex-grow-1 small">
    Linked to <strong>daily task</strong> &middot;
    Assigned to <strong><?= h($linkedTask['assignee_name'] ?? '—') ?></strong>
    &middot; Deadline
    <strong><?= h(date('d M, h:i A', strtotime($linkedTask['deadline']))) ?></strong>
    &middot; Status <span class="badge bg-<?= $tsInfo[1] ?>"><?= h($tsInfo[0]) ?></span>
  </div>
  <a href="tasks.php" class="btn btn-sm btn-outline-<?= $tsInfo[1] ?>">
    <i class="bi bi-box-arrow-up-right"></i>
  </a>
</div>
<?php endif; ?>

<div class="row g-3">
  <!-- ── Left column: lead info ─────────────────────────────── -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <h5 class="fw-bold mb-1"><?= h($lead['name']) ?></h5>
          <?= leadStatusBadge($lead['status']) ?>
        </div>
        <?php if ($lead['company']): ?>
        <div class="small text-muted mb-2">
          <i class="bi bi-building me-1"></i><?= h($lead['company']) ?>
          <?= $lead['designation'] ? ' &middot; ' . h($lead['designation']) : '' ?>
        </div>
        <?php endif; ?>

        <div class="d-flex gap-2 mb-3 flex-wrap">
          <?= leadPriorityBadge($lead['priority']) ?>
          <span class="badge bg-light text-dark border">
            Source: <?= h(ucfirst(str_replace('_',' ', $lead['source']))) ?>
          </span>
        </div>

        <div class="row g-2 small">
          <div class="col-12">
            <div class="lead-field-label">Phone</div>
            <div><a href="tel:<?= h($lead['phone']) ?>"><?= h($lead['phone']) ?></a></div>
          </div>
          <?php if ($lead['email']): ?>
          <div class="col-12">
            <div class="lead-field-label">Email</div>
            <div><a href="mailto:<?= h($lead['email']) ?>"><?= h($lead['email']) ?></a></div>
          </div>
          <?php endif; ?>
          <?php if ($lead['address']): ?>
          <div class="col-12">
            <div class="lead-field-label">Address</div>
            <div><?= nl2br(h($lead['address'])) ?>
              <?php if ($lead['pincode']): ?> &middot; <?= h($lead['pincode']) ?><?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
          <?php if ($lead['product_name'] || $lead['interest']): ?>
          <div class="col-12">
            <div class="lead-field-label">Interest</div>
            <div>
              <?= h($lead['product_name'] ?: '') ?>
              <?php if ($lead['interest']): ?>
              <div class="text-muted"><?= h($lead['interest']) ?></div>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
          <div class="col-12">
            <div class="lead-field-label">Next Follow-up</div>
            <div class="fw-semibold">
              <?php if ($lead['next_followup_date']):
                $isOv = $lead['next_followup_date'] < date('Y-m-d') && !in_array($lead['status'],['won','lost']);
                $isTd = $lead['next_followup_date'] === date('Y-m-d');
                $cls  = $isOv ? 'text-danger' : ($isTd ? 'text-primary' : '');
              ?>
              <span class="<?= $cls ?>"><?= h(date('d M Y', strtotime($lead['next_followup_date']))) ?></span>
              <?php else: ?>—<?php endif; ?>
            </div>
          </div>
          <div class="col-6">
            <div class="lead-field-label">Assignee</div>
            <div><?= h($lead['assignee_name'] ?: 'Unassigned') ?></div>
          </div>
          <div class="col-6">
            <div class="lead-field-label">Created By</div>
            <div><?= h($lead['creator_name'] ?? '—') ?></div>
          </div>
          <div class="col-12">
            <div class="lead-field-label">Created</div>
            <div class="text-muted"><?= h(date('d M Y, h:i A', strtotime($lead['created_at']))) ?></div>
          </div>
          <?php if ($lead['tags']): ?>
          <div class="col-12">
            <div class="lead-field-label">Tags</div>
            <div>
              <?php foreach (array_filter(array_map('trim', explode(',', $lead['tags']))) as $tg): ?>
              <span class="badge-tag"><?= h($tg) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
          <?php if ($lead['notes']): ?>
          <div class="col-12">
            <div class="lead-field-label">Notes</div>
            <div class="bg-light rounded p-2 mt-1" style="white-space:pre-wrap"><?= h($lead['notes']) ?></div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ── Attachments ─────────────────────────────────────── -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-transparent border-bottom fw-semibold small d-flex align-items-center justify-content-between">
        <span><i class="bi bi-paperclip me-1"></i>Attachments (<?= count($attachments) ?>)</span>
        <?php if ($canEdit): ?>
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#attachModal">
          <i class="bi bi-plus-lg"></i>
        </button>
        <?php endif; ?>
      </div>
      <div class="card-body p-2">
        <?php if (empty($attachments)): ?>
        <div class="text-muted small text-center py-3">No files attached yet.</div>
        <?php else: foreach ($attachments as $at): ?>
        <div class="d-flex align-items-center gap-2 p-2 border-bottom">
          <i class="bi bi-file-earmark-fill text-primary"></i>
          <div class="flex-grow-1 small">
            <a href="download.php?path=<?= urlencode($at['file_path']) ?>" target="_blank">
              <?= h($at['file_label'] ?: basename($at['file_path'])) ?>
            </a>
            <div class="text-muted" style="font-size:.7rem">
              <?= h($at['user_name'] ?? '—') ?> &middot;
              <?= h(date('d M, h:i A', strtotime($at['uploaded_at']))) ?>
            </div>
          </div>
          <?php if ($canEdit): ?>
          <button class="btn btn-sm btn-outline-danger py-0 px-2 btn-del-att"
                  data-att-id="<?= (int)$at['id'] ?>" title="Remove">
            <i class="bi bi-x"></i>
          </button>
          <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- ── GPS Visits ──────────────────────────────────────── -->
    <?php if (!empty($visits)): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-transparent border-bottom fw-semibold small">
        <i class="bi bi-geo-alt-fill me-1 text-success"></i>Site Visits (<?= count($visits) ?>)
      </div>
      <div class="card-body p-2">
        <?php foreach ($visits as $v):
          $hasGps = !((float)$v['lat'] === 0.0 && (float)$v['lng'] === 0.0);
        ?>
        <div class="d-flex align-items-start gap-2 p-2 border-bottom small">
          <i class="bi bi-geo-fill text-success mt-1"></i>
          <div class="flex-grow-1">
            <div><?= h($v['user_name'] ?? '—') ?></div>
            <div class="text-muted" style="font-size:.72rem">
              <?= h(date('d M Y, h:i A', strtotime($v['logged_at']))) ?>
              <?php if ($v['notes']): ?> &middot; <?= h($v['notes']) ?><?php endif; ?>
            </div>
            <?php if (!empty($v['photo'])): ?>
            <a href="download.php?path=<?= urlencode($v['photo']) ?>" target="_blank"
               class="d-inline-block mt-1">
              <img src="download.php?path=<?= urlencode($v['photo']) ?>"
                   alt="Visit photo" class="rounded border"
                   style="width:60px;height:60px;object-fit:cover">
            </a>
            <?php endif; ?>
          </div>
          <?php if ($hasGps): ?>
          <a href="https://maps.google.com/?q=<?= (float)$v['lat'] ?>,<?= (float)$v['lng'] ?>"
             target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2">
            <i class="bi bi-map"></i>
          </a>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Right column: timeline ────────────────────────────── -->
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-transparent border-bottom fw-semibold d-flex align-items-center justify-content-between">
        <span><i class="bi bi-clock-history me-1"></i>Activity Timeline</span>
        <span class="small text-muted"><?= count($activities) ?> entries</span>
      </div>
      <div class="card-body">
        <?php if (empty($activities)): ?>
        <div class="text-center text-muted py-4">
          <i class="bi bi-chat-square-text fs-1 opacity-25 d-block mb-2"></i>
          No activity yet. Log the first follow-up using the button above.
        </div>
        <?php else: ?>
        <ul class="timeline">
          <?php foreach ($activities as $a): ?>
          <li>
            <div class="t-icon">
              <i class="bi <?= activityIcon($a['activity_type']) ?>"></i>
            </div>
            <div class="d-flex justify-content-between flex-wrap gap-2">
              <div class="fw-semibold small">
                <?= h(ucfirst(str_replace('_',' ', $a['activity_type']))) ?>
                <?php if ($a['activity_type'] === 'status_change' && $a['old_status'] && $a['new_status']): ?>
                <span class="small text-muted">
                  : <?= h($a['old_status']) ?> → <?= h($a['new_status']) ?>
                </span>
                <?php endif; ?>
                <?php if ($a['outcome'] && $a['outcome'] !== 'pending' && $a['activity_type'] !== 'status_change'): ?>
                <span class="badge bg-light text-dark ms-1" style="font-size:.68rem">
                  <?= h(ucfirst(str_replace('_',' ', $a['outcome']))) ?>
                </span>
                <?php endif; ?>
              </div>
              <div class="small text-muted">
                <?= h($a['user_name'] ?? '—') ?> &middot;
                <?= h(date('d M Y, h:i A', strtotime($a['activity_at']))) ?>
              </div>
            </div>
            <?php if ($a['notes']): ?>
            <div class="mt-1 bg-light rounded p-2 small" style="white-space:pre-wrap">
              <?= h($a['notes']) ?>
            </div>
            <?php endif; ?>
            <?php if ($a['next_followup_date']): ?>
            <div class="mt-1 small text-primary">
              <i class="bi bi-alarm me-1"></i>Next follow-up:
              <?= h(date('d M Y', strtotime($a['next_followup_date']))) ?>
              <?= $a['next_followup_time'] ? ' at ' . h(date('h:i A', strtotime($a['next_followup_time']))) : '' ?>
            </div>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ── Log Follow-up Modal ────────────────────────────────── -->
<?php if ($canEdit): ?>
<div class="modal fade" id="activityModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header">
        <h6 class="modal-title fw-bold">
          <i class="bi bi-chat-text me-1 text-primary"></i>Log Follow-up
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="activityForm">
        <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Type</label>
              <select name="activity_type" class="form-select form-select-sm">
                <option value="call">Call</option>
                <option value="visit">Visit</option>
                <option value="meeting">Meeting</option>
                <option value="message">Message</option>
                <option value="note">Note</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Outcome</label>
              <select name="outcome" class="form-select form-select-sm">
                <option value="pending">Pending</option>
                <option value="connected">Connected</option>
                <option value="not_connected">Not Connected</option>
                <option value="interested">Interested</option>
                <option value="not_interested">Not Interested</option>
                <option value="converted">Converted</option>
                <option value="rejected">Rejected</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small">Notes</label>
              <textarea name="notes" rows="3" class="form-control form-control-sm"
                        placeholder="What happened? What's the next step?"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">
                Next Follow-up Date
                <span class="text-muted fw-normal">(reappears automatically)</span>
              </label>
              <input type="date" name="next_followup_date" class="form-control form-control-sm"
                     min="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Time</label>
              <input type="time" name="next_followup_time" class="form-control form-control-sm">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small">Change Status (optional)</label>
              <select name="new_status" class="form-select form-select-sm">
                <option value="">— Keep as <?= h($lead['status']) ?> —</option>
                <?php foreach (['new','contacted','qualified','meeting','negotiation','won','lost'] as $s):
                  if ($s === $lead['status']) continue; ?>
                <option value="<?= $s ?>"><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i>Save Follow-up
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Edit Lead Modal ────────────────────────────────────── -->
<div class="modal fade" id="editLeadModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow">
      <div class="modal-header">
        <h6 class="modal-title fw-bold">
          <i class="bi bi-pencil-square me-1 text-primary"></i>Edit Lead
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="editLeadForm">
        <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Name *</label>
              <input name="name" class="form-control form-control-sm" required maxlength="150"
                     value="<?= h($lead['name']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Phone *</label>
              <input name="phone" class="form-control form-control-sm" required maxlength="30"
                     value="<?= h($lead['phone']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Email</label>
              <input name="email" type="email" class="form-control form-control-sm" maxlength="150"
                     value="<?= h($lead['email'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Company</label>
              <input name="company" class="form-control form-control-sm" maxlength="150"
                     value="<?= h($lead['company'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Designation</label>
              <input name="designation" class="form-control form-control-sm" maxlength="100"
                     value="<?= h($lead['designation'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Pincode</label>
              <input name="pincode" class="form-control form-control-sm" maxlength="10"
                     value="<?= h($lead['pincode'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small">Address</label>
              <textarea name="address" rows="2" class="form-control form-control-sm"><?= h($lead['address'] ?? '') ?></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Source</label>
              <select name="source" class="form-select form-select-sm">
                <?php foreach (['walk_in','phone','referral','website','social','cold_call','exhibition','other'] as $sr): ?>
                <option value="<?= $sr ?>" <?= $lead['source'] === $sr ? 'selected' : '' ?>>
                  <?= ucfirst(str_replace('_',' ',$sr)) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Priority</label>
              <select name="priority" class="form-select form-select-sm">
                <?php foreach (['hot','high','medium','low'] as $p): ?>
                <option value="<?= $p ?>" <?= $lead['priority'] === $p ? 'selected' : '' ?>>
                  <?= ucfirst($p) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Product</label>
              <select name="product_id" class="form-select form-select-sm">
                <option value="">— None —</option>
                <?php foreach ($products as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= (int)$lead['product_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                  <?= h($p['name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small">Interest</label>
              <input name="interest" class="form-control form-control-sm" maxlength="255"
                     value="<?= h($lead['interest'] ?? '') ?>">
            </div>
            <?php if ($isAdmin): ?>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Assign To</label>
              <select name="assigned_to" class="form-select form-select-sm">
                <option value="">— Unassigned —</option>
                <?php foreach ($allUsers as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= (int)$lead['assigned_to'] === (int)$u['id'] ? 'selected' : '' ?>>
                  <?= h($u['name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
            <div class="col-md-<?= $isAdmin ? 6 : 12 ?>">
              <label class="form-label fw-semibold small">Tags</label>
              <input name="tags" class="form-control form-control-sm" maxlength="255"
                     value="<?= h($lead['tags'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small">Notes</label>
              <textarea name="notes" rows="3" class="form-control form-control-sm"><?= h($lead['notes'] ?? '') ?></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i>Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Attach File Modal ──────────────────────────────────── -->
<div class="modal fade" id="attachModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header">
        <h6 class="modal-title fw-bold">
          <i class="bi bi-paperclip me-1 text-primary"></i>Attach File
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="attachForm" enctype="multipart/form-data">
        <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label fw-semibold small">Label (optional)</label>
            <input name="file_label" class="form-control form-control-sm"
                   placeholder="e.g. Business card, Site photo, Signed form" maxlength="100">
          </div>
          <div>
            <label class="form-label fw-semibold small">File</label>
            <input type="file" name="lead_file" class="form-control form-control-sm"
                   accept="image/jpeg,image/png,.pdf,.doc,.docx,.xls,.xlsx,.zip" required>
            <div class="form-text">Max <?= MAX_FILE_MB ?> MB. Photos auto-compressed.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Upload</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

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
    return fetch('lead_handler.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); });
  }

  var actForm  = document.getElementById('activityForm');
  if (actForm) {
    actForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = actForm.querySelector('[type="submit"]');
      btn.disabled = true;
      post('add_activity', new FormData(actForm)).then(function (res) {
        btn.disabled = false;
        showMsg(res.message, res.success ? 'success' : 'danger');
        if (res.success) { setTimeout(function () { location.reload(); }, 600); }
      }).catch(function () { btn.disabled = false; showMsg('Network error.', 'danger'); });
    });
  }

  var editForm = document.getElementById('editLeadForm');
  if (editForm) {
    editForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = editForm.querySelector('[type="submit"]');
      btn.disabled = true;
      post('update', new FormData(editForm)).then(function (res) {
        btn.disabled = false;
        showMsg(res.message, res.success ? 'success' : 'danger');
        if (res.success) { setTimeout(function () { location.reload(); }, 600); }
      });
    });
  }

  // Attach file with image compression
  var attForm = document.getElementById('attachForm');
  if (attForm) {
    attForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = attForm.querySelector('[type="submit"]');
      btn.disabled = true;
      var fileInput = attForm.querySelector('input[name="lead_file"]');
      var rawFile = fileInput && fileInput.files ? fileInput.files[0] : null;

      var proceed = function (finalFile) {
        var fd = new FormData(attForm);
        if (finalFile && rawFile && finalFile !== rawFile) {
          fd.set('lead_file', finalFile, finalFile.name || 'photo.jpg');
        }
        post('attach_file', fd).then(function (res) {
          btn.disabled = false;
          showMsg(res.message, res.success ? 'success' : 'danger');
          if (res.success) { setTimeout(function () { location.reload(); }, 600); }
        }).catch(function () { btn.disabled = false; showMsg('Network error.', 'danger'); });
      };

      if (typeof compressImageIfNeeded === 'function' && rawFile) {
        compressImageIfNeeded(rawFile).then(function (r) {
          proceed(r && r.file ? r.file : rawFile);
        }).catch(function (err) {
          btn.disabled = false;
          showMsg(err && err.message ? err.message : 'Image error.', 'danger');
        });
      } else {
        proceed(rawFile);
      }
    });
  }

  // Assign as Today's Task (admin)
  var btnAT = document.getElementById('btnAssignTask');
  if (btnAT) {
    btnAT.addEventListener('click', function () {
      if (!confirm('Create a daily task for the assignee, due today at 6:00 PM?')) { return; }
      btnAT.disabled = true;
      var fd = new FormData();
      fd.append('lead_id', '<?= (int)$lead['id'] ?>');
      post('assign_task', fd).then(function (res) {
        btnAT.disabled = false;
        showMsg(res.message, res.success ? 'success' : 'danger');
        if (res.success) { setTimeout(function () { location.reload(); }, 600); }
      }).catch(function () {
        btnAT.disabled = false;
        showMsg('Network error.', 'danger');
      });
    });
  }

  // Delete attachment
  document.querySelectorAll('.btn-del-att').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!confirm('Remove this attachment?')) { return; }
      var fd = new FormData();
      fd.append('attachment_id', btn.dataset.attId);
      post('delete_attachment', fd).then(function (res) {
        showMsg(res.message, res.success ? 'success' : 'danger');
        if (res.success) { setTimeout(function () { location.reload(); }, 400); }
      });
    });
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
