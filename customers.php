<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pdo = getConnection();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_customer') {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "INSERT INTO customers
                (customer_code, customer_abbreviation, customer_name, company_type, turnover,
                 operating_since, reference_other_country, reference_india, pan_no,
                 description, service_required, info_source, crew_address)
             VALUES
                (:code, :abbr, :name, :ctype, :turnover,
                 :op_since, :ref_other, :ref_india, :pan,
                 :desc, :service, :info, :crew)"
        );
        $stmt->execute([
            'code'      => trim($_POST['customer_code']),
            'abbr'      => trim($_POST['customer_abbreviation']),
            'name'      => trim($_POST['customer_name']),
            'ctype'     => $_POST['company_type'] ?: null,
            'turnover'  => $_POST['turnover'] ?: null,
            'op_since'  => $_POST['operating_since'] ?: null,
            'ref_other' => $_POST['reference_other_country'] ?: null,
            'ref_india' => $_POST['reference_india'] ?: null,
            'pan'       => $_POST['pan_no'] ?: null,
            'desc'      => $_POST['description'] ?: null,
            'service'   => $_POST['service_required'] ?: null,
            'info'      => $_POST['info_source'] ?: null,
            'crew'      => $_POST['crew_address'] ?: null,
        ]);
        $customerId = (int)$pdo->lastInsertId();

        // Corporate office address
        $addr = $pdo->prepare(
            "INSERT INTO customer_addresses
                (customer_id, office_type, address_line1, address_line2, address_line3,
                 country, state, city, zip_code, phone1, phone2, fax1, fax2, email, web_url, is_default_billing)
             VALUES
                (:cid, 'corporate', :l1, :l2, :l3, :country, :state, :city, :zip, :p1, :p2, :f1, :f2, :email, :web, :billing)"
        );
        $addr->execute([
            'cid' => $customerId,
            'l1' => $_POST['corp_address_line1'], 'l2' => $_POST['corp_address_line2'] ?: null, 'l3' => $_POST['corp_address_line3'] ?: null,
            'country' => $_POST['corp_country'], 'state' => $_POST['corp_state'], 'city' => $_POST['corp_city'], 'zip' => $_POST['corp_zip'],
            'p1' => $_POST['corp_phone1'] ?: null, 'p2' => $_POST['corp_phone2'] ?: null,
            'f1' => $_POST['corp_fax1'] ?: null, 'f2' => $_POST['corp_fax2'] ?: null,
            'email' => $_POST['corp_email'], 'web' => $_POST['corp_web'] ?: null,
            'billing' => isset($_POST['corp_default_billing']) ? 1 : 0,
        ]);

        // Indian office address (optional if different)
        if (!empty($_POST['ind_address_line1'])) {
            $addr2 = $pdo->prepare(
                "INSERT INTO customer_addresses
                    (customer_id, office_type, address_line1, address_line2, address_line3,
                     country, state, city, zip_code, phone1, phone2, fax1, fax2, email, web_url, is_default_billing)
                 VALUES
                    (:cid, 'indian', :l1, :l2, :l3, :country, :state, :city, :zip, :p1, :p2, :f1, :f2, :email, :web, :billing)"
            );
            $addr2->execute([
                'cid' => $customerId,
                'l1' => $_POST['ind_address_line1'], 'l2' => $_POST['ind_address_line2'] ?: null, 'l3' => $_POST['ind_address_line3'] ?: null,
                'country' => $_POST['ind_country'] ?: 'INDIA', 'state' => $_POST['ind_state'], 'city' => $_POST['ind_city'], 'zip' => $_POST['ind_zip'],
                'p1' => $_POST['ind_phone1'] ?: null, 'p2' => $_POST['ind_phone2'] ?: null,
                'f1' => $_POST['ind_fax1'] ?: null, 'f2' => $_POST['ind_fax2'] ?: null,
                'email' => $_POST['ind_email'] ?: null, 'web' => $_POST['ind_web'] ?: null,
                'billing' => isset($_POST['ind_default_billing']) ? 1 : 0,
            ]);
        }

        $pdo->commit();
        logAction($_SESSION['user_id'], 'customer.create', "Created customer #$customerId ({$_POST['customer_name']})");
        $message = 'Customer created. Add contact person and billing address from the customer detail page.';
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = 'Error: ' . $e->getMessage();
    }
}

$customers = $pdo->query(
    "SELECT c.*,
        (SELECT COUNT(*) FROM customer_contacts cc WHERE cc.customer_id = c.id) AS contact_count,
        (SELECT COUNT(*) FROM customer_billing_addresses cb WHERE cb.customer_id = c.id) AS billing_count,
        (SELECT COUNT(*) FROM contracts ct WHERE ct.customer_id = c.id) AS contract_count
     FROM customers c ORDER BY c.id DESC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Customers — RBAC Console</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.css">
</head>
<body>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>

  <main class="main">
    <div class="topbar">
      <div class="breadcrumb"><strong>Customers</strong> &middot; <?= count($customers) ?> total</div>
      <button type="button" class="btn primary" id="toggleNewCustomer">+ New customer (Step 1: Master)</button>
    </div>

    <?php if ($message): ?><div class="error-box" style="background:var(--cyan-dim); border-color:var(--cyan); color:var(--cyan);"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <div class="card" id="newCustomerForm" style="display:none; margin-bottom:20px;">
      <h3>Customer Master</h3>
      <p style="color:var(--muted); font-size:12.5px; margin-top:-8px;">Step 1 of the onboarding process. Add contacts and billing addresses afterward from the customer detail page.</p>
      <form method="post">
        <input type="hidden" name="action" value="create_customer">

        <div class="grid grid-4">
          <div class="field"><label>Customer Code *</label><input name="customer_code" required></div>
          <div class="field"><label>Customer Abbreviation *</label><input name="customer_abbreviation" required></div>
          <div class="field" style="grid-column: span 2;"><label>Customer Name *</label><input name="customer_name" required></div>
        </div>
        <div class="grid grid-4">
          <div class="field">
            <label>Company Type</label>
            <select name="company_type">
              <option value="">-- Select --</option>
              <option>Principal</option>
              <option>Operator</option>
              <option>Agent</option>
              <option>Vendor</option>
            </select>
          </div>
          <div class="field"><label>Turnover</label><input name="turnover"></div>
          <div class="field"><label>Operating Since</label><input type="text" class="datepicker" name="operating_since" autocomplete="off" placeholder="YYYY-MM-DD"></div>
          <div class="field"><label>PAN No</label><input name="pan_no"></div>
        </div>
        <div class="grid grid-4">
          <div class="field"><label>Reference to Other Country</label><input name="reference_other_country"></div>
          <div class="field"><label>Reference to India</label><input name="reference_india"></div>
        </div>
        <div class="field"><label>Description</label><input name="description"></div>
        <div class="field"><label>Service Required</label><input name="service_required"></div>
        <div class="field"><label>Info Source</label><input name="info_source"></div>
        <div class="field"><label>Crew Address</label><input name="crew_address"></div>

        <h3 style="margin-top:22px;">Corporate Office Address</h3>
        <div class="grid grid-4">
          <div class="field" style="grid-column: span 2;"><label>Address Line 1 *</label><input name="corp_address_line1" required></div>
          <div class="field"><label>Country *</label><input name="corp_country" required></div>
          <div class="field"><label>State *</label><input name="corp_state" required></div>
        </div>
        <div class="grid grid-4">
          <div class="field" style="grid-column: span 2;"><label>Address Line 2</label><input name="corp_address_line2"></div>
          <div class="field"><label>City *</label><input name="corp_city" required></div>
          <div class="field"><label>Zip Code *</label><input name="corp_zip" required></div>
        </div>
        <div class="grid grid-4">
          <div class="field"><label>Phone1</label><input name="corp_phone1"></div>
          <div class="field"><label>Phone2</label><input name="corp_phone2"></div>
          <div class="field"><label>Fax1</label><input name="corp_fax1"></div>
          <div class="field"><label>Fax2</label><input name="corp_fax2"></div>
        </div>
        <div class="grid grid-4">
          <div class="field" style="grid-column: span 2;"><label>Email ID *</label><input type="email" name="corp_email" required></div>
          <div class="field" style="grid-column: span 2;"><label>Web URL</label><input name="corp_web" placeholder="http://www."></div>
        </div>
        <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:var(--text);">
          <input type="checkbox" name="corp_default_billing" style="width:auto;"> Default Billing Address
        </label>

        <h3 style="margin-top:22px;">Indian Office Address <span style="color:var(--muted); font-weight:400; font-size:12px;">(optional, if different)</span></h3>
        <div class="grid grid-4">
          <div class="field" style="grid-column: span 2;"><label>Address Line 1</label><input name="ind_address_line1"></div>
          <div class="field"><label>Country</label><input name="ind_country" value="INDIA"></div>
          <div class="field"><label>State</label><input name="ind_state"></div>
        </div>
        <div class="grid grid-4">
          <div class="field" style="grid-column: span 2;"><label>Address Line 2</label><input name="ind_address_line2"></div>
          <div class="field"><label>City</label><input name="ind_city"></div>
          <div class="field"><label>Zip Code</label><input name="ind_zip"></div>
        </div>
        <div class="grid grid-4">
          <div class="field"><label>Phone1</label><input name="ind_phone1"></div>
          <div class="field"><label>Phone2</label><input name="ind_phone2"></div>
          <div class="field"><label>Fax1</label><input name="ind_fax1"></div>
          <div class="field"><label>Fax2</label><input name="ind_fax2"></div>
        </div>
        <div class="grid grid-4">
          <div class="field" style="grid-column: span 2;"><label>Email ID</label><input type="email" name="ind_email"></div>
          <div class="field" style="grid-column: span 2;"><label>Web URL</label><input name="ind_web" placeholder="http://www."></div>
        </div>
        <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:var(--text); margin-bottom:16px;">
          <input type="checkbox" name="ind_default_billing" style="width:auto;"> Default Billing Address
        </label>

        <button type="submit" class="btn primary">Submit</button>
      </form>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Code</th><th>Name</th><th>Type</th><th>Contacts</th><th>Billing Addr.</th><th>Contracts</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($customers as $c): ?>
          <tr>
            <td class="mono"><?= htmlspecialchars($c['customer_code']) ?></td>
            <td><?= htmlspecialchars($c['customer_name']) ?></td>
            <td style="color:var(--muted)"><?= htmlspecialchars($c['company_type'] ?? '—') ?></td>
            <td class="mono"><?= (int)$c['contact_count'] ?></td>
            <td class="mono"><?= (int)$c['billing_count'] ?></td>
            <td class="mono"><?= (int)$c['contract_count'] ?></td>
            <td><span class="badge <?= $c['status'] ?>"><?= htmlspecialchars($c['status']) ?></span></td>
            <td><a href="customer_view.php?id=<?= $c['id'] ?>">Manage &rarr;</a></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$customers): ?>
          <tr><td colspan="8" style="color:var(--muted)">No customers yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.js"></script>
<script>
  document.getElementById('toggleNewCustomer').addEventListener('click', function () {
    var form = document.getElementById('newCustomerForm');
    form.style.display = (form.style.display === 'none' || !form.style.display) ? 'block' : 'none';
  });

  flatpickr('.datepicker', { dateFormat: 'Y-m-d', allowInput: true });
</script>
</body>
</html>
