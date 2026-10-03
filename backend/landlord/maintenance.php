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
    // 1. Simple Query: Fetch maintenance requests for landlord's properties
    $stmt = $conn->prepare("
        SELECT 
            mr.request_id,
            mr.property_id,
            mr.student_id,
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
            p.neighborhood,
            u.full_name AS student_name,
            u.email AS student_email,
            u.phone AS student_phone
        FROM maintenance_requests mr
        LEFT JOIN maintenance_categories mc ON mr.category_id = mc.category_id
        JOIN properties p ON mr.property_id = p.property_id
        JOIN users u ON mr.student_id = u.user_id
        WHERE mr.landlord_id = ? OR p.landlord_id = ?
        ORDER BY mr.created_at DESC
    ");
    $stmt->bind_param("ii", $landlordId, $landlordId);
    $stmt->execute();
    $res = $stmt->get_result();
    $requests = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'Maintenance requests retrieved successfully.',
        'data'    => $requests
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred while fetching maintenance requests.'
    ]);
    exit;
}

