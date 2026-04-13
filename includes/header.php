<?php
// includes/header.php — injected at the top of every authenticated page.
// Expects $user (array) and $pageTitle (string) to be defined by the caller.
if (!isset($pageTitle)) { $pageTitle = SITE_NAME; }
$role = $user['role'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle) ?> &mdash; <?= h(SITE_NAME) ?></title>
  <?= csrfMeta() ?>
  <link href="assets/vendor/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons.min.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="hrms-app" data-role="<?= h($role) ?>" data-userid="<?= (int)$user['id'] ?>">

<!-- ── Mobile top-bar ───────────────────────────────────────── -->
<nav class="navbar navbar-dark hrms-topbar d-lg-none px-3">
  <button class="btn btn-sm btn-outline-light me-2"
          type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas">
    <i class="bi bi-list fs-5"></i>
  </button>
  <span class="navbar-brand mb-0 fw-bold small">
    <i class="bi bi-building-fill-check me-1"></i><?= h(SITE_NAME) ?>
  </span>
  <div class="ms-auto d-flex align-items-center gap-2">
    <span class="badge bg-secondary small d-none d-sm-inline"><?= h($user['name']) ?></span>
    <a href="logout.php" class="btn btn-sm btn-outline-danger">
      <i class="bi bi-box-arrow-right"></i>
    </a>
  </div>
</nav>

<!-- ── Offcanvas sidebar (mobile) ──────────────────────────── -->
<div class="offcanvas offcanvas-start hrms-sidebar text-white"
     tabindex="-1" id="sidebarOffcanvas">
  <div class="offcanvas-header border-bottom border-white border-opacity-10">
    <h6 class="offcanvas-title fw-bold">
      <i class="bi bi-building-fill-check me-2"></i><?= h(SITE_NAME) ?>
    </h6>
    <button type="button" class="btn-close btn-close-white"
            data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body p-0">
    <?php include __DIR__ . '/sidebar_nav.php'; ?>
  </div>
</div>

<!-- ── Fixed sidebar (desktop) ─────────────────────────────── -->
<aside class="hrms-sidebar hrms-sidebar-fixed d-none d-lg-flex flex-column text-white">
  <div class="sidebar-brand px-4 py-4 border-bottom border-white border-opacity-10">
    <i class="bi bi-building-fill-check me-2 fs-5"></i>
    <span class="fw-bold"><?= h(SITE_NAME) ?></span>
  </div>
  <?php include __DIR__ . '/sidebar_nav.php'; ?>
</aside>

<!-- ── Main wrapper ─────────────────────────────────────────── -->
<div class="hrms-main">
  <!-- Desktop top-bar -->
  <header class="hrms-header d-none d-lg-flex align-items-center px-4 gap-3">
    <div>
      <h6 class="mb-0 fw-bold text-dark"><?= h($pageTitle) ?></h6>
      <small class="text-muted"><?= date('l, d F Y') ?></small>
    </div>
    <div class="ms-auto d-flex align-items-center gap-3">
      <div class="d-flex align-items-center gap-2">
        <div class="avatar-circle">
          <?= strtoupper(substr($user['name'], 0, 1)) ?>
        </div>
        <div class="lh-sm">
          <div class="fw-semibold small"><?= h($user['name']) ?></div>
          <div class="text-muted" style="font-size:.7rem"><?= ucfirst(str_replace('_',' ',$role)) ?></div>
        </div>
      </div>
      <a href="logout.php" class="btn btn-sm btn-outline-danger">
        <i class="bi bi-box-arrow-right me-1"></i>Logout
      </a>
    </div>
  </header>

  <!-- Page content goes here -->
  <main class="hrms-content p-3 p-md-4">
