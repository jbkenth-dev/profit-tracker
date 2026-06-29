<?php
/**
 * Fixed Expenses Module — recurring monthly expenses
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
        $id           = (int)($_POST['id'] ?? 0);
        $expenseName  = sanitize($_POST['expense_name'] ?? '');
        $productId    = (int)($_POST['product_id'] ?? 0);
        $amount       = str_replace([',', ' '], '', $_POST['amount'] ?? '0');
        $status       = $_POST['status'] === 'active' ? 'active' : 'inactive';
        $description  = sanitize($_POST['description'] ?? '');

        if (empty($expenseName)) {
            redirect_with('fixed-expenses.php', 'Expense name is required.', 'error');
        }
        if (!is_valid_amount($amount)) {
            redirect_with('fixed-expenses.php', 'Invalid expense amount.', 'error');
        }

        $params = [
            ':uid'       => $uid,
            ':name'      => $expenseName,
            ':product_id' => $productId ?: null,
            ':amount'    => $amount,
            ':status'    => $status,
            ':desc'      => $description ?: null,
        ];

        if ($action === 'create') {
            $stmt = $pdo->prepare("INSERT INTO fixed_expenses (user_id, expense_name, product_id, amount, status, description) VALUES (:uid, :name, :product_id, :amount, :status, :desc)");
            $stmt->execute($params);
            redirect_with('fixed-expenses.php', 'Fixed expense added successfully!');
        } else {
            $params[':id'] = $id;
            $stmt = $pdo->prepare("UPDATE fixed_expenses SET expense_name = :name, product_id = :product_id, amount = :amount, status = :status, description = :desc WHERE id = :id AND user_id = :uid");
            $stmt->execute($params);
            redirect_with('fixed-expenses.php', 'Fixed expense updated successfully!');
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM fixed_expenses WHERE id = :id AND user_id = :uid");
        $stmt->execute([':id' => $id, ':uid' => $uid]);
        redirect_with('fixed-expenses.php', 'Fixed expense deleted.');
    }
}

// ─── Fetch Data ─────────────────────────────────────────────
$search = sanitize($_GET['search'] ?? '');
$filterProduct = (int)($_GET['product_id'] ?? 0);

$stmt = $pdo->prepare("SELECT id, name FROM products WHERE user_id = :uid AND is_deleted = 0 ORDER BY name");
$stmt->execute([':uid' => $uid]);
$products = $stmt->fetchAll();

$where  = "fe.user_id = :uid";
$params = [':uid' => $uid];

if ($filterProduct > 0) {
    $where .= " AND (fe.product_id = :pid OR fe.product_id IS NULL)";
    $params[':pid'] = $filterProduct;
}

if ($search !== '') {
    $where .= " AND (fe.expense_name LIKE :search OR fe.description LIKE :search2)";
    $params[':search'] = "%{$search}%";
    $params[':search2'] = "%{$search}%";
}

$stmt = $pdo->prepare("
    SELECT fe.*, p.name as product_name
    FROM fixed_expenses fe
    LEFT JOIN products p ON fe.product_id = p.id
    WHERE {$where}
    ORDER BY fe.status ASC, fe.created_at DESC
");
$stmt->execute($params);
$fixedExpenses = $stmt->fetchAll();

// Calculate totals
$totalActive = 0;
$totalInactive = 0;
foreach ($fixedExpenses as $fe) {
    if ($fe['status'] === 'active') {
        $totalActive += (float)$fe['amount'];
    } else {
        $totalInactive += (float)$fe['amount'];
    }
}

$pageTitle = 'Fixed Expenses';
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>

<!-- Page Header -->
<div class="page-header">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
        <div>
            <h4 class="page-title mb-1">Fixed Expenses</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Fixed Expenses</li>
                </ol>
            </nav>
        </div>
        <button class="btn btn-primary" type="button" onclick="openAddFixed()">
            <i class="bi bi-plus-lg me-1"></i>Add Fixed Expense
        </button>
    </div>
</div>

<?= flash_messages() ?>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="stat-card">
            <div class="stat-icon bg-success-soft">
                <i class="bi bi-check-circle text-success"></i>
            </div>
            <div class="stat-info">
                <span class="stat-label">Active Fixed Expenses (Monthly)</span>
                <span class="stat-value"><?= format_currency($totalActive) ?></span>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="stat-card">
            <div class="stat-icon bg-secondary-soft">
                <i class="bi bi-x-circle text-secondary"></i>
            </div>
            <div class="stat-info">
                <span class="stat-label">Inactive Fixed Expenses</span>
                <span class="stat-value"><?= format_currency($totalInactive) ?></span>
            </div>
        </div>
    </div>
</div>

<div class="card card-dashboard">
    <div class="card-body">
        <!-- Filters -->
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text bg-transparent"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" name="search" placeholder="Search fixed expenses..."
                           value="<?= e($search) ?>">
                </div>
            </div>
            <div class="col-md-3">
                <select class="form-select" name="product_id">
                    <option value="0">All Products</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $filterProduct === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
                    <a href="fixed-expenses.php" class="btn btn-outline-secondary">Reset</a>
                </div>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Expense Name</th>
                        <th>Product</th>
                        <th>Amount (Monthly)</th>
                        <th>Status</th>
                        <th>Description</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($fixedExpenses)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5">
                                <div class="text-muted">
                                    <i class="bi bi-inbox" style="font-size: 2.5rem;"></i>
                                    <p class="mt-2 mb-0">No fixed expenses set up</p>
                                    <p class="small">Add recurring expenses like rent, salaries, subscriptions</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $i = 1; ?>
                        <?php foreach ($fixedExpenses as $fe): ?>
                            <tr>
                                <td class="text-muted small"><?= $i++ ?></td>
                                <td class="fw-medium"><?= e($fe['expense_name']) ?></td>
                                <td>
                                    <span class="badge bg-primary-soft text-primary">
                                        <?= e($fe['product_name'] ?? 'All Products') ?>
                                    </span>
                                </td>
                                <td class="text-danger fw-semibold"><?= format_currency((float)$fe['amount']) ?></td>
                                <td>
                                    <span class="badge <?= $fe['status'] === 'active' ? 'bg-success-soft text-success' : 'bg-secondary-soft text-secondary' ?>">
                                        <?= ucfirst(e($fe['status'])) ?>
                                    </span>
                                </td>
                                <td class="text-muted small"><?= e(truncate($fe['description'] ?? '—', 40)) ?></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary"
                                            onclick="openEditFixed(<?= (int)$fe['id'] ?>, '<?= e(addslashes($fe['expense_name'])) ?>', <?= (int)$fe['product_id'] ?>, '<?= e($fe['amount']) ?>', '<?= e($fe['status']) ?>', '<?= e(addslashes($fe['description'] ?? '')) ?>')">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger"
                                            onclick="confirmDeleteFixed(<?= (int)$fe['id'] ?>, '<?= e(addslashes($fe['expense_name'])) ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create/Edit Modal -->
<div class="modal fade" id="fixedModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="fixedModalTitle">Add Fixed Expense</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="fixedForm" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" id="fixedAction" value="create">
                    <input type="hidden" name="id" id="fixedId" value="0">

                    <div class="mb-3">
                        <label class="form-label fw-medium">Expense Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-lg" name="expense_name"
                               id="fixedName" required placeholder="e.g. Office Rent">
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-medium">Product</label>
                            <select class="form-select form-select-lg" name="product_id" id="fixedProduct">
                                <option value="">All Products (General)</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-medium">Monthly Amount <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><?= currency_symbol() ?></span>
                                <input type="number" class="form-control form-control-lg" name="amount"
                                       id="fixedAmount" step="0.01" min="0" required placeholder="0.00">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Status</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="status" value="active" id="fixedActive" checked>
                                <label class="form-check-label" for="fixedActive">Active</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="status" value="inactive" id="fixedInactive">
                                <label class="form-check-label" for="fixedInactive">Inactive</label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Description</label>
                        <textarea class="form-control" name="description" id="fixedDescription" rows="2"
                                  placeholder="Optional description"></textarea>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary flex-fill btn-lg" id="fixedSubmitBtn">
                            <i class="bi bi-check-lg me-1"></i>Save
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-lg" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Form -->
<form method="POST" id="deleteFixedForm" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteFixedId" value="0">
</form>

<script>
function getModal(id) {
    var el = document.getElementById(id);
    if (el && el.parentNode !== document.body) { document.body.appendChild(el); }
    return el;
}

function openAddFixed() {
    document.getElementById('fixedForm').reset();
    document.getElementById('fixedAction').value = 'create';
    document.getElementById('fixedId').value = '0';
    document.getElementById('fixedModalTitle').textContent = 'Add Fixed Expense';
    document.getElementById('fixedSubmitBtn').innerHTML = '<i class="bi bi-check-lg me-1"></i>Save';
    document.getElementById('fixedActive').checked = true;
    new bootstrap.Modal(getModal('fixedModal')).show();
}

function openEditFixed(id, name, product, amount, status, description) {
    document.getElementById('fixedModalTitle').textContent = 'Edit Fixed Expense';
    document.getElementById('fixedAction').value = 'update';
    document.getElementById('fixedId').value = id;
    document.getElementById('fixedName').value = name;
    document.getElementById('fixedProduct').value = product;
    document.getElementById('fixedAmount').value = amount;
    document.getElementById('fixedDescription').value = description;
    document.getElementById('fixedSubmitBtn').innerHTML = '<i class="bi bi-check-lg me-1"></i>Update';

    if (status === 'active') {
        document.getElementById('fixedActive').checked = true;
    } else {
        document.getElementById('fixedInactive').checked = true;
    }

    new bootstrap.Modal(getModal('fixedModal')).show();
}

function confirmDeleteFixed(id, name) {
    Swal.fire({
        title: 'Delete Fixed Expense?',
        text: 'Delete "' + name + '"?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, delete',
        reverseButtons: true,
    }).then(function(result) {
        if (result.isConfirmed) {
            document.getElementById('deleteFixedId').value = id;
            document.getElementById('deleteFixedForm').submit();
        }
    });
}

// Reset modal form on close
document.getElementById('fixedModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('fixedForm').reset();
    document.getElementById('fixedAction').value = 'create';
    document.getElementById('fixedId').value = '0';
    document.getElementById('fixedModalTitle').textContent = 'Add Fixed Expense';
    document.getElementById('fixedSubmitBtn').innerHTML = '<i class="bi bi-check-lg me-1"></i>Save';
    document.getElementById('fixedActive').checked = true;
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
