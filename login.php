<?php
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if (!empty($_GET['deactivated'])) {
    $error = 'Your account was deactivated. Contact an administrator.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Enter both email and password.';
    } else {
        try {
            if (attemptLogin($email, $password)) {
                header('Location: dashboard.php');
                exit;
            }
            $error = 'Email or password is incorrect.';
        } catch (PDOException $e) {
            $error = 'Could not reach the database. Check your connection settings.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sign in — RBAC Console</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
  <div class="login-shell">
    <div class="login-card">
      <div class="login-terminal">
        <span class="dot"></span> aareyrgp_claude &mdash; secure session
      </div>
      <h2>Sign in</h2>
      <p style="color:var(--muted); margin:0 0 20px; font-size:13px;">Access the RBAC administration console.</p>

      <?php if ($error): ?>
        <div class="error-box"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post" novalidate>
        <div class="field">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" placeholder="you@aryaoffshore.com" required autofocus>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;" required>
        </div>
        <button type="submit" class="btn primary" style="width:100%; justify-content:center;">Sign in</button>
      </form>

      <div class="login-footer">RBAC CONSOLE &middot; PHP + PDO + MySQL</div>
    </div>
  </div>
</body>
</html>
