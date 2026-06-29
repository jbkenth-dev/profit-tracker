<?php
/**
 * Reports Module — Monthly, Yearly, Profit, Expense, Revenue, Product Performance
 * Supports: printable view, CSV/Excel export
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

start_session();
require_auth();

$pdo = getDB();
$uid = user_id();

// ─── Determine Report Type & Filters ────────────────────────
$reportType = $_GET['type'] ?? 'monthly';
$reportMonth = (int)($_GET['month'] ?? current_month());
$reportYear  = (int)($_GET['year'] ?? current_year());
$exportMode  = $_GET['export'] ?? '';

// ─── Fetch common data ──────────────────────────────────────
$stmt = $pdo->prepare("SELECT id, name FROM products WHERE user_id = :uid AND is_deleted = 0 ORDER BY name");
$stmt->execute([':uid' => $uid]);
$products = $stmt->fetchAll();

// ─── Helper: Generate report data ───────────────────────────
$reportTitle = '';
$reportData = [];
$reportHeaders = [];

function getMonthlyReport(PDO $pdo, int $uid, int $month, int $year): array {
    // Revenue by product
    $stmt = $pdo->prepare("
        SELECT p.name, COALESCE(r.amount, 0) as revenue, p.capital
        FROM products p
        LEFT JOIN revenue r ON p.id = r.product_id AND r.month = :m AND r.year = :y AND r.user_id = :uid
        WHERE p.user_id = :uid2 AND p.is_deleted = 0
        ORDER BY p.name
    ");
    $stmt->execute([':m' => $month, ':y' => $year, ':uid' => $uid, ':uid2' => $uid]);
    $items = $stmt->fetchAll();

    // Total expenses this month
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE user_id = :uid AND MONTH(expense_date) = :m AND YEAR(expense_date) = :y AND is_fixed = 0");
    $stmt->execute([':uid' => $uid, ':m' => $month, ':y' => $year]);
    $totalExpenses = (float)$stmt->fetchColumn();

    // Fixed expenses (active)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM fixed_expenses WHERE user_id = :uid AND status = 'active'");
    $stmt->execute([':uid' => $uid]);
    $totalFixed = (float)$stmt->fetchColumn();

    // Revenue by month for selected month
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM revenue WHERE user_id = :uid AND month = :m AND year = :y");
    $stmt->execute([':uid' => $uid, ':m' => $month, ':y' => $year]);
    $totalRevenue = (float)$stmt->fetchColumn();

    // Total capital
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(capital), 0) FROM products WHERE user_id = :uid AND is_deleted = 0");
    $stmt->execute([':uid' => $uid]);
    $totalCapital = (float)$stmt->fetchColumn();

    $netProfit = $totalRevenue - $totalCapital - $totalExpenses - $totalFixed;

    return [
        'items'         => $items,
        'totalRevenue'  => $totalRevenue,
        'totalExpenses' => $totalExpenses,
        'totalFixed'    => $totalFixed,
        'totalCapital'  => $totalCapital,
        'netProfit'     => $netProfit,
    ];
}

function getYearlyReport(PDO $pdo, int $uid, int $year): array {
    $monthlyData = [];
    $grandRevenue = 0;
    $grandExpenses = 0;
    $grandFixed = 0;
    $grandCapital = 0;
    $grandProfit = 0;

    // Total capital (one-time cost, NOT deducted monthly)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(capital), 0) FROM products WHERE user_id = :uid AND is_deleted = 0");
    $stmt->execute([':uid' => $uid]);
    $totalCapital = (float)$stmt->fetchColumn();

    for ($m = 1; $m <= 12; $m++) {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM revenue WHERE user_id = :uid AND month = :m AND year = :y");
        $stmt->execute([':uid' => $uid, ':m' => $m, ':y' => $year]);
        $rev = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE user_id = :uid AND MONTH(expense_date) = :m AND YEAR(expense_date) = :y AND is_fixed = 0");
        $stmt->execute([':uid' => $uid, ':m' => $m, ':y' => $year]);
        $exp = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM fixed_expenses WHERE user_id = :uid AND status = 'active'");
        $stmt->execute([':uid' => $uid]);
        $fix = (float)$stmt->fetchColumn();

        // Monthly profit excludes one-time capital
        $profit = $rev - $exp - $fix;

        $monthlyData[$m] = [
            'month'    => $m,
            'revenue'  => $rev,
            'expenses' => $exp,
            'fixed'    => $fix,
            'capital'  => $totalCapital,
            'profit'   => $profit,
        ];

        $grandRevenue  += $rev;
        $grandExpenses += $exp;
        $grandFixed     = $fix;
    }

    // Annual net = revenue - capital - expenses - (fixed * 12)
    $grandFixedAnnual = $grandFixed * 12;
    $grandProfit = $grandRevenue - $totalCapital - $grandExpenses - $grandFixedAnnual;

    return [
        'monthly'          => $monthlyData,
        'grandRevenue'     => $grandRevenue,
        'grandExpenses'    => $grandExpenses,
        'grandFixed'       => $grandFixedAnnual,
        'grandCapital'     => $totalCapital,
        'grandProfit'      => $grandProfit,
    ];
}

function getRevenueReport(PDO $pdo, int $uid, int $year): array {
    $stmt = $pdo->prepare("
        SELECT p.name,
               COALESCE(SUM(r.amount), 0) as total_revenue,
               p.capital
        FROM products p
        LEFT JOIN revenue r ON p.id = r.product_id AND r.year = :y AND r.user_id = :uid
        WHERE p.user_id = :uid2 AND p.is_deleted = 0
        GROUP BY p.id
        ORDER BY total_revenue DESC
    ");
    $stmt->execute([':uid' => $uid, ':y' => $year, ':uid2' => $uid]);
    $items = $stmt->fetchAll();

    $grandTotal = array_sum(array_column($items, 'total_revenue'));

    // Monthly breakdown for chart
    $monthlyRev = [];
    for ($m = 1; $m <= 12; $m++) {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM revenue WHERE user_id = :uid AND month = :m AND year = :y");
        $stmt->execute([':uid' => $uid, ':m' => $m, ':y' => $year]);
        $monthlyRev[$m] = (float)$stmt->fetchColumn();
    }

    return ['items' => $items, 'grandTotal' => $grandTotal, 'monthlyRev' => $monthlyRev];
}

function getExpenseReport(PDO $pdo, int $uid, int $year): array {
    $stmt = $pdo->prepare("
        SELECT e.expense_name, e.amount, e.expense_date,
               p.name as product_name
        FROM expenses e
        LEFT JOIN products p ON e.product_id = p.id
        WHERE e.user_id = :uid AND YEAR(e.expense_date) = :y
        ORDER BY e.expense_date DESC
    ");
    $stmt->execute([':uid' => $uid, ':y' => $year]);
    $items = $stmt->fetchAll();

    $grandTotal = array_sum(array_column($items, 'amount'));

    return ['items' => $items, 'grandTotal' => $grandTotal];
}

function getProductPerformance(PDO $pdo, int $uid): array {
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.capital, p.status,
               COALESCE(SUM(r.amount), 0) as total_revenue,
               COUNT(DISTINCT CONCAT(r.month, '-', r.year)) as months_active
        FROM products p
        LEFT JOIN revenue r ON p.id = r.product_id AND r.user_id = :uid
        WHERE p.user_id = :uid2 AND p.is_deleted = 0
        GROUP BY p.id
        ORDER BY total_revenue DESC
    ");
    $stmt->execute([':uid' => $uid, ':uid2' => $uid]);
    $items = $stmt->fetchAll();

    // Expenses by product (variable + fixed)
    $stmt = $pdo->prepare("
        SELECT p.id, p.name,
               COALESCE((SELECT SUM(e2.amount) FROM expenses e2 WHERE e2.product_id = p.id AND e2.user_id = :uid1), 0)
               + COALESCE((SELECT SUM(fe2.amount) FROM fixed_expenses fe2 WHERE fe2.product_id = p.id AND fe2.user_id = :uid2 AND fe2.status = 'active'), 0)
               as total_expense
        FROM products p
        WHERE p.user_id = :uid3 AND p.is_deleted = 0
        ORDER BY p.name
    ");
    $stmt->execute([':uid1' => $uid, ':uid2' => $uid, ':uid3' => $uid]);
    $expenses = $stmt->fetchAll();

    $expenseMap = [];
    foreach ($expenses as $e) {
        $expenseMap[$e['id']] = (float)$e['total_expense'];
    }

    return ['items' => $items, 'expenseMap' => $expenseMap];
}

// ─── Export Handlers ────────────────────────────────────────
if ($exportMode === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_' . $reportType . '_' . date('Ymd') . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for Excel

    switch ($reportType) {
        case 'monthly':
            $data = getMonthlyReport($pdo, $uid, $reportMonth, $reportYear);
            fputcsv($output, ['Product', 'Capital', 'Revenue']);
            foreach ($data['items'] as $row) {
                fputcsv($output, [$row['name'], $row['capital'], $row['revenue']]);
            }
            fputcsv($output, ['']);
            fputcsv($output, ['Total Revenue', $data['totalRevenue']]);
            fputcsv($output, ['Total Expenses', $data['totalExpenses']]);
            fputcsv($output, ['Fixed Expenses', $data['totalFixed']]);
            fputcsv($output, ['Total Capital', $data['totalCapital']]);
            fputcsv($output, ['Net Profit', $data['netProfit']]);
            break;

        case 'expense':
            $data = getExpenseReport($pdo, $uid, $reportYear);
            fputcsv($output, ['Expense', 'Amount', 'Date', 'Product']);
            foreach ($data['items'] as $row) {
                fputcsv($output, [$row['expense_name'], $row['amount'], $row['expense_date'], $row['product_name']]);
            }
            fputcsv($output, ['']);
            fputcsv($output, ['Grand Total', $data['grandTotal']]);
            break;

        case 'revenue':
            $data = getRevenueReport($pdo, $uid, $reportYear);
            fputcsv($output, ['Product', 'Capital', 'Total Revenue', 'Net']);
            foreach ($data['items'] as $row) {
                $net = (float)$row['total_revenue'] - (float)$row['capital'];
                fputcsv($output, [$row['name'], $row['capital'], $row['total_revenue'], $net]);
            }
            fputcsv($output, ['']);
            fputcsv($output, ['Grand Total', $data['grandTotal']]);
            break;

        default:
            fputcsv($output, ['Report type not supported for CSV export']);
    }

    fclose($output);
    exit;
}

if ($exportMode === 'print') {
    // Render a print-friendly version
    $printData = null;
    switch ($reportType) {
        case 'monthly': $printData = getMonthlyReport($pdo, $uid, $reportMonth, $reportYear); break;
        case 'yearly':  $printData = getYearlyReport($pdo, $uid, $reportYear); break;
        case 'revenue': $printData = getRevenueReport($pdo, $uid, $reportYear); break;
        case 'expense': $printData = getExpenseReport($pdo, $uid, $reportYear); break;
        case 'products': $printData = getProductPerformance($pdo, $uid); break;
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Print Report — Profit Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; padding: 40px; color: #1e293b; }
        .print-header { text-align: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #2563EB; }
        .print-header h1 { font-size: 24px; font-weight: 700; }
        .print-header p { color: #64748b; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { padding: 10px 12px; border: 1px solid #e2e8f0; text-align: left; font-size: 13px; }
        th { background: #f1f5f9; font-weight: 600; }
        .grand-total { font-weight: 700; font-size: 15px; }
        .text-success { color: #16A34A; }
        .text-danger { color: #DC2626; }
        .no-print { display: none; }
        .summary-box { background: #f8fafc; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; }
        .summary-item {}
        .summary-item .label { color: #64748b; font-size: 12px; }
        .summary-item .value { font-size: 18px; font-weight: 700; }
        @media print { body { padding: 20px; } .summary-box { break-inside: avoid; } }
    </style>
</head>
<body>
    <div class="print-header">
        <h1>ProfitTracker — <?= ucfirst($reportType) ?> Report</h1>
        <p>Generated on <?= date('F d, Y \a\t h:i A') ?></p>
    </div>

    <?php if ($reportType === 'monthly' && $printData): ?>
        <div class="summary-box">
            <h5><?= month_name($reportMonth) ?> <?= $reportYear ?> — Summary</h5>
            <div class="summary-grid">
                <div class="summary-item"><div class="label">Total Revenue</div><div class="value text-success"><?= format_currency($printData['totalRevenue']) ?></div></div>
                <div class="summary-item"><div class="label">Total Expenses</div><div class="value text-danger"><?= format_currency($printData['totalExpenses']) ?></div></div>
                <div class="summary-item"><div class="label">Fixed Expenses</div><div class="value text-warning"><?= format_currency($printData['totalFixed']) ?></div></div>
                <div class="summary-item"><div class="label">Total Capital</div><div class="value text-info"><?= format_currency($printData['totalCapital']) ?></div></div>
                <div class="summary-item"><div class="label">Net Profit</div><div class="value <?= $printData['netProfit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= format_currency($printData['netProfit']) ?></div></div>
            </div>
        </div>
        <table>
            <thead><tr><th>Product</th><th>Capital</th><th>Revenue</th></tr></thead>
            <tbody>
                <?php foreach ($printData['items'] as $row): ?>
                    <tr><td><?= e($row['name']) ?></td><td><?= format_currency((float)$row['capital']) ?></td><td><?= format_currency((float)$row['revenue']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!empty($printData['expenseByCat'])): ?>
            <h5 class="mt-4">Expenses by Category</h5>
            <table>
                <thead><tr><th>Category</th><th>Amount</th></tr></thead>
                <tbody>
                    <?php foreach ($printData['expenseByCat'] as $ec): ?>
                        <tr><td><?= e($ec['name'] ?? 'Uncategorized') ?></td><td><?= format_currency((float)$ec['total']) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php elseif ($reportType === 'yearly' && $printData): ?>
        <div class="summary-box">
            <h5><?= $reportYear ?> — Yearly Summary</h5>
            <div class="summary-grid">
                <div class="summary-item"><div class="label">Total Revenue</div><div class="value text-success"><?= format_currency($printData['grandRevenue']) ?></div></div>
                <div class="summary-item"><div class="label">Total Expenses</div><div class="value text-danger"><?= format_currency($printData['grandExpenses']) ?></div></div>
                <div class="summary-item"><div class="label">Fixed Expenses (Annual)</div><div class="value text-warning"><?= format_currency($printData['grandFixed']) ?></div></div>
                <div class="summary-item"><div class="label">Total Capital</div><div class="value text-info"><?= format_currency($printData['grandCapital']) ?></div></div>
                <div class="summary-item"><div class="label">Net Profit</div><div class="value <?= $printData['grandProfit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= format_currency($printData['grandProfit']) ?></div></div>
            </div>
        </div>
        <table>
            <thead><tr><th>Month</th><th>Revenue</th><th>Expenses</th><th>Fixed</th><th>Capital</th><th>Profit</th></tr></thead>
            <tbody>
                <?php foreach ($printData['monthly'] as $md): ?>
                    <tr>
                        <td><?= month_name($md['month']) ?></td>
                        <td><?= format_currency($md['revenue']) ?></td>
                        <td><?= format_currency($md['expenses']) ?></td>
                        <td><?= format_currency($md['fixed']) ?></td>
                        <td><?= format_currency($md['capital']) ?></td>
                        <td class="<?= $md['profit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= format_currency($md['profit']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php elseif ($reportType === 'revenue' && $printData): ?>
        <div class="summary-box">
            <h5><?= $reportYear ?> — Revenue Report</h5>
            <div class="summary-grid">
                <div class="summary-item"><div class="label">Grand Total Revenue</div><div class="value text-success"><?= format_currency($printData['grandTotal']) ?></div></div>
            </div>
        </div>
        <table>
            <thead><tr><th>Product</th><th>Capital</th><th>Total Revenue</th><th>Net</th></tr></thead>
            <tbody>
                <?php foreach ($printData['items'] as $row):
                    $net = (float)$row['total_revenue'] - (float)$row['capital'];
                ?>
                    <tr>
                        <td><?= e($row['name']) ?></td>
                        <td><?= format_currency((float)$row['capital']) ?></td>
                        <td><?= format_currency((float)$row['total_revenue']) ?></td>
                        <td class="<?= $net >= 0 ? 'text-success' : 'text-danger' ?>"><?= format_currency($net) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php elseif ($reportType === 'expense' && $printData): ?>
        <div class="summary-box">
            <h5><?= $reportYear ?> — Expense Report</h5>
            <div class="summary-grid">
                <div class="summary-item"><div class="label">Grand Total Expenses</div><div class="value text-danger"><?= format_currency($printData['grandTotal']) ?></div></div>
            </div>
        </div>
        <h5 class="mt-4">All Expenses</h5>
        <table>
            <thead><tr><th>Category</th><th>Expense</th><th>Amount</th><th>Date</th><th>Product</th></tr></thead>
            <tbody>
                <?php foreach ($printData['items'] as $row): ?>
                    <tr><td><?= e($row['expense_name']) ?></td><td><?= format_currency((float)$row['amount']) ?></td><td><?= format_date($row['expense_date']) ?></td><td><?= e($row['product_name'] ?? '—') ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php elseif ($reportType === 'products' && $printData): ?>
        <div class="summary-box">
            <h5>Product Performance</h5>
        </div>
        <table>
            <thead><tr><th>Product</th><th>Capital</th><th>Total Revenue</th><th>Total Expenses</th><th>Net</th><th>Months Active</th></tr></thead>
            <tbody>
                <?php foreach ($printData['items'] as $row):
                    $exp = $printData['expenseMap'][$row['id']] ?? 0;
                    $net = (float)$row['total_revenue'] - (float)$row['capital'] - $exp;
                ?>
                    <tr>
                        <td><?= e($row['name']) ?></td>
                        <td><?= format_currency((float)$row['capital']) ?></td>
                        <td><?= format_currency((float)$row['total_revenue']) ?></td>
                        <td><?= format_currency($exp) ?></td>
                        <td class="<?= $net >= 0 ? 'text-success' : 'text-danger' ?>"><?= format_currency($net) ?></td>
                        <td><?= (int)$row['months_active'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="mt-4 text-center text-muted" style="font-size: 12px;">
        <p>Generated by ProfitTracker — Business Profit & Expense Management System</p>
    </div>

    <script>window.print();</script>
</body>
</html>
<?php
    exit;
}

// ─── Default: Load report data for display ──────────────────
$currentData = null;
switch ($reportType) {
    case 'monthly':  $currentData = getMonthlyReport($pdo, $uid, $reportMonth, $reportYear); break;
    case 'yearly':   $currentData = getYearlyReport($pdo, $uid, $reportYear); break;
    case 'revenue':  $currentData = getRevenueReport($pdo, $uid, $reportYear); break;
    case 'expense':  $currentData = getExpenseReport($pdo, $uid, $reportYear); break;
    case 'products': $currentData = getProductPerformance($pdo, $uid); break;
}

$pageTitle = 'Reports';
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>

<!-- Page Header -->
<div class="page-header">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
        <div>
            <h4 class="page-title mb-1">Reports</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Reports</li>
                </ol>
            </nav>
        </div>
        <div class="d-flex gap-2">
            <a href="?type=<?= $reportType ?>&month=<?= $reportMonth ?>&year=<?= $reportYear ?>&export=print" class="btn btn-outline-primary" target="_blank">
                <i class="bi bi-printer me-1"></i>Print
            </a>
            <a href="?type=<?= $reportType ?>&month=<?= $reportMonth ?>&year=<?= $reportYear ?>&export=csv" class="btn btn-outline-success">
                <i class="bi bi-file-earmark-excel me-1"></i>Export CSV
            </a>
        </div>
    </div>
</div>

<!-- Report Type & Filters -->
<div class="card card-dashboard mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-medium">Report Type</label>
                <select class="form-select form-select-lg" name="type" onchange="this.form.submit()">
                    <option value="monthly" <?= $reportType === 'monthly' ? 'selected' : '' ?>>Monthly Report</option>
                    <option value="yearly" <?= $reportType === 'yearly' ? 'selected' : '' ?>>Yearly Report</option>
                    <option value="revenue" <?= $reportType === 'revenue' ? 'selected' : '' ?>>Revenue Report</option>
                    <option value="expense" <?= $reportType === 'expense' ? 'selected' : '' ?>>Expense Report</option>
                    <option value="products" <?= $reportType === 'products' ? 'selected' : '' ?>>Product Performance</option>
                </select>
            </div>
            <?php if (in_array($reportType, ['monthly'], true)): ?>
                <div class="col-md-2">
                    <label class="form-label fw-medium">Month</label>
                    <select class="form-select form-select-lg" name="month">
                        <?php foreach (month_options() as $num => $name): ?>
                            <option value="<?= $num ?>" <?= $reportMonth === $num ? 'selected' : '' ?>><?= $name ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <?php if (in_array($reportType, ['monthly', 'yearly', 'revenue', 'expense'], true)): ?>
                <div class="col-md-2">
                    <label class="form-label fw-medium">Year</label>
                    <select class="form-select form-select-lg" name="year">
                        <?php foreach (year_options() as $y): ?>
                            <option value="<?= $y ?>" <?= $reportYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-search me-1"></i>Generate</button>
            </div>
        </form>
    </div>
</div>

<!-- Report Content -->
<?php if ($reportType === 'monthly' && $currentData): ?>
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="stat-card"><div class="stat-icon bg-success-soft"><i class="bi bi-cash-stack text-success"></i></div>
                <div class="stat-info"><span class="stat-label">Revenue</span><span class="stat-value"><?= format_currency($currentData['totalRevenue']) ?></span></div></div>
        </div>
        <div class="col-md-3">
            <div class="stat-card"><div class="stat-icon bg-danger-soft"><i class="bi bi-cart-x text-danger"></i></div>
                <div class="stat-info"><span class="stat-label">Expenses</span><span class="stat-value"><?= format_currency($currentData['totalExpenses']) ?></span></div></div>
        </div>
        <div class="col-md-3">
            <div class="stat-card"><div class="stat-icon bg-warning-soft"><i class="bi bi-receipt text-warning"></i></div>
                <div class="stat-info"><span class="stat-label">Fixed</span><span class="stat-value"><?= format_currency($currentData['totalFixed']) ?></span></div></div>
        </div>
        <div class="col-md-3">
            <div class="stat-card <?= $currentData['netProfit'] >= 0 ? '' : 'stat-card-danger' ?>">
                <div class="stat-icon <?= $currentData['netProfit'] >= 0 ? 'bg-success-soft' : 'bg-danger-soft' ?>">
                    <i class="bi bi-graph-up-arrow <?= $currentData['netProfit'] >= 0 ? 'text-success' : 'text-danger' ?>"></i>
                </div>
                <div class="stat-info"><span class="stat-label">Net Profit</span><span class="stat-value"><?= format_currency($currentData['netProfit']) ?></span></div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-7">
            <div class="card card-dashboard">
                <div class="card-header"><h5 class="card-title mb-0">Revenue by Product</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead><tr><th>Product</th><th>Capital</th><th>Revenue</th><th>Net</th></tr></thead>
                            <tbody>
                                <?php foreach ($currentData['items'] as $row):
                                    $net = (float)$row['revenue'] - (float)$row['capital'];
                                ?>
                                    <tr>
                                        <td class="fw-medium"><?= e($row['name']) ?></td>
                                        <td><?= format_currency((float)$row['capital']) ?></td>
                                        <td class="text-success fw-semibold"><?= format_currency((float)$row['revenue']) ?></td>
                                        <td class="<?= $net >= 0 ? 'text-success' : 'text-danger' ?>"><?= format_currency($net) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="card card-dashboard h-100">
                <div class="card-header"><h5 class="card-title mb-0">Expenses by Category</h5></div>
                <div class="card-body p-0">
                    <?php if (!empty($currentData['expenseByCat'])): ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead><tr><th>Category</th><th>Amount</th></tr></thead>
                            <tbody>
                                <?php foreach ($currentData['expenseByCat'] as $ec): ?>
                                    <tr>
                                        <td><?= e($ec['name'] ?? 'Uncategorized') ?></td>
                                        <td class="text-danger"><?= format_currency((float)$ec['total']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted"><i class="bi bi-inbox" style="font-size: 2rem;"></i><p class="mt-2 mb-0">No expenses</p></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($reportType === 'yearly' && $currentData): ?>
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="stat-card"><div class="stat-icon bg-success-soft"><i class="bi bi-cash-stack text-success"></i></div>
                <div class="stat-info"><span class="stat-label">Total Revenue</span><span class="stat-value"><?= format_currency($currentData['grandRevenue']) ?></span></div></div>
        </div>
        <div class="col-md-3">
            <div class="stat-card"><div class="stat-icon bg-danger-soft"><i class="bi bi-cart-x text-danger"></i></div>
                <div class="stat-info"><span class="stat-label">Total Expenses</span><span class="stat-value"><?= format_currency($currentData['grandExpenses']) ?></span></div></div>
        </div>
        <div class="col-md-3">
            <div class="stat-card"><div class="stat-icon bg-warning-soft"><i class="bi bi-receipt text-warning"></i></div>
                <div class="stat-info"><span class="stat-label">Fixed (Annual)</span><span class="stat-value"><?= format_currency($currentData['grandFixed']) ?></span></div></div>
        </div>
        <div class="col-md-3">
            <div class="stat-card <?= $currentData['grandProfit'] >= 0 ? '' : 'stat-card-danger' ?>">
                <div class="stat-icon <?= $currentData['grandProfit'] >= 0 ? 'bg-success-soft' : 'bg-danger-soft' ?>">
                    <i class="bi bi-graph-up-arrow <?= $currentData['grandProfit'] >= 0 ? 'text-success' : 'text-danger' ?>"></i>
                </div>
                <div class="stat-info"><span class="stat-label">Net Profit</span><span class="stat-value"><?= format_currency($currentData['grandProfit']) ?></span></div>
            </div>
        </div>
    </div>

    <div class="card card-dashboard">
        <div class="card-header"><h5 class="card-title mb-0">Monthly Breakdown</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Month</th><th>Revenue</th><th>Expenses</th><th>Fixed</th><th>Capital</th><th>Profit</th></tr></thead>
                    <tbody>
                        <?php foreach ($currentData['monthly'] as $md): ?>
                            <tr>
                                <td class="fw-medium"><?= month_name($md['month']) ?></td>
                                <td class="text-success"><?= format_currency($md['revenue']) ?></td>
                                <td class="text-danger"><?= format_currency($md['expenses']) ?></td>
                                <td class="text-warning"><?= format_currency($md['fixed']) ?></td>
                                <td class="text-info"><?= format_currency($md['capital']) ?></td>
                                <td class="fw-semibold <?= $md['profit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= format_currency($md['profit']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($reportType === 'revenue' && $currentData): ?>
    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="stat-card"><div class="stat-icon bg-success-soft"><i class="bi bi-cash-stack text-success"></i></div>
                <div class="stat-info"><span class="stat-label">Grand Total Revenue</span><span class="stat-value"><?= format_currency($currentData['grandTotal']) ?></span></div></div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-7">
            <div class="card card-dashboard">
                <div class="card-header"><h5 class="card-title mb-0">Revenue by Product</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead><tr><th>Product</th><th>Capital</th><th>Total Revenue</th><th>Net</th></tr></thead>
                            <tbody>
                                <?php foreach ($currentData['items'] as $row):
                                    $net = (float)$row['total_revenue'] - (float)$row['capital'];
                                ?>
                                    <tr>
                                        <td class="fw-medium"><?= e($row['name']) ?></td>
                                        <td><?= format_currency((float)$row['capital']) ?></td>
                                        <td class="text-success fw-semibold"><?= format_currency((float)$row['total_revenue']) ?></td>
                                        <td class="<?= $net >= 0 ? 'text-success' : 'text-danger' ?>"><?= format_currency($net) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="card card-dashboard h-100">
                <div class="card-header"><h5 class="card-title mb-0">Monthly Trend</h5></div>
                <div class="card-body">
                    <canvas id="revenueTrendChart" height="240"></canvas>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($reportType === 'expense' && $currentData): ?>
    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="stat-card"><div class="stat-icon bg-danger-soft"><i class="bi bi-cart-x text-danger"></i></div>
                <div class="stat-info"><span class="stat-label">Grand Total</span><span class="stat-value"><?= format_currency($currentData['grandTotal']) ?></span></div></div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-12">
            <div class="card card-dashboard">
                <div class="card-header"><h5 class="card-title mb-0">All Expenses</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-hover mb-0">
                            <thead><tr><th>Expense</th><th>Amount</th><th>Date</th><th>Product</th></tr></thead>
                            <tbody>
                                <?php foreach ($currentData['items'] as $row): ?>
                                    <tr>
                                        <td class="fw-medium"><?= e($row['expense_name']) ?></td>
                                        <td class="text-danger"><?= format_currency((float)$row['amount']) ?></td>
                                        <td class="small text-muted"><?= format_date($row['expense_date']) ?></td>
                                        <td class="small text-muted"><?= e($row['product_name'] ?? '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($reportType === 'products' && $currentData): ?>
    <div class="card card-dashboard">
        <div class="card-header"><h5 class="card-title mb-0">Product Performance</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Product</th><th>Capital</th><th>Total Revenue</th><th>Total Expenses</th><th>Net Profit</th><th>ROI</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($currentData['items'] as $row):
                            $exp = $currentData['expenseMap'][$row['id']] ?? 0;
                            $net = (float)$row['total_revenue'] - (float)$row['capital'] - $exp;
                            $roi = (float)$row['capital'] > 0 ? round(($net / (float)$row['capital']) * 100, 1) : 0;
                        ?>
                            <tr>
                                <td class="fw-medium"><?= e($row['name']) ?></td>
                                <td><?= format_currency((float)$row['capital']) ?></td>
                                <td class="text-success"><?= format_currency((float)$row['total_revenue']) ?></td>
                                <td class="text-danger"><?= format_currency($exp) ?></td>
                                <td class="fw-semibold <?= $net >= 0 ? 'text-success' : 'text-danger' ?>"><?= format_currency($net) ?></td>
                                <td class="<?= $roi >= 0 ? 'text-success' : 'text-danger' ?>"><?= $roi ?>%</td>
                                <td><span class="badge <?= $row['status'] === 'active' ? 'bg-success-soft text-success' : 'bg-secondary-soft text-secondary' ?>"><?= ucfirst(e($row['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($currentData['items'])): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No products found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php
// Revenue trend chart for revenue report
if ($reportType === 'revenue' && $currentData):
    $revMonths = json_encode(array_values(month_options()));
    $revData = json_encode(array_values($currentData['monthlyRev']));
$extraJs = <<<JS
<script>
new Chart(document.getElementById('revenueTrendChart'), {
    type: 'line',
    data: {
        labels: {$revMonths},
        datasets: [{
            label: 'Revenue',
            data: {$revData},
            borderColor: '#2563EB',
            backgroundColor: 'rgba(37, 99, 235, 0.08)',
            fill: true,
            tension: 0.4,
            pointRadius: 4,
            pointBackgroundColor: '#2563EB',
            borderWidth: 2.5,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.06)' } },
            x: { grid: { display: false } }
        }
    }
});
</script>
JS;
endif;
?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
