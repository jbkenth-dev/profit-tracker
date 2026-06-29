<?php
/**
 * Logout — Destroy session and redirect to login
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

start_session();

if (is_logged_in()) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE user_id = :uid AND session_token = :token");
        $stmt->execute([
            ':uid'   => user_id(),
            ':token' => session_id(),
        ]);
    } catch (PDOException $e) {
        // Silently handle — session destruction still proceeds
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
}

session_destroy();
redirect('login.php');
