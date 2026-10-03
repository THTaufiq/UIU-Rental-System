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
    // 1. Simple Query: Fetch reviews for landlord's properties
    $stmt = $conn->prepare("
        SELECT 
            r.review_id,
            r.property_id,
            r.student_id,
            r.rating,
            r.comment,
            r.landlord_reply,
            r.replied_at,
            r.status,
            r.created_at,
            p.title AS property_title,
            u.full_name AS student_name,
            rr.reply_text AS separate_reply,
            rr.created_at AS separate_replied_at
        FROM reviews r
        JOIN properties p ON r.property_id = p.property_id
        JOIN users u ON r.student_id = u.user_id
        LEFT JOIN review_replies rr ON r.review_id = rr.review_id
        WHERE p.landlord_id = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->bind_param("i", $landlordId);
    $stmt->execute();
    $res = $stmt->get_result();
    $reviews = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    echo json_encode([
        'success' => true,
        'message' => 'Property reviews retrieved successfully.',
        'data'    => $reviews
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred while fetching property reviews.'
    ]);
    exit;
}

