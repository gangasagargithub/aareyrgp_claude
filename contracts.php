<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pdo = getConnection();
$message = '';
$newForCustomer = (int)($_GET['new_for'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_contract') {
    $stmt = $pdo->prepare(
        "INSERT INTO contracts
            (customer_id, agency_coordinator, effective_date, contract_type, bid_ref_no, bid_ref_date,
             bid_last_submission_date, bid_open_date, short_contract_no, remarks, invoicing_to_different_principal, currency)
         VALUES
            (:cid, :agency, :eff_date, :ctype, :bid_ref, :bid_ref_date,
             :bid_last, :bid_open, :short_no, :remarks, :invoicing, :currency)"
    );
    $stmt->execute([
        'cid' => (int)$_POST['customer_id'],
        'agency' => $_POST['agency_coordinator'] ?: null,
        'eff_date' => $_POST['effective_date'] ?: null,
        'ctype' => $_POST['contract_type'] ?: null,
        'bid_ref' => $_POST['bid_ref_no'] ?: null,
        'bid_ref_date' => $_POST['bid_ref_date'] ?: null,
        'bid_last' => $_POST['bid_last_submission_date'] ?: null,
        'bid_open' => $_POST['bid_open_date'] ?: null,
        'short_no' => $_POST['short_contract_no'] ?: null,
        'remarks' => $_POST['remarks'] ?: null,
        'invoicing' => $_POST['invoicing_to_different_principal'] ?? 'no',
        'currency' => $_POST['currency'] ?: 'INR',
    ]);
    $contractId = (int)$pdo->lastInsertId();
    logAction($_SESSION['user_id'], 'contract.create', "Created offer #$contractId");
    header("Location: contract_view.php?id=$contractId");
    exit;
}

$customers = $pdo->query("SELECT id, customer_name, customer_code FROM customers ORDER BY customer_name")->fetchAll();

$contracts = $pdo->query(
    "SELECT ct.*, c.customer_name, c.customer_code
     FROM contracts ct JOIN customers c ON c.id = ct.customer_id
     ORDER BY ct.id DESC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Offers &amp; Contracts — RBAC Console</title>
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
      <div class="breadcrumb"><strong>Offers &amp; Contracts</strong> &middot; <?= count($contracts) ?> total</div>
      <button type="button" class="btn primary" id="toggleNewContract">+ New Offer (Step 3)</button>
    </div>

    <div class="card" id="newContractForm" style="display:<?= $newForCustomer ? 'block' : 'none' ?>; margin-bottom:20px;">
      <h3>New Offer</h3>
      <p style="color:var(--muted); font-size:12.5px; margin-top:-8px;">Add operators, rates, attachments, and finalize from the offer detail page after creating.</p>
      <form method="post">
        <input type="hidden" name="action" value="create_contract">
        <div class="grid grid-4">
          <div class="field" style="grid-column: span 2;">
            <label>Customer *</label>
            <select name="customer_id" required>
              <option value="">-- Select Customer --</option>
              <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $c['id'] == $newForCustomer ? 'selected' : '' ?>>
                  <?= htmlspecialchars($c['customer_name']) ?> (<?= htmlspecialchars($c['customer_code']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Effective Date *</label><input type="date" name="effective_date" required></div>
          <div class="field">
            <label>Contract Type *</label>
            <select name="contract_type" required>
              <option value="">-- Select --</option>
              <option>Contract With Rate</option>
              <option>Contract Without Rate</option>
              <option>Tender / Bid</option>
            </select>
          </div>
        </div>
        <div class="grid grid-4">
          <div class="field"><label>Agency Co-ordinator</label><input name="agency_coordinator"></div>
          <div class="field"><label>Bid Ref. No.</label><input name="bid_ref_no"></div>
          <div class="field"><label>Bid Ref. Date</label><input type="date" name="bid_ref_date"></div>
          <div class="field"><label>Bid Open Date</label><input type="date" name="bid_open_date"></div>
        </div>
        <div class="grid grid-4">
          <div class="field"><label>Bid Last Submission Date</label><input type="date" name="bid_last_submission_date"></div>
          <div class="field"><label>Short Contract No.</label><input name="short_contract_no"></div>
          <div class="field">
            <label>Currency</label>
            <select name="currency">
              <option value="INR">INR - INDIAN RUPEES</option>
              <option value="USD">USD - US DOLLAR</option>
              <option value="EUR">EUR - EURO</option>
            </select>
          </div>
          <div class="field">
            <label>Invoicing To Different Principal</label>
            <select name="invoicing_to_different_principal">
              <option value="no">No</option>
              <option value="yes">Yes</option>
            </select>
          </div>
        </div>
        <div class="field"><label>Remarks</label><input name="remarks"></div>
        <button type="submit" class="btn primary">Save &amp; Continue</button>
      </form>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>#</th><th>Customer</th><th>Type</th><th>Effective Date</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($contracts as $ct): ?>
          <tr>
            <td class="mono">#<?= $ct['id'] ?></td>
            <td><?= htmlspecialchars($ct['customer_name']) ?></td>
            <td style="color:var(--muted)"><?= htmlspecialchars($ct['contract_type'] ?? '—') ?></td>
            <td class="mono" style="color:var(--muted)"><?= htmlspecialchars($ct['effective_date'] ?? '—') ?></td>
            <td><span class="badge <?= $ct['status'] === 'finalised' ? 'active' : ($ct['status'] === 'reject' ? 'suspended' : 'inactive') ?>"><?= htmlspecialchars($ct['status']) ?></span></td>
            <td><a href="contract_view.php?id=<?= $ct['id'] ?>">Open &rarr;</a></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$contracts): ?>
          <tr><td colspan="6" style="color:var(--muted)">No offers/contracts yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>
</div>

<script>
  document.getElementById('toggleNewContract').addEventListener('click', function () {
    var form = document.getElementById('newContractForm');
    form.style.display = (form.style.display === 'none' || !form.style.display) ? 'block' : 'none';
  });
</script>
</body>
</html>
