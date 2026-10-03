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

$reviewId = isset($data['review_id']) ? $data['review_id'] : (isset($data['id']) ? $data['id'] : null);
$replyText = isset($data['reply_text']) ? trim($data['reply_text']) : (isset($data['landlord_reply']) ? trim($data['landlord_reply']) : (isset($data['reply']) ? trim($data['reply']) : ''));

if ($reviewId === null || !filter_var($reviewId, FILTER_VALIDATE_INT) || (int)$reviewId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid review ID.'
    ]);
    exit;
}

$reviewId = (int)$reviewId;

if (empty($replyText)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Reply text cannot be empty.'
    ]);
    exit;
}

try {
    // 1. Simple Query: Verify review belongs to a property owned by logged-in landlord
    $stmtCheck = $conn->prepare("
        SELECT r.review_id, p.landlord_id
        FROM reviews r
        JOIN properties p ON r.property_id = p.property_id
        WHERE r.review_id = ?
        LIMIT 1
    ");
    $stmtCheck->bind_param("i", $reviewId);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();
    $review = $resCheck ? $resCheck->fetch_assoc() : null;

    if (!$review) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Review not found.'
        ]);
        exit;
    }

    if ((int)$review['landlord_id'] !== (int)$landlordId) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Access denied. You do not own the property for this review.'
        ]);
        exit;
    }

    $conn->begin_transaction();

    // 2. Update reviews table
    $stmtUpdRev = $conn->prepare("
        UPDATE reviews 
        SET landlord_reply = ?, replied_at = NOW(), updated_at = NOW()
        WHERE review_id = ?
    ");
    $stmtUpdRev->bind_param("si", $replyText, $reviewId);
    $stmtUpdRev->execute();

    // 3. Insert into review_replies table
    $stmtInsRep = $conn->prepare("
        INSERT INTO review_replies (review_id, landlord_id, reply_text, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmtInsRep->bind_param("iis", $reviewId, $landlordId, $replyText);
    $stmtInsRep->execute();

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Reply submitted successfully.',
        'data'    => [
            'review_id'  => $reviewId,
            'reply_text' => $replyText
        ]
    ]);
    exit;

} catch (Throwable $e) {
    @$conn->rollback();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error submitting review reply.'
    ]);
    exit;
}

