<?php
// ============================================================
//  HRMS · User Management  (Admin only)
//  Create · Edit · Deactivate / Delete · ID Documents
// ============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
requireRole('admin');

$user = getCurrentUser();
$db   = getDB();

$success = '';
$error   = '';

// ── Document upload helper (images only) ─────────────────────
function uploadIdDoc(string $inputName): array
{
    if (empty($_FILES[$inputName]['name'])) {
        return ['success' => true, 'path' => null]; // optional — no file is fine
    }
    $allowed = ['jpg','jpeg','png','webp'];
    $ext     = strtolower(pathinfo($_FILES[$inputName]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        return ['success' => false, 'path' => null,
                'message' => "ID document must be JPG, PNG, or WEBP (got .{$ext})."];
    }
    if ($_FILES[$inputName]['size'] > MAX_FILE_BYTES) {
        return ['success' => false, 'path' => null,
                'message' => 'Document image exceeds ' . MAX_FILE_MB . ' MB.'];
    }
    if ($_FILES[$inputName]['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'path' => null, 'message' => 'Upload error.'];
    }
    $dir  = UPLOAD_PATH . 'documents/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    move_uploaded_file($_FILES[$inputName]['tmp_name'], $dir . $name);
    return ['success' => true, 'path' => 'documents/' . $name];
}

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Security token invalid. Please refresh and try again.';
    } else {
        $formAction = $_POST['form_action'] ?? '';

        // ── CREATE USER ───────────────────────────────────────
        if ($formAction === 'create_user') {
            $name       = trim($_POST['name']     ?? '');
            $email      = strtolower(trim($_POST['email'] ?? ''));
            $password   = $_POST['password']      ?? '';
            $role       = $_POST['role']          ?? '';
            $ipRestrict = isset($_POST['office_ip_restricted']) ? 1 : 0;
            $validRoles = ['admin', 'office_staff', 'field_worker'];

            if (!$name || !$email || !$password || !$role) {
                $error = 'Name, email, password, and role are required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Invalid email address.';
            } elseif (!in_array($role, $validRoles, true)) {
                $error = 'Invalid role selected.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
            } else {
                $stCheck = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                $stCheck->execute([$email]);
                if ($stCheck->fetch()) {
                    $error = "Email '{$email}' is already registered.";
                } else {
                    // Handle document uploads
                    $front = uploadIdDoc('id_front');
                    $back  = uploadIdDoc('id_back');
                    if (!$front['success']) { $error = $front['message']; }
                    elseif (!$back['success']) { $error = $back['message']; }
                    else {
                        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                        $db->prepare(
                            'INSERT INTO users
                             (name, email, password, role, office_ip_restricted, id_front, id_back)
                             VALUES (?, ?, ?, ?, ?, ?, ?)'
                        )->execute([
                            $name, $email, $hash, $role, $ipRestrict,
                            $front['path'], $back['path']
                        ]);
                        $success = "User '{$name}' created successfully.";
                    }
                }
            }
        }

        // ── UPDATE USER ───────────────────────────────────────
        if ($formAction === 'update_user') {
            $editId     = (int)($_POST['edit_id'] ?? 0);
            $name       = trim($_POST['name']     ?? '');
            $email      = strtolower(trim($_POST['email'] ?? ''));
            $role       = $_POST['role']          ?? '';
            $ipRestrict = isset($_POST['office_ip_restricted']) ? 1 : 0;
            $isActive   = isset($_POST['is_active']) ? 1 : 0;
            $newPwd     = $_POST['new_password']  ?? '';
            $validRoles = ['admin', 'office_staff', 'field_worker'];

            if (!$editId || !$name || !$email || !$role) {
                $error = 'Name, email, and role are required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Invalid email address.';
            } elseif (!in_array($role, $validRoles, true)) {
                $error = 'Invalid role.';
            } elseif ($newPwd && strlen($newPwd) < 8) {
                $error = 'New password must be at least 8 characters.';
            } else {
                $stCheck = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
                $stCheck->execute([$email, $editId]);
                if ($stCheck->fetch()) {
                    $error = "Email '{$email}' is already used by another account.";
                } else {
                    // Fetch current doc paths
                    $stCur = $db->prepare('SELECT id_front, id_back FROM users WHERE id = ?');
                    $stCur->execute([$editId]);
                    $cur = $stCur->fetch();

                    $front = uploadIdDoc('id_front');
                    $back  = uploadIdDoc('id_back');
                    if (!$front['success']) { $error = $front['message']; }
                    elseif (!$back['success']) { $error = $back['message']; }
                    else {
                        $frontPath = $front['path'] ?? $cur['id_front'];
                        $backPath  = $back['path']  ?? $cur['id_back'];

                        if ($newPwd) {
                            $hash = password_hash($newPwd, PASSWORD_BCRYPT, ['cost' => 12]);
                            $db->prepare(
                                'UPDATE users SET name=?, email=?, password=?, role=?,
                                 office_ip_restricted=?, is_active=?, id_front=?, id_back=?
                                 WHERE id=?'
                            )->execute([$name, $email, $hash, $role,
                                        $ipRestrict, $isActive, $frontPath, $backPath, $editId]);
                        } else {
                            $db->prepare(
                                'UPDATE users SET name=?, email=?, role=?,
                                 office_ip_restricted=?, is_active=?, id_front=?, id_back=?
                                 WHERE id=?'
                            )->execute([$name, $email, $role,
                                        $ipRestrict, $isActive, $frontPath, $backPath, $editId]);
                        }
                        $success = "User '{$name}' updated successfully.";
                    }
                }
            }
        }

        // ── TOGGLE ACTIVE ─────────────────────────────────────
        if ($formAction === 'toggle_active') {
            $toggleId = (int)($_POST['toggle_id'] ?? 0);
            $newState = (int)($_POST['new_state'] ?? 0);
            if ($toggleId && $toggleId !== (int)$user['id']) {
                $db->prepare('UPDATE users SET is_active=? WHERE id=?')
                   ->execute([$newState, $toggleId]);
                $success = 'User status updated.';
            } else {
                $error = 'Cannot deactivate your own account.';
            }
        }

        // ── DELETE USER ───────────────────────────────────────
        if ($formAction === 'delete_user') {
            $delId = (int)($_POST['del_id'] ?? 0);
            if ($delId === (int)$user['id']) {
                $error = 'You cannot delete your own account.';
            } elseif ($delId) {
                $stHasRec = $db->prepare('SELECT COUNT(*) FROM attendance WHERE user_id=?');
                $stHasRec->execute([$delId]);
                if ((int)$stHasRec->fetchColumn() > 0) {
                    $db->prepare('UPDATE users SET is_active=0 WHERE id=?')->execute([$delId]);
                    $success = 'User has attendance records — account deactivated to preserve history.';
                } else {
                    $stName = $db->prepare('SELECT name FROM users WHERE id=?');
                    $stName->execute([$delId]);
                    $delName = $stName->fetchColumn();
                    $db->prepare('DELETE FROM users WHERE id=?')->execute([$delId]);
                    $success = "User '{$delName}' permanently deleted.";
                }
            }
        }
    }
}

// ── Fetch users ───────────────────────────────────────────────
$filterRole   = $_GET['role']   ?? '';
$filterStatus = $_GET['status'] ?? '';
$search       = trim($_GET['q'] ?? '');

$where  = [];
$params = [];
if ($filterRole && in_array($filterRole, ['admin','office_staff','field_worker'], true)) {
    $where[] = 'role = ?'; $params[] = $filterRole;
}
if ($filterStatus !== '') {
    $where[] = 'is_active = ?'; $params[] = (int)$filterStatus;
}
if ($search) {
    $where[] = '(name LIKE ? OR email LIKE ?)';
    $params[] = "%{$search}%"; $params[] = "%{$search}%";
}

$sql = 'SELECT * FROM users'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY role ASC, name ASC';
$stUsers = $db->prepare($sql);
$stUsers->execute($params);
$allUsers = $stUsers->fetchAll();

function roleBadge(string $role): string {
    return match($role) {
        'admin'        => '<span class="badge bg-danger">Admin</span>',
        'office_staff' => '<span class="badge bg-primary">Office Staff</span>',
        'field_worker' => '<span class="badge bg-success">Field Worker</span>',
        default        => '<span class="badge bg-secondary">' . h(ucfirst($role)) . '</span>',
    };
}

$pageTitle = 'User Management';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <h5 class="fw-bold mb-0"><i class="bi bi-people-fill me-2 text-primary"></i>User Management</h5>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
    <i class="bi bi-person-plus-fill me-2"></i>Add New User
  </button>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible d-flex gap-2 mb-4">
  <i class="bi bi-check-circle-fill flex-shrink-0 mt-1"></i><div><?= h($success) ?></div>
  <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible d-flex gap-2 mb-4">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i><div><?= h($error) ?></div>
  <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Stats -->
<?php
$totalActive = count(array_filter($allUsers, fn($u) => $u['is_active']));
$totalOffice = count(array_filter($allUsers, fn($u) => $u['role'] === 'office_staff'));
$totalField  = count(array_filter($allUsers, fn($u) => $u['role'] === 'field_worker'));
$docsComplete = count(array_filter($allUsers, fn($u) => $u['id_front'] && $u['id_back']));
?>
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-people-fill"></i></div>
        <div><div class="stat-value"><?= count($allUsers) ?></div><div class="stat-label">Total Users</div></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-person-check-fill"></i></div>
        <div><div class="stat-value"><?= $totalActive ?></div><div class="stat-label">Active</div></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-geo-alt-fill"></i></div>
        <div><div class="stat-value"><?= $totalField ?></div><div class="stat-label">Field Workers</div></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-info-subtle text-info"><i class="bi bi-card-image"></i></div>
        <div><div class="stat-value"><?= $docsComplete ?></div><div class="stat-label">Docs Verified</div></div>
      </div>
    </div>
  </div>
</div>

<!-- Filter -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-5 col-md-4">
        <div class="input-group input-group-sm">
          <span class="input-group-text bg-light"><i class="bi bi-search text-muted"></i></span>
          <input type="text" class="form-control" name="q" placeholder="Search name or email…" value="<?= h($search) ?>">
        </div>
      </div>
      <div class="col-sm-3 col-md-2">
        <select name="role" class="form-select form-select-sm">
          <option value="">All Roles</option>
          <option value="admin"        <?= $filterRole==='admin'        ?'selected':''?>>Admin</option>
          <option value="office_staff" <?= $filterRole==='office_staff' ?'selected':''?>>Office Staff</option>
          <option value="field_worker" <?= $filterRole==='field_worker' ?'selected':''?>>Field Worker</option>
        </select>
      </div>
      <div class="col-sm-3 col-md-2">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Status</option>
          <option value="1" <?= $filterStatus==='1'?'selected':''?>>Active</option>
          <option value="0" <?= $filterStatus==='0'?'selected':''?>>Inactive</option>
        </select>
      </div>
      <div class="col-auto d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Filter</button>
        <a href="admin_users.php" class="btn btn-outline-secondary btn-sm">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Users Table -->
<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="ps-3">#</th>
          <th>User</th>
          <th>Role</th>
          <th class="text-center">IP Restricted</th>
          <th class="text-center">Documents</th>
          <th class="text-center">Status</th>
          <th class="text-center">Joined</th>
          <th class="pe-3 text-center">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($allUsers)): ?>
        <tr><td colspan="8" class="text-center py-5 text-muted">
          <i class="bi bi-search fs-1 d-block mb-2 opacity-25"></i>No users found.
        </td></tr>
        <?php endif; ?>
        <?php foreach ($allUsers as $i => $u): ?>
        <tr class="<?= !$u['is_active'] ? 'table-secondary opacity-75' : '' ?>">
          <td class="ps-3 text-muted small"><?= $i + 1 ?></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="avatar-circle avatar-sm flex-shrink-0"><?= strtoupper(substr($u['name'],0,1)) ?></div>
              <div>
                <div class="fw-semibold small"><?= h($u['name']) ?></div>
                <div class="text-muted" style="font-size:.72rem"><?= h($u['email']) ?></div>
              </div>
            </div>
          </td>
          <td><?= roleBadge($u['role']) ?></td>
          <td class="text-center">
            <?= $u['office_ip_restricted']
              ? '<span class="badge bg-warning text-dark"><i class="bi bi-shield-lock me-1"></i>Yes</span>'
              : '<span class="text-muted small">—</span>' ?>
          </td>
          <td class="text-center">
            <div class="d-flex justify-content-center gap-1">
              <!-- ID Front -->
              <?php if ($u['id_front']): ?>
              <a href="download.php?path=<?= urlencode($u['id_front']) ?>" target="_blank"
                 class="btn btn-xs btn-outline-success" title="View ID Front">
                <i class="bi bi-card-image me-1"></i>Front
              </a>
              <?php else: ?>
              <span class="badge bg-secondary opacity-50">No Front</span>
              <?php endif; ?>
              <!-- ID Back -->
              <?php if ($u['id_back']): ?>
              <a href="download.php?path=<?= urlencode($u['id_back']) ?>" target="_blank"
                 class="btn btn-xs btn-outline-info" title="View ID Back">
                <i class="bi bi-card-image me-1"></i>Back
              </a>
              <?php else: ?>
              <span class="badge bg-secondary opacity-50">No Back</span>
              <?php endif; ?>
            </div>
          </td>
          <td class="text-center">
            <?= $u['is_active']
              ? '<span class="badge bg-success">Active</span>'
              : '<span class="badge bg-secondary">Inactive</span>' ?>
          </td>
          <td class="text-center small text-muted"><?= h(date('d M Y', strtotime($u['created_at']))) ?></td>
          <td class="text-center pe-3">
            <div class="d-flex justify-content-center gap-1">
              <button class="btn btn-xs btn-outline-primary btn-edit-user" title="Edit user"
                      data-id="<?= $u['id'] ?>"
                      data-name="<?= h($u['name']) ?>"
                      data-email="<?= h($u['email']) ?>"
                      data-role="<?= h($u['role']) ?>"
                      data-ip="<?= $u['office_ip_restricted'] ?>"
                      data-active="<?= $u['is_active'] ?>"
                      data-front="<?= h($u['id_front'] ?? '') ?>"
                      data-back="<?= h($u['id_back'] ?? '') ?>">
                <i class="bi bi-pencil-fill"></i>
              </button>
              <?php if ((int)$u['id'] !== (int)$user['id']): ?>
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="form_action" value="toggle_active">
                <input type="hidden" name="toggle_id"  value="<?= $u['id'] ?>">
                <input type="hidden" name="new_state"  value="<?= $u['is_active'] ? 0 : 1 ?>">
                <button type="submit"
                        class="btn btn-xs <?= $u['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                        title="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>"
                        onclick="return confirm('<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?> this user?')">
                  <i class="bi bi-person-<?= $u['is_active'] ? 'dash' : 'check' ?>-fill"></i>
                </button>
              </form>
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="form_action" value="delete_user">
                <input type="hidden" name="del_id"      value="<?= $u['id'] ?>">
                <button type="submit" class="btn btn-xs btn-outline-danger" title="Delete"
                        onclick="return confirm('Delete \'<?= h($u['name']) ?>\'? Cannot be undone.')">
                  <i class="bi bi-trash3-fill"></i>
                </button>
              </form>
              <?php else: ?>
              <span class="text-muted px-1"><i class="bi bi-lock-fill" title="Your account"></i></span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Create User Modal ─────────────────────────────────────── -->
<div class="modal fade" id="createUserModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow">
      <div class="modal-header">
        <h6 class="modal-title fw-bold"><i class="bi bi-person-plus-fill me-2 text-primary"></i>Add New User</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="admin_users.php" enctype="multipart/form-data" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="create_user">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold small">Full Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="name" placeholder="e.g. John Smith" required maxlength="100">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small">Email Address <span class="text-danger">*</span></label>
              <input type="email" class="form-control" name="email" placeholder="john@company.com" required maxlength="150">
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold small">Role <span class="text-danger">*</span></label>
              <select name="role" class="form-select" required id="createRoleSelect">
                <option value="">— Select role —</option>
                <option value="admin">Admin</option>
                <option value="office_staff">Office Staff</option>
                <option value="field_worker">Field Worker</option>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold small">Password <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="password" class="form-control" name="password" id="createPwd"
                       placeholder="Min 8 characters" required minlength="8">
                <button class="btn btn-outline-secondary" type="button" onclick="togglePwd('createPwd',this)">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>
            <div class="col-12">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="office_ip_restricted"
                       id="createIpCheck" value="1" checked>
                <label class="form-check-label small fw-semibold" for="createIpCheck">
                  Enforce Office IP Restriction on Check-In
                </label>
              </div>
            </div>

            <!-- ID Documents -->
            <div class="col-12"><hr class="my-1"><p class="fw-semibold small mb-2">
              <i class="bi bi-card-image me-1 text-primary"></i>ID Documents
              <span class="text-muted fw-normal">(Optional — JPG/PNG/WEBP, max <?= MAX_FILE_MB ?>MB each)</span>
            </p></div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold small">ID Front</label>
              <input type="file" class="form-control" name="id_front" accept="image/jpeg,image/png,image/webp">
              <div class="form-text">e.g. National ID / Passport front page</div>
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold small">ID Back</label>
              <input type="file" class="form-control" name="id_back" accept="image/jpeg,image/png,image/webp">
              <div class="form-text">e.g. National ID / Passport back page</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-person-plus-fill me-2"></i>Create User
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Edit User Modal ───────────────────────────────────────── -->
<div class="modal fade" id="editUserModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow">
      <div class="modal-header">
        <h6 class="modal-title fw-bold"><i class="bi bi-pencil-fill me-2 text-warning"></i>Edit User</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="admin_users.php" enctype="multipart/form-data" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="update_user">
        <input type="hidden" name="edit_id" id="editId">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold small">Full Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="name" id="editName" required maxlength="100">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small">Email Address <span class="text-danger">*</span></label>
              <input type="email" class="form-control" name="email" id="editEmail" required maxlength="150">
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold small">Role <span class="text-danger">*</span></label>
              <select name="role" class="form-select" id="editRole" required>
                <option value="admin">Admin</option>
                <option value="office_staff">Office Staff</option>
                <option value="field_worker">Field Worker</option>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold small">New Password</label>
              <div class="input-group">
                <input type="password" class="form-control" name="new_password" id="editPwd"
                       placeholder="Leave blank to keep current" minlength="8">
                <button class="btn btn-outline-secondary" type="button" onclick="togglePwd('editPwd',this)">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>
            <div class="col-sm-6">
              <div class="form-check form-switch mt-2">
                <input class="form-check-input" type="checkbox" name="office_ip_restricted" id="editIpCheck" value="1">
                <label class="form-check-label small fw-semibold" for="editIpCheck">IP Restricted</label>
              </div>
            </div>
            <div class="col-sm-6">
              <div class="form-check form-switch mt-2">
                <input class="form-check-input" type="checkbox" name="is_active" id="editActiveCheck" value="1">
                <label class="form-check-label small fw-semibold" for="editActiveCheck">Account Active</label>
              </div>
            </div>

            <!-- ID Documents -->
            <div class="col-12"><hr class="my-1"><p class="fw-semibold small mb-1">
              <i class="bi bi-card-image me-1 text-primary"></i>ID Documents
              <span class="text-muted fw-normal">(Upload new image to replace existing)</span>
            </p></div>

            <div class="col-sm-6">
              <label class="form-label fw-semibold small">ID Front</label>
              <!-- Current doc preview -->
              <div id="editFrontPreview" class="mb-2 d-none">
                <a id="editFrontLink" href="#" target="_blank"
                   class="btn btn-sm btn-outline-success w-100">
                  <i class="bi bi-card-image me-1"></i>View Current ID Front
                </a>
              </div>
              <input type="file" class="form-control" name="id_front" accept="image/jpeg,image/png,image/webp">
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold small">ID Back</label>
              <div id="editBackPreview" class="mb-2 d-none">
                <a id="editBackLink" href="#" target="_blank"
                   class="btn btn-sm btn-outline-info w-100">
                  <i class="bi bi-card-image me-1"></i>View Current ID Back
                </a>
              </div>
              <input type="file" class="form-control" name="id_back" accept="image/jpeg,image/png,image/webp">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning text-white">
            <i class="bi bi-save me-2"></i>Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Populate edit modal
document.querySelectorAll('.btn-edit-user').forEach(btn => {
  btn.addEventListener('click', function() {
    document.getElementById('editId').value            = this.dataset.id;
    document.getElementById('editName').value          = this.dataset.name;
    document.getElementById('editEmail').value         = this.dataset.email;
    document.getElementById('editRole').value          = this.dataset.role;
    document.getElementById('editIpCheck').checked     = this.dataset.ip === '1';
    document.getElementById('editActiveCheck').checked = this.dataset.active === '1';
    document.getElementById('editPwd').value           = '';

    // Document previews
    const front = this.dataset.front;
    const back  = this.dataset.back;
    const frontPrev = document.getElementById('editFrontPreview');
    const backPrev  = document.getElementById('editBackPreview');

    if (front) {
      frontPrev.classList.remove('d-none');
      document.getElementById('editFrontLink').href = 'download.php?path=' + encodeURIComponent(front);
    } else { frontPrev.classList.add('d-none'); }

    if (back) {
      backPrev.classList.remove('d-none');
      document.getElementById('editBackLink').href = 'download.php?path=' + encodeURIComponent(back);
    } else { backPrev.classList.add('d-none'); }

    bootstrap.Modal.getOrCreateInstance(document.getElementById('editUserModal')).show();
  });
});

// Auto-toggle IP check based on role
document.getElementById('createRoleSelect')?.addEventListener('change', function() {
  document.getElementById('createIpCheck').checked = (this.value === 'office_staff');
});

function togglePwd(id, btn) {
  const input = document.getElementById(id);
  const icon  = btn.querySelector('i');
  input.type  = input.type === 'password' ? 'text' : 'password';
  icon.className = input.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
