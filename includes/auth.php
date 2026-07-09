<?php
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Redirect to login if no active session, or if the account is no longer active. */
function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    // Re-verify the account is still active on every request — catches the
    // case where an admin deactivates a user who already has a live session.
    $pdo = getConnection();
    $stmt = $pdo->prepare('SELECT status FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $status = $stmt->fetchColumn();

    if ($status !== 'active') {
        logoutUser();
        header('Location: login.php?deactivated=1');
        exit;
    }
}

/** Attempt login. Returns true on success, false on bad credentials. */
function attemptLogin(string $email, string $password): bool
{
    $pdo = getConnection();

    $stmt = $pdo->prepare(
        'SELECT id, first_name, last_name, email, password, status
         FROM users WHERE email = :email LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active') {
        return false;
    }

    if (!password_verify($password, $user['password'])) {
        return false;
    }

    $_SESSION['user_id']    = $user['id'];
    $_SESSION['first_name'] = $user['first_name'];
    $_SESSION['last_name']  = $user['last_name'];
    $_SESSION['email']      = $user['email'];

    // Update last_login
    $update = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = :id');
    $update->execute(['id' => $user['id']]);

    // Fetch role name(s) for display
    $roleStmt = $pdo->prepare(
        'SELECT r.name FROM roles r
         JOIN user_roles ur ON ur.role_id = r.id
         WHERE ur.user_id = :id'
    );
    $roleStmt->execute(['id' => $user['id']]);
    $_SESSION['roles'] = $roleStmt->fetchAll(PDO::FETCH_COLUMN);

    logAction($user['id'], 'login', 'User logged in');

    return true;
}

function logAction(?int $userId, string $action, ?string $details = null): void
{
    $pdo = getConnection();
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs (user_id, action, details, ip_address)
         VALUES (:user_id, :action, :details, :ip)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'action'  => $action,
        'details' => $details,
        'ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

function logoutUser(): void
{
    if (!empty($_SESSION['user_id'])) {
        logAction($_SESSION['user_id'], 'logout', 'User logged out');
    }
    $_SESSION = [];
    session_destroy();
}

/** True if the currently logged-in user holds the Super Admin role. */
function isSuperAdmin(): bool
{
    return in_array('Super Admin', $_SESSION['roles'] ?? [], true);
}

/** True if the currently logged-in user holds Super Admin or Admin. */
function isAdminOrSuperAdmin(): bool
{
    $roles = $_SESSION['roles'] ?? [];
    return in_array('Super Admin', $roles, true) || in_array('Admin', $roles, true);
}

/**
 * Generates the next contract number in the ARYACONT01, ARYACONT02, ... series.
 * Looks at the highest existing numeric suffix rather than a row count, so
 * gaps from any future deletions don't cause a collision or reuse a number.
 */
function generateContractNumber(PDO $pdo): string
{
    $stmt = $pdo->query(
        "SELECT contract_number FROM contracts
         WHERE contract_number REGEXP '^ARYACONT[0-9]+$'
         ORDER BY CAST(SUBSTRING(contract_number, 9) AS UNSIGNED) DESC
         LIMIT 1"
    );
    $last = $stmt->fetchColumn();
    $nextNum = $last ? ((int)substr($last, 8) + 1) : 1;

    return 'ARYACONT' . str_pad((string)$nextNum, 2, '0', STR_PAD_LEFT);
}
