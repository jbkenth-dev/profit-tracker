<?php
/**
 * Products Module — CRUD with soft delete, search, pagination
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

    if ($action === 'create' || $action === 'update') {
        $name        = sanitize($_POST['name'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $capital     = str_replace([',', ' '], '', $_POST['capital'] ?? '0');
        $status      = $_POST['status'] === 'active' ? 'active' : 'inactive';
        $id          = (int)($_POST['id'] ?? 0);

        if (empty($name)) {
            redirect_with('products.php', 'Product name is required.', 'error');
        }
        if (!is_valid_amount($capital)) {
            redirect_with('products.php', 'Invalid capital amount.', 'error');
        }

        if ($action === 'create') {
            $stmt = $pdo->prepare("INSERT INTO products (user_id, name, description, capital, status) VALUES (:uid, :name, :desc, :cap, :status)");
            $stmt->execute([':uid' => $uid, ':name' => $name, ':desc' => $description, ':cap' => $capital, ':status' => $status]);
            redirect_with('products.php', 'Product added successfully!');
        } else {
            // Verify ownership
            $stmt = $pdo->prepare("SELECT id FROM products WHERE id = :id AND user_id = :uid AND is_deleted = 0");
            $stmt->execute([':id' => $id, ':uid' => $uid]);
            if (!$stmt->fetch()) {
                redirect_with('products.php', 'Product not found.', 'error');
            }

            $stmt = $pdo->prepare("UPDATE products SET name = :name, description = :desc, capital = :cap, status = :status WHERE id = :id AND user_id = :uid");
            $stmt->execute([':name' => $name, ':desc' => $description, ':cap' => $capital, ':status' => $status, ':id' => $id, ':uid' => $uid]);
            redirect_with('products.php', 'Product updated successfully!');
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE products SET is_deleted = 1 WHERE id = :id AND user_id = :uid");
        $stmt->execute([':id' => $id, ':uid' => $uid]);
        if ($stmt->rowCount() > 0) {
            redirect_with('products.php', 'Product deleted successfully!');
        }
        redirect_with('products.php', 'Product not found.', 'error');
    }
}

// ─── Fetch Products with Search & Pagination ────────────────
$search  = sanitize($_GET['search'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

$where  = "p.user_id = :uid AND p.is_deleted = 0";
$params = [':uid' => $uid];

if ($search !== '') {
    $where .= " AND (name LIKE :search OR description LIKE :search2)";
    $params[':search'] = "%{$search}%";
    $params[':search2'] = "%{$search}%";
}

// Count total
$stmt = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE {$where}");
$stmt->execute($params);
$totalProducts = (int)$stmt->fetchColumn();
$totalPages = max(1, ceil($totalProducts / $perPage));

// Fetch page with total revenue for capital recovery calculation
$stmt = $pdo->prepare("
    SELECT p.*, COALESCE(SUM(r.amount), 0) as total_revenue
    FROM products p
    LEFT JOIN revenue r ON p.id = r.product_id AND r.user_id = :uid2
    WHERE {$where}
    GROUP BY p.id
    ORDER BY p.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$params[':uid2'] = $uid;
$stmt->execute($params);
$products = $stmt->fetchAll();

// Generate pagination base URL
$baseUrl = 'products.php';
if ($search !== '') $baseUrl .= '?search=' . urlencode($search);

$pageTitle = 'Products';
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>

<!-- Page Header -->
<div class="page-header">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
        <div>
            <h4 class="page-title mb-1">Products</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Products</li>
                </ol>
            </nav>
        </div>
        <button class="btn btn-primary" type="button" onclick="openAddModal()">
            <i class="bi bi-plus-lg me-1"></i>Add Product
        </button>
    </div>
</div>

<?= flash_messages() ?>

<!-- Products Table -->
<div class="card card-dashboard">
    <div class="card-body">
        <!-- Search -->
        <div class="row mb-3">
            <div class="col-md-6 col-lg-4">
                <form method="GET" class="d-flex gap-2">
                    <div class="input-group">
                        <span class="input-group-text bg-transparent"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" name="search" placeholder="Search products..."
                               value="<?= e($search) ?>">
                        <?php if ($search !== ''): ?>
                            <a href="products.php" class="btn btn-outline-secondary">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product Name</th>
                        <th>Capital</th>
                        <th>Capital Status</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5">
                                <div class="text-muted">
                                    <i class="bi bi-inbox" style="font-size: 2.5rem;"></i>
                                    <p class="mt-2 mb-0">No products found</p>
                                    <?php if ($search !== ''): ?>
                                        <p class="small">Try a different search term</p>
                                    <?php else: ?>
                                        <p class="small">Click "Add Product" to get started</p>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $i = $offset + 1; ?>
                        <?php foreach ($products as $p): ?>
                            <tr>
                                <td class="text-muted small"><?= $i++ ?></td>
                                <td>
                                    <div class="fw-medium"><?= e($p['name']) ?></div>
                                    <?php if ($p['description']): ?>
                                        <small class="text-muted"><?= e(truncate($p['description'], 60)) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= format_currency((float)$p['capital']) ?></td>
                                <td>
                                    <?php
                                    $totalRev = (float)$p['total_revenue'];
                                    $capital = (float)$p['capital'];
                                    $recovered = $capital > 0 && $totalRev >= $capital;
                                    ?>
                                    <span class="badge <?= $recovered ? 'bg-success-soft text-success' : 'bg-warning-soft text-warning' ?>" style="font-size:12px;white-space:nowrap;">
                                        <?= $recovered ? '🟢 Recovered' : '🟡 Recovering' ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= $p['status'] === 'active' ? 'bg-success-soft text-success' : 'bg-secondary-soft text-secondary' ?>">
                                        <?= ucfirst(e($p['status'])) ?>
                                    </span>
                                </td>
                                <td class="text-muted small"><?= format_date($p['created_at']) ?></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary"
                                            onclick="openEditModal(<?= (int)$p['id'] ?>, '<?= e(addslashes($p['name'])) ?>', '<?= e(addslashes($p['description'] ?? '')) ?>', '<?= e($p['capital']) ?>', '<?= e($p['status']) ?>')">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger"
                                            onclick="confirmDelete(<?= (int)$p['id'] ?>, '<?= e(addslashes($p['name'])) ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="mt-3">
                <?= pagination_html($page, $totalPages, $baseUrl) ?>
            </div>
        <?php endif; ?>

        <div class="text-muted small mt-2">
            Showing <?= count($products) ?> of <?= $totalProducts ?> product(s)
        </div>
    </div>
</div>

<!-- Create/Edit Modal -->
<div class="modal fade" id="productModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="modalTitle">Add Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="productForm" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="id" id="productId" value="0">

                    <div class="mb-3">
                        <label class="form-label fw-medium">Product Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-lg" name="name" id="productName"
                               required placeholder="Enter product name">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Description</label>
                        <textarea class="form-control" name="description" id="productDescription"
                                  rows="2" placeholder="Optional description"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Capital <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><?= currency_symbol() ?></span>
                            <input type="number" class="form-control form-control-lg" name="capital"
                                   id="productCapital" step="0.01" min="0"
                                   required placeholder="0.00">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Status</label>
                        <select class="form-select form-select-lg" name="status" id="productStatus">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary flex-fill btn-lg" id="productSubmitBtn">
                            <i class="bi bi-check-lg me-1"></i>Save Product
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-lg" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Form -->
<form method="POST" id="deleteForm" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId" value="0">
</form>

<script>
function getModal(id) {
    var el = document.getElementById(id);
    if (el && el.parentNode !== document.body) {
        document.body.appendChild(el);
    }
    return el;
}

function openAddModal() {
    document.getElementById("productForm").reset();
    document.getElementById("formAction").value = "create";
    document.getElementById("productId").value = "0";
    document.getElementById("modalTitle").textContent = "Add Product";
    document.getElementById("productSubmitBtn").innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Product';
    new bootstrap.Modal(getModal("productModal")).show();
}

function openEditModal(id, name, desc, capital, status) {
    document.getElementById("modalTitle").textContent = "Edit Product";
    document.getElementById("formAction").value = "update";
    document.getElementById("productId").value = id;
    document.getElementById("productName").value = name;
    document.getElementById("productDescription").value = desc;
    document.getElementById("productCapital").value = capital;
    document.getElementById("productStatus").value = status;
    document.getElementById("productSubmitBtn").innerHTML = '<i class="bi bi-check-lg me-1"></i>Update Product';
    new bootstrap.Modal(getModal("productModal")).show();
}

function confirmDelete(id, name) {
    Swal.fire({
        title: "Delete Product?",
        text: "Are you sure you want to delete " + name + "?",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#DC2626",
        cancelButtonColor: "#64748b",
        confirmButtonText: "Yes, delete it",
        cancelButtonText: "Cancel",
        reverseButtons: true,
    }).then(function(result) {
        if (result.isConfirmed) {
            document.getElementById("deleteId").value = id;
            document.getElementById("deleteForm").submit();
        }
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
