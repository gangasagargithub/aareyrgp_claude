<?php
$currentPage = basename($_SERVER['SCRIPT_NAME']);
$initials = strtoupper(substr($_SESSION['first_name'] ?? '?', 0, 1) . substr($_SESSION['last_name'] ?? '?', 0, 1));
?>
<button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle navigation">&#9776;</button>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

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
        <div class="nav-label">Search</div>
        <a class="nav-link <?= $currentPage === 'search.php' ? 'active' : '' ?>" href="search.php">
      <span class="nav-icon">&#128269;</span> Search
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
    <a class="nav-link <?= $currentPage === 'rate_structure_master.php' ? 'active' : '' ?>" href="rate_structure_master.php">
      <span class="nav-icon">&#128202;</span> Rate Structure Master
    </a>
  </div>

  <div class="nav-group">
    <div class="nav-label">Operations</div>
    <a class="nav-link <?= $currentPage === 'jobs.php' ? 'active' : '' ?>" href="jobs.php">
      <span class="nav-icon">&#128736;</span> Jobs
    </a>
    <a class="nav-link <?= $currentPage === 'service_categories_master.php' ? 'active' : '' ?>" href="service_categories_master.php">
      <span class="nav-icon">&#127959;</span> Service Categories
    </a>
    <?php if (function_exists('canManageBilling') ? canManageBilling() : true): ?>
    <a class="nav-link <?= $currentPage === 'billing.php' ? 'active' : '' ?>" href="billing.php">
      <span class="nav-icon">&#128179;</span> Billing
    </a>
    <?php endif; ?>
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

<script>
  (function () {
    var toggle = document.getElementById('mobileMenuToggle');
    var backdrop = document.getElementById('sidebarBackdrop');
    var sidebar = document.querySelector('.sidebar');

    if (!toggle || !backdrop || !sidebar) return;

    function closeMenu() {
      sidebar.classList.remove('open');
      backdrop.classList.remove('open');
    }

    toggle.addEventListener('click', function () {
      sidebar.classList.toggle('open');
      backdrop.classList.toggle('open');
    });

    backdrop.addEventListener('click', closeMenu);

    // Close the drawer automatically after tapping a nav link
    sidebar.querySelectorAll('.nav-link').forEach(function (link) {
      link.addEventListener('click', closeMenu);
    });
  })();
</script>
