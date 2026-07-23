<?php
require_once __DIR__ . '/../lib/fpdf/fpdf.php';
require_once __DIR__ . '/../config/company.php';

/**
 * Generates a Proforma Invoice or GST Tax Invoice PDF for a completed job.
 * Returns the relative path (from the app root) where the PDF was saved.
 *
 * $job must include: job_number, title, quantity_completed, unit_rate, completed_at
 * $contract must include: id/contract_number, customer_name, currency
 * $type is 'proforma' or 'final' ('final' is rendered as a GST Tax Invoice)
 * $amount is the taxable value (quantity_completed * unit_rate) — the "actual" rate, unrounded
 * $gst is the breakup array returned by computeGstBreakup(): tax_type, gst_rate,
 *      cgst_amount, sgst_amount, igst_amount, total_amount
 * $recipient is the billing party info returned by resolveGstParty(): gstin, state, label
 */
function generateInvoicePdf(array $job, array $contract, string $type, string $invoiceNumber, float $amount, array $gst, array $recipient = []): string
{
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetMargins(18, 18, 18);

    // Letterhead
    $pdf->SetFont('Helvetica', 'B', 16);
    $pdf->Cell(0, 8, COMPANY_LEGAL_NAME, 0, 1);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell(0, 5, COMPANY_ADDRESS, 0, 1);
    $pdf->Cell(0, 5, 'GSTIN: ' . COMPANY_GSTIN . '  |  State: ' . COMPANY_STATE, 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(4);

    // Document title — a Final invoice under GST is a Tax Invoice
    $label = $type === 'proforma' ? 'PROFORMA INVOICE' : 'TAX INVOICE';
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->SetFillColor($type === 'proforma' ? 245 : 13, $type === 'proforma' ? 166 : 148, $type === 'proforma' ? 35 : 136);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 10, '  ' . $label, 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(4);

    // Invoice meta
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell(40, 6, 'Invoice No:', 0, 0);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(0, 6, $invoiceNumber, 0, 1);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell(40, 6, 'Date:', 0, 0);
    $pdf->Cell(0, 6, date('d-M-Y'), 0, 1);
    $pdf->Cell(40, 6, 'Contract No:', 0, 0);
    $pdf->Cell(0, 6, $contract['contract_number'] ?? ('#' . $contract['id']), 0, 1);
    $pdf->Cell(40, 6, 'Bill To:', 0, 0);
    $pdf->Cell(0, 6, $contract['customer_name'], 0, 1);
    if (!empty($recipient['label'])) {
        $pdf->Cell(40, 6, '', 0, 0);
        $pdf->Cell(0, 6, $recipient['label'], 0, 1);
    }
    $pdf->Cell(40, 6, 'Recipient GSTIN:', 0, 0);
    $pdf->Cell(0, 6, $recipient['gstin'] ?? 'Unregistered / Not on file', 0, 1);
    $pdf->Cell(40, 6, 'Place of Supply:', 0, 0);
    $pdf->Cell(0, 6, $recipient['state'] ?? '-', 0, 1);
    $pdf->Ln(6);

    // Job / line item table
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(24, 8, 'Job No.', 1, 0, 'L', true);
    $pdf->Cell(50, 8, 'Description', 1, 0, 'L', true);
    $pdf->Cell(18, 8, 'SAC', 1, 0, 'L', true);
    $pdf->Cell(20, 8, 'Qty', 1, 0, 'R', true);
    $pdf->Cell(25, 8, 'Rate (Actual)', 1, 0, 'R', true);
    $pdf->Cell(0, 8, 'Amount', 1, 1, 'R', true);

    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(24, 8, $job['job_number'] ?? ('#' . $job['id']), 1, 0);
    $pdf->Cell(50, 8, substr($job['title'], 0, 30), 1, 0);
    $pdf->Cell(18, 8, COMPANY_DEFAULT_SAC_CODE, 1, 0);
    $pdf->Cell(20, 8, number_format((float)$job['quantity_completed'], 2), 1, 0, 'R');
    $pdf->Cell(25, 8, number_format((float)$job['unit_rate'], 2), 1, 0, 'R');
    $pdf->Cell(0, 8, number_format($amount, 2), 1, 1, 'R');
    $pdf->Ln(2);

    // Price breakup — taxable value, GST split, grand total
    $currency = $contract['currency'] ?? 'INR';
    $labelWidth = 145;

    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell($labelWidth, 7, 'Taxable Value', 0, 0, 'R');
    $pdf->Cell(0, 7, $currency . ' ' . number_format($amount, 2), 0, 1, 'R');

    if ($gst['tax_type'] === 'cgst_sgst') {
        $halfRate = number_format($gst['gst_rate'] / 2, 2);
        $pdf->Cell($labelWidth, 7, "CGST @ {$halfRate}%", 0, 0, 'R');
        $pdf->Cell(0, 7, $currency . ' ' . number_format($gst['cgst_amount'], 2), 0, 1, 'R');
        $pdf->Cell($labelWidth, 7, "SGST @ {$halfRate}%", 0, 0, 'R');
        $pdf->Cell(0, 7, $currency . ' ' . number_format($gst['sgst_amount'], 2), 0, 1, 'R');
    } elseif ($gst['tax_type'] === 'igst') {
        $rate = number_format($gst['gst_rate'], 2);
        $pdf->Cell($labelWidth, 7, "IGST @ {$rate}%", 0, 0, 'R');
        $pdf->Cell(0, 7, $currency . ' ' . number_format($gst['igst_amount'], 2), 0, 1, 'R');
    } else {
        $pdf->SetTextColor(120, 120, 120);
        $pdf->Cell($labelWidth, 7, 'GST Not Applicable', 0, 0, 'R');
        $pdf->Cell(0, 7, '-', 0, 1, 'R');
        $pdf->SetTextColor(0, 0, 0);
    }

    $pdf->Ln(1);
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->Cell($labelWidth, 9, 'Total Amount Payable (' . $currency . ')', 'T', 0, 'R');
    $pdf->Cell(0, 9, number_format($gst['total_amount'], 2), 'T', 1, 'R');
    $pdf->Ln(8);

    // Footer note
    $pdf->SetFont('Helvetica', 'I', 8);
    $pdf->SetTextColor(120, 120, 120);
    if ($type === 'proforma') {
        $pdf->MultiCell(0, 5, 'This is a Proforma Invoice for internal billing review, with an indicative GST breakup. It is not a demand for payment and is subject to change before the Tax Invoice is issued.');
    } else {
        $pdf->MultiCell(0, 5, 'This is a Tax Invoice issued under Section 31 of the CGST Act. Payment terms as per the governing contract. Whether tax is payable under reverse charge: No.');
    }

    $uploadDir = __DIR__ . '/../uploads/invoices/' . $type . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $fileName = $invoiceNumber . '.pdf';
    $pdf->Output('F', $uploadDir . $fileName);

    return 'uploads/invoices/' . $type . '/' . $fileName;
}
