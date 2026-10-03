<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Only allow POST request
if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
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

$targetUserId = isset($data['user_id']) ? $data['user_id'] : (isset($data['id']) ? $data['id'] : null);
$requestedStatus = isset($data['account_status']) ? trim($data['account_status']) : (isset($data['status']) ? trim($data['status']) : '');

if ($targetUserId === null || !filter_var($targetUserId, FILTER_VALIDATE_INT) || (int)$targetUserId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid user ID.'
    ]);
    exit;
}

$targetUserId = (int)$targetUserId;

// Self-Protection check: Admin cannot change own status
if ($targetUserId === (int)$currentAdminId) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Admin cannot suspend or modify their own account status.'
    ]);
    exit;
}

// Validate status against whitelist
$allowedStatuses = ['pending', 'active', 'suspended', 'rejected', 'deleted'];
$normalizedStatus = strtolower($requestedStatus);

if (!in_array($normalizedStatus, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid account status requested.'
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

    $oldStatus = $targetUser['account_status'];

    // 2. Update account status via MySQLi prepared statement
    $stmtUpdate = $conn->prepare("
        UPDATE users 
        SET account_status = ?, updated_at = NOW() 
        WHERE user_id = ?
    ");
    $stmtUpdate->bind_param("si", $normalizedStatus, $targetUserId);
    $stmtUpdate->execute();
    $stmtUpdate->close();

    // 3. Send notification to the target user
    $notifTitle = 'Account Status Updated';
    $notifMsg   = 'Your account status has been changed to ' . ucfirst($normalizedStatus) . ' by platform administration.';
    $notifType  = 'system';

    $stmtNotif = $conn->prepare("
        INSERT INTO notifications (
            user_id, notification_type, title, message, reference_type, reference_id, is_read, created_at
        ) VALUES (
            ?, ?, ?, ?, 'user', ?, 0, NOW()
        )
    ");
    $stmtNotif->bind_param("isssi", $targetUserId, $notifType, $notifTitle, $notifMsg, $targetUserId);
    $stmtNotif->execute();
    $stmtNotif->close();

    echo json_encode([
        'success' => true,
        'message' => 'User account status updated successfully.',
        'data'    => [
            'user_id'    => $targetUserId,
            'old_status' => $oldStatus,
            'new_status' => $normalizedStatus
        ]
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error updating user status.'
    ]);
    exit;
}
