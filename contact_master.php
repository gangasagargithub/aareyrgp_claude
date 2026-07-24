<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pdo = getConnection();
$message = '';
$messageType = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_contact_master') {
    if (!canCreate()) {
        $message = 'You do not have permission to add master contacts.';
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO contact_master
                (contact_name, nick_name, designation, address_line1, address_line2, address_line3,
                 country, state, city, zip_code, phone1, phone2, fax1, fax2, email, web_url)
             VALUES
                (:name, :nick, :desig, :l1, :l2, :l3, :country, :state, :city, :zip, :p1, :p2, :f1, :f2, :email, :web)"
        );
        $stmt->execute([
            'name' => trim($_POST['contact_name']),
            'nick' => $_POST['nick_name'] ?: null,
            'desig' => $_POST['designation'] ?: null,
            'l1' => $_POST['address_line1'] ?: null,
            'l2' => $_POST['address_line2'] ?: null,
            'l3' => $_POST['address_line3'] ?: null,
            'country' => $_POST['country'] ?: null,
            'state' => $_POST['state'] ?: null,
            'city' => $_POST['city'] ?: null,
            'zip' => $_POST['zip_code'] ?: null,
            'p1' => $_POST['phone1'] ?: null,
            'p2' => $_POST['phone2'] ?: null,
            'f1' => $_POST['fax1'] ?: null,
            'f2' => $_POST['fax2'] ?: null,
            'email' => $_POST['email'] ?: null,
            'web' => $_POST['web_url'] ?: null,
        ]);
        $newId = (int)$pdo->lastInsertId();
        logAction($_SESSION['user_id'], 'contact_master.create', "Added master contact #$newId ({$_POST['contact_name']})");
        $message = 'Contact added to master.';
        $messageType = 'success';
    }
}

if (isset($_GET['toggle'])) {
    if (!canEdit()) {
        $message = 'You do not have permission to change master contacts.';
    } else {
        $id = (int)$_GET['toggle'];
        $stmt = $pdo->prepare('SELECT status FROM contact_master WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $current = $stmt->fetchColumn();
        if ($current) {
            $new = $current === 'active' ? 'inactive' : 'active';
            $pdo->prepare('UPDATE contact_master SET status = :s WHERE id = :id')->execute(['s' => $new, 'id' => $id]);
            logAction($_SESSION['user_id'], 'contact_master.status_change', "Master contact #$id set to $new");
        }
        header('Location: contact_master.php');
        exit;
    }
}

if (isset($_GET['delete'])) {
    if (!canDelete()) {
        $message = 'Only Super Admin can delete master contacts.';
    } else {
        $id = (int)$_GET['delete'];
        $pdo->prepare('DELETE FROM contact_master WHERE id = :id')->execute(['id' => $id]);
        logAction($_SESSION['user_id'], 'contact_master.delete', "Deleted master contact #$id");
        header('Location: contact_master.php');
        exit;
    }
}

$contacts = $pdo->query(
    "SELECT cm.*,
        (SELECT COUNT(*) FROM customer_contacts cc WHERE cc.contact_master_id = cm.id) AS linked_customers
     FROM contact_master cm ORDER BY cm.contact_name"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Contact Master — WORKFLOW</title>
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
      <div class="breadcrumb"><strong>Contact Master</strong> &middot; <?= count($contacts) ?> contacts &middot; reusable directory of people, separate from system login users</div>
      <?php if (canCreate()): ?>
      <button type="button" class="btn primary" id="toggleNewContact">+ New Master Contact</button>
      <?php endif; ?>
    </div>

    <?php if ($message): ?>
      <div class="error-box" <?= $messageType === 'success' ? 'style="background:var(--cyan-dim); border-color:var(--cyan); color:var(--cyan);"' : '' ?>><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if (canCreate()): ?>
    <div class="card" id="newContactForm" style="display:none; margin-bottom:20px;">
      <h3>New Master Contact</h3>
      <p style="color:var(--muted); font-size:12.5px; margin-top:-8px;">Create a reusable contact person here, then fetch it directly when adding a contact to any customer — no retyping.</p>
      <form method="post">
        <input type="hidden" name="action" value="create_contact_master">
        <div class="grid grid-4">
          <div class="field"><label>Contact Name *</label><input name="contact_name" required></div>
          <div class="field"><label>Nick Name</label><input name="nick_name"></div>
          <div class="field"><label>Designation</label><input name="designation"></div>
          <div class="field"><label>Email ID</label><input type="email" name="email"></div>
        </div>
        <div class="grid grid-4">
          <div class="field" style="grid-column: span 2;"><label>Address Line 1</label><input name="address_line1"></div>
          <div class="field"><label>Country</label><input name="country"></div>
          <div class="field"><label>State</label><input name="state"></div>
        </div>
        <div class="grid grid-4">
          <div class="field" style="grid-column: span 2;"><label>Address Line 2</label><input name="address_line2"></div>
          <div class="field"><label>City</label><input name="city"></div>
          <div class="field"><label>Zip Code</label><input name="zip_code"></div>
        </div>
        <div class="grid grid-4">
          <div class="field"><label>Address Line 3</label><input name="address_line3"></div>
          <div class="field"><label>Phone1</label><input name="phone1"></div>
          <div class="field"><label>Phone2</label><input name="phone2"></div>
          <div class="field"><label>Web URL</label><input name="web_url" placeholder="http://www."></div>
        </div>
        <div class="grid grid-4">
          <div class="field"><label>Fax1</label><input name="fax1"></div>
          <div class="field"><label>Fax2</label><input name="fax2"></div>
        </div>
        <button type="submit" class="btn primary">Save to Master</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Name</th><th>Designation</th><th>Email</th><th>Phone</th><th>Linked to</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($contacts as $c): ?>
          <tr>
            <td><?= htmlspecialchars($c['contact_name']) ?><?= $c['nick_name'] ? ' <span style="color:var(--muted); font-size:12px;">(' . htmlspecialchars($c['nick_name']) . ')</span>' : '' ?></td>
            <td style="color:var(--muted)"><?= htmlspecialchars($c['designation'] ?? '—') ?></td>
            <td class="mono"><?= htmlspecialchars($c['email'] ?? '—') ?></td>
            <td class="mono"><?= htmlspecialchars($c['phone1'] ?? '—') ?></td>
            <td class="mono" style="color:var(--muted)"><?= (int)$c['linked_customers'] ?> customer<?= $c['linked_customers'] == 1 ? '' : 's' ?></td>
            <td><span class="badge <?= $c['status'] ?>"><?= htmlspecialchars($c['status']) ?></span></td>
            <td>
              <?php if (canEdit()): ?>
              <a href="contact_master.php?toggle=<?= $c['id'] ?>" style="color:<?= $c['status'] === 'active' ? 'var(--amber)' : 'var(--cyan)' ?>; margin-right:10px; font-size:12px;">
                <?= $c['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
              </a>
              <?php endif; ?>
              <?php if (canDelete()): ?>
              <a href="contact_master.php?delete=<?= $c['id'] ?>" onclick="return confirm('Delete this master contact? Customers already linked to it will keep their own copy of the details.');" style="color:var(--red); font-size:12px;">Delete</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$contacts): ?>
          <tr><td colspan="7" style="color:var(--muted)">No master contacts yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>
</div>

<script>
  var toggleBtn = document.getElementById('toggleNewContact');
  if (toggleBtn) {
    toggleBtn.addEventListener('click', function () {
      var form = document.getElementById('newContactForm');
      form.style.display = (form.style.display === 'none' || !form.style.display) ? 'block' : 'none';
    });
  }
</script>
</body>
</html>
