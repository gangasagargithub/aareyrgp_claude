<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pdo = getConnection();
$contractId = (int)($_GET['id'] ?? 0);
$message = '';

$stmt = $pdo->prepare(
    "SELECT ct.*, c.customer_name, c.customer_code FROM contracts ct
     JOIN customers c ON c.id = ct.customer_id WHERE ct.id = :id"
);
$stmt->execute(['id' => $contractId]);
$contract = $stmt->fetch();

if (!$contract) {
    header('Location: contracts.php');
    exit;
}

$billingAddresses = $pdo->prepare('SELECT id, address_code, address_description FROM customer_billing_addresses WHERE customer_id = :id');
$billingAddresses->execute(['id' => $contract['customer_id']]);
$billingAddresses = $billingAddresses->fetchAll();

$agencyCoordinators = $pdo->query(
    "SELECT u.id, u.first_name, u.last_name FROM users u
     JOIN user_roles ur ON ur.user_id = u.id
     JOIN roles r ON r.id = ur.role_id
     WHERE r.name = 'Agency Co-ordinator' AND u.status = 'active'
     ORDER BY u.first_name"
)->fetchAll();

// Rate Structure master lists (managed via rate_structure_master.php)
$rateLocations = $pdo->query("SELECT name FROM rate_master_locations WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$ratePriorities = $pdo->query("SELECT name FROM rate_master_priorities WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$rateModTypes = $pdo->query("SELECT name FROM rate_master_mod_types WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$rateUnits = $pdo->query("SELECT name FROM rate_master_units WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$rateForOptions = $pdo->query("SELECT name FROM rate_master_rate_for WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

// Every write action on this page (edit details, add operator, add rate group,
// upload attachment, finalize) modifies a drafted contract or finalises it —
// restrict to Admin/Super Admin.
$modifyActions = ['update_contract', 'add_operator', 'add_rate_group', 'upload_attachment', 'finalize'];
$requestedAction = $_POST['action'] ?? '';
$isModifyRequest = $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($requestedAction, $modifyActions, true);
$canModify = !$isModifyRequest || isAdminOrSuperAdmin();

if ($isModifyRequest && !$canModify) {
    $message = 'Only Admin or Super Admin can modify a drafted contract or finalize it.';
}

// Edit offer/contract details
if ($canModify && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_contract') {
    $stmt = $pdo->prepare(
        "UPDATE contracts SET
            agency_coordinator = :agency, effective_date = :eff, contract_type = :ctype,
            bid_ref_no = :bid_ref, bid_ref_date = :bid_ref_date, bid_last_submission_date = :bid_last,
            bid_open_date = :bid_open, short_contract_no = :short_no, remarks = :remarks,
            invoicing_to_different_principal = :invoicing, currency = :currency
         WHERE id = :id"
    );
    $stmt->execute([
        'agency' => $_POST['agency_coordinator'] ?: null,
        'eff' => $_POST['effective_date'] ?: null,
        'ctype' => $_POST['contract_type'] ?: null,
        'bid_ref' => $_POST['bid_ref_no'] ?: null,
        'bid_ref_date' => $_POST['bid_ref_date'] ?: null,
        'bid_last' => $_POST['bid_last_submission_date'] ?: null,
        'bid_open' => $_POST['bid_open_date'] ?: null,
        'short_no' => $_POST['short_contract_no'] ?: null,
        'remarks' => $_POST['remarks'] ?: null,
        'invoicing' => $_POST['invoicing_to_different_principal'] ?? 'no',
        'currency' => $_POST['currency'] ?: 'INR',
        'id' => $contractId,
    ]);
    logAction($_SESSION['user_id'], 'contract.update', "Updated details for contract #$contractId");
    header("Location: contract_view.php?id=$contractId");
    exit;
}

// Add operator (Principal's contract(s) with Operator(s))
if ($canModify && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_operator') {
    $stmt = $pdo->prepare(
        "INSERT INTO contract_operators (contract_id, operator, contract_no, project_name, project_abbreviation, billing_address_id)
         VALUES (:cid, :op, :no, :pname, :pabbr, :baddr)"
    );
    $stmt->execute([
        'cid' => $contractId, 'op' => $_POST['operator'], 'no' => $_POST['contract_no'],
        'pname' => $_POST['project_name'], 'pabbr' => $_POST['project_abbreviation'],
        'baddr' => $_POST['billing_address_id'] ?: null,
    ]);
    logAction($_SESSION['user_id'], 'contract.operator_add', "Added operator to contract #$contractId");
    header("Location: contract_view.php?id=$contractId");
    exit;
}

// Add rate group + rate rows (Rate Structure)
if ($canModify && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_rate_group') {
    $stmt = $pdo->prepare(
        "INSERT INTO contract_rate_groups
            (contract_id, contract_clause_no, rate_for, effective_date, service_tax_applicable,
             statutory_charges_applicable, estimated_quantity, disbursement_currency, remarks,
             printing_remarks, printing_remarks2, chargeability_options)
         VALUES
            (:cid, :clause, :rate_for, :eff, :stax, :stat, :qty, :curr, :remarks, :pr1, :pr2, :chg)"
    );
    $stmt->execute([
        'cid' => $contractId, 'clause' => $_POST['contract_clause_no'] ?: null, 'rate_for' => $_POST['rate_for'],
        'eff' => $_POST['effective_date'] ?: null, 'stax' => $_POST['service_tax_applicable'] ?? 'yes',
        'stat' => $_POST['statutory_charges_applicable'] ?? 'yes', 'qty' => $_POST['estimated_quantity'] ?: null,
        'curr' => $_POST['disbursement_currency'] ?: 'INR', 'remarks' => $_POST['remarks'] ?: null,
        'pr1' => $_POST['printing_remarks'] ?: null, 'pr2' => $_POST['printing_remarks2'] ?: null,
        'chg' => $_POST['chargeability_options'] ?: null,
    ]);
    $rateGroupId = (int)$pdo->lastInsertId();

    $locations = $_POST['loc_location'] ?? [];
    foreach ($locations as $i => $loc) {
        if ($loc === '') continue;
        $item = $pdo->prepare(
            "INSERT INTO contract_rate_items (rate_group_id, location, per_unit, priority, mod_type, rate)
             VALUES (:rg, :loc, :per, :prio, :mod, :rate)"
        );
        $item->execute([
            'rg' => $rateGroupId, 'loc' => $loc,
            'per' => $_POST['loc_per'][$i], 'prio' => $_POST['loc_priority'][$i],
            'mod' => $_POST['loc_mod'][$i], 'rate' => $_POST['loc_rate'][$i],
        ]);
    }

    logAction($_SESSION['user_id'], 'contract.rate_add', "Added rate group to contract #$contractId");
    header("Location: contract_view.php?id=$contractId");
    exit;
}

// Upload signed contract attachment
if ($canModify && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_attachment') {
    if (!empty($_FILES['attachment']['name'])) {
        $uploadDir = __DIR__ . '/uploads/contracts/' . $contractId . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $safeName = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $_FILES['attachment']['name']);
        $destPath = $uploadDir . $safeName;

        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $destPath)) {
            $relativePath = 'uploads/contracts/' . $contractId . '/' . $safeName;
            $stmt = $pdo->prepare(
                'INSERT INTO contract_attachments (contract_id, description, file_path) VALUES (:cid, :desc, :path)'
            );
            $stmt->execute(['cid' => $contractId, 'desc' => $_POST['description'] ?: 'Signed Contract', 'path' => $relativePath]);
            $pdo->prepare('UPDATE contracts SET signed_contract_path = :path WHERE id = :id')
                ->execute(['path' => $relativePath, 'id' => $contractId]);
            logAction($_SESSION['user_id'], 'contract.attachment_upload', "Uploaded attachment to contract #$contractId");
        } else {
            $message = 'File upload failed.';
        }
    }
    header("Location: contract_view.php?id=$contractId");
    exit;
}

// Finalize contract (select start/end date, convert to contract)
if ($canModify && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'finalize') {
    $stmt = $pdo->prepare(
        "UPDATE contracts SET status = 'finalised', start_date = :start, end_date = :end WHERE id = :id"
    );
    $stmt->execute(['start' => $_POST['start_date'], 'end' => $_POST['end_date'], 'id' => $contractId]);
    logAction($_SESSION['user_id'], 'contract.finalize', "Finalised contract #$contractId (converted to contract)");
    header("Location: contract_view.php?id=$contractId");
    exit;
}

$operators = $pdo->prepare(
    "SELECT co.*, cb.address_code FROM contract_operators co
     LEFT JOIN customer_billing_addresses cb ON cb.id = co.billing_address_id
     WHERE co.contract_id = :id"
);
$operators->execute(['id' => $contractId]);
$operators = $operators->fetchAll();

$rateGroups = $pdo->prepare('SELECT * FROM contract_rate_groups WHERE contract_id = :id ORDER BY id');
$rateGroups->execute(['id' => $contractId]);
$rateGroups = $rateGroups->fetchAll();

foreach ($rateGroups as &$rg) {
    $items = $pdo->prepare('SELECT * FROM contract_rate_items WHERE rate_group_id = :id');
    $items->execute(['id' => $rg['id']]);
    $rg['items'] = $items->fetchAll();
}
unset($rg);

$attachments = $pdo->prepare('SELECT * FROM contract_attachments WHERE contract_id = :id ORDER BY id DESC');
$attachments->execute(['id' => $contractId]);
$attachments = $attachments->fetchAll();

$statusBadgeClass = $contract['status'] === 'finalised' ? 'active' : ($contract['status'] === 'reject' ? 'suspended' : 'inactive');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Offer #<?= $contractId ?> — RBAC Console</title>
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
      <div class="breadcrumb"><a href="contracts.php">Offers &amp; Contracts</a> &middot; <strong><?= htmlspecialchars($contract['contract_number'] ?? ('#' . $contractId)) ?></strong> &middot; <?= htmlspecialchars($contract['customer_name']) ?></div>
      <div style="display:flex; align-items:center; gap:12px;">
        <?php if (isAdminOrSuperAdmin()): ?>
        <button type="button" class="btn" id="toggleEditContract">Edit Details</button>
        <?php endif; ?>
        <span class="badge <?= $statusBadgeClass ?>" style="font-size:13px; padding:6px 14px;"><?= htmlspecialchars($contract['status']) ?></span>
      </div>
    </div>

    <?php if (isAdminOrSuperAdmin()): ?>
    <div class="card" id="editContractForm" style="display:none; margin-bottom:20px;">
      <h3>Edit Offer / Contract Details</h3>
      <form method="post">
        <input type="hidden" name="action" value="update_contract">
        <div class="grid grid-4">
          <div class="field">
            <label>Agency Co-ordinator</label>
            <select name="agency_coordinator">
              <option value="">-- Select --</option>
              <?php
                $currentAgency = $contract['agency_coordinator'] ?? '';
                $agencyNames = [];
                foreach ($agencyCoordinators as $ac) {
                    $agencyNames[] = $ac['first_name'] . ' ' . $ac['last_name'];
                }
                $currentAgencyStillHasRole = in_array($currentAgency, $agencyNames, true);
              ?>
              <?php if ($currentAgency !== '' && !$currentAgencyStillHasRole): ?>
                <option value="<?= htmlspecialchars($currentAgency) ?>" selected>
                  <?= htmlspecialchars($currentAgency) ?> (current — role no longer assigned)
                </option>
              <?php endif; ?>
              <?php foreach ($agencyCoordinators as $ac):
                $acName = $ac['first_name'] . ' ' . $ac['last_name']; ?>
                <option value="<?= htmlspecialchars($acName) ?>" <?= $currentAgency === $acName ? 'selected' : '' ?>>
                  <?= htmlspecialchars($acName) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if ($currentAgency !== '' && !$currentAgencyStillHasRole): ?>
              <p style="color:var(--amber); font-size:11px; margin-top:6px;">This person no longer holds the Agency Co-ordinator role — kept as-is unless you pick someone else.</p>
            <?php endif; ?>
          </div>
          <div class="field"><label>Effective Date</label><input type="text" class="datepicker" name="effective_date" autocomplete="off" value="<?= htmlspecialchars($contract['effective_date'] ?? '') ?>"></div>
          <div class="field">
            <label>Contract Type</label>
            <select name="contract_type">
              <option value="">-- Select --</option>
              <?php foreach (['Contract With Rate', 'Contract Without Rate', 'Tender / Bid'] as $ctype): ?>
                <option <?= $contract['contract_type'] === $ctype ? 'selected' : '' ?>><?= $ctype ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Currency</label>
            <select name="currency">
              <?php foreach (['INR' => 'INR - INDIAN RUPEES', 'USD' => 'USD - US DOLLAR', 'EUR' => 'EUR - EURO'] as $code => $label): ?>
                <option value="<?= $code ?>" <?= $contract['currency'] === $code ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="grid grid-4">
          <div class="field"><label>Bid Ref. No.</label><input name="bid_ref_no" value="<?= htmlspecialchars($contract['bid_ref_no'] ?? '') ?>"></div>
          <div class="field"><label>Bid Ref. Date</label><input type="text" class="datepicker" name="bid_ref_date" autocomplete="off" value="<?= htmlspecialchars($contract['bid_ref_date'] ?? '') ?>"></div>
          <div class="field"><label>Bid Open Date</label><input type="text" class="datepicker" name="bid_open_date" autocomplete="off" value="<?= htmlspecialchars($contract['bid_open_date'] ?? '') ?>"></div>
          <div class="field"><label>Bid Last Submission Date</label><input type="text" class="datepicker" name="bid_last_submission_date" autocomplete="off" value="<?= htmlspecialchars($contract['bid_last_submission_date'] ?? '') ?>"></div>
        </div>
        <div class="grid grid-4">
          <div class="field"><label>Short Contract No.</label><input name="short_contract_no" value="<?= htmlspecialchars($contract['short_contract_no'] ?? '') ?>"></div>
          <div class="field">
            <label>Invoicing To Different Principal</label>
            <select name="invoicing_to_different_principal">
              <option value="no" <?= $contract['invoicing_to_different_principal'] === 'no' ? 'selected' : '' ?>>No</option>
              <option value="yes" <?= $contract['invoicing_to_different_principal'] === 'yes' ? 'selected' : '' ?>>Yes</option>
            </select>
          </div>
        </div>
        <div class="field"><label>Remarks</label><input name="remarks" value="<?= htmlspecialchars($contract['remarks'] ?? '') ?>"></div>
        <button type="submit" class="btn primary">Save Changes</button>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($message): ?><div class="error-box"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <div class="grid grid-4" style="margin-bottom:20px;">
      <div class="card"><div class="card-title">Contract Number</div><div class="mono" style="font-size:18px; color:var(--cyan);"><?= htmlspecialchars($contract['contract_number'] ?? '—') ?></div></div>
      <div class="card"><div class="card-title">Contract Type</div><div style="font-size:15px; font-weight:600;"><?= htmlspecialchars($contract['contract_type'] ?? '—') ?></div></div>
      <div class="card"><div class="card-title">Effective Date</div><div class="mono" style="font-size:15px;"><?= htmlspecialchars($contract['effective_date'] ?? '—') ?></div></div>
      <div class="card"><div class="card-title">Currency</div><div class="mono" style="font-size:15px;"><?= htmlspecialchars($contract['currency']) ?></div></div>
    </div>
    <div class="grid grid-4" style="margin-bottom:20px;">
      <div class="card"><div class="card-title">Agency Co-ordinator</div><div style="font-size:15px;"><?= htmlspecialchars($contract['agency_coordinator'] ?? '—') ?></div></div>
    </div>

    <!-- Operators -->
    <div class="card" style="margin-bottom:20px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <h3>Principal's Contract(s) with Operator(s)</h3>
        <?php if (isAdminOrSuperAdmin()): ?>
        <button type="button" class="btn primary" id="toggleOperatorForm">+ Add Operator</button>
        <?php endif; ?>
      </div>
      <?php if (isAdminOrSuperAdmin()): ?>
      <div id="operatorForm" style="display:none; margin-top:14px; border-top:1px solid var(--border); padding-top:16px;">
        <form method="post">
          <input type="hidden" name="action" value="add_operator">
          <div class="grid grid-4">
            <div class="field"><label>Operator *</label><input name="operator" required></div>
            <div class="field"><label>Contract No *</label><input name="contract_no" required></div>
            <div class="field"><label>Project Name *</label><input name="project_name" required></div>
            <div class="field"><label>Project Abbreviation *</label><input name="project_abbreviation" required></div>
          </div>
          <div class="field" style="max-width:280px;">
            <label>Billing Address</label>
            <select name="billing_address_id">
              <option value="">-- Select --</option>
              <?php foreach ($billingAddresses as $b): ?>
                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['address_code'] . ' — ' . $b['address_description']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn primary">Add</button>
        </form>
      </div>
      <?php endif; ?>
      <div class="table-wrap" style="border:none; margin-top:14px;">
        <table>
          <thead><tr><th>Operator</th><th>Contract No</th><th>Project</th><th>Abbr.</th><th>Billing Addr.</th></tr></thead>
          <tbody>
            <?php foreach ($operators as $op): ?>
            <tr>
              <td><?= htmlspecialchars($op['operator']) ?></td>
              <td class="mono"><?= htmlspecialchars($op['contract_no']) ?></td>
              <td><?= htmlspecialchars($op['project_name']) ?></td>
              <td class="mono"><?= htmlspecialchars($op['project_abbreviation']) ?></td>
              <td style="color:var(--muted)"><?= htmlspecialchars($op['address_code'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$operators): ?><tr><td colspan="5" style="color:var(--muted)">No operators added yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Rate Structure -->
    <div class="card" style="margin-bottom:20px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <h3>Rate Structure</h3>
        <?php if (isAdminOrSuperAdmin()): ?>
        <button type="button" class="btn primary" id="toggleRateForm">+ Add Rate Group</button>
        <?php endif; ?>
      </div>

      <?php if (isAdminOrSuperAdmin()): ?>
      <div id="rateForm" style="display:none; margin-top:14px; border-top:1px solid var(--border); padding-top:16px;">
        <form method="post">
          <input type="hidden" name="action" value="add_rate_group">
          <div class="grid grid-4">
            <div class="field"><label>Contract Clause No.</label><input name="contract_clause_no"></div>
            <div class="field" style="grid-column: span 2;">
              <label>Rate For *</label>
              <input name="rate_for" list="rateForList" required placeholder="e.g. Rate For MOD Clearance">
              <datalist id="rateForList">
                <?php foreach ($rateForOptions as $rf): ?><option value="<?= htmlspecialchars($rf) ?>"><?php endforeach; ?>
              </datalist>
            </div>
            <div class="field"><label>Effective Date</label><input type="text" class="datepicker" name="effective_date" autocomplete="off" placeholder="YYYY-MM-DD"></div>
          </div>
          <div class="grid grid-4">
            <div class="field">
              <label>Service Tax Applicable</label>
              <select name="service_tax_applicable"><option value="yes">Yes</option><option value="no">No</option></select>
            </div>
            <div class="field">
              <label>Statutory Charges Applicable</label>
              <select name="statutory_charges_applicable"><option value="yes">Yes</option><option value="no">No</option></select>
            </div>
            <div class="field"><label>Estimated Quantity</label><input type="number" name="estimated_quantity"></div>
            <div class="field">
              <label>Disbursement Currency</label>
              <select name="disbursement_currency"><option value="INR">INR</option><option value="USD">USD</option><option value="EUR">EUR</option></select>
            </div>
          </div>
          <div class="field"><label>Remarks</label><input name="remarks" placeholder="e.g. GST will be payable at actuals"></div>
          <div class="field"><label>Offer/Contract Printing Remarks</label><input name="printing_remarks"></div>
          <div class="field"><label>Offer/Contract Printing Remarks 2</label><input name="printing_remarks2"></div>
          <div class="field" style="max-width:280px;"><label>Chargeability Options</label><input name="chargeability_options" placeholder="AS PER RATE STRUCTURE-CRS"></div>

          <h4 style="margin:18px 0 10px;">Rate Rows</h4>
          <div id="rateRows">
            <div class="grid grid-4 rate-row" style="margin-bottom:8px;">
              <div class="field">
                <label>Location</label>
                <select name="loc_location[]">
                  <?php foreach ($rateLocations as $loc): ?><option><?= htmlspecialchars($loc) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label>Per</label>
                <select name="loc_per[]">
                  <?php foreach ($rateUnits as $unit): ?><option><?= htmlspecialchars($unit) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label>Priority</label>
                <select name="loc_priority[]">
                  <?php foreach ($ratePriorities as $p): ?><option><?= htmlspecialchars($p) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label>Mod Type</label>
                <select name="loc_mod[]">
                  <?php foreach ($rateModTypes as $mt): ?><option><?= htmlspecialchars($mt) ?></option><?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="field" style="max-width:200px;"><label>Rate</label><input type="number" step="0.01" name="loc_rate[]"></div>
          </div>
          <button type="button" class="btn" id="addRateRow" style="margin-bottom:14px;">+ Add Row</button>
          <br>
          <button type="submit" class="btn primary">Save Rate Group</button>
        </form>
      </div>
      <?php endif; ?>

      <?php foreach ($rateGroups as $rg): ?>
        <div style="margin-top:18px; border-top:1px solid var(--border); padding-top:14px;">
          <div style="display:flex; justify-content:space-between;">
            <strong><?= htmlspecialchars($rg['rate_for']) ?></strong>
            <span class="mono" style="color:var(--muted); font-size:12px;">Clause <?= htmlspecialchars($rg['contract_clause_no'] ?? '—') ?> &middot; Eff. <?= htmlspecialchars($rg['effective_date'] ?? '—') ?></span>
          </div>
          <?php if ($rg['remarks']): ?><p style="color:var(--muted); font-size:12.5px; margin:6px 0;"><?= htmlspecialchars($rg['remarks']) ?></p><?php endif; ?>
          <div class="table-wrap" style="border:none; margin-top:8px;">
            <table>
              <thead><tr><th>Location</th><th>Per</th><th>Priority</th><th>Mod Type</th><th>Rate</th></tr></thead>
              <tbody>
                <?php foreach ($rg['items'] as $item): ?>
                <tr>
                  <td><?= htmlspecialchars($item['location']) ?></td>
                  <td><?= htmlspecialchars($item['per_unit']) ?></td>
                  <td><?= htmlspecialchars($item['priority']) ?></td>
                  <td><?= htmlspecialchars($item['mod_type']) ?></td>
                  <td class="mono"><?= number_format($item['rate'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$rateGroups): ?><p style="color:var(--muted); margin-top:14px;">No rate groups added yet.</p><?php endif; ?>
    </div>

    <!-- Attachments -->
    <div class="card" style="margin-bottom:20px;">
      <h3>Attachments</h3>
      <?php if (isAdminOrSuperAdmin()): ?>
      <form method="post" enctype="multipart/form-data" style="margin-top:10px;">
        <input type="hidden" name="action" value="upload_attachment">
        <div class="grid grid-4">
          <div class="field" style="grid-column: span 2;"><label>Description</label><input name="description" placeholder="Signed Contract"></div>
          <div class="field" style="grid-column: span 2;"><label>File</label><input type="file" name="attachment" required></div>
        </div>
        <button type="submit" class="btn primary">Attach</button>
      </form>
      <?php else: ?>
      <p style="color:var(--muted); font-size:12.5px; margin-top:10px;">Only Admin or Super Admin can attach files to this contract.</p>
      <?php endif; ?>

      <div class="table-wrap" style="border:none; margin-top:14px;">
        <table>
          <thead><tr><th>Description</th><th>File</th><th>Uploaded</th></tr></thead>
          <tbody>
            <?php foreach ($attachments as $att): ?>
            <tr>
              <td><?= htmlspecialchars($att['description']) ?></td>
              <td><a href="<?= htmlspecialchars($att['file_path']) ?>" target="_blank" class="mono"><?= htmlspecialchars(basename($att['file_path'])) ?></a></td>
              <td class="mono" style="color:var(--muted)"><?= htmlspecialchars($att['uploaded_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$attachments): ?><tr><td colspan="3" style="color:var(--muted)">No attachments yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Finalize -->
    <div class="card">
      <h3>Finalize Offer &rarr; Convert to Contract</h3>
      <?php if ($contract['status'] === 'finalised'): ?>
        <p style="color:var(--cyan);">This offer was finalised. Start: <span class="mono"><?= htmlspecialchars($contract['start_date']) ?></span> &middot; End: <span class="mono"><?= htmlspecialchars($contract['end_date']) ?></span></p>
      <?php elseif (isAdminOrSuperAdmin()): ?>
        <p style="color:var(--muted); font-size:12.5px;">Set the effective start and end dates, then finalize to convert this draft offer into an active contract.</p>
        <form method="post">
          <input type="hidden" name="action" value="finalize">
          <div class="grid grid-4">
            <div class="field"><label>Start Date (Effective Date) *</label><input type="text" class="datepicker" name="start_date" autocomplete="off" placeholder="YYYY-MM-DD" required></div>
            <div class="field"><label>End Date *</label><input type="text" class="datepicker" name="end_date" autocomplete="off" placeholder="YYYY-MM-DD" required></div>
          </div>
          <button type="submit" class="btn primary" onclick="return confirm('Finalize this offer and convert it to a contract? This cannot be undone.');">Finalize &amp; Convert to Contract</button>
        </form>
      <?php else: ?>
        <p style="color:var(--muted); font-size:12.5px;">This offer is still in draft. Only Admin or Super Admin can finalize it.</p>
      <?php endif; ?>
    </div>
  </main>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.js"></script>
<script>
  var toggleEditBtn = document.getElementById('toggleEditContract');
  if (toggleEditBtn) {
    toggleEditBtn.addEventListener('click', function () {
      var f = document.getElementById('editContractForm');
      f.style.display = (f.style.display === 'none' || !f.style.display) ? 'block' : 'none';
    });
  }

  var toggleOperatorBtn = document.getElementById('toggleOperatorForm');
  if (toggleOperatorBtn) {
    toggleOperatorBtn.addEventListener('click', function () {
      var f = document.getElementById('operatorForm');
      f.style.display = (f.style.display === 'none' || !f.style.display) ? 'block' : 'none';
    });
  }

  var toggleRateBtn = document.getElementById('toggleRateForm');
  if (toggleRateBtn) {
    toggleRateBtn.addEventListener('click', function () {
      var f = document.getElementById('rateForm');
      f.style.display = (f.style.display === 'none' || !f.style.display) ? 'block' : 'none';
    });
  }

  var RATE_MASTER = {
    locations: <?= json_encode($rateLocations) ?>,
    units: <?= json_encode($rateUnits) ?>,
    priorities: <?= json_encode($ratePriorities) ?>,
    modTypes: <?= json_encode($rateModTypes) ?>
  };

  function buildOptions(values) {
    return values.map(function (v) {
      var opt = document.createElement('option');
      opt.textContent = v;
      return opt.outerHTML;
    }).join('');
  }

  var addRateRowBtn = document.getElementById('addRateRow');
  if (addRateRowBtn) {
    addRateRowBtn.addEventListener('click', function () {
      var rows = document.getElementById('rateRows');
      var row = document.createElement('div');
      row.className = 'grid grid-4 rate-row';
      row.style.marginBottom = '8px';
      row.innerHTML = `
        <div class="field"><label>Location</label><select name="loc_location[]">${buildOptions(RATE_MASTER.locations)}</select></div>
        <div class="field"><label>Per</label><select name="loc_per[]">${buildOptions(RATE_MASTER.units)}</select></div>
        <div class="field"><label>Priority</label>
          <select name="loc_priority[]">${buildOptions(RATE_MASTER.priorities)}</select>
        </div>
        <div class="field"><label>Mod Type</label>
          <select name="loc_mod[]">${buildOptions(RATE_MASTER.modTypes)}</select>
        </div>`;
      rows.appendChild(row);
      var rateField = document.createElement('div');
      rateField.className = 'field';
      rateField.style.maxWidth = '200px';
      rateField.innerHTML = '<label>Rate</label><input type="number" step="0.01" name="loc_rate[]">';
      rows.appendChild(rateField);
    });
  }

  flatpickr('.datepicker', { dateFormat: 'Y-m-d', allowInput: true });
</script>
</body>
</html>
