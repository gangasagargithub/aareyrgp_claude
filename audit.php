<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pdo = getConnection();

$logs = $pdo->query(
    "SELECT a.id, a.action, a.details, a.ip_address, a.created_at,
            u.first_name, u.last_name
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.created_at DESC
     LIMIT 200"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Audit Log — RBAC Console</title>
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
      <div class="breadcrumb"><strong>Audit Log</strong> &middot; last <?= count($logs) ?> events</div>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>#</th><th>User</th><th>Action</th><th>Details</th><th>IP</th><th>Time</th></tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $log): ?>
          <tr>
            <td class="mono" style="color:var(--muted)">#<?= $log['id'] ?></td>
            <td><?= htmlspecialchars(trim(($log['first_name'] ?? 'System') . ' ' . ($log['last_name'] ?? ''))) ?></td>
            <td class="mono"><?= htmlspecialchars($log['action']) ?></td>
            <td style="color:var(--muted)"><?= htmlspecialchars($log['details'] ?? '—') ?></td>
            <td class="mono" style="color:var(--muted)"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
            <td class="mono" style="color:var(--muted)"><?= htmlspecialchars($log['created_at']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$logs): ?>
          <tr><td colspan="6" style="color:var(--muted)">No audit events yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>
</div>
</body>
</html>
