<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pdo = getConnection();
$message = '';

// Handle new user creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $first = trim($_POST['first_name'] ?? '');
    $last  = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $roleId = (int)($_POST['role_id'] ?? 0);

    if ($first && $last && $email && $pass) {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare(
            'INSERT INTO users (first_name, last_name, email, password) VALUES (:f, :l, :e, :p)'
        );
        $stmt->execute(['f' => $first, 'l' => $last, 'e' => $email, 'p' => $hash]);
        $newId = (int)$pdo->lastInsertId();

        if ($roleId) {
            $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:u, :r)')
                ->execute(['u' => $newId, 'r' => $roleId]);
        }

        logAction($_SESSION['user_id'], 'user.create', "Created user #$newId ($email)");
        $message = 'User created.';
    }
}

// Handle password reset (Super Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    if (!isSuperAdmin()) {
        $message = 'Only Super Admin can reset passwords.';
    } else {
        $id = (int)($_POST['user_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';

        if ($id && strlen($newPassword) >= 6) {
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            $pdo->prepare('UPDATE users SET password = :p WHERE id = :id')
                ->execute(['p' => $hash, 'id' => $id]);
            logAction($_SESSION['user_id'], 'user.password_reset', "Super Admin reset password for user #$id");
            $message = 'Password reset successfully.';
        } else {
            $message = 'Password must be at least 6 characters.';
        }
    }
}

// Handle status toggle (active <-> inactive)
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];

    $stmt = $pdo->prepare('SELECT status FROM users WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $current = $stmt->fetchColumn();

    if ($current) {
        $new = $current === 'active' ? 'inactive' : 'active';
        $pdo->prepare('UPDATE users SET status = :s WHERE id = :id')
            ->execute(['s' => $new, 'id' => $id]);
        logAction($_SESSION['user_id'], 'user.status_change', "User #$id set to $new");
    }

    header('Location: users.php');
    exit;
}

// Handle delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
    logAction($_SESSION['user_id'], 'user.delete', "Deleted user #$id");
    header('Location: users.php');
    exit;
}

$roles = $pdo->query('SELECT id, name FROM roles ORDER BY name')->fetchAll();

$users = $pdo->query(
    "SELECT u.id, u.first_name, u.last_name, u.email, u.status, u.last_login,
            GROUP_CONCAT(r.name SEPARATOR ', ') AS role_names
     FROM users u
     LEFT JOIN user_roles ur ON ur.user_id = u.id
     LEFT JOIN roles r ON r.id = ur.role_id
     GROUP BY u.id
     ORDER BY u.id"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Users — RBAC Console</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>

  <main class="main">
    <div class="topbar">
      <div class="breadcrumb"><strong>Users</strong> &middot; <?= count($users) ?> total</div>
      <button type="button" class="btn primary" id="toggleNewUser">+ New user</button>
    </div>

    <?php if ($message): ?><div class="error-box" style="background:var(--cyan-dim); border-color:var(--cyan); color:var(--cyan);"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <div class="card" id="newUserForm" style="display:none; margin-bottom:20px;">
      <h3>Create user</h3>
      <form method="post">
        <input type="hidden" name="action" value="create">
        <div class="grid grid-4">
          <div class="field"><label>First name</label><input name="first_name" required></div>
          <div class="field"><label>Last name</label><input name="last_name" required></div>
          <div class="field"><label>Email</label><input type="email" name="email" required></div>
          <div class="field"><label>Password</label><input type="password" name="password" required></div>
        </div>
        <div class="field" style="max-width:240px;">
          <label>Role</label>
          <select name="role_id">
            <?php foreach ($roles as $r): ?>
              <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn primary">Save user</button>
      </form>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr>
            <td><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></td>
            <td class="mono"><?= htmlspecialchars($u['email']) ?></td>
            <td><?= htmlspecialchars($u['role_names'] ?? '—') ?></td>
            <td><span class="badge <?= $u['status'] ?>"><?= htmlspecialchars($u['status']) ?></span></td>
            <td class="mono" style="color:var(--muted)"><?= $u['last_login'] ? htmlspecialchars($u['last_login']) : 'never' ?></td>
            <td>
              <a href="users.php?toggle_status=<?= $u['id'] ?>"
                 onclick="return confirm('<?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?> this user?');"
                 style="color:<?= $u['status'] === 'active' ? 'var(--amber)' : 'var(--cyan)' ?>; margin-right:14px;">
                <?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
              </a>
              <?php if (isSuperAdmin()): ?>
              <a href="#" class="reset-pw-toggle" data-id="<?= $u['id'] ?>" style="color:var(--cyan); margin-right:14px;">Reset Password</a>
              <?php endif; ?>
              <a href="users.php?delete=<?= $u['id'] ?>"
                 onclick="return confirm('Delete this user?');"
                 style="color:var(--red);">Delete</a>
            </td>
          </tr>
          <?php if (isSuperAdmin()): ?>
          <tr id="reset-pw-row-<?= $u['id'] ?>" style="display:none;">
            <td colspan="6" style="background:var(--panel-alt);">
              <form method="post" style="display:flex; align-items:end; gap:12px; padding:10px 0;">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <div class="field" style="margin-bottom:0; max-width:260px;">
                  <label>New password for <?= htmlspecialchars($u['first_name']) ?></label>
                  <input type="password" name="new_password" minlength="6" required placeholder="At least 6 characters">
                </div>
                <button type="submit" class="btn primary">Set Password</button>
              </form>
            </td>
          </tr>
          <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </main>
</div>

<script>
  document.getElementById('toggleNewUser').addEventListener('click', function () {
    var form = document.getElementById('newUserForm');
    form.style.display = (form.style.display === 'none' || !form.style.display) ? 'block' : 'none';
  });

  document.querySelectorAll('.reset-pw-toggle').forEach(function (link) {
    link.addEventListener('click', function (e) {
      e.preventDefault();
      var row = document.getElementById('reset-pw-row-' + this.dataset.id);
      row.style.display = (row.style.display === 'none' || !row.style.display) ? 'table-row' : 'none';
    });
  });
</script>
</body>
</html>
