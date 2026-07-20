<?php
require_once __DIR__ . '/../lib/fpdf/fpdf.php';

/**
 * Generates a Proforma or Final invoice PDF for a completed job.
 * Returns the relative path (from the app root) where the PDF was saved.
 *
 * $job must include: job_number, title, quantity_completed, unit_rate, completed_at
 * $contract must include: contract_number, customer_name
 * $type is 'proforma' or 'final'
 */
function generateInvoicePdf(array $job, array $contract, string $type, string $invoiceNumber, float $amount): string
{
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetMargins(18, 18, 18);

    // Letterhead
    $pdf->SetFont('Helvetica', 'B', 16);
    $pdf->Cell(0, 8, 'Arya Offshore Services Pvt. Limited', 0, 1);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell(0, 5, 'Project ABC - RBAC Console', 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(4);

    // Document title
    $label = $type === 'proforma' ? 'PROFORMA INVOICE' : 'FINAL INVOICE';
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
    $pdf->Ln(6);

    // Job / line item table
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(30, 8, 'Job No.', 1, 0, 'L', true);
    $pdf->Cell(70, 8, 'Description', 1, 0, 'L', true);
    $pdf->Cell(25, 8, 'Qty', 1, 0, 'R', true);
    $pdf->Cell(30, 8, 'Rate', 1, 0, 'R', true);
    $pdf->Cell(0, 8, 'Amount', 1, 1, 'R', true);

    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(30, 8, $job['job_number'] ?? ('#' . $job['id']), 1, 0);
    $pdf->Cell(70, 8, substr($job['title'], 0, 40), 1, 0);
    $pdf->Cell(25, 8, number_format((float)$job['quantity_completed'], 2), 1, 0, 'R');
    $pdf->Cell(30, 8, number_format((float)$job['unit_rate'], 2), 1, 0, 'R');
    $pdf->Cell(0, 8, number_format($amount, 2), 1, 1, 'R');

    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(155, 9, 'Total (' . ($contract['currency'] ?? 'INR') . ')', 1, 0, 'R');
    $pdf->Cell(0, 9, number_format($amount, 2), 1, 1, 'R');
    $pdf->Ln(10);

    // Footer note
    $pdf->SetFont('Helvetica', 'I', 8);
    $pdf->SetTextColor(120, 120, 120);
    if ($type === 'proforma') {
        $pdf->MultiCell(0, 5, 'This is a Proforma Invoice for internal billing review. It is not a demand for payment and is subject to change before the Final Invoice is issued.');
    } else {
        $pdf->MultiCell(0, 5, 'This is the Final Invoice for the job referenced above. Payment terms as per the governing contract.');
    }

    $uploadDir = __DIR__ . '/../uploads/invoices/' . $type . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $fileName = $invoiceNumber . '.pdf';
    $pdf->Output('F', $uploadDir . $fileName);

    return 'uploads/invoices/' . $type . '/' . $fileName;
}
