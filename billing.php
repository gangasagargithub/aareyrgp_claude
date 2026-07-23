<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pdo = getConnection();

$jobs = $pdo->query(
    "SELECT j.*, ct.contract_number, ct.currency, c.customer_name,
            CONCAT(u.first_name, ' ', u.last_name) AS assigned_name
     FROM jobs j
     JOIN contracts ct ON ct.id = j.contract_id
     JOIN customers c ON c.id = ct.customer_id
     LEFT JOIN users u ON u.id = j.assigned_to
     WHERE j.work_status = 'completed'
     ORDER BY (j.billing_status = 'final_invoiced') ASC, j.completed_at DESC"
)->fetchAll();

$pendingCount = 0;
$proformaCount = 0;
$doneCount = 0;
foreach ($jobs as $j) {
    if ($j['billing_status'] === 'pending') $pendingCount++;
    elseif ($j['billing_status'] === 'proforma_generated') $proformaCount++;
    elseif ($j['billing_status'] === 'final_invoiced') $doneCount++;
}

// Total billing amount per customer (grouped by currency, since a customer
// can have contracts billed in more than one currency).
$customerTotals = [];
foreach ($jobs as $j) {
    if ($j['quantity_completed'] === null || $j['unit_rate'] === null) continue;
    $amount = (float)$j['quantity_completed'] * (float)$j['unit_rate'];
    $key = $j['customer_name'] . '|' . $j['currency'];
    if (!isset($customerTotals[$key])) {
        $customerTotals[$key] = [
            'customer_name' => $j['customer_name'],
            'currency' => $j['currency'],
            'total' => 0.0,
            'job_count' => 0,
        ];
    }
    $customerTotals[$key]['total'] += $amount;
    $customerTotals[$key]['job_count']++;
}
usort($customerTotals, fn($a, $b) => $b['total'] <=> $a['total']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Billing Queue — RBAC Console</title>
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
      <div class="breadcrumb"><strong>Billing Queue</strong> &middot; completed jobs ready for invoicing</div>
    </div>

    <div class="grid grid-4" style="margin-bottom:20px;">
      <div class="card"><div class="card-title">Awaiting Proforma</div><div class="stat-value amber"><?= $pendingCount ?></div></div>
      <div class="card"><div class="card-title">Proforma Issued</div><div class="stat-value" style="color:var(--muted)"><?= $proformaCount ?></div></div>
      <div class="card"><div class="card-title">Final Invoiced</div><div class="stat-value cyan"><?= $doneCount ?></div></div>
    </div>

    <?php if (!canManageBilling()): ?>
    <div class="error-box" style="margin-bottom:20px;">You can view this queue, but only Billing, Admin, or Super Admin can generate invoices. Open a job to see options.</div>
    <?php endif; ?>

    <h3 style="margin:0 2px 2px;">Total Billing by Customer</h3>
    <p style="margin:0 0 10px; color:var(--muted); font-size:12px;">Taxable value (excl. GST) across completed jobs — see each job's invoices for the GST breakup.</p>
    <div class="table-wrap" style="margin-bottom:20px;">
      <table>
        <thead>
          <tr><th>Customer</th><th>Completed Jobs</th><th>Total Billing Amount (excl. GST)</th></tr>
        </thead>
        <tbody>
          <?php foreach ($customerTotals as $ct): ?>
          <tr>
            <td><?= htmlspecialchars($ct['customer_name']) ?></td>
            <td class="mono" style="color:var(--muted)"><?= $ct['job_count'] ?></td>
            <td class="mono" style="color:var(--cyan)"><strong><?= htmlspecialchars($ct['currency'] . ' ' . number_format($ct['total'], 2)) ?></strong></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$customerTotals): ?>
          <tr><td colspan="3" style="color:var(--muted)">No billable amounts yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Job No.</th><th>Title</th><th>Contract</th><th>Customer</th><th>Completed</th><th>Amount</th><th>Billing Status</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($jobs as $j): ?>
          <?php $amount = ($j['quantity_completed'] !== null && $j['unit_rate'] !== null) ? (float)$j['quantity_completed'] * (float)$j['unit_rate'] : null; ?>
          <tr>
            <td class="mono" style="color:var(--cyan)"><?= htmlspecialchars($j['job_number'] ?? '—') ?></td>
            <td><?= htmlspecialchars($j['title']) ?></td>
            <td class="mono" style="color:var(--muted)"><?= htmlspecialchars($j['contract_number'] ?? '—') ?></td>
            <td><?= htmlspecialchars($j['customer_name']) ?></td>
            <td class="mono" style="color:var(--muted)"><?= htmlspecialchars($j['completed_at'] ?? '—') ?></td>
            <td class="mono"><?= $amount !== null ? htmlspecialchars($j['currency'] . ' ' . number_format($amount, 2)) : '—' ?></td>
            <td><span class="badge <?= $j['billing_status'] === 'final_invoiced' ? 'active' : ($j['billing_status'] === 'proforma_generated' ? 'inactive' : 'suspended') ?>"><?= htmlspecialchars($j['billing_status']) ?></span></td>
            <td><a href="job_view.php?id=<?= $j['id'] ?>">Open &rarr;</a></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$jobs): ?>
          <tr><td colspan="8" style="color:var(--muted)">No completed jobs yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>
</div>
</body>
</html>
