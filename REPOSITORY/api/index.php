<?php
/**
 * RBAC Console REST API
 * ----------------------------------------------------------
 * All endpoints require: Authorization: Bearer <api_key>
 * All request/response bodies are JSON, except file uploads
 * (multipart/form-data on the attachments endpoint).
 *
 * See README.md in this folder for full endpoint documentation.
 */

require_once __DIR__ . '/lib/response.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/customers.php';
require_once __DIR__ . '/lib/contracts.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Health check does not require auth
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^.*/api#', '', $path); // strip everything up to and including /api
$path = trim($path, '/');
$segments = $path === '' ? [] : explode('/', $path);
$method = $_SERVER['REQUEST_METHOD'];

if ($segments === ['health']) {
    jsonSuccess(['status' => 'ok', 'time' => date('c')]);
}

// Everything else requires a valid API key
$apiKey = authenticateApiKey();
$apiKeyId = (int)$apiKey['id'];

$pdo = getConnection();

// ---- Routing table ----
// /customers
if ($segments === ['customers'] && $method === 'GET') {
    handleCustomersList($pdo);
}
if ($segments === ['customers'] && $method === 'POST') {
    handleCustomerCreate($pdo, $apiKeyId);
}

// /customers/{id}
if (count($segments) === 2 && $segments[0] === 'customers' && ctype_digit($segments[1]) && $method === 'GET') {
    handleCustomerGet($pdo, (int)$segments[1]);
}

// /customers/{id}/contacts
if (count($segments) === 3 && $segments[0] === 'customers' && ctype_digit($segments[1]) && $segments[2] === 'contacts') {
    $customerId = (int)$segments[1];
    if ($method === 'GET')  handleCustomerContactsList($pdo, $customerId);
    if ($method === 'POST') handleCustomerContactCreate($pdo, $apiKeyId, $customerId);
}

// /customers/{id}/billing-addresses
if (count($segments) === 3 && $segments[0] === 'customers' && ctype_digit($segments[1]) && $segments[2] === 'billing-addresses') {
    $customerId = (int)$segments[1];
    if ($method === 'GET')  handleCustomerBillingList($pdo, $customerId);
    if ($method === 'POST') handleCustomerBillingCreate($pdo, $apiKeyId, $customerId);
}

// /contracts
if ($segments === ['contracts'] && $method === 'GET') {
    handleContractsList($pdo);
}
if ($segments === ['contracts'] && $method === 'POST') {
    handleContractCreate($pdo, $apiKeyId);
}

// /contracts/{id}
if (count($segments) === 2 && $segments[0] === 'contracts' && ctype_digit($segments[1]) && $method === 'GET') {
    handleContractGet($pdo, (int)$segments[1]);
}

// /contracts/{id}/operators
if (count($segments) === 3 && $segments[0] === 'contracts' && ctype_digit($segments[1]) && $segments[2] === 'operators' && $method === 'POST') {
    handleContractOperatorCreate($pdo, $apiKeyId, (int)$segments[1]);
}

// /contracts/{id}/rate-groups
if (count($segments) === 3 && $segments[0] === 'contracts' && ctype_digit($segments[1]) && $segments[2] === 'rate-groups' && $method === 'POST') {
    handleContractRateGroupCreate($pdo, $apiKeyId, (int)$segments[1]);
}

// /contracts/{id}/finalize
if (count($segments) === 3 && $segments[0] === 'contracts' && ctype_digit($segments[1]) && $segments[2] === 'finalize' && $method === 'POST') {
    handleContractFinalize($pdo, $apiKeyId, (int)$segments[1]);
}

// /contracts/{id}/attachments
if (count($segments) === 3 && $segments[0] === 'contracts' && ctype_digit($segments[1]) && $segments[2] === 'attachments' && $method === 'POST') {
    handleContractAttachmentCreate($pdo, $apiKeyId, (int)$segments[1]);
}

// No route matched
jsonError('Not found: ' . $method . ' /' . $path, 404);
