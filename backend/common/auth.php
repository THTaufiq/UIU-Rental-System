<?php
// Shared Session & Authentication Helpers

/**
 * Ensure PHP session is started safely.
 */
function initSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Check whether a valid user login session exists.
 *
 * @return bool
 */
function isLoggedIn() {
    initSession();
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get the currently logged-in user's ID from the session.
 *
 * @return int|null
 */
function getCurrentUserId() {
    if (isLoggedIn()) {
        return (int)$_SESSION['user_id'];
    }
    return null;
}

/**
 * Get the currently logged-in user's role from the session.
 *
 * @return string|null
 */
function getCurrentUserRole() {
    if (isLoggedIn() && isset($_SESSION['role'])) {
        return strtolower(trim($_SESSION['role']));
    }
    return null;
}

/**
 * Require user to be logged in.
 * If unauthenticated, returns 401 status code and JSON error response, then exits.
 */
function requireLogin() {
    if (!isLoggedIn()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Authentication required'
        ]);
        exit;
    }
}

/**
 * Require user to be logged in and possess an allowed role.
 * If unauthorized, returns 403 status code and JSON error response, then exits.
 *
 * @param string|array $allowedRoles Single role string or array of allowed roles (e.g. 'student', ['student', 'admin'])
 */
function requireRole($allowedRoles) {
    requireLogin();

    $currentRole = getCurrentUserRole();

    if (is_string($allowedRoles)) {
        $allowedRoles = [$allowedRoles];
    }

    $normalizedAllowed = array_map(function($role) {
        return strtolower(trim($role));
    }, (array)$allowedRoles);

    if (!$currentRole || !in_array($currentRole, $normalizedAllowed, true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Access denied'
        ]);
        exit;
    }
}

/**
 * Safely destroy the current user session.
 */
function logoutSession() {
    initSession();
    $_SESSION = array();

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

    session_destroy();
}
