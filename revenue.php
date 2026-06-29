<?php
/**
 * Revenue Module — monthly revenue per product
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

start_session();
require_auth();

$pdo = getDB();
$uid = user_id();

// ─── Handle POST Actions ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action    = $_POST['action'] ?? '';
    $productId = (int)($_POST['product_id'] ?? 0);
    $month     = (int)($_POST['month'] ?? 0);
    $year      = (int)($_POST['year'] ?? 0);
    $amount    = str_replace([',', ' '], '', $_POST['amount'] ?? '0');

    // Validate
    if ($productId <= 0 || $month < 1 || $month > 12 || $year < 2000) {
        redirect_with('revenue.php', 'Invalid input values.', 'error');
    }
    if (!is_valid_amount($amount)) {
        redirect_with('revenue.php', 'Invalid revenue amount.', 'error');
    }

    // Verify product ownership
    $stmt = $pdo->prepare("SELECT id FROM products WHERE id = :id AND user_id = :uid AND is_deleted = 0");
    $stmt->execute([':id' => $productId, ':uid' => $uid]);
    if (!$stmt->fetch()) {
        redirect_with('revenue.php', 'Product not found.', 'error');
    }

    if ($action === 'save') {
        // Upsert: if record exists, update; else insert
        $stmt = $pdo->prepare("SELECT id FROM revenue WHERE user_id = :uid AND product_id = :pid AND month = :m AND year = :y");
        $stmt->execute([':uid' => $uid, ':pid' => $productId, ':m' => $month, ':y' => $year]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE revenue SET amount = :amount WHERE id = :id AND user_id = :uid");
            $stmt->execute([':amount' => $amount, ':id' => $existing['id'], ':uid' => $uid]);
            redirect_with('revenue.php', 'Revenue updated successfully!');
        } else {
            $stmt = $pdo->prepare("INSERT INTO revenue (user_id, product_id, month, year, amount) VALUES (:uid, :pid, :m, :y, :amount)");
            $stmt->execute([':uid' => $uid, ':pid' => $productId, ':m' => $month, ':y' => $year, ':amount' => $amount]);
            redirect_with('revenue.php', 'Revenue saved successfully!');
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM revenue WHERE id = :id AND user_id = :uid");
        $stmt->execute([':id' => $id, ':uid' => $uid]);
        redirect_with('revenue.php', 'Revenue record deleted.');
    }
}

// ─── Filters ─────────────────────────────────────────────────
$filterMonth = (int)($_GET['month'] ?? 0);
$filterYear  = (int)($_GET['year'] ?? 0);
$filterProduct = (int)($_GET['product_id'] ?? 0);
$search      = sanitize($_GET['search'] ?? '');

// Fetch products for dropdown
$stmt = $pdo->prepare("SELECT id, name FROM products WHERE user_id = :uid AND is_deleted = 0 ORDER BY name");
$stmt->execute([':uid' => $uid]);
$products = $stmt->fetchAll();

// Build query
$where  = "r.user_id = :uid";
$params = [':uid' => $uid];

if ($filterMonth > 0) {
    $where .= " AND r.month = :m";
    $params[':m'] = $filterMonth;
}
if ($filterYear > 0) {
    $where .= " AND r.year = :y";
    $params[':y'] = $filterYear;
}
if ($filterProduct > 0) {
    $where .= " AND r.product_id = :pid";
    $params[':pid'] = $filterProduct;
}
if ($search !== '') {
    $where .= " AND (p.name LIKE :search)";
    $params[':search'] = "%{$search}%";
}

// Pagination
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM revenue r LEFT JOIN products p ON r.product_id = p.id WHERE {$where}");
$stmt->execute($params);
$totalRecords = (int)$stmt->fetchColumn();
$totalPages = max(1, ceil($totalRecords / $perPage));

$stmt = $pdo->prepare("
    SELECT r.*, p.name as product_name
    FROM revenue r
    LEFT JOIN products p ON r.product_id = p.id
    WHERE {$where}
    ORDER BY r.year DESC, r.month DESC, p.name ASC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$records = $stmt->fetchAll();

// Build pagination URL
$baseUrl = 'revenue.php';
$queryParams = [];
if ($filterMonth > 0) $queryParams['month'] = $filterMonth;
if ($filterYear > 0) $queryParams['year'] = $filterYear;
if ($filterProduct > 0) $queryParams['product_id'] = $filterProduct;
if ($search !== '') $queryParams['search'] = $search;
if (!empty($queryParams)) $baseUrl .= '?' . http_build_query($queryParams);

$pageTitle = 'Revenue';
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>

<!-- Page Header -->
<div class="page-header">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
        <div>
            <h4 class="page-title mb-1">Revenue</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Revenue</li>
                </ol>
            </nav>
        </div>
        <button class="btn btn-primary" type="button" onclick="openAddRevenue()">
            <i class="bi bi-plus-lg me-1"></i>Add Revenue
        </button>
    </div>
</div>

<?= flash_messages() ?>

<div class="card card-dashboard">
    <div class="card-body">
        <!-- Filters -->
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-3">
                <input type="text" class="form-control" name="search" placeholder="Search product..."
                       value="<?= e($search) ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="product_id">
                    <option value="0">All Products</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $filterProduct === (int)$p['id'] ? 'selected' : '' ?>>
                            <?= e($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="month">
                    <option value="0">All Months</option>
                    <?php foreach (month_options() as $num => $name): ?>
                        <option value="<?= $num ?>" <?= $filterMonth === $num ? 'selected' : '' ?>><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="year">
                    <option value="0">All Years</option>
                    <?php foreach (year_options() as $y): ?>
                        <option value="<?= $y ?>" <?= $filterYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-funnel me-1"></i>Filter</button>
                    <a href="revenue.php" class="btn btn-outline-secondary flex-fill">Reset</a>
                </div>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>Month</th>
                        <th>Year</th>
                        <th>Amount</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5">
                                <div class="text-muted">
                                    <i class="bi bi-inbox" style="font-size: 2.5rem;"></i>
                                    <p class="mt-2 mb-0">No revenue records found</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $i = $offset + 1; ?>
                        <?php foreach ($records as $r): ?>
                            <tr>
                                <td class="text-muted small"><?= $i++ ?></td>
                                <td class="fw-medium"><?= e($r['product_name'] ?? 'Unknown') ?></td>
                                <td><?= month_name((int)$r['month']) ?></td>
                                <td><?= (int)$r['year'] ?></td>
                                <td class="text-success fw-semibold"><?= format_currency((float)$r['amount']) ?></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary"
                                            onclick="openEditRevenue(<?= (int)$r['id'] ?>, <?= (int)$r['product_id'] ?>, <?= (int)$r['month'] ?>, <?= (int)$r['year'] ?>, '<?= e($r['amount']) ?>')">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger"
                                            onclick="confirmDeleteRevenue(<?= (int)$r['id'] ?>, '<?= e(addslashes($r['product_name'] ?? '')) ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="mt-3"><?= pagination_html($page, $totalPages, $baseUrl) ?></div>
        <?php endif; ?>

        <div class="text-muted small mt-2">Showing <?= count($records) ?> of <?= $totalRecords ?> record(s)</div>
    </div>
</div>

<!-- Create/Edit Modal -->
<div class="modal fade" id="revenueModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="revenueModalTitle">Add Revenue</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="revenueForm" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" id="revenueId" value="0">

                    <div class="mb-3">
                        <label class="form-label fw-medium">Product <span class="text-danger">*</span></label>
                        <select class="form-select form-select-lg" name="product_id" id="revenueProduct" required>
                            <option value="">Select product...</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-medium">Month <span class="text-danger">*</span></label>
                            <select class="form-select form-select-lg" name="month" id="revenueMonth" required>
                                <option value="">Month</option>
                                <?php foreach (month_options() as $num => $name): ?>
                                    <option value="<?= $num ?>"><?= $name ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-medium">Year <span class="text-danger">*</span></label>
                            <select class="form-select form-select-lg" name="year" id="revenueYear" required>
                                <option value="">Year</option>
                                <?php foreach (year_options() as $y): ?>
                                    <option value="<?= $y ?>" <?= $y === current_year() ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Revenue Amount <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><?= currency_symbol() ?></span>
                            <input type="number" class="form-control form-control-lg" name="amount"
                                   id="revenueAmount" step="0.01" min="0" required placeholder="0.00">
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary flex-fill btn-lg">
                            <i class="bi bi-check-lg me-1"></i>Save Revenue
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-lg" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Form -->
<form method="POST" id="deleteRevenueForm" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteRevenueId" value="0">
</form>

<script>
function getModal(id) {
    var el = document.getElementById(id);
    if (el && el.parentNode !== document.body) { document.body.appendChild(el); }
    return el;
}

function openAddRevenue() {
    document.getElementById('revenueForm').reset();
    document.getElementById('revenueModalTitle').textContent = 'Add Revenue';
    new bootstrap.Modal(getModal('revenueModal')).show();
}

function openEditRevenue(id, product, month, year, amount) {
    document.getElementById('revenueModalTitle').textContent = 'Edit Revenue';
    document.getElementById('revenueProduct').value = product;
    document.getElementById('revenueMonth').value = month;
    document.getElementById('revenueYear').value = year;
    document.getElementById('revenueAmount').value = amount;
    new bootstrap.Modal(getModal('revenueModal')).show();
}

function confirmDeleteRevenue(id, label) {
    Swal.fire({
        title: 'Delete Revenue?',
        text: 'Delete record for ' + label + '?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, delete',
        reverseButtons: true,
    }).then(function(result) {
        if (result.isConfirmed) {
            document.getElementById('deleteRevenueId').value = id;
            document.getElementById('deleteRevenueForm').submit();
        }
    });
}

// Reset modal on close
document.getElementById('revenueModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('revenueForm').reset();
    document.getElementById('revenueModalTitle').textContent = 'Add Revenue';
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
