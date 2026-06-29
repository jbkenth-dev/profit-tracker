<?php
/**
 * Expenses Module — Full CRUD with search, filter, pagination
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

    $action = $_POST['action'] ?? '';

    if (in_array($action, ['create', 'update'], true)) {
        $productId  = (int)($_POST['product_id'] ?? 0);
        $expenseName = sanitize($_POST['expense_name'] ?? '');
        $amount     = str_replace([',', ' '], '', $_POST['amount'] ?? '0');
        $expenseDate = sanitize($_POST['expense_date'] ?? '');
        $notes      = sanitize($_POST['notes'] ?? '');
        $id         = (int)($_POST['id'] ?? 0);

        if (empty($expenseName) || empty($expenseDate)) {
            redirect_with('expenses.php', 'Expense name and date are required.', 'error');
        }
        if (!is_valid_amount($amount)) {
            redirect_with('expenses.php', 'Invalid expense amount.', 'error');
        }

        $params = [
            ':uid'         => $uid,
            ':product_id'  => $productId ?: null,
            ':name'        => $expenseName,
            ':amount'      => $amount,
            ':date'        => $expenseDate,
            ':notes'       => $notes ?: null,
        ];

        if ($action === 'create') {
            $stmt = $pdo->prepare("INSERT INTO expenses (user_id, product_id, expense_name, amount, expense_date, notes) VALUES (:uid, :product_id, :name, :amount, :date, :notes)");
            $stmt->execute($params);
            redirect_with('expenses.php', 'Expense saved successfully!');
        } else {
            $params[':id'] = $id;
            $stmt = $pdo->prepare("UPDATE expenses SET product_id = :product_id, expense_name = :name, amount = :amount, expense_date = :date, notes = :notes WHERE id = :id AND user_id = :uid");
            $stmt->execute($params);
            redirect_with('expenses.php', 'Expense updated successfully!');
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = :id AND user_id = :uid");
        $stmt->execute([':id' => $id, ':uid' => $uid]);
        redirect_with('expenses.php', 'Expense deleted.');
    }
}

// ─── Filters ─────────────────────────────────────────────────
$filterMonth = (int)($_GET['month'] ?? 0);
$filterYear  = (int)($_GET['year'] ?? current_year());
$filterProduct = (int)($_GET['product_id'] ?? 0);
$search      = sanitize($_GET['search'] ?? '');

// Fetch products
$stmt = $pdo->prepare("SELECT id, name FROM products WHERE user_id = :uid AND is_deleted = 0 ORDER BY name");
$stmt->execute([':uid' => $uid]);
$products = $stmt->fetchAll();

// Build query
$where  = "e.user_id = :uid";
$params = [':uid' => $uid];

if ($filterMonth > 0) {
    $where .= " AND MONTH(e.expense_date) = :m";
    $params[':m'] = $filterMonth;
}
if ($filterYear > 0) {
    $where .= " AND YEAR(e.expense_date) = :y";
    $params[':y'] = $filterYear;
}
if ($filterProduct > 0) {
    $where .= " AND e.product_id = :pid";
    $params[':pid'] = $filterProduct;
}
if ($search !== '') {
    $where .= " AND (e.expense_name LIKE :search OR e.notes LIKE :search2)";
    $params[':search'] = "%{$search}%";
    $params[':search2'] = "%{$search}%";
}

// Pagination
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM expenses e WHERE {$where}");
$stmt->execute($params);
$totalRecords = (int)$stmt->fetchColumn();
$totalPages = max(1, ceil($totalRecords / $perPage));

$stmt = $pdo->prepare("
    SELECT e.*, p.name as product_name
    FROM expenses e
    LEFT JOIN products p ON e.product_id = p.id
    WHERE {$where}
    ORDER BY e.expense_date DESC, e.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$expenses = $stmt->fetchAll();

// Build pagination URL
$baseUrl = 'expenses.php';
$queryParams = [];
if ($filterMonth > 0) $queryParams['month'] = $filterMonth;
if ($filterYear > 0) $queryParams['year'] = $filterYear;
if ($filterProduct > 0) $queryParams['product_id'] = $filterProduct;
if ($search !== '') $queryParams['search'] = $search;
if (!empty($queryParams)) $baseUrl .= '?' . http_build_query($queryParams);

// Calculate totals
$totalAmount = array_sum(array_column($expenses, 'amount'));

$pageTitle = 'Expenses';
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>

<!-- Page Header -->
<div class="page-header">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
        <div>
            <h4 class="page-title mb-1">Expenses</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Expenses</li>
                </ol>
            </nav>
        </div>
        <button class="btn btn-primary" type="button" onclick="openAddExpense()">
            <i class="bi bi-plus-lg me-1"></i>Add Expense
        </button>
    </div>
</div>

<?= flash_messages() ?>

<div class="card card-dashboard">
    <div class="card-body">
        <!-- Filters -->
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-3">
                <input type="text" class="form-control" name="search" placeholder="Search expenses..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="product_id">
                    <option value="0">All Products</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $filterProduct === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
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
                    <?php foreach (year_options() as $y): ?>
                        <option value="<?= $y ?>" <?= $filterYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
            </div>
        </form>

        <?php if (!empty($expenses)): ?>
            <div class="mb-3 text-end">
                <span class="text-muted small">Showing total: </span>
                <span class="fw-bold text-danger"><?= format_currency($totalAmount) ?></span>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Expense</th>
                        <th>Product</th>
                        <th>Amount</th>
                        <th>Date</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($expenses)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5">
                                <div class="text-muted">
                                    <i class="bi bi-inbox" style="font-size: 2.5rem;"></i>
                                    <p class="mt-2 mb-0 fw-medium">No expenses found</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $i = $offset + 1; ?>
                        <?php foreach ($expenses as $e): ?>
                            <tr>
                                <td class="text-muted small"><?= $i++ ?></td>
                                <td>
                                    <div class="fw-medium"><?= e($e['expense_name']) ?></div>
                                    <?php if ($e['notes']): ?>
                                        <small class="text-muted"><?= e(truncate($e['notes'], 40)) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-primary-soft text-primary"><?= e($e['product_name'] ?? '—') ?></span></td>
                                <td class="text-danger fw-semibold"><?= format_currency((float)$e['amount']) ?></td>
                                <td class="text-muted small"><?= format_date($e['expense_date']) ?></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary"
                                            onclick="openEditExpense(<?= (int)$e['id'] ?>, '<?= e(addslashes($e['expense_name'])) ?>', <?= (int)$e['product_id'] ?>, '<?= e($e['amount']) ?>', '<?= e($e['expense_date']) ?>', '<?= e(addslashes($e['notes'] ?? '')) ?>')">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger"
                                            onclick="confirmDeleteExpense(<?= (int)$e['id'] ?>, '<?= e(addslashes($e['expense_name'])) ?>')">
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

        <div class="text-muted small mt-2">Showing <?= count($expenses) ?> of <?= $totalRecords ?> expense(s)</div>
    </div>
</div>

<!-- Create/Edit Modal -->
<div class="modal fade" id="expenseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="expenseModalTitle">Add Expense</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="expenseForm" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" id="expenseAction" value="create">
                    <input type="hidden" name="id" id="expenseId" value="0">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Expense Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-lg" name="expense_name"
                                   id="expenseName" required placeholder="e.g. Office Supplies">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-medium">Amount <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><?= currency_symbol() ?></span>
                                <input type="number" class="form-control form-control-lg" name="amount"
                                       id="expenseAmount" step="0.01" min="0" required placeholder="0.00">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-medium">Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control form-control-lg" name="expense_date"
                                   id="expenseDate" required value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>

                    <div class="row g-3 mt-2">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Product (optional)</label>
                            <select class="form-select form-select-lg" name="product_id" id="expenseProduct">
                                <option value="0">— No specific product —</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Notes (optional)</label>
                            <textarea class="form-control" name="notes" id="expenseNotes" rows="2" placeholder="Additional notes..."></textarea>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary flex-fill btn-lg" id="expenseSubmitBtn">
                            <i class="bi bi-check-lg me-1"></i>Save Expense
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-lg" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Form -->
<form method="POST" id="deleteExpenseForm" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteExpenseId" value="0">
</form>

<script>
function getModal(id) {
    var el = document.getElementById(id);
    if (el && el.parentNode !== document.body) { document.body.appendChild(el); }
    return el;
}

function openAddExpense() {
    document.getElementById('expenseForm').reset();
    document.getElementById('expenseAction').value = 'create';
    document.getElementById('expenseId').value = '0';
    document.getElementById('expenseModalTitle').textContent = 'Add Expense';
    document.getElementById('expenseSubmitBtn').innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Expense';
    new bootstrap.Modal(getModal('expenseModal')).show();
}

function openEditExpense(id, name, product, amount, date, notes) {
    document.getElementById('expenseModalTitle').textContent = 'Edit Expense';
    document.getElementById('expenseAction').value = 'update';
    document.getElementById('expenseId').value = id;
    document.getElementById('expenseName').value = name;
    document.getElementById('expenseAmount').value = amount;
    document.getElementById('expenseDate').value = date;
    document.getElementById('expenseProduct').value = product;
    document.getElementById('expenseNotes').value = notes;
    document.getElementById('expenseSubmitBtn').innerHTML = '<i class="bi bi-check-lg me-1"></i>Update Expense';
    new bootstrap.Modal(getModal('expenseModal')).show();
}

function confirmDeleteExpense(id, name) {
    Swal.fire({
        title: 'Delete Expense?',
        text: 'Delete "' + name + '"?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, delete',
        reverseButtons: true,
    }).then(function(result) {
        if (result.isConfirmed) {
            document.getElementById('deleteExpenseId').value = id;
            document.getElementById('deleteExpenseForm').submit();
        }
    });
}

document.getElementById('expenseModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('expenseForm').reset();
    document.getElementById('expenseAction').value = 'create';
    document.getElementById('expenseId').value = '0';
    document.getElementById('expenseModalTitle').textContent = 'Add Expense';
    document.getElementById('expenseSubmitBtn').innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Expense';
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
