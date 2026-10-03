<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Require logged-in landlord role
requireRole('landlord');

// Derive identity from session
$landlordId = getCurrentUserId();

$agId = isset($_GET['agreement_id']) ? $_GET['agreement_id'] : (isset($_GET['id']) ? $_GET['id'] : null);

if ($agId === null || !filter_var($agId, FILTER_VALIDATE_INT) || (int)$agId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid agreement ID.'
    ]);
    exit;
}

$agId = (int)$agId;

try {
    // Direct SQL query fetching single agreement with ownership check
    $stmt = $conn->prepare("
        SELECT 
            ra.agreement_id,
            ra.application_id,
            ra.property_id,
            ra.student_id,
            ra.landlord_id,
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
            p.full_address,
            p.neighborhood,
            p.city,
            u.full_name AS student_name,
            u.email AS student_email,
            u.phone AS student_phone
        FROM rental_agreements ra
        JOIN properties p ON ra.property_id = p.property_id
        JOIN users u ON ra.student_id = u.user_id
        WHERE ra.agreement_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $agId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ag = $res ? $res->fetch_assoc() : null;

    if (!$ag) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Agreement not found.'
        ]);
        exit;
    }

    // Strict ownership check
    if ((int)$ag['landlord_id'] !== (int)$landlordId) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Access denied. You do not own this agreement.'
        ]);
        exit;
    }

    unset($ag['landlord_id']);

    echo json_encode([
        'success' => true,
        'data'    => $ag
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred while fetching agreement details.'
    ]);
    exit;
}

