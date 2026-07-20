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

/** True if the currently logged-in user holds the given role. */
function hasRole(string $role): bool
{
    return in_array($role, $_SESSION['roles'] ?? [], true);
}

/** True if the currently logged-in user holds the Super Admin role. */
function isSuperAdmin(): bool
{
    return hasRole('Super Admin');
}

/**
 * Permission matrix:
 * - Super Admin: everything (create, edit, delete, reset password, view)
 * - Admin:       create + edit + reset password + view — NOT delete
 * - Editor:      reset password only — no create/edit/delete
 * - Viewer:      view only — no writes at all
 */
function canCreate(): bool
{
    return isSuperAdmin() || hasRole('Admin');
}

function canEdit(): bool
{
    return isSuperAdmin() || hasRole('Admin');
}

function canDelete(): bool
{
    return isSuperAdmin();
}

function canResetPassword(): bool
{
    return isSuperAdmin() || hasRole('Admin') || hasRole('Editor');
}

/** Billing role (plus Super Admin/Admin) can review completed jobs and generate invoices. */
function canManageBilling(): bool
{
    return isSuperAdmin() || hasRole('Admin') || hasRole('Billing');
}

/**
 * A job can be closed (completion info filled in) by the person it's assigned
 * to, OR by anyone with general edit rights (Super Admin/Admin) — matches
 * "he should fill the information and close the job" for the assigned worker,
 * while still letting an admin close/correct it on someone's behalf.
 */
function canCloseJob(array $job): bool
{
    $currentUserId = $_SESSION['user_id'] ?? 0;
    return (int)($job['assigned_to'] ?? 0) === (int)$currentUserId || canEdit();
}

/** Generates the next job number in the ARYAJOB01, ARYAJOB02, ... series. */
function generateJobNumber(PDO $pdo): string
{
    $stmt = $pdo->query(
        "SELECT job_number FROM jobs
         WHERE job_number REGEXP '^ARYAJOB[0-9]+$'
         ORDER BY CAST(SUBSTRING(job_number, 8) AS UNSIGNED) DESC
         LIMIT 1"
    );
    $last = $stmt->fetchColumn();
    $nextNum = $last ? ((int)substr($last, 7) + 1) : 1;

    return 'ARYAJOB' . str_pad((string)$nextNum, 2, '0', STR_PAD_LEFT);
}

/**
 * Generates the next invoice number for a given type.
 * Proforma: ARYAPROF01, ARYAPROF02, ...
 * Final:    ARYAINV01, ARYAINV02, ...
 */
function generateInvoiceNumber(PDO $pdo, string $type): string
{
    $prefix = $type === 'proforma' ? 'ARYAPROF' : 'ARYAINV';
    $prefixLen = strlen($prefix) + 1;

    $stmt = $pdo->prepare(
        "SELECT invoice_number FROM invoices
         WHERE invoice_type = :type AND invoice_number REGEXP :pattern
         ORDER BY CAST(SUBSTRING(invoice_number, :len) AS UNSIGNED) DESC
         LIMIT 1"
    );
    $stmt->execute([
        'type' => $type,
        'pattern' => '^' . $prefix . '[0-9]+$',
        'len' => $prefixLen,
    ]);
    $last = $stmt->fetchColumn();
    $nextNum = $last ? ((int)substr($last, strlen($prefix)) + 1) : 1;

    return $prefix . str_pad((string)$nextNum, 2, '0', STR_PAD_LEFT);
}

/** @deprecated kept as an alias for canEdit() so existing call sites keep working. */
function isAdminOrSuperAdmin(): bool
{
    return canEdit();
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
