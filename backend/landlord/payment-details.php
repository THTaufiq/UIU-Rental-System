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

$payId = isset($_GET['payment_id']) ? $_GET['payment_id'] : (isset($_GET['id']) ? $_GET['id'] : null);

if ($payId === null || !filter_var($payId, FILTER_VALIDATE_INT) || (int)$payId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid payment ID.'
    ]);
    exit;
}

$payId = (int)$payId;

try {
    // 1. Fetch payment details with ownership check
    $stmtPay = $conn->prepare("
        SELECT 
            pay.payment_id,
            pay.agreement_id,
            pay.application_id,
            pay.student_id,
            pay.landlord_id,
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
            p.full_address,
            u.full_name AS student_name,
            u.email AS student_email,
            u.phone AS student_phone
        FROM payments pay
        JOIN properties p ON pay.property_id = p.property_id
        JOIN users u ON pay.student_id = u.user_id
        WHERE pay.payment_id = ?
        LIMIT 1
    ");
    $stmtPay->bind_param("i", $payId);
    $stmtPay->execute();
    $resPay = $stmtPay->get_result();
    $payment = $resPay ? $resPay->fetch_assoc() : null;

    if (!$payment) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Payment not found.'
        ]);
        exit;
    }

    // Strict ownership check
    if ((int)$payment['landlord_id'] !== (int)$landlordId) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Access denied. You do not own this payment record.'
        ]);
        exit;
    }

    unset($payment['landlord_id']);

    // 2. Fetch payment receipt info if available
    $stmtRc = $conn->prepare("
        SELECT receipt_id, payment_id, receipt_number, issued_at, sent_to_tenant
        FROM payment_receipts
        WHERE payment_id = ?
        LIMIT 1
    ");
    $stmtRc->bind_param("i", $payId);
    $stmtRc->execute();
    $resRc = $stmtRc->get_result();
    $receipt = $resRc ? $resRc->fetch_assoc() : null;

    echo json_encode([
        'success' => true,
        'data'    => [
            'payment' => $payment,
            'receipt' => $receipt ?: null
        ]
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred while fetching payment details.'
    ]);
    exit;
}

