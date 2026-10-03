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

$propertyId  = isset($data['property_id']) ? $data['property_id'] : (isset($data['id']) ? $data['id'] : null);
$rawVerified = isset($data['verified']) ? $data['verified'] : null;

if ($propertyId === null || !filter_var($propertyId, FILTER_VALIDATE_INT) || (int)$propertyId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid property ID.'
    ]);
    exit;
}

if ($rawVerified === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Verification status is required.'
    ]);
    exit;
}

$propertyId  = (int)$propertyId;
$verifiedVal = (int)(bool)$rawVerified;

try {
    // 1. Fetch property & landlord details
    $stmtProp = $conn->prepare("SELECT property_id, landlord_id, title, verified FROM properties WHERE property_id = ? LIMIT 1");
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

    $oldVerified = (int)$prop['verified'];

    // 2. Update verified column using MySQLi
    $stmtUpd = $conn->prepare("UPDATE properties SET verified = ?, updated_at = NOW() WHERE property_id = ?");
    $stmtUpd->bind_param("ii", $verifiedVal, $propertyId);
    $stmtUpd->execute();
    $stmtUpd->close();

    // 3. Create landlord notification
    $landlordId  = (int)$prop['landlord_id'];
    $notifTitle  = 'Property Verification Updated';
    $verifStatusStr = ($verifiedVal === 1) ? 'Verified' : 'Unverified';
    $notifMsg    = 'Your property "' . $prop['title'] . '" is now marked as ' . $verifStatusStr . ' by administration.';

    $stmtNotif = $conn->prepare("
        INSERT INTO notifications (
            user_id, notification_type, title, message, reference_type, reference_id, is_read, created_at
        ) VALUES (
            ?, 'verification', ?, ?, 'property', ?, 0, NOW()
        )
    ");
    if ($stmtNotif) {
        $stmtNotif->bind_param("issi", $landlordId, $notifTitle, $notifMsg, $propertyId);
        $stmtNotif->execute();
        $stmtNotif->close();
    }

    // 4. Create Audit Log entry
    $oldDataStr = json_encode(['verified' => $oldVerified]);
    $newDataStr = json_encode(['verified' => $verifiedVal]);
    $stmtAudit  = $conn->prepare("
        INSERT INTO audit_logs (
            actor_user_id, action_type, table_name, record_id, old_data, new_data, created_at
        ) VALUES (
            ?, 'update_verification', 'properties', ?, ?, ?, NOW()
        )
    ");
    if ($stmtAudit) {
        $stmtAudit->bind_param("iiss", $adminId, $propertyId, $oldDataStr, $newDataStr);
        $stmtAudit->execute();
        $stmtAudit->close();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Property verification status updated successfully.',
        'data'    => [
            'property_id'  => $propertyId,
            'old_verified' => $oldVerified,
            'new_verified' => $verifiedVal
        ]
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error updating property verification.'
    ]);
    exit;
}
