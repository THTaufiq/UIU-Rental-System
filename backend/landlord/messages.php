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
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $cid = isset($_GET['conversation_id']) ? $_GET['conversation_id'] : (isset($_GET['id']) ? $_GET['id'] : null);

    if ($cid === null || !filter_var($cid, FILTER_VALIDATE_INT) || (int)$cid <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid conversation ID.'
        ]);
        exit;
    }

    $cid = (int)$cid;

    try {
        // 1. Simple Query: Verify landlord is a participant
        $stmtCheck = $conn->prepare("
            SELECT 1 
            FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ?
            LIMIT 1
        ");
        $stmtCheck->bind_param("ii", $cid, $landlordId);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();

        if (!$resCheck || !$resCheck->fetch_assoc()) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Access denied. You are not a participant in this conversation.'
            ]);
            exit;
        }

        // 2. Simple Query: Fetch messages for this conversation
        $stmtMsgs = $conn->prepare("
            SELECT 
                m.message_id,
                m.conversation_id,
                m.sender_id,
                m.message_text,
                m.attachment_path,
                m.sent_at,
                m.read_at,
                u.full_name AS sender_name
            FROM messages m
            JOIN users u ON m.sender_id = u.user_id
            WHERE m.conversation_id = ? AND m.deleted_at IS NULL
            ORDER BY m.sent_at ASC
        ");
        $stmtMsgs->bind_param("i", $cid);
        $stmtMsgs->execute();
        $resMsgs = $stmtMsgs->get_result();
        $messages = $resMsgs ? $resMsgs->fetch_all(MYSQLI_ASSOC) : [];

        echo json_encode([
            'success' => true,
            'message' => 'Messages retrieved successfully.',
            'data'    => [
                'conversation_id' => $cid,
                'current_user_id' => $landlordId,
                'messages'        => $messages
            ]
        ]);
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error retrieving messages.'
        ]);
        exit;
    }

} elseif ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $cid = isset($data['conversation_id']) ? $data['conversation_id'] : null;
    $messageText = isset($data['message_text']) ? trim($data['message_text']) : (isset($data['message']) ? trim($data['message']) : '');

    if ($cid === null || !filter_var($cid, FILTER_VALIDATE_INT) || (int)$cid <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid conversation ID.'
        ]);
        exit;
    }

    $cid = (int)$cid;

    if (empty($messageText)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Message text cannot be empty.'
        ]);
        exit;
    }

    try {
        // 1. Simple Query: Verify landlord is a participant
        $stmtCheck = $conn->prepare("
            SELECT 1 
            FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ?
            LIMIT 1
        ");
        $stmtCheck->bind_param("ii", $cid, $landlordId);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();

        if (!$resCheck || !$resCheck->fetch_assoc()) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Access denied. You are not a participant in this conversation.'
            ]);
            exit;
        }

        // 2. Simple Query: Insert message into physical `messages` table
        // SENDER IDENTITY MUST COME FROM SESSION ($landlordId)
        $stmtIns = $conn->prepare("
            INSERT INTO messages (conversation_id, sender_id, message_text, sent_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmtIns->bind_param("iis", $cid, $landlordId, $messageText);
        $stmtIns->execute();
        $newMsgId = (int)$conn->insert_id;

        // 3. Simple Query: Update conversations.last_message_at
        $stmtUpdC = $conn->prepare("
            UPDATE conversations 
            SET last_message_at = NOW() 
            WHERE conversation_id = ?
        ");
        $stmtUpdC->bind_param("i", $cid);
        $stmtUpdC->execute();

        echo json_encode([
            'success' => true,
            'message' => 'Message sent successfully.',
            'data'    => [
                'message_id'      => $newMsgId,
                'conversation_id' => $cid,
                'sender_id'       => $landlordId,
                'message_text'    => $messageText
            ]
        ]);
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error sending message.'
        ]);
        exit;
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

