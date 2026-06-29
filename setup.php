<?php
/**
 * Setup Utility — Initialize database with proper hashes and sample data
 *
 * Run this ONCE after creating the database and updating config/database.php.
 * Usage: http://localhost/profit-tracker/setup.php
 * Delete this file after setup for security.
 */
require_once __DIR__ . '/config/database.php';

// Disable output buffering
if (ob_get_level()) ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup — Profit Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; padding: 40px; }
        .card { max-width: 720px; margin: 0 auto; border-radius: 18px; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .step { padding: 12px 16px; border-radius: 10px; margin-bottom: 8px; }
        .step-success { background: #dcfce7; color: #16a34a; }
        .step-error { background: #fee2e2; color: #dc2626; }
        .step-info { background: #dbeafe; color: #2563eb; }
    </style>
</head>
<body>
<div class="card p-4">
    <div class="text-center mb-4">
        <h1 class="h3 fw-bold">Profit Tracker Setup</h1>
        <p class="text-muted">Database initialization utility</p>
    </div>

<?php
try {
    $pdo = getDB();
    echo '<div class="step step-success">✓ Database connection established successfully.</div>';

    // ─── Read schema.sql ─────────────────────────────────
    $schemaPath = __DIR__ . '/database/schema.sql';
    if (!file_exists($schemaPath)) {
        echo '<div class="step step-error">✗ schema.sql not found at: ' . e($schemaPath) . '</div>';
        exit;
    }

    $schema = file_get_contents($schemaPath);

    // Remove USE and CREATE DATABASE statements (DB already selected)
    $schema = preg_replace('/CREATE DATABASE[^;]+;/i', '', $schema);
    $schema = preg_replace('/USE\s+`[^`]+`;/i', '', $schema);

    // Split by semicolons and execute each statement
    $statements = array_filter(
        array_map('trim', explode(';', $schema)),
        fn($s) => !empty($s) && !preg_match('/^--/', $s) && !preg_match('/^INSERT INTO\s+`?users`?/i', $s)
    );

    foreach ($statements as $stmt) {
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            // Skip "already exists" errors
            if (strpos($e->getMessage(), 'already exists') === false && strpos($e->getMessage(), 'Duplicate') === false) {
                echo '<div class="step step-error">✗ SQL Error: ' . e($e->getMessage()) . '<br><small>' . e(substr($stmt, 0, 100)) . '...</small></div>';
            }
        }
    }

    echo '<div class="step step-success">✓ Tables created successfully.</div>';

    // ─── Check if demo user exists ───────────────────────
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = 'demo@example.com'");
    $stmt->execute();
    $demoUser = $stmt->fetch();

    if (!$demoUser) {
        // Create demo user with proper hash
        $hash = password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, full_name) VALUES ('demo', 'demo@example.com', :pw, 'Demo User')");
        $stmt->execute([':pw' => $hash]);
        $userId = (int)$pdo->lastInsertId();
        echo '<div class="step step-success">✓ Demo user created (demo@example.com / password)</div>';

        // Seed sample data
        seedSampleData($pdo, $userId);
        echo '<div class="step step-success">✓ Sample data seeded successfully.</div>';
    } else {
        // Update existing demo password if needed
        $userId = (int)$demoUser['id'];
        $hash = password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare("UPDATE users SET password = :pw WHERE id = :id");
        $stmt->execute([':pw' => $hash, ':id' => $userId]);
        echo '<div class="step step-info">ℹ Demo user already exists — password reset to "password"</div>';

        // Check if sample products exist
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        if ((int)$stmt->fetchColumn() === 0) {
            seedSampleData($pdo, $userId);
            echo '<div class="step step-success">✓ Sample data seeded successfully.</div>';
        } else {
            echo '<div class="step step-info">ℹ Sample data already exists — skipped.</div>';
        }
    }

    echo '<hr>';
    echo '<div class="text-center mt-3">';
    echo '<a href="auth/login.php" class="btn btn-primary btn-lg px-5">Go to Login</a>';
    echo '</div>';
    echo '<div class="text-center mt-3"><small class="text-muted">⚠ Delete this setup.php file after successful setup.</small></div>';

} catch (PDOException $e) {
    echo '<div class="step step-error">✗ Database Error: ' . e($e->getMessage()) . '</div>';
    echo '<div class="mt-3"><p class="text-muted">Please update <code>config/database.php</code> with your MySQL credentials and make sure the database exists.</p></div>';
}

function seedSampleData(PDO $pdo, int $uid): void {
    // Expenses categories (system ones should already exist from schema.sql)
    $catStmt = $pdo->query("SELECT id, name FROM expense_categories WHERE is_system = 1");
    $cats = [];
    foreach ($catStmt->fetchAll() as $c) {
        $cats[$c['name']] = (int)$c['id'];
    }

    // Products
    $products = [
        ['Web Development Service', 'Custom website development and maintenance', 15000.00],
        ['Graphic Design Package', 'Logo design, branding, and graphic services', 8000.00],
        ['Consulting Service', 'Business and tech consulting per session', 5000.00],
        ['Mobile App Development', 'iOS and Android app development', 25000.00],
    ];

    $prodIds = [];
    $stmt = $pdo->prepare("INSERT INTO products (user_id, name, description, capital, status) VALUES (:uid, :name, :desc, :cap, 'active')");
    foreach ($products as $p) {
        $stmt->execute([':uid' => $uid, ':name' => $p[0], ':desc' => $p[1], ':cap' => $p[2]]);
        $prodIds[] = (int)$pdo->lastInsertId();
    }

    // Revenue (6 months for each product)
    $revData = [
        [1, 45000, 52000, 48000, 55000, 61000, 58000],
        [2, 22000, 18000, 25000, 30000, 28000, 32000],
        [3, 12000, 15000, 10000, 18000, 14000, 16000],
        [4, 35000, 42000, 38000, 45000, 50000, 47000],
    ];

    $stmt = $pdo->prepare("INSERT INTO revenue (user_id, product_id, month, year, amount) VALUES (:uid, :pid, :m, 2026, :amt)");
    foreach ($revData as $pi => $months) {
        foreach ($months as $mi => $amt) {
            $stmt->execute([':uid' => $uid, ':pid' => $prodIds[$pi], ':m' => ($mi + 1), ':amt' => $amt]);
        }
    }

    // Expenses
    $expenses = [
        [0, 'Utilities', 'Office Electricity Bill', 3500.00, '2026-01-15'],
        [0, 'Salary', 'Developer Salary - Jan', 25000.00, '2026-01-30'],
        [0, 'Supplies', 'Office Supplies', 1800.00, '2026-01-10'],
        [1, 'Marketing', 'Facebook Ads Campaign', 5000.00, '2026-02-01'],
        [1, 'Transportation', 'Client Meeting Transport', 1200.00, '2026-02-12'],
        [2, 'Taxes', 'Business Tax Q1', 8000.00, '2026-03-15'],
        [0, 'Maintenance', 'Server Maintenance', 4500.00, '2026-03-20'],
        [3, 'Salary', 'Developer Salary - Feb', 25000.00, '2026-02-28'],
        [3, 'Marketing', 'Google Ads', 3500.00, '2026-03-05'],
        [0, 'Utilities', 'Internet Bill', 2500.00, '2026-04-10'],
        [1, 'Supplies', 'Design Software License', 3000.00, '2026-04-15'],
        [2, 'Others', 'Miscellaneous', 1500.00, '2026-05-08'],
        [3, 'Salary', 'Developer Salary - Mar', 25000.00, '2026-03-31'],
        [0, 'Utilities', 'Office Rent', 15000.00, '2026-05-01'],
        [1, 'Marketing', 'Social Media Marketing', 4000.00, '2026-06-02'],
    ];

    $stmt = $pdo->prepare("INSERT INTO expenses (user_id, product_id, category_id, expense_name, amount, expense_date) VALUES (:uid, :pid, :cid, :name, :amt, :date)");
    foreach ($expenses as $e) {
        $pid = $prodIds[$e[0]] ?? null;
        $catId = $cats[$e[1]] ?? null;
        $stmt->execute([':uid' => $uid, ':pid' => $pid, ':cid' => $catId, ':name' => $e[2], ':amt' => $e[3], ':date' => $e[4]]);
    }

    // Fixed expenses
    $fixed = [
        ['Office Rent', 'Utilities', 15000.00, 'Monthly office space rental'],
        ['Internet Subscription', 'Utilities', 2500.00, 'Fiber internet plan'],
        ['Cloud Hosting', 'Utilities', 4500.00, 'AWS hosting services'],
        ['Employee Salary', 'Salary', 25000.00, 'Monthly salary for 1 developer'],
        ['Software Licenses', 'Supplies', 3000.00, 'Monthly software subscriptions'],
    ];

    $stmt = $pdo->prepare("INSERT INTO fixed_expenses (user_id, expense_name, category_id, amount, status, description) VALUES (:uid, :name, :cid, :amt, 'active', :desc)");
    foreach ($fixed as $f) {
        $catId = $cats[$f[1]] ?? null;
        $stmt->execute([':uid' => $uid, ':name' => $f[0], ':cid' => $catId, ':amt' => $f[2], ':desc' => $f[3]]);
    }
}

function e(?string $v): string {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}
?>
</div>
</body>
</html>
