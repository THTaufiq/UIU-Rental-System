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
    // Direct SQL query fetching payments for landlord
    $stmt = $conn->prepare("
        SELECT 
            pay.payment_id,
            pay.agreement_id,
            pay.application_id,
            pay.student_id,
            pay.property_id,
            pay.payment_type,
            pay.amount,
            pay.method,
            pay.transaction_reference,
            pay.payment_month,
            pay.status,
            pay.paid_at,
            pay.notes,
            pay.created_at,
            p.title AS property_title,
            u.full_name AS student_name,
            u.email AS student_email,
            pr.receipt_number
        FROM payments pay
        JOIN properties p ON pay.property_id = p.property_id
        JOIN users u ON pay.student_id = u.user_id
        LEFT JOIN payment_receipts pr ON pay.payment_id = pr.payment_id
        WHERE pay.landlord_id = ?
        ORDER BY pay.created_at DESC
    ");
    $stmt->bind_param("i", $landlordId);
    $stmt->execute();
    $res = $stmt->get_result();
    $payments = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    echo json_encode([
        'success' => true,
        'message' => 'Payments retrieved successfully.',
        'data'    => $payments
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred while fetching payments.'
    ]);
    exit;
}

