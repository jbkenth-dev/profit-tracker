<?php
/**
 * Register Page
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

start_session();

if (is_logged_in()) {
    redirect('../dashboard.php');
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $username = sanitize($_POST['username'] ?? '');
        $fullName = sanitize($_POST['full_name'] ?? '');
        $email    = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (empty($username) || empty($email) || empty($password) || empty($confirm)) {
            $error = 'All fields are required.';
        } elseif (!preg_match('/^[a-zA-Z0-9_-]{3,50}$/', $username)) {
            $error = 'Username must be 3-50 characters (letters, numbers, underscores, hyphens).';
        } elseif (!is_valid_email($email)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            try {
                $pdo = getDB();

                // Check duplicate email
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
                $stmt->execute([':email' => $email]);
                if ($stmt->fetch()) {
                    $error = 'An account with this email already exists.';
                } elseif (!empty($username)) {
                    // Check duplicate username
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username LIMIT 1");
                    $stmt->execute([':username' => $username]);
                    if ($stmt->fetch()) {
                        $error = 'This username is already taken.';
                    } else {
                        // Create user
                        $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                        $stmt = $pdo->prepare(
                            "INSERT INTO users (username, email, password, full_name) VALUES (:u, :e, :p, :f)"
                        );
                        $stmt->execute([
                            ':u' => $username,
                            ':e' => $email,
                            ':p' => $hashed,
                            ':f' => $fullName ?: $username,
                        ]);

                        $success = 'Account created successfully! You can now log in.';
                    }
                }
            } catch (PDOException $e) {
                $error = 'An error occurred. Please try again later.';
            }
        }
    }
}

$pageTitle = 'Register';
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — Profit Tracker</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.05) 1px, transparent 1px);
            background-size: 50px 50px;
            pointer-events: none;
        }

        .auth-container {
            width: 100%;
            max-width: 460px;
            position: relative;
            z-index: 1;
        }

        .auth-card {
            background: rgba(255,255,255,0.98);
            border-radius: 24px;
            padding: 40px 32px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
        }

        .auth-header { text-align: center; margin-bottom: 28px; }

        .auth-logo {
            width: 60px; height: 60px;
            background: linear-gradient(135deg, #16A34A, #15803d);
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px;
            box-shadow: 0 8px 24px rgba(22, 163, 74, 0.3);
        }

        .auth-logo i { font-size: 26px; color: #fff; }

        .auth-header h1 { font-size: 22px; font-weight: 700; color: #1e293b; margin-bottom: 6px; }
        .auth-header p { color: #64748b; font-size: 14px; }

        .form-floating { margin-bottom: 14px; }

        .form-floating > .form-control {
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            padding: 14px 14px 6px;
            height: 56px;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .form-floating > .form-control:focus {
            border-color: #16A34A;
            box-shadow: 0 0 0 4px rgba(22, 163, 74, 0.1);
        }

        .form-floating > label { padding: 14px 14px; color: #94a3b8; font-size: 14px; }

        .form-floating > .form-control:focus ~ label,
        .form-floating > .form-control:not(:placeholder-shown) ~ label {
            transform: scale(0.85) translateY(-6px) translateX(4px);
        }

        .input-icon-wrapper { position: relative; }
        .input-icon-wrapper .form-control { padding-left: 44px; }

        .input-icon {
            position: absolute; left: 14px; top: 50%;
            transform: translateY(-50%);
            color: #94a3b8; font-size: 18px; z-index: 5;
        }

        .btn-register {
            width: 100%; padding: 13px; border-radius: 12px;
            background: linear-gradient(135deg, #16A34A, #15803d);
            border: none; color: #fff; font-size: 15px; font-weight: 600;
            cursor: pointer; transition: all 0.2s ease; margin-top: 4px;
        }

        .btn-register:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 24px rgba(22, 163, 74, 0.35);
        }

        .btn-register:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        .auth-footer {
            text-align: center; margin-top: 20px; padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }

        .auth-footer a { color: #16A34A; text-decoration: none; font-weight: 600; }
        .auth-footer a:hover { color: #15803d; }

        .alert-custom {
            border-radius: 12px; padding: 10px 14px; font-size: 13px; margin-bottom: 16px; border: none;
        }

        .password-toggle {
            position: absolute; right: 14px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none; color: #94a3b8; cursor: pointer; z-index: 5;
        }

        .grid-2 {
            display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
        }

        @media (max-width: 480px) {
            .auth-card { padding: 28px 20px; }
            .grid-2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <div class="auth-logo">
                    <i class="bi bi-person-plus"></i>
                </div>
                <h1>Create Account</h1>
                <p>Register your business dashboard</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-custom d-flex align-items-center gap-2">
                    <i class="bi bi-exclamation-circle"></i> <?= e($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success alert-custom d-flex align-items-center gap-2">
                    <i class="bi bi-check-circle"></i> <?= e($success) ?>
                </div>
                <div class="text-center mt-3">
                    <a href="login.php" class="btn btn-outline-primary rounded-pill px-4">Go to Login</a>
                </div>
            <?php else: ?>
            <form method="POST" action="" id="registerForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                <div class="grid-2">
                    <div class="form-floating input-icon-wrapper">
                        <i class="bi bi-person input-icon"></i>
                        <input type="text" class="form-control" id="username" name="username"
                               placeholder="Username" required
                               value="<?= e($_POST['username'] ?? '') ?>">
                        <label for="username">Username</label>
                    </div>
                    <div class="form-floating input-icon-wrapper">
                        <i class="bi bi-person-badge input-icon"></i>
                        <input type="text" class="form-control" id="full_name" name="full_name"
                               placeholder="Full Name" value="<?= e($_POST['full_name'] ?? '') ?>">
                        <label for="full_name">Full Name</label>
                    </div>
                </div>

                <div class="form-floating input-icon-wrapper">
                    <i class="bi bi-envelope input-icon"></i>
                    <input type="email" class="form-control" id="email" name="email"
                           placeholder="name@example.com" required
                           value="<?= e($_POST['email'] ?? '') ?>">
                    <label for="email">Email Address</label>
                </div>

                <div class="form-floating input-icon-wrapper">
                    <i class="bi bi-lock input-icon"></i>
                    <input type="password" class="form-control" id="password" name="password"
                           placeholder="Password" required minlength="6">
                    <label for="password">Password</label>
                    <button type="button" class="password-toggle" id="togglePassword" tabindex="-1">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>

                <div class="form-floating input-icon-wrapper">
                    <i class="bi bi-shield-lock input-icon"></i>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                           placeholder="Confirm Password" required>
                    <label for="confirm_password">Confirm Password</label>
                </div>

                <button type="submit" class="btn-register" id="registerBtn">
                    <span id="registerBtnText">Create Account</span>
                    <div class="spinner-border spinner-border-sm d-none" id="registerSpinner"></div>
                </button>
            </form>

            <div class="auth-footer">
                <p class="mb-0">Already have an account? <a href="login.php">Sign in</a></p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Password toggle
        document.getElementById('togglePassword').addEventListener('click', function() {
            const pw = document.getElementById('password');
            const icon = this.querySelector('i');
            pw.type = pw.type === 'password' ? 'text' : 'password';
            icon.className = pw.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
        });

        // Loading state
        document.getElementById('registerForm')?.addEventListener('submit', function() {
            const btn = document.getElementById('registerBtn');
            document.getElementById('registerBtnText').textContent = 'Creating account...';
            btn.disabled = true;
            document.getElementById('registerSpinner').classList.remove('d-none');
        });
    });
    </script>
</body>
</html>
