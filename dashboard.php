<?php
/**
 * Dashboard — main analytics page
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

start_session();
require_auth();

$pdo = getDB();
$uid = user_id();
$currentMonth = current_month();
$currentYear  = current_year();
$fullName = $_SESSION['full_name'] ?? 'User';

// ─── Product Filter ────────────────────────────────────────
$filterProduct = (int)($_GET['product_id'] ?? 0);

$stmt = $pdo->prepare("SELECT id, name FROM products WHERE user_id = :uid AND is_deleted = 0 ORDER BY name");
$stmt->execute([':uid' => $uid]);
$allProducts = $stmt->fetchAll();

$selectedProductName = '';
if ($filterProduct) {
    foreach ($allProducts as $p) {
        if ((int)$p['id'] === $filterProduct) {
            $selectedProductName = $p['name'];
            break;
        }
    }
}

// Build product filter SQL snippet
$prodRev = $filterProduct ? " AND product_id = :pid" : "";
$prodExp = $filterProduct ? " AND product_id = :pid" : "";
$prodFix = $filterProduct ? " AND (product_id = :pid OR product_id IS NULL)" : "";
$prodCap = $filterProduct ? " AND id = :pid" : "";
$pidParam = $filterProduct ? [':pid' => $filterProduct] : [];

// ─── Summary Stats ──────────────────────────────────────────
$stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE user_id = :uid AND is_deleted = 0");
$stmt->execute([':uid' => $uid]);
$totalProducts = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM revenue WHERE user_id = :uid AND month = :m AND year = :y{$prodRev}");
$stmt->execute(array_merge([':uid' => $uid, ':m' => $currentMonth, ':y' => $currentYear], $pidParam));
$monthlyRevenue = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE user_id = :uid AND MONTH(expense_date) = :m AND YEAR(expense_date) = :y AND is_fixed = 0{$prodExp}");
$stmt->execute(array_merge([':uid' => $uid, ':m' => $currentMonth, ':y' => $currentYear], $pidParam));
$monthlyExpenses = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM fixed_expenses WHERE user_id = :uid AND status = 'active'{$prodFix}");
$stmt->execute(array_merge([':uid' => $uid], $pidParam));
$monthlyFixedExpenses = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(capital), 0) FROM products WHERE user_id = :uid AND is_deleted = 0{$prodCap}");
$stmt->execute(array_merge([':uid' => $uid], $pidParam));
$totalCapital = (float)$stmt->fetchColumn();

// Profit = Revenue - Capital - Expenses - Fixed Expenses
$netProfit = $monthlyRevenue - $totalCapital - $monthlyExpenses - $monthlyFixedExpenses;

// Last month comparison for trends
$prevMonth = $currentMonth === 1 ? 12 : $currentMonth - 1;
$prevYear  = $currentMonth === 1 ? $currentYear - 1 : $currentYear;

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM revenue WHERE user_id = :uid AND month = :m AND year = :y{$prodRev}");
$stmt->execute(array_merge([':uid' => $uid, ':m' => $prevMonth, ':y' => $prevYear], $pidParam));
$prevRevenue = (float)$stmt->fetchColumn();

$revTrend = $prevRevenue > 0 ? round((($monthlyRevenue - $prevRevenue) / $prevRevenue) * 100, 1) : 0;

// ─── Chart Data: All months with revenue data ──────────────
$chartData = [];
// Find the full date range that has revenue (optionally filtered by product)
$stmt = $pdo->prepare("SELECT MIN(CONCAT(year,'-',LPAD(month,2,'0'))) as first, MAX(CONCAT(year,'-',LPAD(month,2,'0'))) as last FROM revenue WHERE user_id = :uid{$prodRev}");
$stmt->execute(array_merge([':uid' => $uid], $pidParam));
$range = $stmt->fetch();

if ($range && $range['first'] && $range['last']) {
    $start = new DateTime($range['first'] . '-01');
    $end   = new DateTime($range['last'] . '-01');
    $end->modify('+1 month'); // include the last month
    $interval = new DateInterval('P1M');
    $period   = new DatePeriod($start, $interval, $end);
} else {
    // No revenue data — show current month only
    $period = [new DateTime("{$currentYear}-{$currentMonth}-01")];
}

foreach ($period as $dt) {
    $m = (int)$dt->format('n');
    $y = (int)$dt->format('Y');

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM revenue WHERE user_id = :uid AND month = :m AND year = :y{$prodRev}");
    $stmt->execute(array_merge([':uid' => $uid, ':m' => $m, ':y' => $y], $pidParam));
    $rev = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE user_id = :uid AND MONTH(expense_date) = :m AND YEAR(expense_date) = :y AND is_fixed = 0{$prodExp}");
    $stmt->execute(array_merge([':uid' => $uid, ':m' => $m, ':y' => $y], $pidParam));
    $exp = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM fixed_expenses WHERE user_id = :uid AND status = 'active'{$prodFix}");
    $stmt->execute(array_merge([':uid' => $uid], $pidParam));
    $fix = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(capital), 0) FROM products WHERE user_id = :uid AND is_deleted = 0{$prodCap}");
    $stmt->execute(array_merge([':uid' => $uid], $pidParam));
    $cap = (float)$stmt->fetchColumn();

    $opProfit = $rev - $exp - $fix;

    $chartData[] = [
        'label'    => $dt->format('M') . ' ' . $y,
        'month'    => $m,
        'year'     => $y,
        'revenue'  => $rev,
        'expense'  => $exp + $fix,
        'profit'   => $opProfit,
    ];
}

// ─── Latest Expenses ────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT e.*, p.name as product_name
    FROM expenses e
    LEFT JOIN products p ON e.product_id = p.id
    WHERE e.user_id = :uid
    ORDER BY e.created_at DESC
    LIMIT 5
");
$stmt->execute([':uid' => $uid]);
$latestExpenses = $stmt->fetchAll();

// ─── Top Products by Revenue ────────────────────────────────
$stmt = $pdo->prepare("
    SELECT p.id, p.name, p.capital,
           COALESCE(SUM(r.amount), 0) as total_revenue
    FROM products p
    LEFT JOIN revenue r ON p.id = r.product_id AND r.user_id = :uid2
    WHERE p.user_id = :uid AND p.is_deleted = 0
    GROUP BY p.id
    ORDER BY total_revenue DESC
    LIMIT 5
");
$stmt->execute([':uid' => $uid, ':uid2' => $uid]);
$topProducts = $stmt->fetchAll();

// ─── Year Summary Stats ─────────────────────────────────────
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM revenue WHERE user_id = :uid AND year = :y{$prodRev}");
$stmt->execute(array_merge([':uid' => $uid, ':y' => $currentYear], $pidParam));
$yearlyRevenue = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE user_id = :uid AND YEAR(expense_date) = :y AND is_fixed = 0{$prodExp}");
$stmt->execute(array_merge([':uid' => $uid, ':y' => $currentYear], $pidParam));
$yearlyExpenses = (float)$stmt->fetchColumn();

$yearlyFixedExpenses = $monthlyFixedExpenses * 12;
$yearlyCapital = $totalCapital;
$yearlyNetProfit = $yearlyRevenue - $yearlyCapital - $yearlyExpenses - $yearlyFixedExpenses;

$pageTitle = 'Dashboard';
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>

<!-- ─── Welcome Banner ──────────────────────────────────── -->
<div class="dash-welcome d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
    <div>
        <h5>Good <?= date('A') === 'AM' ? 'morning' : 'afternoon' ?>, <?= e($fullName) ?> 👋</h5>
        <p>Here's what's happening with your business this month.</p>
    </div>
    <div class="dash-date text-end">
        <div><i class="bi bi-calendar3 me-1"></i><?= date('F d, Y') ?></div>
        <div class="mt-1">
            <span class="badge bg-white bg-opacity-25 text-white">
                <i class="bi bi-clock me-1"></i><?= date('l') ?>
            </span>
        </div>
    </div>
</div>

<?= flash_messages() ?>

<!-- ─── Section: Key Metrics ─────────────────────────────── -->
<div class="d-flex align-items-center gap-2 mb-3">
    <div class="section-title">Key Metrics</div>
    <span class="badge bg-primary-soft text-primary rounded-pill"><?= month_name($currentMonth) ?> <?= $currentYear ?></span>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-4 col-lg-4 col-md-6 col-sm-6 d-flex">
        <div class="stat-card stat-card-primary flex-fill">
            <div class="stat-icon bg-primary-soft">
                <i class="bi bi-box-seam text-primary"></i>
            </div>
            <div class="stat-info">
                <span class="stat-label"><?= $filterProduct ? 'Product' : 'Products' ?></span>
                <span class="stat-value"><?= $filterProduct ? e($selectedProductName) : $totalProducts ?></span>
                <span class="stat-trend stat-trend-up"><i class="bi bi-box"></i> <?= $filterProduct ? 'Selected' : 'Active' ?></span>
            </div>
        </div>
    </div>
    <div class="col-xl-4 col-lg-4 col-md-6 col-sm-6 d-flex">
        <div class="stat-card stat-card-success flex-fill">
            <div class="stat-icon bg-success-soft">
                <i class="bi bi-cash-stack text-success"></i>
            </div>
            <div class="stat-info">
                <span class="stat-label">Revenue</span>
                <span class="stat-value"><?= format_currency($monthlyRevenue) ?></span>
                <?php if ($revTrend != 0): ?>
                <span class="stat-trend <?= $revTrend >= 0 ? 'stat-trend-up' : 'stat-trend-down' ?>">
                    <i class="bi bi-<?= $revTrend >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i><?= abs($revTrend) ?>%
                </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-4 col-lg-4 col-md-6 col-sm-6 d-flex">
        <div class="stat-card stat-card-danger flex-fill">
            <div class="stat-icon bg-danger-soft">
                <i class="bi bi-cart-x text-danger"></i>
            </div>
            <div class="stat-info">
                <span class="stat-label">Expenses</span>
                <span class="stat-value"><?= format_currency($monthlyExpenses) ?></span>
                <span class="stat-trend stat-trend-down"><i class="bi bi-arrow-down"></i> Outflow</span>
            </div>
        </div>
    </div>
    <div class="col-xl-4 col-lg-4 col-md-6 col-sm-6 d-flex">
        <div class="stat-card stat-card-warning flex-fill">
            <div class="stat-icon bg-warning-soft">
                <i class="bi bi-receipt text-warning"></i>
            </div>
            <div class="stat-info">
                <span class="stat-label">Fixed</span>
                <span class="stat-value"><?= format_currency($monthlyFixedExpenses) ?></span>
                <span class="stat-trend" style="background:var(--warning-light);color:var(--warning);"><i class="bi bi-calendar-check"></i> Monthly</span>
            </div>
        </div>
    </div>
    <div class="col-xl-4 col-lg-4 col-md-6 col-sm-6 d-flex">
        <div class="stat-card stat-card-purple flex-fill">
            <div class="stat-icon bg-purple-soft">
                <i class="bi bi-cpu" style="color:var(--purple)"></i>
            </div>
            <div class="stat-info">
                <span class="stat-label">Capital</span>
                <span class="stat-value"><?= format_currency($totalCapital) ?></span>
                <span class="stat-trend" style="background:var(--purple-light);color:var(--purple);"><i class="bi bi-box"></i> Investment</span>
            </div>
        </div>
    </div>
    <div class="col-xl-4 col-lg-4 col-md-6 col-sm-6 d-flex">
        <div class="stat-card flex-fill <?= $netProfit >= 0 ? 'stat-card-success' : 'stat-card-danger' ?>">
            <div class="stat-icon <?= $netProfit >= 0 ? 'bg-success-soft' : 'bg-danger-soft' ?>">
                <i class="bi bi-graph-up-arrow <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>"></i>
            </div>
            <div class="stat-info">
                <span class="stat-label">Net Profit</span>
                <span class="stat-value <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= $netProfit >= 0 ? '+' : '' ?><?= format_currency($netProfit) ?>
                </span>
                <span class="stat-trend <?= $netProfit >= 0 ? 'stat-trend-up' : 'stat-trend-down' ?>">
                    <i class="bi bi-<?= $netProfit >= 0 ? 'check-circle' : 'exclamation-circle' ?>"></i>
                    <?= $netProfit >= 0 ? 'Profitable' : 'Loss' ?>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- ─── Section: Analytics ───────────────────────────────── -->
<div class="d-flex align-items-center gap-2 mb-3">
    <div class="section-title">Analytics</div>
    <?php if ($filterProduct): ?>
        <?php
        $pname = '';
        foreach ($allProducts as $p) { if ((int)$p['id'] === $filterProduct) { $pname = $p['name']; break; } }
        ?>
        <span class="badge bg-primary-soft text-primary rounded-pill"><?= e($pname) ?></span>
    <?php endif; ?>
</div>

<div class="row g-4 mb-4">
    <!-- Revenue vs Expenses Line Chart -->
    <div class="col-lg-6">
        <div class="card card-dashboard card-chart h-100">
            <div class="card-header d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2">
                <h5 class="card-title mb-0">
                    <i class="bi bi-graph-up"></i>Revenue vs Expenses
                </h5>
                <form method="GET" class="d-flex gap-2">
                    <select class="form-select form-select-sm" name="product_id" onchange="this.form.submit()" style="width:auto;min-width:160px;">
                        <option value="0">All Products</option>
                        <?php foreach ($allProducts as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $filterProduct === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="card-body">
                <canvas id="revenueExpenseChart" height="220"></canvas>
                <div class="d-flex justify-content-between mt-3 small text-muted">
                    <span><span class="fw-semibold text-primary">Revenue</span> vs <span class="fw-semibold text-danger">Operating Costs</span></span>
                    <span class="fw-bold <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>">
                        Net Profit (incl. capital): <?= format_currency($netProfit) ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Monthly Profit Bar Chart -->
    <div class="col-lg-6">
        <div class="card card-dashboard card-chart h-100">
            <div class="card-header d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2">
                <h5 class="card-title mb-0"><i class="bi bi-bar-chart"></i>Monthly Profit</h5>
                <div class="chart-header-metrics">
                    <div class="chart-header-metric">
                        <div class="chm-label">Best Month</div>
                        <?php $profits = array_column($chartData, 'profit'); $maxProfit = count($profits) > 0 ? max($profits) : 0; ?>
                        <div class="chm-value text-success"><?= format_currency($maxProfit) ?></div>
                    </div>
                    <div class="chart-header-metric">
                        <div class="chm-label">Total (12mo)</div>
                        <div class="chm-value" style="color:var(--text-primary)"><?= format_currency(array_sum($profits)) ?></div>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <canvas id="profitChart" height="220"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Current Month Summary -->
    <div class="col-lg-5">
        <div class="card card-dashboard h-100">
            <div class="card-header"><h5 class="card-title mb-0"><i class="bi bi-calculator"></i>Current Month Summary</h5></div>
            <div class="card-body d-flex flex-column justify-content-center">
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted small">Revenue</span>
                    <span class="fw-semibold text-success"><?= format_currency($monthlyRevenue) ?></span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted small">Expenses</span>
                    <span class="fw-semibold text-danger"><?= format_currency($monthlyExpenses) ?></span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted small">Fixed Expenses</span>
                    <span class="fw-semibold text-warning"><?= format_currency($monthlyFixedExpenses) ?></span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted small">Capital</span>
                    <span class="fw-semibold text-info"><?= format_currency($totalCapital) ?></span>
                </div>
                <div class="d-flex justify-content-between py-2">
                    <span class="fw-semibold">Net Profit</span>
                    <span class="fw-bold <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>"><?= format_currency($netProfit) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Top Products -->
    <div class="col-lg-7">
        <div class="card card-dashboard h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0"><i class="bi bi-trophy"></i>Top Products</h5>
                <a href="products.php" class="btn btn-sm btn-outline-primary rounded-pill">Manage</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Product</th><th>Capital</th><th>Total Revenue</th><th class="text-end">Net</th></tr></thead>
                        <tbody>
                            <?php foreach ($topProducts as $tp):
                                $net = (float)$tp['total_revenue'] - (float)$tp['capital'];
                            ?>
                                <tr>
                                    <td class="fw-medium"><?= e($tp['name']) ?></td>
                                    <td><?= format_currency((float)$tp['capital']) ?></td>
                                    <td class="text-success fw-semibold"><?= format_currency((float)$tp['total_revenue']) ?></td>
                                    <td class="text-end"><span class="badge <?= $net >= 0 ? 'bg-success-soft' : 'bg-danger-soft' ?> px-3 py-2"><?= $net >= 0 ? '+' : '' ?><?= format_currency($net) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($topProducts)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">No products yet</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Latest Expenses -->
    <div class="col-lg-6">
        <div class="card card-dashboard h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0"><i class="bi bi-clock-history"></i>Latest Expenses</h5>
                <a href="expenses.php" class="btn btn-sm btn-outline-primary rounded-pill">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Expense</th><th>Product</th><th class="text-end">Amount</th><th class="text-end">Date</th></tr></thead>
                        <tbody>
                            <?php foreach ($latestExpenses as $le): ?>
                                <tr>
                                    <td class="fw-medium"><?= e($le['expense_name']) ?></td>
                                    <td><span class="badge bg-primary-soft text-primary"><?= e($le['product_name'] ?? 'General') ?></span></td>
                                    <td class="text-end text-danger fw-semibold"><?= format_currency((float)$le['amount']) ?></td>
                                    <td class="text-end text-muted small"><?= format_date($le['expense_date']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($latestExpenses)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">No expenses recorded</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Year Summary -->
    <div class="col-lg-6">
        <div class="card card-dashboard h-100">
            <div class="card-header"><h5 class="card-title mb-0"><i class="bi bi-calendar3"></i>Year <?= $currentYear ?> Summary</h5></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6"><div class="chart-metric"><div class="metric-label">Yearly Revenue</div><div class="metric-value text-success"><?= format_currency($yearlyRevenue) ?></div></div></div>
                    <div class="col-6"><div class="chart-metric"><div class="metric-label">Yearly Expenses</div><div class="metric-value text-danger"><?= format_currency($yearlyExpenses) ?></div></div></div>
                    <div class="col-6"><div class="chart-metric"><div class="metric-label">Fixed Costs</div><div class="metric-value text-warning"><?= format_currency($yearlyFixedExpenses) ?></div></div></div>
                    <div class="col-6"><div class="chart-metric"><div class="metric-label">Total Capital</div><div class="metric-value text-info"><?= format_currency($yearlyCapital) ?></div></div></div>
                    <div class="col-12 mt-2">
                        <div class="d-flex justify-content-between align-items-center p-3 rounded-4 <?= $yearlyNetProfit >= 0 ? 'bg-success-soft' : 'bg-danger-soft' ?>">
                            <span class="fw-semibold <?= $yearlyNetProfit >= 0 ? 'text-success' : 'text-danger' ?>"><i class="bi bi-<?= $yearlyNetProfit >= 0 ? 'check-circle-fill' : 'exclamation-circle-fill' ?> me-2"></i>Yearly Net Profit</span>
                            <span class="fw-bold fs-5 <?= $yearlyNetProfit >= 0 ? 'text-success' : 'text-danger' ?>"><?= format_currency($yearlyNetProfit) ?></span>
                        </div>
                    </div>
                    <div class="col-6"><div class="d-flex justify-content-between p-2"><small class="text-muted">Monthly Avg Revenue</small><small class="fw-semibold"><?= format_currency($yearlyRevenue / 12) ?></small></div></div>
                    <div class="col-6"><div class="d-flex justify-content-between p-2"><small class="text-muted">Profit Margin</small><small class="fw-semibold <?= $yearlyNetProfit >= 0 ? 'text-success' : 'text-danger' ?>"><?= $yearlyRevenue > 0 ? round(($yearlyNetProfit / $yearlyRevenue) * 100, 1) : 0 ?>%</small></div></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$chartLabels  = json_encode(array_column($chartData, 'label'));
$chartRevenue = json_encode(array_column($chartData, 'revenue'));
$chartExpense = json_encode(array_column($chartData, 'expense'));
$chartProfit  = json_encode(array_column($chartData, 'profit'));
$currencySym  = $_SESSION['currency'] ?? '₱';
$barColors    = array_map(function($v) { return $v >= 0 ? 'rgba(22,163,74,0.75)' : 'rgba(220,38,38,0.75)'; }, array_column($chartData, 'profit'));
$barBorders   = array_map(function($v) { return $v >= 0 ? '#16A34A' : '#DC2626'; }, array_column($chartData, 'profit'));
$barColorsJson  = json_encode(array_values($barColors));
$barBordersJson = json_encode(array_values($barBorders));
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Chart === 'undefined') { console.error('Chart.js not loaded'); return; }
    Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
    Chart.defaults.color = '#64748b';

    new Chart(document.getElementById('revenueExpenseChart'), {
        type: 'line',
        data: {
            labels: <?= $chartLabels ?>,
            datasets: [
                {
                    label: 'Revenue',
                    data: <?= $chartRevenue ?>,
                    borderColor: '#2563EB',
                    backgroundColor: 'rgba(37,99,235,0.06)',
                    fill: true, tension: 0.4, pointRadius: 4, pointHoverRadius: 6,
                    pointBackgroundColor: '#fff', pointBorderColor: '#2563EB', pointBorderWidth: 2.5, borderWidth: 2.5,
                },
                {
                    label: 'Total Costs',
                    data: <?= $chartExpense ?>,
                    borderColor: '#DC2626',
                    backgroundColor: 'rgba(220,38,38,0.06)',
                    fill: true, tension: 0.4, pointRadius: 4, pointHoverRadius: 6,
                    pointBackgroundColor: '#fff', pointBorderColor: '#DC2626', pointBorderWidth: 2.5, borderWidth: 2.5,
                }
            ]
        },
        options: {
            responsive: true, aspectRatio: 2,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1e293b', padding: 12, cornerRadius: 10,
                    callbacks: {
                        label: function(ctx) {
                            return '  ' + ctx.dataset.label + ': <?= $currencySym ?>' + ctx.parsed.y.toLocaleString();
                        }
                    }
                }
            },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)', drawBorder: false }, ticks: { callback: function(v) { return '<?= $currencySym ?>' + v.toLocaleString(); }, font: { size: 11 } } },
                x: { grid: { display: false }, ticks: { font: { size: 11 } } }
            },
            interaction: { intersect: false, mode: 'index' }
        }
    });

    new Chart(document.getElementById('profitChart'), {
        type: 'bar',
        data: {
            labels: <?= $chartLabels ?>,
            datasets: [{
                label: 'Net Profit',
                data: <?= $chartProfit ?>,
                backgroundColor: <?= $barColorsJson ?>,
                borderColor: <?= $barBordersJson ?>,
                borderWidth: 1, borderRadius: 6, borderSkipped: false,
            }]
        },
        options: {
            responsive: true, aspectRatio: 2,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1e293b', padding: 12, cornerRadius: 10,
                    callbacks: {
                        label: function(ctx) { return '  Net: <?= $currencySym ?>' + ctx.parsed.y.toLocaleString(); }
                    }
                }
            },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)', drawBorder: false }, ticks: { callback: function(v) { return '<?= $currencySym ?>' + v.toLocaleString(); }, font: { size: 11 } } },
                x: { grid: { display: false }, ticks: { font: { size: 11 } } }
            }
        }
    });

    // Pie chart removed — categories are no longer used
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
