<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pdo = getConnection();

$roles = $pdo->query('SELECT id, name, description FROM roles ORDER BY id')->fetchAll();
$permissions = $pdo->query('SELECT id, name, description FROM permissions ORDER BY name')->fetchAll();

$rolePerms = $pdo->query(
    "SELECT rp.role_id, p.name FROM role_permissions rp
     JOIN permissions p ON p.id = rp.permission_id"
)->fetchAll();

$grouped = [];
foreach ($rolePerms as $row) {
    $grouped[$row['role_id']][] = $row['name'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Roles &amp; Permissions — RBAC Console</title>
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
      <div class="breadcrumb"><strong>Roles &amp; Permissions</strong> &middot; <?= count($roles) ?> roles, <?= count($permissions) ?> permissions</div>
    </div>

    <div class="grid grid-4">
      <?php foreach ($roles as $role): ?>
        <div class="card">
          <div class="card-title"><?= htmlspecialchars($role['name']) ?></div>
          <p style="color:var(--muted); font-size:12.5px; margin:0 0 10px;"><?= htmlspecialchars($role['description']) ?></p>
          <?php foreach (($grouped[$role['id']] ?? []) as $perm): ?>
            <span class="badge active" style="margin:2px 4px 2px 0;"><?= htmlspecialchars($perm) ?></span>
          <?php endforeach; ?>
          <?php if (empty($grouped[$role['id']])): ?>
            <span style="color:var(--muted); font-size:12px;">No permissions assigned</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card" style="margin-top:20px;">
      <h3 style="margin-bottom:14px;">All permissions</h3>
      <div class="table-wrap" style="border:none;">
        <table>
          <thead><tr><th>Name</th><th>Description</th></tr></thead>
          <tbody>
            <?php foreach ($permissions as $p): ?>
            <tr>
              <td class="mono"><?= htmlspecialchars($p['name']) ?></td>
              <td style="color:var(--muted)"><?= htmlspecialchars($p['description']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>
</body>
</html>
