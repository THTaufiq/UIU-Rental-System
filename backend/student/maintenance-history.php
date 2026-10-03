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
            mr.request_id,
            mr.property_id,
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
            landlord.full_name AS landlord_name
        FROM maintenance_requests mr
        JOIN maintenance_categories mc ON mr.category_id = mc.category_id
        JOIN properties p ON mr.property_id = p.property_id
        JOIN users landlord ON mr.landlord_id = landlord.user_id
        WHERE mr.student_id = ?
        ORDER BY mr.created_at DESC
    ");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $studentId);
    $stmt->execute();
    $res = $stmt->get_result();
    $requests = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'Maintenance history retrieved successfully.',
        'data'    => $requests
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error retrieving maintenance history.'
    ]);
    exit;
}
