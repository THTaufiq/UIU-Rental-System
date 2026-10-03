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

// Require logged-in landlord role
requireRole('landlord');

// Derive identity from session
$landlordId = getCurrentUserId();

// Read payload
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$reqId = isset($data['request_id']) ? $data['request_id'] : (isset($data['maintenance_request_id']) ? $data['maintenance_request_id'] : null);
$requestedStatus = isset($data['status']) ? trim($data['status']) : '';
$landlordNotes   = isset($data['landlord_notes']) ? trim($data['landlord_notes']) : (isset($data['landlord_note']) ? trim($data['landlord_note']) : (isset($data['notes']) ? trim($data['notes']) : ''));

if ($reqId === null || !filter_var($reqId, FILTER_VALIDATE_INT) || (int)$reqId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid maintenance request ID.'
    ]);
    exit;
}

$reqId = (int)$reqId;

// Enum normalization mapping
$allowedStatuses = [
    'pending'     => 'Pending',
    'in_progress' => 'In Progress',
    'in progress' => 'In Progress',
    'resolved'    => 'Resolved',
    'rejected'    => 'Rejected',
    'cancelled'   => 'Cancelled'
];

$normalizedKey = strtolower($requestedStatus);
if (!isset($allowedStatuses[$normalizedKey])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid status requested.'
    ]);
    exit;
}

$newStatus = $allowedStatuses[$normalizedKey];

try {
    // 1. Fetch existing request & verify landlord ownership
    $stmtCheck = $conn->prepare("
        SELECT mr.request_id, mr.student_id, mr.status AS old_status, mr.title, p.landlord_id
        FROM maintenance_requests mr
        JOIN properties p ON mr.property_id = p.property_id
        WHERE mr.request_id = ?
        LIMIT 1
    ");
    $stmtCheck->bind_param("i", $reqId);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();
    $req = $resCheck ? $resCheck->fetch_assoc() : null;

    if (!$req) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Maintenance request not found.'
        ]);
        exit;
    }

    if ((int)$req['landlord_id'] !== (int)$landlordId) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Access denied. You do not own this property.'
        ]);
        exit;
    }

    $oldStatus = $req['old_status'];

    $conn->begin_transaction();

    // 2. Update maintenance request
    $resolvedAt = ($newStatus === 'Resolved') ? date('Y-m-d H:i:s') : null;
    $resolvedBy = ($newStatus === 'Resolved') ? $landlordId : null;

    $stmtUpdate = $conn->prepare("
        UPDATE maintenance_requests 
        SET 
            status = ?,
            landlord_notes = ?,
            landlord_note = ?,
            updated_at = NOW(),
            resolved_at = COALESCE(?, resolved_at),
            resolved_by = COALESCE(?, resolved_by)
        WHERE request_id = ?
    ");
    $stmtUpdate->bind_param("ssssii", $newStatus, $landlordNotes, $landlordNotes, $resolvedAt, $resolvedBy, $reqId);
    $stmtUpdate->execute();

    // 3. Insert status history record
    $stmtHist = $conn->prepare("
        INSERT INTO maintenance_status_history (
            request_id, old_status, new_status, changed_by, note, changed_at
        ) VALUES (
            ?, ?, ?, ?, ?, NOW()
        )
    ");
    $stmtHist->bind_param("issis", $reqId, $oldStatus, $newStatus, $landlordId, $landlordNotes);
    $stmtHist->execute();

    // 4. Notify student
    $studentId = (int)$req['student_id'];
    $notifMsg = 'Maintenance request "' . $req['title'] . '" status changed to ' . $newStatus . '.';
    $stmtNotif = $conn->prepare("
        INSERT INTO notifications (
            user_id, notification_type, title, message, reference_type, reference_id, is_read, created_at
        ) VALUES (
            ?, 'maintenance', 'Maintenance Request Updated', ?, 'maintenance_request', ?, 0, NOW()
        )
    ");
    $stmtNotif->bind_param("isi", $studentId, $notifMsg, $reqId);
    $stmtNotif->execute();

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Maintenance request status updated successfully.',
        'data'    => [
            'request_id' => $reqId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus
        ]
    ]);
    exit;

} catch (Throwable $e) {
    @$conn->rollback();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error updating maintenance request: ' . $e->getMessage()
    ]);
    exit;
}

