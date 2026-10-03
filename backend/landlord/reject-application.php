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

// Require logged-in landlord role
requireRole('landlord');

// Derive identity from session
$landlordId = getCurrentUserId();

// Read payload
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$appId = isset($data['application_id']) ? $data['application_id'] : null;
$rejectionReason = isset($data['rejection_reason']) ? trim($data['rejection_reason']) : '';

if ($appId === null || !filter_var($appId, FILTER_VALIDATE_INT) || (int)$appId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid application ID.'
    ]);
    exit;
}

$appId = (int)$appId;

if (empty($rejectionReason)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Rejection reason is required.'
    ]);
    exit;
}

try {
    // 1. Retrieve application & property info with ownership check
    $stmtApp = $conn->prepare("
        SELECT 
            ra.application_id,
            ra.property_id,
            ra.student_id,
            ra.status AS application_status,
            p.landlord_id,
            p.title AS property_title
        FROM rental_applications ra
        JOIN properties p ON ra.property_id = p.property_id
        WHERE ra.application_id = ?
        LIMIT 1
    ");
    $stmtApp->bind_param("i", $appId);
    $stmtApp->execute();
    $resApp = $stmtApp->get_result();
    $app = $resApp ? $resApp->fetch_assoc() : null;

    if (!$app) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Application not found.'
        ]);
        exit;
    }

    // Ownership check
    if ((int)$app['landlord_id'] !== (int)$landlordId) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Access denied. You do not own this property.'
        ]);
        exit;
    }

    // Status check
    if ($app['application_status'] !== 'pending') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Application is not in pending status.'
        ]);
        exit;
    }

    // Update application
    $stmtUpdate = $conn->prepare("
        UPDATE rental_applications 
        SET status = 'rejected', rejection_reason = ?, reviewed_at = NOW(), reviewed_by = ?
        WHERE application_id = ?
    ");
    $stmtUpdate->bind_param("sii", $rejectionReason, $landlordId, $appId);
    $stmtUpdate->execute();

    // Notify student
    $studentId = (int)$app['student_id'];
    $notifMsg = 'Your application for property "' . $app['property_title'] . '" was rejected. Reason: ' . $rejectionReason;
    $stmtNotif = $conn->prepare("
        INSERT INTO notifications (
            user_id, notification_type, title, message, reference_type, reference_id, is_read, created_at
        ) VALUES (
            ?, 'application', 'Application Update', ?, 'rental_application', ?, 0, NOW()
        )
    ");
    $stmtNotif->bind_param("isi", $studentId, $notifMsg, $appId);
    $stmtNotif->execute();

    echo json_encode([
        'success' => true,
        'message' => 'Application rejected successfully.',
        'data'    => [
            'application_id' => $appId,
            'status'         => 'rejected'
        ]
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred while rejecting application.'
    ]);
    exit;
}

