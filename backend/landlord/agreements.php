<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Require logged-in landlord role
requireRole('landlord');

// Always identify landlord using $_SESSION['user_id']
$landlordId = getCurrentUserId();

try {
    // Direct SQL query fetching agreements for landlord
    $stmt = $conn->prepare("
        SELECT 
            ra.agreement_id,
            ra.application_id,
            ra.property_id,
            ra.student_id,
            ra.start_date,
            ra.end_date,
            ra.duration_months,
            ra.monthly_rent,
            ra.security_deposit,
            ra.service_charge,
            ra.terms,
            ra.status,
            ra.created_at,
            ra.finalized_at,
            p.title AS property_title,
            p.neighborhood,
            p.city,
            u.full_name AS student_name,
            u.email AS student_email,
            u.phone AS student_phone
        FROM rental_agreements ra
        JOIN properties p ON ra.property_id = p.property_id
        JOIN users u ON ra.student_id = u.user_id
        WHERE ra.landlord_id = ?
        ORDER BY ra.created_at DESC
    ");
    $stmt->bind_param("i", $landlordId);
    $stmt->execute();
    $res = $stmt->get_result();
    $agreements = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    echo json_encode([
        'success' => true,
        'message' => 'Rental agreements retrieved successfully.',
        'data'    => $agreements
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred while fetching rental agreements.'
    ]);
    exit;
}

