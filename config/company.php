<?php
/**
 * Seller (your company) details used on GST invoices — letterhead,
 * GSTIN, and the "home state" used to decide CGST+SGST vs IGST.
 *
 * IMPORTANT: Replace the placeholder values below with your actual
 * registration details before generating invoices for real customers.
 * These are printed on every Proforma and Tax Invoice PDF.
 */

// Legal name as registered under GST
define('COMPANY_LEGAL_NAME', 'Arya Offshore Services Pvt. Limited');

// Your company's GSTIN (15-character GST Identification Number).
// e.g. '27ABCDE1234F1Z5' — TODO: replace with the real GSTIN.
define('COMPANY_GSTIN', 'TODO-ENTER-COMPANY-GSTIN');

// Registered address line shown under the letterhead.
define('COMPANY_ADDRESS', 'TODO: enter registered office address');

// The state your company is registered in for GST. This is compared
// against the customer's billing state to decide the tax split:
//   - same state  -> CGST + SGST (split evenly)
//   - other state -> IGST (full rate)
define('COMPANY_STATE', 'Maharashtra');

// Default SAC (Services Accounting Code) printed on invoice line items
// when a job/service doesn't have a more specific code configured.
define('COMPANY_DEFAULT_SAC_CODE', '9985');
