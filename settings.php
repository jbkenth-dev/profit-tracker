<?php
/**
 * Settings Page — Update profile, password, currency
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

start_session();
require_auth();

$pdo = getDB();
$uid = user_id();

// Fetch current user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :uid");
$stmt->execute([':uid' => $uid]);
$user = $stmt->fetch();

if (!$user) {
    redirect('auth/logout.php');
}

// ─── Handle POST ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $section = $_POST['section'] ?? '';

    // Update Profile
    if ($section === 'profile') {
        $fullName = sanitize($_POST['full_name'] ?? '');
        $email    = sanitize($_POST['email'] ?? '');
        $currency = sanitize($_POST['currency'] ?? '₱');

        if (empty($email) || !is_valid_email($email)) {
            redirect_with('settings.php', 'Please enter a valid email address.', 'error');
        }

        // Check if email is taken by another user
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :uid");
        $stmt->execute([':email' => $email, ':uid' => $uid]);
        if ($stmt->fetch()) {
            redirect_with('settings.php', 'This email is already in use by another account.', 'error');
        }

        $stmt = $pdo->prepare("UPDATE users SET full_name = :name, email = :email, currency = :currency WHERE id = :uid");
        $stmt->execute([':name' => $fullName ?: $user['username'], ':email' => $email, ':currency' => $currency, ':uid' => $uid]);

        // Update session
        $_SESSION['full_name'] = $fullName ?: $user['username'];
        $_SESSION['email'] = $email;
        $_SESSION['currency'] = $currency;

        redirect_with('settings.php', 'Profile updated successfully!');
    }

    // Change Password
    if ($section === 'password') {
        $currentPw    = $_POST['current_password'] ?? '';
        $newPw        = $_POST['new_password'] ?? '';
        $confirmPw    = $_POST['confirm_password'] ?? '';

        if (empty($currentPw) || empty($newPw) || empty($confirmPw)) {
            redirect_with('settings.php', 'All password fields are required.', 'error');
        }

        if (!password_verify($currentPw, $user['password'])) {
            redirect_with('settings.php', 'Current password is incorrect.', 'error');
        }

        if (strlen($newPw) < 6) {
            redirect_with('settings.php', 'New password must be at least 6 characters.', 'error');
        }

        if ($newPw !== $confirmPw) {
            redirect_with('settings.php', 'New passwords do not match.', 'error');
        }

        $hashed = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare("UPDATE users SET password = :pw WHERE id = :uid");
        $stmt->execute([':pw' => $hashed, ':uid' => $uid]);

        redirect_with('settings.php', 'Password changed successfully!');
    }
}

$pageTitle = 'Settings';
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>

<!-- Page Header -->
<div class="page-header">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
        <div>
            <h4 class="page-title mb-1">Settings</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Settings</li>
                </ol>
            </nav>
        </div>
    </div>
</div>

<?= flash_messages() ?>

<div class="row g-4">
    <!-- Profile Settings -->
    <div class="col-lg-6">
        <div class="card card-dashboard">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-person me-2"></i>Profile</h5>
            </div>
            <div class="card-body">
                <form method="POST" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="section" value="profile">

                    <div class="mb-3">
                        <label class="form-label fw-medium">Username</label>
                        <input type="text" class="form-control" value="<?= e($user['username']) ?>" disabled>
                        <small class="text-muted">Username cannot be changed.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Full Name</label>
                        <input type="text" class="form-control form-control-lg" name="full_name"
                               value="<?= e($user['full_name'] ?? '') ?>"
                               placeholder="Your full name">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control form-control-lg" name="email"
                               value="<?= e($user['email']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Currency Symbol</label>
                        <select class="form-select form-select-lg" name="currency">
                            <option value="₱" <?= $user['currency'] === '₱' ? 'selected' : '' ?>>₱ — Philippine Peso</option>
                            <option value="$" <?= $user['currency'] === '$' ? 'selected' : '' ?>>$ — US Dollar</option>
                            <option value="€" <?= $user['currency'] === '€' ? 'selected' : '' ?>>€ — Euro</option>
                            <option value="£" <?= $user['currency'] === '£' ? 'selected' : '' ?>>£ — British Pound</option>
                            <option value="¥" <?= $user['currency'] === '¥' ? 'selected' : '' ?>>¥ — Japanese Yen</option>
                            <option value="A$" <?= $user['currency'] === 'A$' ? 'selected' : '' ?>>A$ — Australian Dollar</option>
                            <option value="C$" <?= $user['currency'] === 'C$' ? 'selected' : '' ?>>C$ — Canadian Dollar</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-check-lg me-1"></i>Save Changes
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Password Change -->
    <div class="col-lg-6">
        <div class="card card-dashboard">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-shield-lock me-2"></i>Change Password</h5>
            </div>
            <div class="card-body">
                <form method="POST" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="section" value="password">

                    <div class="mb-3">
                        <label class="form-label fw-medium">Current Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control form-control-lg" name="current_password" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">New Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control form-control-lg" name="new_password" required minlength="6">
                        <small class="text-muted">Minimum 6 characters.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Confirm New Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control form-control-lg" name="confirm_password" required>
                    </div>

                    <button type="submit" class="btn btn-warning btn-lg">
                        <i class="bi bi-key me-1"></i>Change Password
                    </button>
                </form>
            </div>
        </div>

        <!-- Account Info -->
        <div class="card card-dashboard mt-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-info-circle me-2"></i>Account Info</h5>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">Member Since</span>
                    <span class="fw-medium"><?= format_date($user['created_at'], 'F d, Y') ?></span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">Last Updated</span>
                    <span class="fw-medium"><?= format_date($user['updated_at'], 'F d, Y \a\t h:i A') ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
