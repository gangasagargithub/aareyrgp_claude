<?php
require_once __DIR__ . '/../config/company.php';

/**
 * Works out which GSTIN/state to bill against for a given contract.
 *
 * Priority:
 *   1. If the contract has an operator with a billing address attached
 *      (contract_operators.billing_address_id), use that address's GSTIN/state.
 *      This covers "invoicing to a different principal" contracts.
 *   2. Otherwise, fall back to the customer's first billing address on file
 *      (customer_billing_addresses).
 *   3. Otherwise, return nulls — the invoice will still generate, just without
 *      a recipient GSTIN (treated as an unregistered / out-of-state recipient).
 */
function resolveGstParty(PDO $pdo, int $contractId, int $customerId): array
{
    $stmt = $pdo->prepare(
        "SELECT cba.gstin, cba.state, cba.address_description
         FROM contract_operators co
         JOIN customer_billing_addresses cba ON cba.id = co.billing_address_id
         WHERE co.contract_id = :cid AND co.billing_address_id IS NOT NULL
         ORDER BY co.id LIMIT 1"
    );
    $stmt->execute(['cid' => $contractId]);
    $row = $stmt->fetch();

    if (!$row) {
        $stmt = $pdo->prepare(
            'SELECT gstin, state, address_description FROM customer_billing_addresses
             WHERE customer_id = :cust ORDER BY id LIMIT 1'
        );
        $stmt->execute(['cust' => $customerId]);
        $row = $stmt->fetch();
    }

    return [
        'gstin' => $row['gstin'] ?? null,
        'state' => $row['state'] ?? null,
        'label' => $row['address_description'] ?? null,
    ];
}

/**
 * Computes the GST breakup for a taxable amount.
 *
 * - Rate is the "actual" rate configured on the contract (contracts.gst_rate),
 *   not a hardcoded percentage — pass in $gstRate from the contract row.
 * - CGST+SGST (split evenly) applies when the recipient's state matches
 *   COMPANY_STATE (intra-state supply); IGST (full rate) applies otherwise.
 * - If the contract has GST marked not applicable, everything is zero and
 *   tax_type is 'none'.
 */
function computeGstBreakup(float $taxableAmount, string $gstApplicable, float $gstRate, ?string $recipientState): array
{
    if ($gstApplicable !== 'yes' || $gstRate <= 0) {
        return [
            'tax_type' => 'none',
            'gst_rate' => 0.00,
            'cgst_amount' => 0.00,
            'sgst_amount' => 0.00,
            'igst_amount' => 0.00,
            'total_amount' => round($taxableAmount, 2),
        ];
    }

    $isIntraState = $recipientState !== null
        && strtolower(trim($recipientState)) === strtolower(trim(COMPANY_STATE));

    if ($isIntraState) {
        $half = round($taxableAmount * ($gstRate / 2) / 100, 2);
        return [
            'tax_type' => 'cgst_sgst',
            'gst_rate' => $gstRate,
            'cgst_amount' => $half,
            'sgst_amount' => $half,
            'igst_amount' => 0.00,
            'total_amount' => round($taxableAmount + $half + $half, 2),
        ];
    }

    $igst = round($taxableAmount * $gstRate / 100, 2);
    return [
        'tax_type' => 'igst',
        'gst_rate' => $gstRate,
        'cgst_amount' => 0.00,
        'sgst_amount' => 0.00,
        'igst_amount' => $igst,
        'total_amount' => round($taxableAmount + $igst, 2),
    ];
}
