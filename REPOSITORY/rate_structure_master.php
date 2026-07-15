<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pdo = getConnection();
$message = '';
$messageType = 'error';

// Whitelist of manageable master lists — table names never come from raw user input.
$masterTypes = [
    'locations'  => ['table' => 'rate_master_locations',  'label' => 'Locations'],
    'priorities' => ['table' => 'rate_master_priorities', 'label' => 'Priorities'],
    'mod_types'  => ['table' => 'rate_master_mod_types',  'label' => 'Mod Types'],
    'units'      => ['table' => 'rate_master_units',      'label' => 'Units (Per)'],
    'rate_for'   => ['table' => 'rate_master_rate_for',   'label' => 'Rate For Categories'],
];

function masterTable(array $masterTypes, string $type): ?string
{
    return $masterTypes[$type]['table'] ?? null;
}

// Add a new master entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_master') {
    if (!canCreate()) {
        $message = 'You do not have permission to add master entries.';
    } else {
        $table = masterTable($masterTypes, $_POST['type'] ?? '');
        $name = trim($_POST['name'] ?? '');
        if ($table && $name !== '') {
            try {
                $stmt = $pdo->prepare("INSERT INTO `$table` (name) VALUES (:name)");
                $stmt->execute(['name' => $name]);
                logAction($_SESSION['user_id'], 'rate_master.add', "Added '$name' to $table");
                $message = 'Entry added.';
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = str_contains($e->getMessage(), 'Duplicate') ? 'That entry already exists.' : 'Failed to add entry.';
            }
        } else {
            $message = 'Name is required.';
        }
    }
}

// Toggle active/inactive
if (isset($_GET['toggle']) && isset($_GET['type'])) {
    if (!canEdit()) {
        $message = 'You do not have permission to change master entries.';
    } else {
        $table = masterTable($masterTypes, $_GET['type']);
        $id = (int)$_GET['toggle'];
        if ($table && $id) {
            $stmt = $pdo->prepare("SELECT status FROM `$table` WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $current = $stmt->fetchColumn();
            if ($current) {
                $new = $current === 'active' ? 'inactive' : 'active';
                $pdo->prepare("UPDATE `$table` SET status = :s WHERE id = :id")->execute(['s' => $new, 'id' => $id]);
                logAction($_SESSION['user_id'], 'rate_master.status_change', "$table #$id set to $new");
            }
            header('Location: rate_structure_master.php');
            exit;
        }
    }
}

// Delete a master entry
if (isset($_GET['delete']) && isset($_GET['type'])) {
    if (!canDelete()) {
        $message = 'Only Super Admin can delete master entries.';
    } else {
        $table = masterTable($masterTypes, $_GET['type']);
        $id = (int)$_GET['delete'];
        if ($table && $id) {
            $pdo->prepare("DELETE FROM `$table` WHERE id = :id")->execute(['id' => $id]);
            logAction($_SESSION['user_id'], 'rate_master.delete', "Deleted #$id from $table");
            header('Location: rate_structure_master.php');
            exit;
        }
    }
}

// Load all lists
$lists = [];
foreach ($masterTypes as $key => $meta) {
    $lists[$key] = $pdo->query("SELECT * FROM `{$meta['table']}` ORDER BY name")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Rate Structure Master — RBAC Console</title>
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
      <div class="breadcrumb"><strong>Rate Structure Master</strong> &middot; lookup values used when building rate structures on offers/contracts</div>
    </div>

    <?php if ($message): ?>
      <div class="error-box" <?= $messageType === 'success' ? 'style="background:var(--cyan-dim); border-color:var(--cyan); color:var(--cyan);"' : '' ?>><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="grid grid-2">
      <?php foreach ($masterTypes as $key => $meta): ?>
      <div class="card" style="margin-bottom:20px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
          <h3 style="margin:0;"><?= htmlspecialchars($meta['label']) ?></h3>
          <span class="mono" style="color:var(--muted); font-size:11px;"><?= count($lists[$key]) ?> entries</span>
        </div>

        <?php if (canCreate()): ?>
        <form method="post" style="display:flex; gap:8px; margin-bottom:14px;">
          <input type="hidden" name="action" value="add_master">
          <input type="hidden" name="type" value="<?= $key ?>">
          <input name="name" placeholder="Add new <?= htmlspecialchars(strtolower(rtrim($meta['label'], 's'))) ?>..." required style="flex:1;">
          <button type="submit" class="btn primary" style="white-space:nowrap;">Add</button>
        </form>
        <?php endif; ?>

        <div class="table-wrap" style="border:none;">
          <table>
            <tbody>
              <?php foreach ($lists[$key] as $item): ?>
              <tr>
                <td><?= htmlspecialchars($item['name']) ?></td>
                <td style="width:90px;"><span class="badge <?= $item['status'] ?>"><?= htmlspecialchars($item['status']) ?></span></td>
                <td style="width:140px; text-align:right;">
                  <?php if (canEdit()): ?>
                  <a href="rate_structure_master.php?toggle=<?= $item['id'] ?>&type=<?= $key ?>"
                     style="color:<?= $item['status'] === 'active' ? 'var(--amber)' : 'var(--cyan)' ?>; font-size:12px; margin-right:10px;">
                    <?= $item['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                  </a>
                  <?php endif; ?>
                  <?php if (canDelete()): ?>
                  <a href="rate_structure_master.php?delete=<?= $item['id'] ?>&type=<?= $key ?>"
                     onclick="return confirm('Delete this entry?');"
                     style="color:var(--red); font-size:12px;">Delete</a>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$lists[$key]): ?>
              <tr><td colspan="3" style="color:var(--muted); font-size:12.5px;">No entries yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </main>
</div>
</body>
</html>
