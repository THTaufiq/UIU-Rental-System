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

if ($method !== 'POST') {
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

$propertyId = isset($data['property_id']) ? (int)$data['property_id'] : 0;
$status     = isset($data['status']) ? strtolower(trim($data['status'])) : '';

$allowedStatuses = ['available', 'rented', 'unavailable', 'pending', 'removed'];

if ($propertyId <= 0 || !in_array($status, $allowedStatuses)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid property ID or status value.'
    ]);
    exit;
}

try {
    // 1. Simple Query: Verify landlord ownership of property
    $stmtCheck = $conn->prepare("SELECT 1 FROM properties WHERE property_id = ? AND landlord_id = ? LIMIT 1");
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

    // 2. Simple Query: Update property status
    $stmtUpd = $conn->prepare("UPDATE properties SET status = ? WHERE property_id = ? AND landlord_id = ?");
    $stmtUpd->bind_param("sii", $status, $propertyId, $landlordId);
    $stmtUpd->execute();

    echo json_encode([
        'success' => true,
        'message' => 'Property status updated successfully!',
        'data'    => [
            'property_id' => $propertyId,
            'status'      => $status
        ]
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error updating property status.'
    ]);
    exit;
}

