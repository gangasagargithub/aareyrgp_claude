<?php
$currentPage = basename($_SERVER['SCRIPT_NAME']);
$initials = strtoupper(substr($_SESSION['first_name'] ?? '?', 0, 1) . substr($_SESSION['last_name'] ?? '?', 0, 1));
?>
<aside class="sidebar">
  <div class="brand">
    <span class="brand-dot"></span>
    <span class="brand-name">RBAC Console<small>aareyrgp_claude</small></span>
  </div>

  <div class="nav-group">
    <div class="nav-label">Overview</div>
    <a class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php">
      <span class="nav-icon">&#9635;</span> Dashboard
    </a>
  </div>

  <div class="nav-group">
    <div class="nav-label">Access Control</div>
    <a class="nav-link <?= $currentPage === 'users.php' ? 'active' : '' ?>" href="users.php">
      <span class="nav-icon">&#128100;</span> Users
    </a>
    <a class="nav-link <?= $currentPage === 'roles.php' ? 'active' : '' ?>" href="roles.php">
      <span class="nav-icon">&#128274;</span> Roles &amp; Permissions
    </a>
  </div>

  <div class="nav-group">
    <div class="nav-label">Business Development</div>
    <a class="nav-link <?= $currentPage === 'customers.php' ? 'active' : '' ?>" href="customers.php">
      <span class="nav-icon">&#127970;</span> Customers
    </a>
    <a class="nav-link <?= $currentPage === 'contracts.php' ? 'active' : '' ?>" href="contracts.php">
      <span class="nav-icon">&#128196;</span> Offers &amp; Contracts
    </a>
  </div>

  <div class="nav-group">
    <div class="nav-label">Monitoring</div>
    <a class="nav-link <?= $currentPage === 'audit.php' ? 'active' : '' ?>" href="audit.php">
      <span class="nav-icon">&#128220;</span> Audit Log
    </a>
  </div>

  <div class="sidebar-footer">
    Logged in as<br>
    <strong style="color:var(--text)"><?= htmlspecialchars(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?></strong>
    <br><a href="logout.php">Sign out</a>
  </div>
</aside>
