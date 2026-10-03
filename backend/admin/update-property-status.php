<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Only allow POST request
if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
    exit;
}

// Require logged-in admin role
requireRole('admin');

// Derive admin user identity from session
$adminId = getCurrentUserId();

// Read payload
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$propertyId      = isset($data['property_id']) ? $data['property_id'] : (isset($data['id']) ? $data['id'] : null);
$requestedStatus = isset($data['status']) ? strtolower(trim($data['status'])) : '';

if ($propertyId === null || !filter_var($propertyId, FILTER_VALIDATE_INT) || (int)$propertyId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid property ID.'
    ]);
    exit;
}

$propertyId = (int)$propertyId;

$allowedStatuses = ['pending', 'approved', 'rejected', 'removed', 'rented', 'unavailable'];
if (!in_array($requestedStatus, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid property status requested.'
    ]);
    exit;
}

try {
    // 1. Fetch current property details
    $stmtProp = $conn->prepare("SELECT property_id, landlord_id, title, status FROM properties WHERE property_id = ? LIMIT 1");
    $stmtProp->bind_param("i", $propertyId);
    $stmtProp->execute();
    $resProp = $stmtProp->get_result();
    $prop = $resProp->fetch_assoc();
    $stmtProp->close();

    if (!$prop) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Property not found.'
        ]);
        exit;
    }

    $oldStatus  = $prop['status'];
    $landlordId = (int)$prop['landlord_id'];

    // 2. Conflicting Rental State Safeguard
    // If attempting to change from rented/active to available/pending/rejected/removed, check active rental agreements
    if ($requestedStatus !== 'rented' && $oldStatus === 'rented') {
        $stmtAgr = $conn->prepare("SELECT agreement_id FROM rental_agreements WHERE property_id = ? AND status = 'active' LIMIT 1");
        $stmtAgr->bind_param("i", $propertyId);
        $stmtAgr->execute();
        $resAgr = $stmtAgr->get_result();
        if ($resAgr->fetch_assoc()) {
            $stmtAgr->close();
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'Cannot change status of a property with an active rental agreement.'
            ]);
            exit;
        }
        $stmtAgr->close();
    }

    // 3. Update status using MySQLi prepared statement
    $stmtUpd = $conn->prepare("UPDATE properties SET status = ?, updated_at = NOW() WHERE property_id = ?");
    $stmtUpd->bind_param("si", $requestedStatus, $propertyId);
    $stmtUpd->execute();
    $stmtUpd->close();

    // 4. Create landlord notification
    $notifTitle = 'Property Status Updated';
    $notifMsg   = 'Status for property "' . $prop['title'] . '" has been changed to ' . ucfirst($requestedStatus) . '.';

    $stmtNotif = $conn->prepare("
        INSERT INTO notifications (
            user_id, notification_type, title, message, reference_type, reference_id, is_read, created_at
        ) VALUES (
            ?, 'property', ?, ?, 'property', ?, 0, NOW()
        )
    ");
    if ($stmtNotif) {
        $stmtNotif->bind_param("issi", $landlordId, $notifTitle, $notifMsg, $propertyId);
        $stmtNotif->execute();
        $stmtNotif->close();
    }

    // 5. Create Audit Log entry
    $oldDataStr = json_encode(['status' => $oldStatus]);
    $newDataStr = json_encode(['status' => $requestedStatus]);
    $stmtAudit  = $conn->prepare("
        INSERT INTO audit_logs (
            actor_user_id, action_type, table_name, record_id, old_data, new_data, created_at
        ) VALUES (
            ?, 'update_status', 'properties', ?, ?, ?, NOW()
        )
    ");
    if ($stmtAudit) {
        $stmtAudit->bind_param("iiss", $adminId, $propertyId, $oldDataStr, $newDataStr);
        $stmtAudit->execute();
        $stmtAudit->close();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Property status updated successfully.',
        'data'    => [
            'property_id' => $propertyId,
            'old_status'  => $oldStatus,
            'new_status'  => $requestedStatus
        ]
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error updating property status.'
    ]);
    exit;
}
