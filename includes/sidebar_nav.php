<?php
// includes/sidebar_nav.php — shared nav links (used by both fixed sidebar and offcanvas).
$currentPage = basename($_SERVER['PHP_SELF']);
$role        = $user['role'];

$navItems = [
    ['href' => 'dashboard.php',   'icon' => 'bi-speedometer2',    'label' => 'Dashboard',
     'roles' => ['admin','office_staff','field_worker']],
    ['href' => 'tasks.php',            'icon' => 'bi-list-task',        'label' => 'Task Manager',
     'roles' => ['admin']],
    ['href' => 'admin_task_history.php','icon'=> 'bi-clock-history',    'label' => 'Task History',
     'roles' => ['admin']],
    ['href' => 'leave_module.php','icon' => 'bi-calendar-check',  'label' => 'Leave Requests',
     'roles' => ['admin','office_staff','field_worker']],
    ['href' => 'admin_reports.php','icon'=> 'bi-bar-chart-line',  'label' => 'Attendance Reports',
     'roles' => ['admin']],
    ['href' => 'admin_users.php',     'icon' => 'bi-people-fill',     'label' => 'User Management',
     'roles' => ['admin']],
    ['href' => 'admin_locations.php', 'icon' => 'bi-geo-alt-fill',   'label' => 'Location Tracker',
     'roles' => ['admin']],
    ['href' => 'kpi_dashboard.php',   'icon' => 'bi-bar-chart-fill', 'label' => 'KPI Dashboard',
     'roles' => ['admin']],
    ['href' => 'data_purge.php',      'icon' => 'bi-trash3',         'label' => 'Data Maintenance',
     'roles' => ['admin']],
];
?>
<!-- User profile pill -->
<div class="px-3 py-3 border-bottom border-white border-opacity-10">
  <div class="d-flex align-items-center gap-2">
    <div class="avatar-circle avatar-sm flex-shrink-0">
      <?= strtoupper(substr($user['name'], 0, 1)) ?>
    </div>
    <div class="lh-sm overflow-hidden">
      <div class="fw-semibold small text-truncate"><?= h($user['name']) ?></div>
      <div class="opacity-60" style="font-size:.7rem">
        <?= ucfirst(str_replace('_', ' ', $role)) ?>
      </div>
    </div>
  </div>
</div>

<!-- Nav links -->
<nav class="nav flex-column px-2 py-2 flex-grow-1">
  <?php foreach ($navItems as $item): ?>
    <?php if (!in_array($role, $item['roles'], true)) continue; ?>
    <a href="<?= h($item['href']) ?>"
       class="nav-link sidebar-link d-flex align-items-center gap-2 rounded-3 px-3 py-2 mb-1
              <?= ($currentPage === $item['href']) ? 'active' : '' ?>">
      <i class="bi <?= $item['icon'] ?> flex-shrink-0"></i>
      <span><?= h($item['label']) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<!-- Bottom logout -->
<div class="px-3 py-3 border-top border-white border-opacity-10 mt-auto">
  <a href="logout.php" class="btn btn-sm btn-outline-danger w-100">
    <i class="bi bi-box-arrow-right me-2"></i>Sign Out
  </a>
</div>
