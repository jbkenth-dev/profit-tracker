<?php
/**
 * Top Navigation Bar
 */
$fullName = $_SESSION['full_name'] ?? 'User';
$userName = $_SESSION['username'] ?? 'user';
?>
<nav class="navbar navbar-expand top-navbar">
    <div class="container-fluid">
        <button class="btn btn-sm sidebar-toggle d-lg-none me-2" id="sidebarToggle" type="button">
            <i class="bi bi-list"></i>
        </button>

        <a class="navbar-brand d-lg-none" href="dashboard.php">
            <i class="bi bi-graph-up-arrow me-1"></i>ProfitTracker
        </a>

        <div class="ms-auto d-flex align-items-center gap-3">
            <!-- Current Date -->
            <span class="text-muted small d-none d-md-inline">
                <i class="bi bi-calendar3 me-1"></i><?= date('F d, Y') ?>
            </span>

            <!-- User Dropdown -->
            <div class="dropdown">
                <button class="btn btn-user-dropdown dropdown-toggle d-flex align-items-center gap-2" data-bs-toggle="dropdown" style="padding:8px 12px;min-height:44px;">
                    <div class="user-avatar d-none d-lg-flex" style="width:36px;height:36px;font-size:15px;">
                        <?= strtoupper(substr($fullName, 0, 1)) ?>
                    </div>
                    <span class="fw-medium d-lg-none" style="font-size:14px;"><?= e($userName) ?></span>
                    <span class="fw-medium d-none d-lg-inline" style="font-size:14px;"><?= e($fullName) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:180px;border-radius:12px;padding:6px;">
                    <li><a class="dropdown-item" href="settings.php" style="padding:10px 14px;border-radius:8px;font-size:14px;"><i class="bi bi-gear me-2"></i>Settings</a></li>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li><a class="dropdown-item text-danger" href="auth/logout.php" style="padding:10px 14px;border-radius:8px;font-size:14px;"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>
