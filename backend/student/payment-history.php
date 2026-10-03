<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Require logged-in student role
requireRole('student');

$studentId = getCurrentUserId();

try {
    $stmt = $conn->prepare("
        SELECT 
            p.payment_id,
            p.agreement_id,
            p.application_id,
            p.payment_type,
            p.amount,
            p.method,
            p.transaction_reference,
            p.payment_month,
            p.status,
            p.paid_at,
            p.notes,
            p.created_at,
            pr.receipt_id,
            pr.receipt_number,
            pr.pdf_path,
            prop.title AS property_title,
            prop.neighborhood,
            landlord.full_name AS landlord_name
        FROM payments p
        LEFT JOIN payment_receipts pr ON p.payment_id = pr.payment_id
        JOIN properties prop ON p.property_id = prop.property_id
        JOIN users landlord ON p.landlord_id = landlord.user_id
        WHERE p.student_id = ?
        ORDER BY p.created_at DESC
    ");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $studentId);
    $stmt->execute();
    $res = $stmt->get_result();
    $payments = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'Payment history retrieved successfully.',
        'data'    => $payments
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error fetching payment history.'
    ]);
    exit;
}
