<?php
/**
 * Header — HTML head, CDN assets, and body opener
 */

// Auto-download missing assets (server-side, no browser tracking issues)
$needed = [
    __DIR__ . '/../assets/css/bootstrap.min.css'           => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
    __DIR__ . '/../assets/css/bootstrap-icons.min.css'     => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
    __DIR__ . '/../assets/css/fonts/bootstrap-icons.woff2' => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2',
    __DIR__ . '/../assets/css/fonts/bootstrap-icons.woff'  => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff',
    __DIR__ . '/../assets/css/dataTables.bootstrap5.min.css' => 'https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css',
    __DIR__ . '/../assets/js/bootstrap.bundle.min.js'      => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
    __DIR__ . '/../assets/js/jquery-3.7.1.min.js'         => 'https://code.jquery.com/jquery-3.7.1.min.js',
    __DIR__ . '/../assets/js/jquery.dataTables.min.js'     => 'https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js',
    __DIR__ . '/../assets/js/dataTables.bootstrap5.min.js' => 'https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js',
    __DIR__ . '/../assets/js/chart.umd.min.js'             => 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
    __DIR__ . '/../assets/js/sweetalert2.min.js'           => 'https://cdn.jsdelivr.net/npm/sweetalert2@11',
];
foreach ($needed as $local => $url) {
    if (!file_exists($local) || filesize($local) < 100) {
        $dir = dirname($local);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $ctx = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => 'Mozilla/5.0']]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data !== false && strlen($data) > 100) {
            @file_put_contents($local, $data);
        }
    }
}

// Auto-migration: add product_id + remove categories
try {
    $pdo = @getDB();
    if ($pdo) {
        // Add product_id to fixed_expenses if missing
        $stmt = @$pdo->query("SHOW COLUMNS FROM fixed_expenses LIKE 'product_id'");
        if ($stmt && !$stmt->fetch()) {
            @$pdo->exec("ALTER TABLE fixed_expenses ADD COLUMN product_id INT UNSIGNED DEFAULT NULL AFTER expense_name");
            @$pdo->exec("ALTER TABLE fixed_expenses ADD INDEX idx_product_id (product_id)");
        }
        // Remove category_id from expenses
        $stmt = @$pdo->query("SHOW COLUMNS FROM expenses LIKE 'category_id'");
        if ($stmt && $stmt->fetch()) {
            @$pdo->exec("ALTER TABLE expenses DROP FOREIGN KEY expenses_ibfk_3");
            @$pdo->exec("ALTER TABLE expenses DROP INDEX idx_category_id");
            @$pdo->exec("ALTER TABLE expenses DROP COLUMN category_id");
        }
        // Remove category_id from fixed_expenses
        $stmt = @$pdo->query("SHOW COLUMNS FROM fixed_expenses LIKE 'category_id'");
        if ($stmt && $stmt->fetch()) {
            @$pdo->exec("ALTER TABLE fixed_expenses DROP FOREIGN KEY fixed_expenses_ibfk_2");
            @$pdo->exec("ALTER TABLE fixed_expenses DROP INDEX idx_category_id");
            @$pdo->exec("ALTER TABLE fixed_expenses DROP COLUMN category_id");
        }
        // Drop expense_categories table
        @$pdo->exec("DROP TABLE IF EXISTS expense_categories");
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'Profit Tracker') ?> — Profit Tracker</title>

    <!-- Local Assets (no CDN dependency) -->
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="assets/css/style.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Chart.js -->
    <script src="assets/js/chart.umd.min.js"></script>

    <!-- Modal z-index fix - always above everything -->
    <style>
        .modal-backdrop { z-index: 10000 !important; }
        .modal, .modal.show { z-index: 10001 !important; }
        .modal-dialog { z-index: 10002 !important; }
        .btn, button { cursor: pointer; }
    </style>

    <!-- SweetAlert2 -->
    <script src="assets/js/sweetalert2.min.js"></script>
</head>
<body>

<div class="app-wrapper">
    <?php require_once __DIR__ . '/navbar.php'; ?>
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="main-content" id="mainContent">
