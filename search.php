<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pdo = getConnection();
$query = trim($_GET['q'] ?? '');
$results = [];

/**
 * Run one search section defensively: if the query fails (e.g. a column/table
 * name doesn't match your live schema), skip it instead of fataling the page.
 */
function runSearchSection(PDO $pdo, $sql, $params, $rowMapper)
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row = $rowMapper($row);
        }
        unset($row);
        return $rows;
    } catch (Exception $e) {
        return ['_error' => $e->getMessage()];
    }
}

if ($query !== '') {
    $like = '%' . $query . '%';

    // Customers — each LIKE clause gets its own uniquely-named placeholder,
    // since native (non-emulated) prepared statements don't allow reusing
    // the same named parameter more than once in one query.
    $results['Customers'] = runSearchSection(
        $pdo,
        "SELECT id, customer_code, customer_name, customer_abbreviation, pan_no
         FROM customers
         WHERE customer_name LIKE :q1 OR customer_code LIKE :q2 OR customer_abbreviation LIKE :q3 OR pan_no LIKE :q4
         ORDER BY customer_name LIMIT 20",
        ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like],
        function ($r) {
            return [
                'link' => 'customer_view.php?id=' . $r['id'],
                'title' => $r['customer_name'],
                'subtitle' => $r['customer_code'] . ' · ' . $r['customer_abbreviation'],
            ];
        }
    );

    // Contracts / Offers
    $results['Offers & Contracts'] = runSearchSection(
        $pdo,
        "SELECT ct.id, ct.contract_number, ct.contract_type, ct.status, c.customer_name
         FROM contracts ct JOIN customers c ON c.id = ct.customer_id
         WHERE ct.contract_number LIKE :q1 OR c.customer_name LIKE :q2 OR ct.short_contract_no LIKE :q3 OR ct.bid_ref_no LIKE :q4
         ORDER BY ct.id DESC LIMIT 20",
        ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like],
        function ($r) {
            return [
                'link' => 'contract_view.php?id=' . $r['id'],
                'title' => $r['contract_number'] ? $r['contract_number'] : ('#' . $r['id']),
                'subtitle' => $r['customer_name'] . ' · ' . ($r['contract_type'] ? $r['contract_type'] : '—') . ' · ' . $r['status'],
            ];
        }
    );

    // Jobs
    $results['Jobs'] = runSearchSection(
        $pdo,
        "SELECT j.id, j.job_number, j.title, j.work_status, j.billing_status, c.customer_name
         FROM jobs j JOIN contracts ct ON ct.id = j.contract_id JOIN customers c ON c.id = ct.customer_id
         WHERE j.job_number LIKE :q1 OR j.title LIKE :q2
         ORDER BY j.id DESC LIMIT 20",
        ['q1' => $like, 'q2' => $like],
        function ($r) {
            return [
                'link' => 'job_view.php?id=' . $r['id'],
                'title' => $r['job_number'] . ' — ' . $r['title'],
                'subtitle' => $r['customer_name'] . ' · ' . $r['work_status'] . ' · ' . $r['billing_status'],
            ];
        }
    );

    // Users
    $results['Users'] = runSearchSection(
        $pdo,
        "SELECT id, first_name, last_name, email, status
         FROM users
         WHERE first_name LIKE :q1 OR last_name LIKE :q2 OR email LIKE :q3
            OR CONCAT(first_name, ' ', last_name) LIKE :q4
         ORDER BY first_name LIMIT 20",
        ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like],
        function ($r) {
            return [
                'link' => 'users.php',
                'title' => $r['first_name'] . ' ' . $r['last_name'],
                'subtitle' => $r['email'] . ' · ' . $r['status'],
            ];
        }
    );

    // Roles
    $results['Roles'] = runSearchSection(
        $pdo,
        "SELECT id, name, description FROM roles WHERE name LIKE :q1 OR description LIKE :q2 ORDER BY name LIMIT 20",
        ['q1' => $like, 'q2' => $like],
        function ($r) {
            return [
                'link' => 'roles.php',
                'title' => $r['name'],
                'subtitle' => $r['description'] ? $r['description'] : '',
            ];
        }
    );

    // Service Categories
    $results['Service Categories'] = runSearchSection(
        $pdo,
        "SELECT id, name, description, status FROM service_categories WHERE name LIKE :q1 OR description LIKE :q2 ORDER BY name LIMIT 20",
        ['q1' => $like, 'q2' => $like],
        function ($r) {
            return [
                'link' => 'service_categories_master.php',
                'title' => $r['name'],
                'subtitle' => ($r['description'] ? $r['description'] : '') . ' · ' . $r['status'],
            ];
        }
    );

    // Rate Structure Master (all 5 lookup tables, combined with a type label)
    $rateMasterTables = [
        'Location'  => 'rate_master_locations',
        'Priority'  => 'rate_master_priorities',
        'Mod Type'  => 'rate_master_mod_types',
        'Unit/Per'  => 'rate_master_units',
        'Rate For'  => 'rate_master_rate_for',
    ];
    $rateRows = [];
    foreach ($rateMasterTables as $label => $table) {
        try {
            $stmt = $pdo->prepare("SELECT id, name, status FROM `$table` WHERE name LIKE :q1 ORDER BY name LIMIT 10");
            $stmt->execute(['q1' => $like]);
            foreach ($stmt->fetchAll() as $row) {
                $rateRows[] = [
                    'link' => 'rate_structure_master.php',
                    'title' => $row['name'],
                    'subtitle' => $label . ' · ' . $row['status'],
                ];
            }
        } catch (Exception $e) {
            // Skip this lookup table if it doesn't exist on this install.
        }
    }
    $results['Rate Structure Master'] = $rateRows;
}

$totalResults = 0;
$sectionErrors = [];
foreach ($results as $groupName => $rows) {
    if (isset($rows['_error'])) {
        $sectionErrors[$groupName] = $rows['_error'];
        $results[$groupName] = [];
        continue;
    }
    $totalResults += count($rows);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Search — RBAC Console</title>
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
      <div class="breadcrumb"><strong>Master Search</strong><?= $query !== '' ? ' · ' . $totalResults . ' result' . ($totalResults === 1 ? '' : 's') : '' ?></div>
    </div>

    <div class="card" style="margin-bottom:20px;">
      <form method="get" style="display:flex; gap:10px;">
        <input type="text" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="Search customers, contracts, jobs, users, roles, rate structure..." autofocus style="flex:1;">
        <button type="submit" class="btn primary">Search</button>
      </form>
      <p style="color:var(--muted); font-size:11.5px; margin:10px 0 0;">Searches across Customers, Offers &amp; Contracts, Jobs, Users, Roles, Service Categories, and all Rate Structure Master lists at once.</p>
    </div>

    <?php if ($sectionErrors): ?>
      <div class="error-box">
        Some sections couldn't be searched (likely a table/column name mismatch on this install):
        <ul style="margin:8px 0 0 18px; padding:0;">
          <?php foreach ($sectionErrors as $name => $err): ?>
            <li><strong><?= htmlspecialchars($name) ?>:</strong> <?= htmlspecialchars($err) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($query === ''): ?>
      <div class="card"><p style="color:var(--muted); margin:0;">Type something above to search across every master and record type in the system.</p></div>
    <?php elseif ($totalResults === 0): ?>
      <div class="card"><p style="color:var(--muted); margin:0;">No results for "<?= htmlspecialchars($query) ?>".</p></div>
    <?php else: ?>
      <?php foreach ($results as $groupName => $rows): ?>
        <?php if (!$rows) continue; ?>
        <div class="card" style="margin-bottom:20px;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h3 style="margin:0;"><?= htmlspecialchars($groupName) ?></h3>
            <span class="mono" style="color:var(--muted); font-size:11px;"><?= count($rows) ?> match<?= count($rows) === 1 ? '' : 'es' ?></span>
          </div>
          <div class="table-wrap" style="border:none;">
            <table>
              <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                  <td>
                    <a href="<?= htmlspecialchars($row['link']) ?>" style="font-weight:600;"><?= htmlspecialchars($row['title']) ?></a>
                  </td>
                  <td style="color:var(--muted); font-size:12.5px;"><?= htmlspecialchars($row['subtitle']) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
