<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'POST';
if ($method !== 'POST' && $method !== 'DELETE') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
    exit;
}

// Require logged-in admin role
requireRole('admin');

// Derive logged-in admin identity from session
$currentAdminId = getCurrentUserId();

// Read input payload
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$targetUserId = isset($data['user_id']) ? $data['user_id'] : (isset($data['id']) ? $data['id'] : (isset($_GET['user_id']) ? $_GET['user_id'] : null));

if ($targetUserId === null || !filter_var($targetUserId, FILTER_VALIDATE_INT) || (int)$targetUserId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid user ID.'
    ]);
    exit;
}

$targetUserId = (int)$targetUserId;

// Self-Protection check: Admin cannot delete own account
if ($targetUserId === (int)$currentAdminId) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Admin cannot delete their own account.'
    ]);
    exit;
}

try {
    // 1. Verify target user exists
    $stmtCheck = $conn->prepare("SELECT user_id, full_name, account_status FROM users WHERE user_id = ? LIMIT 1");
    $stmtCheck->bind_param("i", $targetUserId);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();
    $targetUser = $resCheck->fetch_assoc();
    $stmtCheck->close();

    if (!$targetUser) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Target user not found.'
        ]);
        exit;
    }

    // 2. Perform soft-delete (account_status = 'deleted') via MySQLi prepared statement
    $stmtDelete = $conn->prepare("
        UPDATE users 
        SET account_status = 'deleted', updated_at = NOW() 
        WHERE user_id = ?
    ");
    $stmtDelete->bind_param("i", $targetUserId);
    $stmtDelete->execute();
    $stmtDelete->close();

    echo json_encode([
        'success' => true,
        'message' => 'User account marked as deleted successfully.',
        'data'    => [
            'user_id' => $targetUserId,
            'status'  => 'deleted'
        ]
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error deleting user account.'
    ]);
    exit;
}
