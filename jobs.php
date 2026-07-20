<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pdo = getConnection();
$message = '';
$messageType = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_job') {
    if (!canCreate()) {
        $message = 'You do not have permission to create jobs.';
    } else {
        $contractId = (int)($_POST['contract_id'] ?? 0);
        $rateItemId = (int)($_POST['rate_item_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');

        if (!$contractId || !$rateItemId || $title === '') {
            $message = 'Contract, rate item, and title are required.';
        } else {
            // Look up the rate item server-side rather than trusting a client-sent rate,
            // and pull its rate_group_id so both are stored consistently.
            $rateStmt = $pdo->prepare(
                "SELECT ri.id, ri.rate, ri.rate_group_id FROM contract_rate_items ri
                 JOIN contract_rate_groups rg ON rg.id = ri.rate_group_id
                 WHERE ri.id = :id AND rg.contract_id = :cid"
            );
            $rateStmt->execute(['id' => $rateItemId, 'cid' => $contractId]);
            $rateItem = $rateStmt->fetch();

            if (!$rateItem) {
                $message = 'Selected rate item does not belong to the chosen contract.';
            } else {
                $jobNumber = generateJobNumber($pdo);
                $stmt = $pdo->prepare(
                    "INSERT INTO jobs
                        (job_number, contract_id, rate_group_id, rate_item_id, service_category_id, assigned_to,
                         title, description, quantity_planned, unit_rate, created_by)
                     VALUES
                        (:jnum, :cid, :rgid, :riid, :catid, :assigned, :title, :desc, :qty, :rate, :creator)"
                );
                $stmt->execute([
                    'jnum' => $jobNumber,
                    'cid' => $contractId,
                    'rgid' => $rateItem['rate_group_id'],
                    'riid' => $rateItem['id'],
                    'catid' => $_POST['service_category_id'] ?: null,
                    'assigned' => $_POST['assigned_to'] ?: null,
                    'title' => $title,
                    'desc' => $_POST['description'] ?: null,
                    'qty' => $_POST['quantity_planned'] !== '' ? $_POST['quantity_planned'] : null,
                    'rate' => $rateItem['rate'],
                    'creator' => $_SESSION['user_id'],
                ]);
                $jobId = (int)$pdo->lastInsertId();
                logAction($_SESSION['user_id'], 'job.create', "Created job #$jobId ($jobNumber)");
                header("Location: job_view.php?id=$jobId");
                exit;
            }
        }
    }
}

$contracts = $pdo->query(
    "SELECT ct.id, ct.contract_number, c.customer_name FROM contracts ct
     JOIN customers c ON c.id = ct.customer_id ORDER BY ct.id DESC"
)->fetchAll();

// All rate items across all contracts, with a display label — filtered client-side by contract.
$allRateItems = $pdo->query(
    "SELECT ri.id, ri.location, ri.per_unit, ri.priority, ri.mod_type, ri.rate,
            rg.contract_id, rg.rate_for
     FROM contract_rate_items ri
     JOIN contract_rate_groups rg ON rg.id = ri.rate_group_id
     ORDER BY rg.contract_id, ri.location"
)->fetchAll();

$categories = $pdo->query("SELECT id, name FROM service_categories WHERE status = 'active' ORDER BY name")->fetchAll();

// All active users tagged per category, for client-side filtering of "Assigned To".
$usersByCategory = $pdo->query(
    "SELECT usc.service_category_id, u.id, u.first_name, u.last_name
     FROM user_service_categories usc
     JOIN users u ON u.id = usc.user_id
     WHERE u.status = 'active'
     ORDER BY u.first_name"
)->fetchAll();

$onlyMine = isset($_GET['mine']);
$jobsQuery = "SELECT j.*, ct.contract_number, c.customer_name,
                     CONCAT(u.first_name, ' ', u.last_name) AS assigned_name,
                     sc.name AS category_name
              FROM jobs j
              JOIN contracts ct ON ct.id = j.contract_id
              JOIN customers c ON c.id = ct.customer_id
              LEFT JOIN users u ON u.id = j.assigned_to
              LEFT JOIN service_categories sc ON sc.id = j.service_category_id";
if ($onlyMine) {
    $jobsQuery .= " WHERE j.assigned_to = " . (int)$_SESSION['user_id'];
}
$jobsQuery .= " ORDER BY j.id DESC";
$jobs = $pdo->query($jobsQuery)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Jobs — RBAC Console</title>
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
      <div class="breadcrumb">
        <strong>Jobs</strong> &middot; <?= count($jobs) ?> <?= $onlyMine ? 'assigned to you' : 'total' ?>
        &middot; <a href="jobs.php<?= $onlyMine ? '' : '?mine=1' ?>"><?= $onlyMine ? 'Show all' : 'Show only mine' ?></a>
      </div>
      <?php if (canCreate()): ?>
      <button type="button" class="btn primary" id="toggleNewJob">+ New Job</button>
      <?php endif; ?>
    </div>

    <?php if ($message): ?>
      <div class="error-box" <?= $messageType === 'success' ? 'style="background:var(--cyan-dim); border-color:var(--cyan); color:var(--cyan);"' : '' ?>><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if (canCreate()): ?>
    <div class="card" id="newJobForm" style="display:none; margin-bottom:20px;">
      <h3>New Job</h3>
      <form method="post">
        <input type="hidden" name="action" value="create_job">
        <div class="grid grid-4">
          <div class="field">
            <label>Contract *</label>
            <select name="contract_id" id="jobContract" required>
              <option value="">-- Select Contract --</option>
              <?php foreach ($contracts as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars(($c['contract_number'] ?? '#' . $c['id']) . ' — ' . $c['customer_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="grid-column: span 2;">
            <label>Rate Item (from that contract's rate structure) *</label>
            <select name="rate_item_id" id="jobRateItem" required>
              <option value="">-- Select a contract first --</option>
            </select>
          </div>
          <div class="field">
            <label>Service Category</label>
            <select name="service_category_id" id="jobCategory">
              <option value="">-- Select --</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="grid grid-4">
          <div class="field" style="grid-column: span 2;">
            <label>Title *</label>
            <input name="title" required placeholder="e.g. Port Clearance — Vessel XYZ">
          </div>
          <div class="field">
            <label>Assigned To</label>
            <select name="assigned_to" id="jobAssignee">
              <option value="">-- Select category first --</option>
            </select>
          </div>
          <div class="field"><label>Quantity Planned</label><input type="number" step="0.01" name="quantity_planned"></div>
        </div>
        <div class="field"><label>Description</label><input name="description"></div>
        <button type="submit" class="btn primary">Create Job</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Job No.</th><th>Title</th><th>Contract</th><th>Category</th><th>Assigned To</th><th>Work Status</th><th>Billing</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($jobs as $j): ?>
          <tr>
            <td class="mono" style="color:var(--cyan)"><?= htmlspecialchars($j['job_number'] ?? '—') ?></td>
            <td><?= htmlspecialchars($j['title']) ?></td>
            <td style="color:var(--muted)"><?= htmlspecialchars(($j['contract_number'] ?? '—') . ' · ' . $j['customer_name']) ?></td>
            <td><?= htmlspecialchars($j['category_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($j['assigned_name'] ?? '—') ?></td>
            <td><span class="badge <?= $j['work_status'] === 'completed' ? 'active' : 'inactive' ?>"><?= htmlspecialchars($j['work_status']) ?></span></td>
            <td><span class="badge <?= $j['billing_status'] === 'final_invoiced' ? 'active' : ($j['billing_status'] === 'proforma_generated' ? 'inactive' : 'suspended') ?>"><?= htmlspecialchars($j['billing_status']) ?></span></td>
            <td><a href="job_view.php?id=<?= $j['id'] ?>">Open &rarr;</a></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$jobs): ?>
          <tr><td colspan="8" style="color:var(--muted)">No jobs yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>
</div>

<script>
  var RATE_ITEMS = <?= json_encode($allRateItems) ?>;
  var USERS_BY_CATEGORY = <?= json_encode($usersByCategory) ?>;

  var toggleNewJobBtn = document.getElementById('toggleNewJob');
  if (toggleNewJobBtn) {
    toggleNewJobBtn.addEventListener('click', function () {
      var form = document.getElementById('newJobForm');
      form.style.display = (form.style.display === 'none' || !form.style.display) ? 'block' : 'none';
    });
  }

  var contractSelect = document.getElementById('jobContract');
  var rateItemSelect = document.getElementById('jobRateItem');
  if (contractSelect && rateItemSelect) {
    contractSelect.addEventListener('change', function () {
      var contractId = parseInt(this.value, 10);
      rateItemSelect.innerHTML = '';
      var matches = RATE_ITEMS.filter(function (ri) { return ri.contract_id == contractId; });
      if (!matches.length) {
        rateItemSelect.innerHTML = '<option value="">No rate structure on this contract yet</option>';
        return;
      }
      rateItemSelect.innerHTML = '<option value="">-- Select --</option>';
      matches.forEach(function (ri) {
        var opt = document.createElement('option');
        opt.value = ri.id;
        opt.textContent = ri.rate_for + ' — ' + ri.location + ' / ' + ri.per_unit + ' / ' + ri.priority + ' / ' + ri.mod_type + ' — Rate ' + ri.rate;
        rateItemSelect.appendChild(opt);
      });
    });
  }

  var categorySelect = document.getElementById('jobCategory');
  var assigneeSelect = document.getElementById('jobAssignee');
  if (categorySelect && assigneeSelect) {
    categorySelect.addEventListener('change', function () {
      var catId = parseInt(this.value, 10);
      assigneeSelect.innerHTML = '';
      var matches = USERS_BY_CATEGORY.filter(function (u) { return u.service_category_id == catId; });
      if (!matches.length) {
        assigneeSelect.innerHTML = '<option value="">No users in this category</option>';
        return;
      }
      assigneeSelect.innerHTML = '<option value="">-- Select --</option>';
      matches.forEach(function (u) {
        var opt = document.createElement('option');
        opt.value = u.id;
        opt.textContent = u.first_name + ' ' + u.last_name;
        assigneeSelect.appendChild(opt);
      });
    });
  }
</script>
</body>
</html>
