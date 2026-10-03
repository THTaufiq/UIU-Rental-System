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
    // Direct SQL query returning only the logged-in student's active/latest rental agreement using MySQLi
    $stmt = $conn->prepare("
        SELECT 
            ra.agreement_id, ra.application_id, ra.property_id, ra.landlord_id,
            ra.start_date, ra.end_date, ra.duration_months, ra.monthly_rent,
            ra.security_deposit, ra.service_charge, ra.terms, ra.status AS agreement_status,
            ra.created_at, ra.finalized_at,
            p.title AS property_title, p.neighborhood, p.full_address, p.city, p.distance_from_uiu_km,
            l.full_name AS landlord_name, l.phone AS landlord_phone, l.email AS landlord_email,
            pi.image_url AS property_image
        FROM rental_agreements ra
        JOIN properties p ON ra.property_id = p.property_id
        LEFT JOIN users l ON ra.landlord_id = l.user_id
        LEFT JOIN property_images pi ON p.property_id = pi.property_id AND pi.is_primary = 1
        WHERE ra.student_id = ?
        ORDER BY (CASE WHEN ra.status = 'active' THEN 1 ELSE 2 END), ra.agreement_id DESC
        LIMIT 1
    ");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $studentId);
    $stmt->execute();
    $res = $stmt->get_result();
    $lease = $res->fetch_assoc();
    $stmt->close();

    if (!$lease) {
        echo json_encode([
            'success' => true,
            'message' => 'No active rental agreement found.',
            'data'    => null
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data'    => $lease
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred while fetching lease details.'
    ]);
    exit;
}
