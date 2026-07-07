<?php
/** Send a JSON response and stop execution. */
function jsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonSuccess($data, int $statusCode = 200): void
{
    jsonResponse($statusCode, ['success' => true, 'data' => $data]);
}

function jsonError(string $message, int $statusCode = 400): void
{
    jsonResponse($statusCode, ['success' => false, 'error' => $message]);
}

/** Decode the JSON request body into an assoc array. Empty body -> []. */
function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonError('Malformed JSON body: ' . json_last_error_msg(), 400);
    }
    return $decoded ?? [];
}

/** Require given keys to be present (and non-empty) in an array; errors out if missing. */
function requireFields(array $body, array $fields): void
{
    $missing = [];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $body) || $body[$field] === '' || $body[$field] === null) {
            $missing[] = $field;
        }
    }
    if ($missing) {
        jsonError('Missing required field(s): ' . implode(', ', $missing), 422);
    }
}
