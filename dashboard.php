<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pdo = getConnection();

$totalUsers    = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$activeUsers   = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
$totalRoles    = $pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
$recentEvents  = $pdo->query(
    "SELECT a.action, a.details, a.created_at, u.first_name, u.last_name
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.created_at DESC LIMIT 8"
)->fetchAll();

$dbStatus = 'connected';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Dashboard — RBAC Console</title>
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
      <div class="breadcrumb"><strong>Dashboard</strong> &middot; overview</div>
      <div class="user-chip">
        <div class="avatar"><?= htmlspecialchars(strtoupper(substr($_SESSION['first_name'],0,1).substr($_SESSION['last_name'],0,1))) ?></div>
        <?= htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']) ?>
      </div>
    </div>

    <div class="status-strip">
      <div class="status-item"><span class="dot"></span> DB: <span class="val"><?= $dbStatus ?></span></div>
      <div class="status-item"><span class="dot"></span> Schema: <span class="val">aareyrgp_claude</span></div>
      <div class="status-item"><span class="dot amber"></span> Session: <span class="val"><?= session_id() ? substr(session_id(),0,10) : 'n/a' ?>&hellip;</span></div>
      <div class="status-item"><span class="dot"></span> Server time: <span class="val"><?= date('Y-m-d H:i:s') ?></span></div>
    </div>

    <div class="grid grid-4" style="margin-bottom:24px;">
      <div class="card">
        <div class="card-title">Total Users</div>
        <div class="stat-value"><?= (int)$totalUsers ?></div>
      </div>
      <div class="card">
        <div class="card-title">Active Users</div>
        <div class="stat-value cyan"><?= (int)$activeUsers ?></div>
      </div>
      <div class="card">
        <div class="card-title">Roles Defined</div>
        <div class="stat-value"><?= (int)$totalRoles ?></div>
      </div>
      <div class="card">
        <div class="card-title">Your Role</div>
        <div class="stat-value amber" style="font-size:18px;"><?= htmlspecialchars(implode(', ', $_SESSION['roles'] ?? ['—'])) ?></div>
      </div>
    </div>

    <div class="card">
      <h3 style="margin-bottom:14px;">Recent activity</h3>
      <div class="table-wrap" style="border:none;">
        <table>
          <thead>
            <tr><th>User</th><th>Action</th><th>Details</th><th>Time</th></tr>
          </thead>
          <tbody>
            <?php foreach ($recentEvents as $e): ?>
            <tr>
              <td><?= htmlspecialchars(trim(($e['first_name'] ?? 'System') . ' ' . ($e['last_name'] ?? ''))) ?></td>
              <td class="mono"><?= htmlspecialchars($e['action']) ?></td>
              <td style="color:var(--muted)"><?= htmlspecialchars($e['details'] ?? '—') ?></td>
              <td class="mono" style="color:var(--muted)"><?= htmlspecialchars($e['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$recentEvents): ?>
            <tr><td colspan="4" style="color:var(--muted)">No activity recorded yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>
</body>
</html>
