<?php
// Start PHP session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear all session variables
$_SESSION = array();

// Destroy session cookie if set
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy session
session_destroy();

// If request expects JSON (fetch call)
if (
    (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
    (!empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
) {
    header('Content-Type: application/json');
    echo json_encode([
        'success'  => true,
        'message'  => 'Logged out successfully.',
        'redirect' => 'login.html'
    ]);
    exit;
}

// Default redirect for browser GET request
header('Location: ../../../frontend/pages/login.html');
exit;
