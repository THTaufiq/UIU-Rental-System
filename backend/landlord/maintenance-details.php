<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Require logged-in landlord role
requireRole('landlord');

// Always derive identity from session
$landlordId = getCurrentUserId();

$reqId = isset($_GET['request_id']) ? $_GET['request_id'] : (isset($_GET['maintenance_request_id']) ? $_GET['maintenance_request_id'] : (isset($_GET['id']) ? $_GET['id'] : null));

if ($reqId === null || !filter_var($reqId, FILTER_VALIDATE_INT) || (int)$reqId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid maintenance request ID.'
    ]);
    exit;
}

$reqId = (int)$reqId;

try {
    // 1. Simple Query: Fetch request details with landlord property check
    $stmt = $conn->prepare("
        SELECT 
            mr.request_id,
            mr.property_id,
            mr.student_id,
            mr.landlord_id,
            mr.category_id,
            mc.category_name,
            mr.title,
            mr.description,
            mr.priority,
            mr.status,
            mr.attachment_path,
            mr.landlord_notes,
            mr.landlord_note,
            mr.created_at,
            mr.updated_at,
            mr.resolved_at,
            p.title AS property_title,
            p.full_address,
            p.neighborhood,
            u.full_name AS student_name,
            u.email AS student_email,
            u.phone AS student_phone
        FROM maintenance_requests mr
        JOIN maintenance_categories mc ON mr.category_id = mc.category_id
        JOIN properties p ON mr.property_id = p.property_id
        JOIN users u ON mr.student_id = u.user_id
        WHERE mr.request_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $reqId);
    $stmt->execute();
    $res = $stmt->get_result();
    $request = $res ? $res->fetch_assoc() : null;

    if (!$request) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Maintenance request not found.'
        ]);
        exit;
    }

    // Strict ownership check
    if ((int)$request['landlord_id'] !== (int)$landlordId) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Access denied. You do not own the property for this request.'
        ]);
        exit;
    }

    unset($request['landlord_id']);

    // 2. Simple Query: Fetch status history if available
    $stmtHist = $conn->prepare("
        SELECT history_id, request_id, old_status, new_status, changed_by, note, changed_at
        FROM maintenance_status_history
        WHERE request_id = ?
        ORDER BY changed_at ASC
    ");
    $stmtHist->bind_param("i", $reqId);
    $stmtHist->execute();
    $resHist = $stmtHist->get_result();
    $history = $resHist ? $resHist->fetch_all(MYSQLI_ASSOC) : [];

    echo json_encode([
        'success' => true,
        'data'    => [
            'request' => $request,
            'history' => $history ?: []
        ]
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred while fetching maintenance details.'
    ]);
    exit;
}

