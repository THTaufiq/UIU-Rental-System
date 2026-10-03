<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Require logged-in student role
requireRole('student');

// Logged-in student ID
$studentId = getCurrentUserId();

try {
    // Direct SQL query returning only the logged-in student's applications using MySQLi
    $stmt = $conn->prepare("
        SELECT 
            ra.application_id, ra.property_id, ra.move_in_date, ra.duration_months, 
            ra.message, ra.status, ra.rejection_reason, ra.applied_at, ra.reviewed_at,
            p.title AS property_title, p.neighborhood, p.full_address, p.city, p.monthly_rent,
            p.security_deposit,
            pi.image_url AS property_image,
            l.full_name AS landlord_name, l.phone AS landlord_phone
        FROM rental_applications ra
        JOIN properties p ON ra.property_id = p.property_id
        LEFT JOIN users l ON p.landlord_id = l.user_id
        LEFT JOIN property_images pi ON p.property_id = pi.property_id AND pi.is_primary = 1
        WHERE ra.student_id = ?
        ORDER BY ra.applied_at DESC
    ");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $studentId);
    $stmt->execute();
    $res = $stmt->get_result();
    $applications = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode([
        'success' => true,
        'count'   => count($applications),
        'data'    => $applications
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred while fetching applications.'
    ]);
    exit;
}
