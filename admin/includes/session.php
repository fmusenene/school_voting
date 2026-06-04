<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

function sendNoCacheHeaders() {
    if (!headers_sent()) {
        header('Expires: Thu, 19 Nov 1981 08:52:00 GMT');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}

function isAdminLoggedIn() {
    return isset($_SESSION['admin_id'], $_SESSION['admin_username']);
}

function requireAdminLogin() {
    sendNoCacheHeaders();

    if (!isAdminLoggedIn()) {
        logoutAdmin(false);
    }

    if (!isset($_SESSION['last_regenerated'])) {
        $_SESSION['last_regenerated'] = time();
    } elseif (time() - $_SESSION['last_regenerated'] > 300) {
        session_regenerate_id(true);
        $_SESSION['last_regenerated'] = time();
    }
}

function logoutAdmin($redirect = true) {
    sendNoCacheHeaders();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();

    if ($redirect) {
        header('Location: login.php');
        exit();
    }

    header('Location: login.php');
    exit();
}