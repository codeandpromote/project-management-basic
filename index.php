<?php
// ============================================================
//  HRMS · Login Page
// ============================================================
require_once __DIR__ . '/auth.php';
startSession();

// Already logged in → go to dashboard
if (!empty($_SESSION['user_id'])) {
    redirect('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch. Please refresh and try again.';
    } else {
        $result = attemptLogin(
            $_POST['email']    ?? '',
            $_POST['password'] ?? ''
        );
        if ($result['success']) {
            redirect('dashboard.php');
        }
        $error = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= SITE_NAME ?> &mdash; Sign In</title>
  <?= csrfMeta() ?>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="hrms-login-bg">

<div class="d-flex align-items-center justify-content-center min-vh-100 p-3">
  <div class="card login-card border-0 shadow-lg w-100" style="max-width:420px">
    <div class="card-body p-5">

      <!-- Logo -->
      <div class="text-center mb-4">
        <div class="login-logo d-inline-flex align-items-center justify-content-center rounded-4 mb-3">
          <i class="bi bi-building-fill-check fs-1 text-white"></i>
        </div>
        <h4 class="fw-bold mb-0"><?= h(SITE_NAME) ?></h4>
        <p class="text-muted small mb-0">Workforce Management Platform</p>
      </div>

      <!-- Error alert -->
      <?php if ($error): ?>
      <div class="alert alert-danger alert-dismissible d-flex align-items-start gap-2 py-2 mb-3" role="alert">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
        <div class="small"><?= h($error) ?></div>
        <button type="button" class="btn-close btn-close-sm ms-auto" data-bs-dismiss="alert"></button>
      </div>
      <?php endif; ?>

      <!-- Login form -->
      <form method="POST" action="index.php" autocomplete="on" novalidate>
        <?= csrfField() ?>

        <div class="mb-3">
          <label for="email" class="form-label fw-semibold small">Email address</label>
          <div class="input-group">
            <span class="input-group-text bg-light border-end-0">
              <i class="bi bi-envelope text-muted"></i>
            </span>
            <input type="email" class="form-control border-start-0 ps-0"
                   id="email" name="email"
                   value="<?= h($_POST['email'] ?? '') ?>"
                   placeholder="you@company.com"
                   required autofocus autocomplete="username">
          </div>
        </div>

        <div class="mb-4">
          <label for="password" class="form-label fw-semibold small">Password</label>
          <div class="input-group">
            <span class="input-group-text bg-light border-end-0">
              <i class="bi bi-lock text-muted"></i>
            </span>
            <input type="password" class="form-control border-start-0 border-end-0 ps-0"
                   id="password" name="password"
                   placeholder="••••••••" required autocomplete="current-password">
            <button class="btn btn-outline-secondary border-start-0" type="button" id="togglePwd"
                    title="Toggle password visibility">
              <i class="bi bi-eye" id="eyeIcon"></i>
            </button>
          </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 fw-semibold py-2">
          <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
        </button>
      </form>

      <hr class="my-4">
      <p class="text-center text-muted" style="font-size:.75rem">
        &copy; <?= date('Y') ?> <?= h(SITE_NAME) ?>. All rights reserved.
      </p>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const pwdInput = document.getElementById('password');
  const eyeIcon  = document.getElementById('eyeIcon');
  document.getElementById('togglePwd').addEventListener('click', () => {
    const isText = pwdInput.type === 'text';
    pwdInput.type = isText ? 'password' : 'text';
    eyeIcon.className = isText ? 'bi bi-eye' : 'bi bi-eye-slash';
  });
</script>
</body>
</html>
