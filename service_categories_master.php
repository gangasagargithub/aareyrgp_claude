<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pdo = getConnection();
$message = '';
$messageType = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_category') {
    if (!canCreate()) {
        $message = 'You do not have permission to add service categories.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($name !== '') {
            try {
                $stmt = $pdo->prepare('INSERT INTO service_categories (name, description) VALUES (:n, :d)');
                $stmt->execute(['n' => $name, 'd' => $desc ?: null]);
                logAction($_SESSION['user_id'], 'service_category.add', "Added service category '$name'");
                $message = 'Service category added.';
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = str_contains($e->getMessage(), 'Duplicate') ? 'That category already exists.' : 'Failed to add category.';
            }
        } else {
            $message = 'Name is required.';
        }
    }
}

if (isset($_GET['toggle'])) {
    if (!canEdit()) {
        $message = 'You do not have permission to change service categories.';
    } else {
        $id = (int)$_GET['toggle'];
        $stmt = $pdo->prepare('SELECT status FROM service_categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $current = $stmt->fetchColumn();
        if ($current) {
            $new = $current === 'active' ? 'inactive' : 'active';
            $pdo->prepare('UPDATE service_categories SET status = :s WHERE id = :id')->execute(['s' => $new, 'id' => $id]);
            logAction($_SESSION['user_id'], 'service_category.status_change', "Category #$id set to $new");
        }
        header('Location: service_categories_master.php');
        exit;
    }
}

if (isset($_GET['delete'])) {
    if (!canDelete()) {
        $message = 'Only Super Admin can delete service categories.';
    } else {
        $id = (int)$_GET['delete'];
        $pdo->prepare('DELETE FROM service_categories WHERE id = :id')->execute(['id' => $id]);
        logAction($_SESSION['user_id'], 'service_category.delete', "Deleted category #$id");
        header('Location: service_categories_master.php');
        exit;
    }
}

$categories = $pdo->query(
    "SELECT sc.*, (SELECT COUNT(*) FROM user_service_categories usc WHERE usc.service_category_id = sc.id) AS user_count
     FROM service_categories sc ORDER BY sc.name"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Service Categories — RBAC Console</title>
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
      <div class="breadcrumb"><strong>Service Categories</strong> &middot; operational departments jobs can be assigned to</div>
    </div>

    <?php if ($message): ?>
      <div class="error-box" <?= $messageType === 'success' ? 'style="background:var(--cyan-dim); border-color:var(--cyan); color:var(--cyan);"' : '' ?>><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if (canCreate()): ?>
    <div class="card" style="margin-bottom:20px;">
      <h3>Add Service Category</h3>
      <form method="post" style="display:flex; gap:12px; align-items:flex-end;">
        <input type="hidden" name="action" value="add_category">
        <div class="field" style="margin-bottom:0; flex:1;"><label>Name</label><input name="name" required placeholder="e.g. Port Operation"></div>
        <div class="field" style="margin-bottom:0; flex:2;"><label>Description</label><input name="description" placeholder="Optional"></div>
        <button type="submit" class="btn primary">Add</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="table-wrap">
      <table>
        <thead><tr><th>Name</th><th>Description</th><th>Users</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($categories as $cat): ?>
          <tr>
            <td><?= htmlspecialchars($cat['name']) ?></td>
            <td style="color:var(--muted)"><?= htmlspecialchars($cat['description'] ?? '—') ?></td>
            <td class="mono"><?= (int)$cat['user_count'] ?></td>
            <td><span class="badge <?= $cat['status'] ?>"><?= htmlspecialchars($cat['status']) ?></span></td>
            <td>
              <?php if (canEdit()): ?>
              <a href="service_categories_master.php?toggle=<?= $cat['id'] ?>" style="color:<?= $cat['status'] === 'active' ? 'var(--amber)' : 'var(--cyan)' ?>; font-size:12px; margin-right:10px;">
                <?= $cat['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
              </a>
              <?php endif; ?>
              <?php if (canDelete()): ?>
              <a href="service_categories_master.php?delete=<?= $cat['id'] ?>" onclick="return confirm('Delete this category?');" style="color:var(--red); font-size:12px;">Delete</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$categories): ?><tr><td colspan="5" style="color:var(--muted)">No service categories yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <p style="color:var(--muted); font-size:12px; margin-top:14px;">To assign a user to a category, edit them from the <a href="users.php">Users</a> page.</p>
  </main>
</div>
</body>
</html>
