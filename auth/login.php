<?php
/**
 * Login Page
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

start_session();

// If already logged in, redirect to dashboard
if (is_logged_in()) {
    redirect('../dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $email    = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Please enter both email and password.';
        } elseif (!is_valid_email($email)) {
            $error = 'Please enter a valid email address.';
        } else {
            try {
                $pdo = getDB();
                $stmt = $pdo->prepare("SELECT id, username, email, password, full_name, currency FROM users WHERE email = :email LIMIT 1");
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    // Regenerate session to prevent fixation
                    regenerate_session();

                    $_SESSION['user_id']  = (int)$user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email']    = $user['email'];
                    $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
                    $_SESSION['currency'] = $user['currency'];

                    // Log session
                    $stmt = $pdo->prepare(
                        "INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at)
                         VALUES (:uid, :token, :ip, :ua, DATE_ADD(NOW(), INTERVAL 7 DAY))"
                    );
                    $stmt->execute([
                        ':uid'   => $user['id'],
                        ':token' => session_id(),
                        ':ip'    => get_ip(),
                        ':ua'    => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    ]);

                    $redirect = $_SESSION['redirect_after'] ?? '../dashboard.php';
                    unset($_SESSION['redirect_after']);
                    redirect($redirect);
                } else {
                    $error = 'Invalid email or password.';
                }
            } catch (PDOException $e) {
                $error = 'An error occurred. Please try again later.';
            }
        }
    }
}

$pageTitle = 'Login';
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Profit Tracker</title>

    <!-- Bootstrap 5.3 + Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- Google Fonts -->
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
            max-width: 440px;
            position: relative;
            z-index: 1;
        }

        .auth-card {
            background: rgba(255,255,255,0.98);
            border-radius: 24px;
            padding: 48px 36px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15), 0 0 0 1px rgba(255,255,255,0.1);
            backdrop-filter: blur(20px);
            transition: transform 0.3s ease;
        }

        .auth-card:hover {
            transform: translateY(-2px);
        }

        .auth-header {
            text-align: center;
            margin-bottom: 36px;
        }

        .auth-logo {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #2563EB, #1d4ed8);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 8px 24px rgba(37, 99, 235, 0.3);
        }

        .auth-logo i {
            font-size: 28px;
            color: #fff;
        }

        .auth-header h1 {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .auth-header p {
            color: #64748b;
            font-size: 14px;
            font-weight: 400;
        }

        .form-floating {
            margin-bottom: 16px;
        }

        .form-floating > .form-control {
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            padding: 16px 16px 8px;
            height: 58px;
            font-size: 15px;
            transition: all 0.2s ease;
        }

        .form-floating > .form-control:focus {
            border-color: #2563EB;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
        }

        .form-floating > label {
            padding: 16px 16px;
            color: #94a3b8;
        }

        .form-floating > .form-control:focus ~ label,
        .form-floating > .form-control:not(:placeholder-shown) ~ label {
            transform: scale(0.85) translateY(-8px) translateX(4px);
        }

        .input-icon-wrapper {
            position: relative;
        }

        .input-icon-wrapper .form-control {
            padding-left: 48px;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 20px;
            z-index: 5;
        }

        .btn-login {
            width: 100%;
            padding: 14px;
            border-radius: 12px;
            background: linear-gradient(135deg, #2563EB, #1d4ed8);
            border: none;
            color: #fff;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 8px;
        }

        .btn-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 24px rgba(37, 99, 235, 0.35);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .btn-login:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .auth-footer {
            text-align: center;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #e2e8f0;
        }

        .auth-footer a {
            color: #2563EB;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }

        .auth-footer a:hover {
            color: #1d4ed8;
        }

        .alert-custom {
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 14px;
            margin-bottom: 20px;
            border: none;
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            z-index: 5;
            padding: 4px;
        }

        .password-toggle:hover {
            color: #64748b;
        }

        .demo-credentials {
            background: #f8fafc;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #64748b;
        }

        .demo-credentials code {
            background: #e2e8f0;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <div class="auth-logo">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
                <h1>Welcome Back</h1>
                <p>Sign in to your business dashboard</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-custom d-flex align-items-center gap-2">
                    <i class="bi bi-exclamation-circle"></i>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <div class="demo-credentials d-flex align-items-center gap-2">
                <i class="bi bi-info-circle"></i>
                <span>Demo: <code>demo@example.com</code> / <code>password</code></span>
            </div>

            <form method="POST" action="" id="loginForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

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
                           placeholder="Password" required>
                    <label for="password">Password</label>
                    <button type="button" class="password-toggle" id="togglePassword" tabindex="-1">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>

                <button type="submit" class="btn-login" id="loginBtn">
                    <span id="loginBtnText">Sign In</span>
                    <div class="spinner-border spinner-border-sm d-none" id="loginSpinner" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </button>
            </form>

            <div class="auth-footer">
                <p class="mb-0">Don't have an account? <a href="register.php">Create one</a></p>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Password toggle
        document.getElementById('togglePassword').addEventListener('click', function() {
            const pw = document.getElementById('password');
            const icon = this.querySelector('i');
            if (pw.type === 'password') {
                pw.type = 'text';
                icon.className = 'bi bi-eye-slash';
            } else {
                pw.type = 'password';
                icon.className = 'bi bi-eye';
            }
        });

        // Loading state
        document.getElementById('loginForm').addEventListener('submit', function() {
            const btn = document.getElementById('loginBtn');
            const text = document.getElementById('loginBtnText');
            const spinner = document.getElementById('loginSpinner');
            btn.disabled = true;
            text.textContent = 'Signing in...';
            spinner.classList.remove('d-none');
        });
    });
    </script>
</body>
</html>
