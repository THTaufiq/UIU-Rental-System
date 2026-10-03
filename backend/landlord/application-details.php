<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Require logged-in landlord role
requireRole('landlord');

// Always derive identity from session
$landlordId = getCurrentUserId();

$appId = isset($_GET['application_id']) ? $_GET['application_id'] : (isset($_GET['id']) ? $_GET['id'] : null);

if ($appId === null || !filter_var($appId, FILTER_VALIDATE_INT) || (int)$appId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid application ID.'
    ]);
    exit;
}

$appId = (int)$appId;

try {
    // 1. Fetch application details joined with property & user info
    $stmt = $conn->prepare("
        SELECT 
            ra.application_id,
            ra.property_id,
            ra.student_id,
            ra.move_in_date,
            ra.duration_months,
            ra.message,
            ra.status,
            ra.rejection_reason,
            ra.applied_at,
            ra.reviewed_at,
            ra.landlord_note,
            p.title AS property_title,
            p.full_address,
            p.neighborhood,
            p.city,
            p.monthly_rent,
            p.security_deposit,
            p.landlord_id,
            u.full_name AS student_name,
            u.email AS student_email,
            u.phone AS student_phone,
            sp.university_student_id,
            sp.program
        FROM rental_applications ra
        JOIN properties p ON ra.property_id = p.property_id
        JOIN users u ON ra.student_id = u.user_id
        LEFT JOIN student_profiles sp ON u.user_id = sp.student_id
        WHERE ra.application_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $appId);
    $stmt->execute();
    $res = $stmt->get_result();
    $app = $res ? $res->fetch_assoc() : null;

    if (!$app) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Application not found.'
        ]);
        exit;
    }

    // 2. Strict ownership check: landlord_id must match logged-in landlord session
    if ((int)$app['landlord_id'] !== (int)$landlordId) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Access denied. You do not own the property for this application.'
        ]);
        exit;
    }

    // Remove landlord_id from output for cleanliness if desired
    unset($app['landlord_id']);

    echo json_encode([
        'success' => true,
        'data'    => $app
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred while fetching application details.'
    ]);
    exit;
}

