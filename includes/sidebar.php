<?php
/**
 * Sidebar Navigation
 *
 * Uses offcanvas on mobile, static sidebar on desktop
 */
$currentPage = basename($_SERVER['SCRIPT_NAME']);
?>

<!-- Offcanvas overlay for mobile -->
<div class="offcanvas-backdrop fade" id="sidebarBackdrop"></div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <a href="dashboard.php" class="sidebar-brand">
            <div class="brand-icon">
                <i class="bi bi-graph-up-arrow"></i>
            </div>
            <div class="brand-text">
                <span class="brand-name">ProfitTracker</span>
                <span class="brand-tagline">Business Dashboard</span>
            </div>
        </a>
        <button class="btn btn-sm sidebar-close d-lg-none" id="sidebarClose">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <div class="sidebar-body">
        <ul class="sidebar-nav">
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="products.php" class="nav-link <?= $currentPage === 'products.php' ? 'active' : '' ?>">
                    <i class="bi bi-box-seam"></i>
                    <span>Products</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="revenue.php" class="nav-link <?= $currentPage === 'revenue.php' ? 'active' : '' ?>">
                    <i class="bi bi-cash-stack"></i>
                    <span>Revenue</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="expenses.php" class="nav-link <?= $currentPage === 'expenses.php' ? 'active' : '' ?>">
                    <i class="bi bi-cart-x"></i>
                    <span>Expenses</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="fixed-expenses.php" class="nav-link <?= $currentPage === 'fixed-expenses.php' ? 'active' : '' ?>">
                    <i class="bi bi-receipt"></i>
                    <span>Fixed Expenses</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="reports.php" class="nav-link <?= $currentPage === 'reports.php' ? 'active' : '' ?>">
                    <i class="bi bi-file-earmark-bar-graph"></i>
                    <span>Reports</span>
                </a>
            </li>
        </ul>
    </div>

    <div class="sidebar-footer">
        <div class="sidebar-footer-info">
            <i class="bi bi-shield-check text-success"></i>
            <small class="text-muted">Secured System</small>
        </div>
    </div>
</aside>
