<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pdo = getConnection();
$customerId = (int)($_GET['id'] ?? 0);
$message = '';
$messageType = 'error';

$stmt = $pdo->prepare('SELECT * FROM customers WHERE id = :id');
$stmt->execute(['id' => $customerId]);
$customer = $stmt->fetch();

if (!$customer) {
    header('Location: customers.php');
    exit;
}

$canWrite = canCreate();

// Link an existing Contact Master entry directly to this customer (no retyping)
if ($canWrite && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'link_master_contact') {
    $masterId = (int)($_POST['contact_master_id'] ?? 0);
    if ($masterId) {
        $master = $pdo->prepare('SELECT * FROM contact_master WHERE id = :id');
        $master->execute(['id' => $masterId]);
        $m = $master->fetch();
        if ($m) {
            $stmt = $pdo->prepare(
                "INSERT INTO customer_contacts
                    (customer_id, contact_master_id, contact_name, nick_name, designation, contact_type,
                     address_line1, address_line2, address_line3, country, state, city, zip_code,
                     phone1, phone2, fax1, fax2, email, web_url)
                 VALUES
                    (:cid, :mid, :name, :nick, :desig, :ctype,
                     :l1, :l2, :l3, :country, :state, :city, :zip,
                     :p1, :p2, :f1, :f2, :email, :web)"
            );
            $stmt->execute([
                'cid' => $customerId, 'mid' => $masterId,
                'name' => $m['contact_name'], 'nick' => $m['nick_name'], 'desig' => $m['designation'], 'ctype' => 'General',
                'l1' => $m['address_line1'], 'l2' => $m['address_line2'], 'l3' => $m['address_line3'],
                'country' => $m['country'], 'state' => $m['state'], 'city' => $m['city'], 'zip' => $m['zip_code'],
                'p1' => $m['phone1'], 'p2' => $m['phone2'], 'f1' => $m['fax1'], 'f2' => $m['fax2'],
                'email' => $m['email'], 'web' => $m['web_url'],
            ]);
            logAction($_SESSION['user_id'], 'customer.contact_link_master', "Linked master contact #$masterId to customer #$customerId");
            header("Location: customer_view.php?id=$customerId");
            exit;
        }
    }
    $message = 'Select a contact to fetch from the master list.';
}

// Add contact person manually (Step 1: Add Contact Person Details For Billing)
if ($canWrite && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_contact') {
    $pdo->beginTransaction();
    try {
        $masterId = null;

        // Optionally also save this new contact into the master directory for future reuse
        if (!empty($_POST['save_to_master'])) {
            $m = $pdo->prepare(
                "INSERT INTO contact_master
                    (contact_name, nick_name, designation, address_line1, address_line2, address_line3,
                     country, state, city, zip_code, phone1, phone2, fax1, fax2, email, web_url)
                 VALUES
                    (:name, :nick, :desig, :l1, :l2, :l3, :country, :state, :city, :zip, :p1, :p2, :f1, :f2, :email, :web)"
            );
            $m->execute([
                'name' => $_POST['contact_name'], 'nick' => $_POST['nick_name'] ?: null, 'desig' => $_POST['designation'] ?: null,
                'l1' => $_POST['address_line1'], 'l2' => $_POST['address_line2'] ?: null, 'l3' => $_POST['address_line3'] ?: null,
                'country' => $_POST['country'], 'state' => $_POST['state'], 'city' => $_POST['city'], 'zip' => $_POST['zip_code'],
                'p1' => $_POST['phone1'] ?: null, 'p2' => $_POST['phone2'] ?: null,
                'f1' => $_POST['fax1'] ?: null, 'f2' => $_POST['fax2'] ?: null,
                'email' => $_POST['email'], 'web' => $_POST['web_url'] ?: null,
            ]);
            $masterId = (int)$pdo->lastInsertId();
        }

        $stmt = $pdo->prepare(
            "INSERT INTO customer_contacts
                (customer_id, contact_master_id, contact_name, nick_name, designation, contact_type,
                 address_line1, address_line2, address_line3, country, state, city, zip_code,
                 phone1, phone2, fax1, fax2, email, web_url)
             VALUES
                (:cid, :mid, :name, :nick, :desig, :ctype,
                 :l1, :l2, :l3, :country, :state, :city, :zip,
                 :p1, :p2, :f1, :f2, :email, :web)"
        );
        $stmt->execute([
            'cid' => $customerId, 'mid' => $masterId,
            'name' => $_POST['contact_name'], 'nick' => $_POST['nick_name'] ?: null,
            'desig' => $_POST['designation'], 'ctype' => $_POST['contact_type'],
            'l1' => $_POST['address_line1'], 'l2' => $_POST['address_line2'] ?: null, 'l3' => $_POST['address_line3'] ?: null,
            'country' => $_POST['country'], 'state' => $_POST['state'], 'city' => $_POST['city'], 'zip' => $_POST['zip_code'],
            'p1' => $_POST['phone1'] ?: null, 'p2' => $_POST['phone2'] ?: null,
            'f1' => $_POST['fax1'] ?: null, 'f2' => $_POST['fax2'] ?: null,
            'email' => $_POST['email'], 'web' => $_POST['web_url'] ?: null,
        ]);

        $pdo->commit();
        logAction($_SESSION['user_id'], 'customer.contact_add', "Added contact to customer #$customerId" . ($masterId ? " (saved to master #$masterId)" : ''));
        header("Location: customer_view.php?id=$customerId");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = 'Failed to add contact: ' . $e->getMessage();
    }
}

// Add billing address (Step 2: Customer Billing Address Mapping)
if ($canWrite && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_billing') {
    $stmt = $pdo->prepare(
        "INSERT INTO customer_billing_addresses
            (customer_id, address_code, address_line1, address_line2, address_line3, address_description,
             country, state, city, zip_code, phone1, phone2, fax1, fax2, email, web_url, gstin)
         VALUES
            (:cid, :code, :l1, :l2, :l3, :desc,
             :country, :state, :city, :zip, :p1, :p2, :f1, :f2, :email, :web, :gstin)"
    );
    $stmt->execute([
        'cid' => $customerId, 'code' => $_POST['address_code'],
        'l1' => $_POST['address_line1'], 'l2' => $_POST['address_line2'] ?: null, 'l3' => $_POST['address_line3'] ?: null,
        'desc' => $_POST['address_description'],
        'country' => $_POST['country'], 'state' => $_POST['state'], 'city' => $_POST['city'], 'zip' => $_POST['zip_code'],
        'p1' => $_POST['phone1'] ?: null, 'p2' => $_POST['phone2'] ?: null,
        'f1' => $_POST['fax1'] ?: null, 'f2' => $_POST['fax2'] ?: null,
        'email' => $_POST['email'], 'web' => $_POST['web_url'] ?: null, 'gstin' => $_POST['gstin'] ?: null,
    ]);
    logAction($_SESSION['user_id'], 'customer.billing_add', "Added billing address to customer #$customerId");
    header("Location: customer_view.php?id=$customerId");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['link_master_contact', 'add_contact', 'add_billing'], true) && !$canWrite) {
    $message = 'You do not have permission to add contacts or billing addresses.';
}

$addresses = $pdo->prepare('SELECT * FROM customer_addresses WHERE customer_id = :id');
$addresses->execute(['id' => $customerId]);
$addresses = $addresses->fetchAll();

$contacts = $pdo->prepare(
    "SELECT cc.*, cm.contact_name AS master_name FROM customer_contacts cc
     LEFT JOIN contact_master cm ON cm.id = cc.contact_master_id
     WHERE cc.customer_id = :id ORDER BY cc.id DESC"
);
$contacts->execute(['id' => $customerId]);
$contacts = $contacts->fetchAll();

// Master contacts not yet linked to this customer, for the "fetch" dropdown
$linkedMasterIds = array_filter(array_column($contacts, 'contact_master_id'));
$masterContacts = $pdo->query("SELECT id, contact_name, nick_name, designation, email FROM contact_master WHERE status = 'active' ORDER BY contact_name")->fetchAll();

$billingAddresses = $pdo->prepare('SELECT * FROM customer_billing_addresses WHERE customer_id = :id ORDER BY id DESC');
$billingAddresses->execute(['id' => $customerId]);
$billingAddresses = $billingAddresses->fetchAll();

$contracts = $pdo->prepare('SELECT * FROM contracts WHERE customer_id = :id ORDER BY id DESC');
$contracts->execute(['id' => $customerId]);
$contracts = $contracts->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($customer['customer_name']) ?> — WORKFLOW</title>
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
      <div class="breadcrumb"><a href="customers.php">Customers</a> &middot; <strong><?= htmlspecialchars($customer['customer_name']) ?></strong></div>
      <?php if (canCreate()): ?>
      <a href="contracts.php?new_for=<?= $customerId ?>" class="btn primary">+ Create Offer / Contract</a>
      <?php endif; ?>
    </div>

    <?php if ($message): ?>
      <div class="error-box" <?= $messageType === 'success' ? 'style="background:var(--cyan-dim); border-color:var(--cyan); color:var(--cyan);"' : '' ?>><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="grid grid-4" style="margin-bottom:20px;">
      <div class="card"><div class="card-title">Customer Code</div><div class="stat-value mono" style="font-size:18px;"><?= htmlspecialchars($customer['customer_code']) ?></div></div>
      <div class="card"><div class="card-title">Abbreviation</div><div class="stat-value mono" style="font-size:18px;"><?= htmlspecialchars($customer['customer_abbreviation']) ?></div></div>
      <div class="card"><div class="card-title">Company Type</div><div class="stat-value" style="font-size:18px;"><?= htmlspecialchars($customer['company_type'] ?? '—') ?></div></div>
      <div class="card"><div class="card-title">Status</div><div><span class="badge <?= $customer['status'] ?>" style="font-size:13px;"><?= htmlspecialchars($customer['status']) ?></span></div></div>
    </div>

    <!-- Office Addresses -->
    <div class="card" style="margin-bottom:20px;">
      <h3>Office Addresses</h3>
      <div class="table-wrap" style="border:none;">
        <table>
          <thead><tr><th>Type</th><th>Address</th><th>City / State</th><th>Email</th><th>Billing?</th></tr></thead>
          <tbody>
            <?php foreach ($addresses as $a): ?>
            <tr>
              <td style="text-transform:capitalize;"><?= htmlspecialchars($a['office_type']) ?></td>
              <td><?= htmlspecialchars($a['address_line1']) ?></td>
              <td style="color:var(--muted)"><?= htmlspecialchars($a['city'] . ', ' . $a['state']) ?></td>
              <td class="mono"><?= htmlspecialchars($a['email'] ?? '—') ?></td>
              <td><?= $a['is_default_billing'] ? '<span class="badge active">Yes</span>' : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$addresses): ?><tr><td colspan="5" style="color:var(--muted)">No office addresses.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Contact Persons -->
    <div class="card" style="margin-bottom:20px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <h3>Contact Persons (Billing)</h3>
        <?php if ($canWrite): ?>
        <button type="button" class="btn primary" id="toggleContactForm">+ Add Contact Details</button>
        <?php endif; ?>
      </div>

      <?php if ($canWrite): ?>
      <!-- Fetch from Contact Master — reusable directory, separate from system login users -->
      <div style="margin-top:14px; border-top:1px solid var(--border); padding-top:14px;">
        <form method="post" style="display:flex; gap:10px; align-items:end;">
          <input type="hidden" name="action" value="link_master_contact">
          <div class="field" style="flex:1; margin-bottom:0;">
            <label>Fetch existing contact from Contact Master</label>
            <select name="contact_master_id">
              <option value="">-- Select a master contact --</option>
              <?php foreach ($masterContacts as $mc): ?>
                <option value="<?= $mc['id'] ?>">
                  <?= htmlspecialchars($mc['contact_name']) ?><?= $mc['designation'] ? ' — ' . htmlspecialchars($mc['designation']) : '' ?><?= $mc['email'] ? ' (' . htmlspecialchars($mc['email']) . ')' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn">Fetch &amp; Link</button>
        </form>
        <p style="color:var(--muted); font-size:11px; margin:8px 0 0;">
          Don't see who you need? <a href="contact_master.php">Add them to Contact Master</a> first, or use "+ Add Contact Details" below for a one-off entry.
        </p>
      </div>
      <?php endif; ?>

      <?php if ($canWrite): ?>
      <div id="contactForm" style="display:none; margin-top:14px; border-top:1px solid var(--border); padding-top:16px;">
        <form method="post">
          <input type="hidden" name="action" value="add_contact">
          <div class="grid grid-4">
            <div class="field"><label>Contact Name *</label><input name="contact_name" required></div>
            <div class="field"><label>Nick Name</label><input name="nick_name"></div>
            <div class="field"><label>Designation *</label><input name="designation" required></div>
            <div class="field">
              <label>Contact Type *</label>
              <select name="contact_type" required>
                <option value="">-- Select --</option>
                <option>Billing</option>
                <option>Operations</option>
                <option>Commercial</option>
                <option>General</option>
              </select>
            </div>
          </div>
          <div class="grid grid-4">
            <div class="field" style="grid-column: span 2;"><label>Address Line 1 *</label><input name="address_line1" required></div>
            <div class="field"><label>Country *</label><input name="country" required></div>
            <div class="field"><label>State *</label><input name="state" required></div>
          </div>
          <div class="grid grid-4">
            <div class="field" style="grid-column: span 2;"><label>Address Line 2</label><input name="address_line2"></div>
            <div class="field"><label>City *</label><input name="city" required></div>
            <div class="field"><label>Zip Code *</label><input name="zip_code" required></div>
          </div>
          <div class="grid grid-4">
            <div class="field"><label>Address Line 3</label><input name="address_line3"></div>
            <div class="field"><label>Phone1</label><input name="phone1"></div>
            <div class="field"><label>Phone2</label><input name="phone2"></div>
            <div class="field"><label>Fax1</label><input name="fax1"></div>
          </div>
          <div class="grid grid-4">
            <div class="field"><label>Fax2</label><input name="fax2"></div>
            <div class="field" style="grid-column: span 2;"><label>Email ID *</label><input type="email" name="email" required></div>
          </div>
          <div class="field"><label>Web URL</label><input name="web_url" placeholder="http://www."></div>
          <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:var(--text); margin-bottom:16px;">
            <input type="checkbox" name="save_to_master" value="1" style="width:auto;"> Also save this contact to Contact Master for reuse on other customers
          </label>
          <button type="submit" class="btn primary">Submit</button>
        </form>
      </div>
      <?php endif; ?>

      <div class="table-wrap" style="border:none; margin-top:14px;">
        <table>
          <thead><tr><th>Name</th><th>Designation</th><th>Type</th><th>Email</th><th>Phone</th><th>Source</th></tr></thead>
          <tbody>
            <?php foreach ($contacts as $ct): ?>
            <tr>
              <td><?= htmlspecialchars($ct['contact_name']) ?></td>
              <td style="color:var(--muted)"><?= htmlspecialchars($ct['designation']) ?></td>
              <td><?= htmlspecialchars($ct['contact_type']) ?></td>
              <td class="mono"><?= htmlspecialchars($ct['email']) ?></td>
              <td class="mono"><?= htmlspecialchars($ct['phone1'] ?? '—') ?></td>
              <td><?= $ct['contact_master_id'] ? '<span class="badge active" style="font-size:10px;">Master</span>' : '<span style="color:var(--muted); font-size:11px;">One-off</span>' ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$contacts): ?><tr><td colspan="6" style="color:var(--muted)">No contacts yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Billing Addresses -->
    <div class="card" style="margin-bottom:20px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <h3>Customer Billing Addresses</h3>
        <?php if ($canWrite): ?>
        <button type="button" class="btn primary" id="toggleBillingForm">+ Add Billing Address</button>
        <?php endif; ?>
      </div>

      <?php if ($canWrite): ?>
      <div id="billingForm" style="display:none; margin-top:14px; border-top:1px solid var(--border); padding-top:16px;">
        <form method="post">
          <input type="hidden" name="action" value="add_billing">
          <div class="grid grid-4">
            <div class="field"><label>Address Code *</label><input name="address_code" required></div>
            <div class="field" style="grid-column: span 2;"><label>Address Description *</label><input name="address_description" required></div>
            <div class="field"><label>GSTIN</label><input name="gstin"></div>
          </div>
          <div class="grid grid-4">
            <div class="field" style="grid-column: span 2;"><label>Address Line 1 *</label><input name="address_line1" required></div>
            <div class="field"><label>Country *</label><input name="country" required></div>
            <div class="field"><label>State *</label><input name="state" required></div>
          </div>
          <div class="grid grid-4">
            <div class="field" style="grid-column: span 2;"><label>Address Line 2</label><input name="address_line2"></div>
            <div class="field"><label>City *</label><input name="city" required></div>
            <div class="field"><label>Zip Code *</label><input name="zip_code" required></div>
          </div>
          <div class="grid grid-4">
            <div class="field"><label>Phone1</label><input name="phone1"></div>
            <div class="field"><label>Phone2</label><input name="phone2"></div>
            <div class="field"><label>Fax1</label><input name="fax1"></div>
            <div class="field"><label>Fax2</label><input name="fax2"></div>
          </div>
          <div class="grid grid-4">
            <div class="field" style="grid-column: span 2;"><label>Email ID *</label><input type="email" name="email" required></div>
            <div class="field" style="grid-column: span 2;"><label>Web URL</label><input name="web_url" placeholder="http://www."></div>
          </div>
          <button type="submit" class="btn primary">Submit</button>
        </form>
      </div>
      <?php endif; ?>

      <div class="table-wrap" style="border:none; margin-top:14px;">
        <table>
          <thead><tr><th>Code</th><th>Description</th><th>City / State</th><th>GSTIN</th></tr></thead>
          <tbody>
            <?php foreach ($billingAddresses as $b): ?>
            <tr>
              <td class="mono"><?= htmlspecialchars($b['address_code']) ?></td>
              <td><?= htmlspecialchars($b['address_description']) ?></td>
              <td style="color:var(--muted)"><?= htmlspecialchars($b['city'] . ', ' . $b['state']) ?></td>
              <td class="mono"><?= htmlspecialchars($b['gstin'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$billingAddresses): ?><tr><td colspan="4" style="color:var(--muted)">No billing addresses yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Contracts -->
    <div class="card">
      <h3>Offers &amp; Contracts</h3>
      <div class="table-wrap" style="border:none;">
        <table>
          <thead><tr><th>Contract No.</th><th>Type</th><th>Effective Date</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($contracts as $ct): ?>
            <tr>
              <td class="mono" style="color:var(--cyan)"><?= htmlspecialchars($ct['contract_number'] ?? ('#' . $ct['id'])) ?></td>
              <td><?= htmlspecialchars($ct['contract_type'] ?? '—') ?></td>
              <td class="mono" style="color:var(--muted)"><?= htmlspecialchars($ct['effective_date'] ?? '—') ?></td>
              <td><span class="badge <?= $ct['status'] === 'finalised' ? 'active' : ($ct['status'] === 'reject' ? 'suspended' : 'inactive') ?>"><?= htmlspecialchars($ct['status']) ?></span></td>
              <td><a href="contract_view.php?id=<?= $ct['id'] ?>">Open &rarr;</a></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$contracts): ?><tr><td colspan="5" style="color:var(--muted)">No offers/contracts yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>

<script>
  var toggleContactBtn = document.getElementById('toggleContactForm');
  if (toggleContactBtn) {
    toggleContactBtn.addEventListener('click', function () {
      var f = document.getElementById('contactForm');
      f.style.display = (f.style.display === 'none' || !f.style.display) ? 'block' : 'none';
    });
  }

  var toggleBillingBtn = document.getElementById('toggleBillingForm');
  if (toggleBillingBtn) {
    toggleBillingBtn.addEventListener('click', function () {
      var f = document.getElementById('billingForm');
      f.style.display = (f.style.display === 'none' || !f.style.display) ? 'block' : 'none';
    });
  }
</script>
</body>
</html>
