<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Require logged-in landlord role (role_id = 2)
requireRole('landlord');

$landlordId = getCurrentUserId();
$method = $_SERVER['REQUEST_METHOD'] ?? 'POST';

if ($method !== 'POST' && $method !== 'DELETE') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$propertyId = isset($data['property_id']) ? (int)$data['property_id'] : (isset($_GET['property_id']) ? (int)$_GET['property_id'] : 0);

if ($propertyId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid property ID.'
    ]);
    exit;
}

try {
    // 1. Simple Query: Verify ownership
    $stmtCheck = $conn->prepare("SELECT property_id FROM properties WHERE property_id = ? AND landlord_id = ? LIMIT 1");
    $stmtCheck->bind_param("ii", $propertyId, $landlordId);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();

    if (!$resCheck || !$resCheck->fetch_assoc()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Property not found or access denied.'
        ]);
        exit;
    }

    // 2. Simple Query: Check active rental applications
    $stmtAppCount = $conn->prepare("SELECT COUNT(*) AS cnt FROM rental_applications WHERE property_id = ? AND status IN ('pending', 'approved')");
    $stmtAppCount->bind_param("i", $propertyId);
    $stmtAppCount->execute();
    $resApp = $stmtAppCount->get_result();
    $rowApp = $resApp ? $resApp->fetch_assoc() : null;
    $appCount = $rowApp ? (int)$rowApp['cnt'] : 0;

    // 3. Simple Query: Check active rental agreements
    $stmtLeaseCount = $conn->prepare("SELECT COUNT(*) AS cnt FROM rental_agreements WHERE property_id = ? AND status = 'active'");
    $stmtLeaseCount->bind_param("i", $propertyId);
    $stmtLeaseCount->execute();
    $resLease = $stmtLeaseCount->get_result();
    $rowLease = $resLease ? $resLease->fetch_assoc() : null;
    $leaseCount = $rowLease ? (int)$rowLease['cnt'] : 0;

    // 4. Simple Query: Check paid payments
    $stmtPayCount = $conn->prepare("SELECT COUNT(*) AS cnt FROM payments WHERE property_id = ? AND status = 'paid'");
    $stmtPayCount->bind_param("i", $propertyId);
    $stmtPayCount->execute();
    $resPay = $stmtPayCount->get_result();
    $rowPay = $resPay ? $resPay->fetch_assoc() : null;
    $payCount = $rowPay ? (int)$rowPay['cnt'] : 0;

    if ($appCount > 0 || $leaseCount > 0 || $payCount > 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Property cannot be deleted because it has active rental or application records.'
        ]);
        exit;
    }

    // 5. Simple Query: Safely delete property from `properties`
    $stmtDel = $conn->prepare("DELETE FROM properties WHERE property_id = ? AND landlord_id = ?");
    $stmtDel->bind_param("ii", $propertyId, $landlordId);
    $stmtDel->execute();

    echo json_encode([
        'success' => true,
        'message' => 'Property deleted successfully.',
        'data'    => [
            'property_id' => $propertyId
        ]
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error deleting property.'
    ]);
    exit;
}

