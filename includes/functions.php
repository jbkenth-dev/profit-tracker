<?php
/**
 * Core Functions & Security Helpers
 */

// ─── CSRF Protection ────────────────────────────────────────

/**
 * Generate and store CSRF token in session
 *
 * @return string
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Generate CSRF hidden input field
 *
 * @return string
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

/**
 * Verify CSRF token from request
 *
 * @param string $token
 * @return bool
 */
function verify_csrf(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Require a valid CSRF token or exit with error
 */
function require_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf($token)) {
        $_SESSION['error'] = 'Invalid security token. Please try again.';
        redirect_back();
    }
}

// ─── Session & Auth ─────────────────────────────────────────

/**
 * Start a secure session
 */
function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Regenerate session ID to prevent session fixation
 */
function regenerate_session(): void {
    session_regenerate_id(true);
}

/**
 * Check if user is logged in
 *
 * @return bool
 */
function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

/**
 * Require authentication — redirect to login if not authenticated
 */
function require_auth(): void {
    if (!is_logged_in()) {
        $_SESSION['redirect_after'] = $_SERVER['REQUEST_URI'] ?? 'dashboard.php';
        header('Location: auth/login.php');
        exit;
    }
}

/**
 * Get current user ID
 *
 * @return int|null
 */
function user_id(): ?int {
    return $_SESSION['user_id'] ?? null;
}

// ─── Input Validation & Sanitization ────────────────────────

/**
 * Sanitize output for HTML display (XSS protection)
 *
 * @param string|null $value
 * @return string
 */
function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Sanitize input string
 *
 * @param string $value
 * @return string
 */
function sanitize(string $value): string {
    return trim(htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8'));
}

/**
 * Validate email format
 *
 * @param string $email
 * @return bool
 */
function is_valid_email(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate monetary amount (positive number)
 *
 * @param mixed $amount
 * @return bool
 */
function is_valid_amount($amount): bool {
    return is_numeric($amount) && $amount >= 0;
}

/**
 * Check if value is a positive integer
 *
 * @param mixed $value
 * @return bool
 */
function is_positive_int($value): bool {
    return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
}

// ─── Redirect Helpers ───────────────────────────────────────

/**
 * Redirect to a URL
 *
 * @param string $url
 */
function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

/**
 * Redirect back to previous page
 */
function redirect_back(): void {
    $referer = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
    redirect($referer);
}

/**
 * Redirect with a flash message
 *
 * @param string $url
 * @param string $message
 * @param string $type success|error|warning|info
 */
function redirect_with(string $url, string $message, string $type = 'success'): void {
    $_SESSION[$type] = $message;
    redirect($url);
}

// ─── Flash Messages ─────────────────────────────────────────

/**
 * Display and clear flash messages
 *
 * @return string HTML for alerts
 */
function flash_messages(): string {
    $types = ['success', 'error', 'warning', 'info'];
    $html = '';

    foreach ($types as $type) {
        if (isset($_SESSION[$type])) {
            $alertClass = $type === 'error' ? 'danger' : $type;
            $html .= '<div class="alert alert-' . $alertClass . ' alert-dismissible fade show" role="alert">'
                   . e($_SESSION[$type])
                   . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>'
                   . '</div>';
            unset($_SESSION[$type]);
        }
    }

    return $html;
}

// ─── Currency Formatting ────────────────────────────────────

/**
 * Get user's currency symbol
 *
 * @return string
 */
function currency_symbol(): string {
    return $_SESSION['currency'] ?? '₱';
}

/**
 * Format amount with currency symbol
 *
 * @param float $amount
 * @return string
 */
function format_currency(float $amount): string {
    $symbol = currency_symbol();
    return $symbol . number_format($amount, 2);
}

// ─── Date Helpers ───────────────────────────────────────────

/**
 * Get month name from number
 *
 * @param int $month
 * @return string
 */
function month_name(int $month): string {
    $months = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
    ];
    return $months[$month] ?? 'Unknown';
}

/**
 * Format date for display
 *
 * @param string $date
 * @param string $format
 * @return string
 */
function format_date(string $date, string $format = 'M d, Y'): string {
    $timestamp = strtotime($date);
    return $timestamp ? date($format, $timestamp) : '—';
}

/**
 * Get current month number
 *
 * @return int
 */
function current_month(): int {
    return (int)date('n');
}

/**
 * Get current year
 *
 * @return int
 */
function current_year(): int {
    return (int)date('Y');
}

// ─── Pagination ─────────────────────────────────────────────

/**
 * Generate pagination HTML
 *
 * @param int $currentPage
 * @param int $totalPages
 * @param string $baseUrl
 * @return string
 */
function pagination_html(int $currentPage, int $totalPages, string $baseUrl): string {
    if ($totalPages <= 1) return '';

    $html = '<nav><ul class="pagination pagination-sm justify-content-center mb-0">';

    // Previous
    $prevDisabled = $currentPage <= 1 ? ' disabled' : '';
    $prevUrl = $currentPage > 1 ? $baseUrl . '&page=' . ($currentPage - 1) : '#';
    $html .= '<li class="page-item' . $prevDisabled . '">'
           . '<a class="page-link" href="' . $prevUrl . '">&laquo;</a></li>';

    // Pages
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);

    if ($start > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '&page=1">1</a></li>';
        if ($start > 2) $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
    }

    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $currentPage ? ' active' : '';
        $html .= '<li class="page-item' . $active . '">'
               . '<a class="page-link" href="' . $baseUrl . '&page=' . $i . '">' . $i . '</a></li>';
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '&page=' . $totalPages . '">' . $totalPages . '</a></li>';
    }

    // Next
    $nextDisabled = $currentPage >= $totalPages ? ' disabled' : '';
    $nextUrl = $currentPage < $totalPages ? $baseUrl . '&page=' . ($currentPage + 1) : '#';
    $html .= '<li class="page-item' . $nextDisabled . '">'
           . '<a class="page-link" href="' . $nextUrl . '">&raquo;</a></li>';

    $html .= '</ul></nav>';
    return $html;
}

// ─── Miscellaneous ──────────────────────────────────────────

/**
 * Generate a random token
 *
 * @param int $length
 * @return string
 */
function generate_token(int $length = 32): string {
    return bin2hex(random_bytes($length));
}

/**
 * Truncate text to a given length
 *
 * @param string $text
 * @param int $length
 * @return string
 */
function truncate(string $text, int $length = 50): string {
    if (mb_strlen($text) <= $length) return $text;
    return mb_substr($text, 0, $length) . '...';
}

/**
 * Get user IP address
 *
 * @return string
 */
function get_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Get year options for select dropdowns
 *
 * @param int $yearsBack
 * @param int $yearsForward
 * @return array
 */
function year_options(int $yearsBack = 5, int $yearsForward = 2): array {
    $current = current_year();
    $years = [];
    for ($y = $current - $yearsBack; $y <= $current + $yearsForward; $y++) {
        $years[$y] = $y;
    }
    return $years;
}

/**
 * Month options array
 *
 * @return array
 */
function month_options(): array {
    return [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
    ];
}
