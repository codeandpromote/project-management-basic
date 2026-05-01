<?php
// ============================================================
//  HRMS · Admin — Lead Product / Service Catalog
// ============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
requireRole('admin');

$user = getCurrentUser();
$db   = getDB();

$products = $db->query(
    'SELECT p.*,
            (SELECT COUNT(*) FROM leads l WHERE l.product_id = p.id AND l.is_deleted = 0) AS lead_count
       FROM lead_products p
      ORDER BY p.is_active DESC, p.name ASC'
)->fetchAll();

$pageTitle = 'Product Catalog';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h5 class="fw-bold mb-0">
    <i class="bi bi-box-seam me-2 text-primary"></i>Product &amp; Service Catalog
  </h5>
  <div class="d-flex gap-2">
    <a href="leads.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i>Back to Leads
    </a>
    <button class="btn btn-sm btn-primary" id="btnAddProduct">
      <i class="bi bi-plus-lg me-1"></i>Add Product
    </button>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th class="ps-3">Name</th>
          <th>Description</th>
          <th>Leads</th>
          <th>Status</th>
          <th class="pe-3">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($products)): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">No products yet. Click "Add Product" to create your first.</td></tr>
        <?php endif; ?>
        <?php foreach ($products as $p): ?>
        <tr>
          <td class="ps-3 fw-semibold"><?= h($p['name']) ?></td>
          <td class="small text-muted"><?= h($p['description'] ?: '—') ?></td>
          <td><span class="badge bg-light text-dark"><?= (int)$p['lead_count'] ?></span></td>
          <td>
            <?= $p['is_active']
                ? '<span class="badge bg-success">Active</span>'
                : '<span class="badge bg-secondary">Retired</span>' ?>
          </td>
          <td class="pe-3">
            <button class="btn btn-sm btn-outline-primary btn-edit-prod"
                    data-id="<?= (int)$p['id'] ?>"
                    data-name="<?= h($p['name']) ?>"
                    data-desc="<?= h($p['description'] ?? '') ?>"
                    data-active="<?= (int)$p['is_active'] ?>">
              <i class="bi bi-pencil"></i>
            </button>
            <?php if ($p['is_active']): ?>
            <button class="btn btn-sm btn-outline-danger btn-retire-prod"
                    data-id="<?= (int)$p['id'] ?>"
                    data-name="<?= h($p['name']) ?>">
              <i class="bi bi-x-circle"></i>
            </button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Edit / Create Modal -->
<div class="modal fade" id="prodModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header">
        <h6 class="modal-title fw-bold" id="prodModalTitle">Product</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="prodForm">
        <input type="hidden" name="id" id="prodId" value="0">
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label fw-semibold small">Name *</label>
            <input name="name" id="prodName" class="form-control form-control-sm" required maxlength="150">
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold small">Description</label>
            <textarea name="description" id="prodDesc" rows="2" class="form-control form-control-sm"></textarea>
          </div>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" id="prodActive" name="is_active" checked>
            <label for="prodActive" class="form-check-label small">Active (visible in lead dropdown)</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  var csrf = document.querySelector('meta[name="csrf-token"]').content;
  var modalEl = document.getElementById('prodModal');
  var modal   = modalEl ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;

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
    return fetch('lead_handler.php', { method: 'POST', body: fd }).then(r => r.json());
  }

  document.getElementById('btnAddProduct').addEventListener('click', function () {
    document.getElementById('prodModalTitle').textContent = 'New Product';
    document.getElementById('prodId').value    = '0';
    document.getElementById('prodName').value  = '';
    document.getElementById('prodDesc').value  = '';
    document.getElementById('prodActive').checked = true;
    modal.show();
  });

  document.querySelectorAll('.btn-edit-prod').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('prodModalTitle').textContent = 'Edit Product';
      document.getElementById('prodId').value    = btn.dataset.id;
      document.getElementById('prodName').value  = btn.dataset.name;
      document.getElementById('prodDesc').value  = btn.dataset.desc;
      document.getElementById('prodActive').checked = btn.dataset.active === '1';
      modal.show();
    });
  });

  document.querySelectorAll('.btn-retire-prod').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!confirm('Retire "' + btn.dataset.name + '"? Existing leads keep this product, but it will be hidden from new-lead dropdowns.')) { return; }
      var fd = new FormData();
      fd.append('id', btn.dataset.id);
      post('product_delete', fd).then(function (res) {
        showMsg(res.message, res.success ? 'success' : 'danger');
        if (res.success) { setTimeout(function () { location.reload(); }, 500); }
      });
    });
  });

  document.getElementById('prodForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var fd = new FormData(e.target);
    post('product_save', fd).then(function (res) {
      showMsg(res.message, res.success ? 'success' : 'danger');
      if (res.success) { setTimeout(function () { location.reload(); }, 500); }
    });
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
