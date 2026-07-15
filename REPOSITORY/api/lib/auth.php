<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/response.php';

/**
 * Validates the Authorization: Bearer <key> header against api_keys.
 * Returns the api_keys row on success; sends a 401 JSON error and exits on failure.
 */
function authenticateApiKey(): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        jsonError('Missing or malformed Authorization header. Expected: Bearer <api_key>', 401);
    }

    $providedKey = trim($matches[1]);
    $hash = hash('sha256', $providedKey);

    $pdo = getConnection();
    $stmt = $pdo->prepare("SELECT * FROM api_keys WHERE key_hash = :hash AND status = 'active' LIMIT 1");
    $stmt->execute(['hash' => $hash]);
    $keyRow = $stmt->fetch();

    if (!$keyRow) {
        jsonError('Invalid or revoked API key.', 401);
    }

    $pdo->prepare('UPDATE api_keys SET last_used_at = NOW() WHERE id = :id')
        ->execute(['id' => $keyRow['id']]);

    return $keyRow;
}

/** Write an audit_logs entry attributed to the API key rather than a user session. */
function logApiAction(int $apiKeyId, string $action, ?string $details = null): void
{
    $pdo = getConnection();
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (NULL, :action, :details, :ip)'
    );
    $stmt->execute([
        'action'  => $action,
        'details' => "[api_key #$apiKeyId] " . ($details ?? ''),
        'ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}
