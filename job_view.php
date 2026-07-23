<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/invoice_pdf.php';
require_once __DIR__ . '/includes/gst.php';
requireLogin();

$pdo = getConnection();
$jobId = (int)($_GET['id'] ?? 0);
$message = '';
$messageType = 'error';

$stmt = $pdo->prepare(
    "SELECT j.*, ct.contract_number, ct.currency, ct.status AS contract_status,
            ct.gst_applicable, ct.gst_rate,
            c.customer_name, c.id AS customer_id,
            CONCAT(u.first_name, ' ', u.last_name) AS assigned_name,
            CONCAT(cb.first_name, ' ', cb.last_name) AS completed_name,
            sc.name AS category_name,
            ri.location, ri.per_unit, ri.priority, ri.mod_type,
            rg.rate_for
     FROM jobs j
     JOIN contracts ct ON ct.id = j.contract_id
     JOIN customers c ON c.id = ct.customer_id
     LEFT JOIN users u ON u.id = j.assigned_to
     LEFT JOIN users cb ON cb.id = j.completed_by
     LEFT JOIN service_categories sc ON sc.id = j.service_category_id
     LEFT JOIN contract_rate_items ri ON ri.id = j.rate_item_id
     LEFT JOIN contract_rate_groups rg ON rg.id = j.rate_group_id
     WHERE j.id = :id"
);
$stmt->execute(['id' => $jobId]);
$job = $stmt->fetch();

if (!$job) {
    header('Location: jobs.php');
    exit;
}

// Complete the job — the assigned worker or an Admin/Super Admin can do this.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_job') {
    if (!canCloseJob($job)) {
        $message = 'Only the assigned user, Admin, or Super Admin can close this job.';
    } elseif ($job['work_status'] === 'completed') {
        $message = 'This job is already completed.';
    } else {
        $qty = $_POST['quantity_completed'] ?? '';
        if ($qty === '' || !is_numeric($qty)) {
            $message = 'Enter the quantity completed.';
        } else {
            $stmt = $pdo->prepare(
                "UPDATE jobs SET work_status = 'completed', quantity_completed = :qty,
                    completion_notes = :notes, completed_at = NOW(), completed_by = :uid
                 WHERE id = :id"
            );
            $stmt->execute([
                'qty' => $qty,
                'notes' => $_POST['completion_notes'] ?: null,
                'uid' => $_SESSION['user_id'],
                'id' => $jobId,
            ]);
            logAction($_SESSION['user_id'], 'job.complete', "Closed job #$jobId ({$job['job_number']}), qty=$qty");
            header("Location: job_view.php?id=$jobId");
            exit;
        }
    }
}

// Generate Proforma Invoice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_proforma') {
    if (!canManageBilling()) {
        $message = 'Only Billing, Admin, or Super Admin can generate invoices.';
    } elseif ($job['work_status'] !== 'completed') {
        $message = 'This job must be completed before billing.';
    } elseif ($job['billing_status'] !== 'pending') {
        $message = 'A proforma invoice was already generated for this job.';
    } else {
        $amount = (float)$job['quantity_completed'] * (float)$job['unit_rate'];
        $recipient = resolveGstParty($pdo, (int)$job['contract_id'], (int)$job['customer_id']);
        $gst = computeGstBreakup($amount, $job['gst_applicable'] ?? 'yes', (float)($job['gst_rate'] ?? 0), $recipient['state']);
        $invoiceNumber = generateInvoiceNumber($pdo, 'proforma');
        $pdfPath = generateInvoicePdf($job, [
            'id' => $job['contract_id'], 'contract_number' => $job['contract_number'],
            'customer_name' => $job['customer_name'], 'currency' => $job['currency'],
        ], 'proforma', $invoiceNumber, $amount, $gst, $recipient);

        $stmt = $pdo->prepare(
            "INSERT INTO invoices
                (invoice_number, invoice_type, job_id, contract_id, amount,
                 recipient_gstin, recipient_state, place_of_supply, tax_type, gst_rate,
                 cgst_amount, sgst_amount, igst_amount, total_amount, generated_by, pdf_path)
             VALUES
                (:num, 'proforma', :jid, :cid, :amt,
                 :rgstin, :rstate, :pos, :ttype, :grate,
                 :cgst, :sgst, :igst, :total, :uid, :path)"
        );
        $stmt->execute([
            'num' => $invoiceNumber, 'jid' => $jobId, 'cid' => $job['contract_id'],
            'amt' => $amount, 'rgstin' => $recipient['gstin'], 'rstate' => $recipient['state'],
            'pos' => $recipient['state'], 'ttype' => $gst['tax_type'], 'grate' => $gst['gst_rate'],
            'cgst' => $gst['cgst_amount'], 'sgst' => $gst['sgst_amount'], 'igst' => $gst['igst_amount'],
            'total' => $gst['total_amount'], 'uid' => $_SESSION['user_id'], 'path' => $pdfPath,
        ]);
        $pdo->prepare("UPDATE jobs SET billing_status = 'proforma_generated' WHERE id = :id")->execute(['id' => $jobId]);

        logAction($_SESSION['user_id'], 'invoice.proforma_generate', "Generated proforma $invoiceNumber for job #$jobId");
        header("Location: job_view.php?id=$jobId");
        exit;
    }
}

// Generate Final Invoice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_final') {
    if (!canManageBilling()) {
        $message = 'Only Billing, Admin, or Super Admin can generate invoices.';
    } elseif ($job['billing_status'] !== 'proforma_generated') {
        $message = 'Generate the proforma invoice first.';
    } else {
        $amount = (float)$job['quantity_completed'] * (float)$job['unit_rate'];
        $recipient = resolveGstParty($pdo, (int)$job['contract_id'], (int)$job['customer_id']);
        $gst = computeGstBreakup($amount, $job['gst_applicable'] ?? 'yes', (float)($job['gst_rate'] ?? 0), $recipient['state']);
        $invoiceNumber = generateInvoiceNumber($pdo, 'final');
        $pdfPath = generateInvoicePdf($job, [
            'id' => $job['contract_id'], 'contract_number' => $job['contract_number'],
            'customer_name' => $job['customer_name'], 'currency' => $job['currency'],
        ], 'final', $invoiceNumber, $amount, $gst, $recipient);

        $stmt = $pdo->prepare(
            "INSERT INTO invoices
                (invoice_number, invoice_type, job_id, contract_id, amount,
                 recipient_gstin, recipient_state, place_of_supply, tax_type, gst_rate,
                 cgst_amount, sgst_amount, igst_amount, total_amount, generated_by, pdf_path)
             VALUES
                (:num, 'final', :jid, :cid, :amt,
                 :rgstin, :rstate, :pos, :ttype, :grate,
                 :cgst, :sgst, :igst, :total, :uid, :path)"
        );
        $stmt->execute([
            'num' => $invoiceNumber, 'jid' => $jobId, 'cid' => $job['contract_id'],
            'amt' => $amount, 'rgstin' => $recipient['gstin'], 'rstate' => $recipient['state'],
            'pos' => $recipient['state'], 'ttype' => $gst['tax_type'], 'grate' => $gst['gst_rate'],
            'cgst' => $gst['cgst_amount'], 'sgst' => $gst['sgst_amount'], 'igst' => $gst['igst_amount'],
            'total' => $gst['total_amount'], 'uid' => $_SESSION['user_id'], 'path' => $pdfPath,
        ]);
        $pdo->prepare("UPDATE jobs SET billing_status = 'final_invoiced' WHERE id = :id")->execute(['id' => $jobId]);

        logAction($_SESSION['user_id'], 'invoice.final_generate', "Generated final invoice $invoiceNumber for job #$jobId");
        header("Location: job_view.php?id=$jobId");
        exit;
    }
}

$invoices = $pdo->prepare('SELECT * FROM invoices WHERE job_id = :id ORDER BY id');
$invoices->execute(['id' => $jobId]);
$invoices = $invoices->fetchAll();

$computedAmount = ($job['quantity_completed'] !== null && $job['unit_rate'] !== null)
    ? (float)$job['quantity_completed'] * (float)$job['unit_rate']
    : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($job['job_number'] ?? ('Job #' . $jobId)) ?> — RBAC Console</title>
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
      <div class="breadcrumb"><a href="jobs.php">Jobs</a> &middot; <strong><?= htmlspecialchars($job['job_number'] ?? ('#' . $jobId)) ?></strong> &middot; <?= htmlspecialchars($job['customer_name']) ?></div>
      <span class="badge <?= $job['work_status'] === 'completed' ? 'active' : 'inactive' ?>" style="font-size:13px; padding:6px 14px;"><?= htmlspecialchars($job['work_status']) ?></span>
    </div>

    <?php if ($message): ?>
      <div class="error-box" <?= $messageType === 'success' ? 'style="background:var(--cyan-dim); border-color:var(--cyan); color:var(--cyan);"' : '' ?>><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:20px; background:var(--panel-alt);">
      <p style="margin:0; font-size:12.5px; color:var(--muted);">
        This job draws against contract <a href="contract_view.php?id=<?= $job['contract_id'] ?>"><strong><?= htmlspecialchars($job['contract_number'] ?? '#' . $job['contract_id']) ?></strong></a>,
        which stays <strong><?= htmlspecialchars($job['contract_status']) ?></strong> regardless of this job's status — completing or billing a job never closes the contract itself.
      </p>
    </div>

    <div class="grid grid-4" style="margin-bottom:20px;">
      <div class="card"><div class="card-title">Title</div><div style="font-size:15px; font-weight:600;"><?= htmlspecialchars($job['title']) ?></div></div>
      <div class="card"><div class="card-title">Rate Structure</div><div style="font-size:13px;"><?= htmlspecialchars(($job['rate_for'] ?? '—') . ' — ' . ($job['location'] ?? '') . ' / ' . ($job['per_unit'] ?? '')) ?></div></div>
      <div class="card"><div class="card-title">Unit Rate</div><div class="mono" style="font-size:15px; color:var(--cyan);"><?= htmlspecialchars($job['currency'] . ' ' . number_format((float)$job['unit_rate'], 2)) ?></div></div>
      <div class="card"><div class="card-title">Service Category</div><div style="font-size:15px;"><?= htmlspecialchars($job['category_name'] ?? '—') ?></div></div>
    </div>
    <div class="grid grid-4" style="margin-bottom:20px;">
      <div class="card"><div class="card-title">Assigned To</div><div style="font-size:15px;"><?= htmlspecialchars($job['assigned_name'] ?? 'Unassigned') ?></div></div>
      <div class="card"><div class="card-title">Quantity Planned</div><div class="mono" style="font-size:15px;"><?= $job['quantity_planned'] !== null ? number_format((float)$job['quantity_planned'], 2) : '—' ?></div></div>
      <div class="card"><div class="card-title">Quantity Completed</div><div class="mono" style="font-size:15px;"><?= $job['quantity_completed'] !== null ? number_format((float)$job['quantity_completed'], 2) : '—' ?></div></div>
      <div class="card"><div class="card-title">Job Amount</div><div class="mono" style="font-size:15px; color:var(--cyan);"><?= $computedAmount !== null ? htmlspecialchars($job['currency'] . ' ' . number_format($computedAmount, 2)) : '—' ?></div></div>
      <div class="card"><div class="card-title">GST</div><div class="mono" style="font-size:15px;"><?= ($job['gst_applicable'] ?? 'yes') === 'yes' ? htmlspecialchars(number_format((float)($job['gst_rate'] ?? 0), 2) . '% (per contract)') : 'Not applicable' ?></div></div>
    </div>

    <?php if ($job['description']): ?>
    <div class="card" style="margin-bottom:20px;">
      <div class="card-title">Description</div>
      <p style="margin:6px 0 0; font-size:13.5px;"><?= nl2br(htmlspecialchars($job['description'])) ?></p>
    </div>
    <?php endif; ?>

    <!-- Complete Job -->
    <div class="card" style="margin-bottom:20px;">
      <h3>Job Completion</h3>
      <?php if ($job['work_status'] === 'completed'): ?>
        <p style="color:var(--cyan); font-size:13.5px;">
          Completed by <strong><?= htmlspecialchars($job['completed_name'] ?? 'Unknown') ?></strong> on <span class="mono"><?= htmlspecialchars($job['completed_at']) ?></span>
        </p>
        <?php if ($job['completion_notes']): ?>
          <p style="color:var(--muted); font-size:13px; margin-top:8px;"><?= nl2br(htmlspecialchars($job['completion_notes'])) ?></p>
        <?php endif; ?>
      <?php elseif (canCloseJob($job)): ?>
        <p style="color:var(--muted); font-size:12.5px;">Enter the actual quantity completed and any notes, then close the job. Once closed, it becomes visible to Billing for invoicing.</p>
        <form method="post">
          <input type="hidden" name="action" value="complete_job">
          <div class="grid grid-4">
            <div class="field"><label>Quantity Completed *</label><input type="number" step="0.01" name="quantity_completed" required></div>
          </div>
          <div class="field"><label>Completion Notes</label><input name="completion_notes"></div>
          <button type="submit" class="btn primary" onclick="return confirm('Close this job? It will become visible to Billing.');">Complete &amp; Close Job</button>
        </form>
      <?php else: ?>
        <p style="color:var(--muted); font-size:12.5px;">Only the assigned user, Admin, or Super Admin can close this job.</p>
      <?php endif; ?>
    </div>

    <!-- Billing -->
    <div class="card">
      <h3>Billing</h3>
      <?php if ($job['work_status'] !== 'completed'): ?>
        <p style="color:var(--muted); font-size:12.5px;">This job isn't billable yet — it needs to be completed and closed first.</p>
      <?php else: ?>
        <?php if (canManageBilling()): ?>
          <?php if ($job['billing_status'] === 'pending'): ?>
            <form method="post" style="margin-bottom:14px;">
              <input type="hidden" name="action" value="generate_proforma">
              <button type="submit" class="btn primary">Generate Proforma Invoice</button>
            </form>
          <?php elseif ($job['billing_status'] === 'proforma_generated'): ?>
            <form method="post" style="margin-bottom:14px;">
              <input type="hidden" name="action" value="generate_final">
              <button type="submit" class="btn primary" onclick="return confirm('Generate the Final Invoice for this job?');">Generate Final Invoice</button>
            </form>
          <?php else: ?>
            <p style="color:var(--cyan); font-size:13px; margin-bottom:14px;">Final invoice issued — billing complete for this job.</p>
          <?php endif; ?>
        <?php else: ?>
          <p style="color:var(--muted); font-size:12.5px; margin-bottom:14px;">Only Billing, Admin, or Super Admin can generate invoices.</p>
        <?php endif; ?>

        <div class="table-wrap" style="border:none;">
          <table>
            <thead><tr><th>Invoice No.</th><th>Type</th><th>Taxable Value</th><th>GST</th><th>Total</th><th>Generated</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($invoices as $inv): ?>
              <?php
                $taxLabel = '—';
                if ($inv['tax_type'] === 'cgst_sgst') {
                    $taxLabel = 'CGST+SGST ' . number_format((float)$inv['gst_rate'], 2) . '% = ' . $job['currency'] . ' ' . number_format((float)$inv['cgst_amount'] + (float)$inv['sgst_amount'], 2);
                } elseif ($inv['tax_type'] === 'igst') {
                    $taxLabel = 'IGST ' . number_format((float)$inv['gst_rate'], 2) . '% = ' . $job['currency'] . ' ' . number_format((float)$inv['igst_amount'], 2);
                } else {
                    $taxLabel = 'Not applicable';
                }
              ?>
              <tr>
                <td class="mono"><?= htmlspecialchars($inv['invoice_number']) ?></td>
                <td><span class="badge <?= $inv['invoice_type'] === 'final' ? 'active' : 'inactive' ?>"><?= htmlspecialchars($inv['invoice_type'] === 'final' ? 'tax invoice' : $inv['invoice_type']) ?></span></td>
                <td class="mono"><?= htmlspecialchars($job['currency'] . ' ' . number_format((float)$inv['amount'], 2)) ?></td>
                <td class="mono" style="color:var(--muted); font-size:12px;"><?= htmlspecialchars($taxLabel) ?></td>
                <td class="mono" style="color:var(--cyan)"><strong><?= htmlspecialchars($job['currency'] . ' ' . number_format((float)$inv['total_amount'], 2)) ?></strong></td>
                <td class="mono" style="color:var(--muted)"><?= htmlspecialchars($inv['generated_at']) ?></td>
                <td><a href="<?= htmlspecialchars($inv['pdf_path']) ?>" target="_blank">Download PDF</a></td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$invoices): ?><tr><td colspan="7" style="color:var(--muted)">No invoices generated yet.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>
</body>
</html>
