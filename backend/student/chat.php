<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Require logged-in student role
requireRole('student');

$studentId = getCurrentUserId();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $conversationId = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : 0;

    if ($conversationId > 0) {
        // Fetch specific conversation messages
        try {
            // 1. Verify student participation
            $stmtCheck = $conn->prepare("
                SELECT 1 
                FROM conversation_participants 
                WHERE conversation_id = ? AND user_id = ?
                LIMIT 1
            ");
            if (!$stmtCheck) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmtCheck->bind_param("ii", $conversationId, $studentId);
            $stmtCheck->execute();
            $resCheck = $stmtCheck->get_result();
            if (!$resCheck->fetch_assoc()) {
                $stmtCheck->close();
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'Access denied to conversation.'
                ]);
                exit;
            }
            $stmtCheck->close();

            // 2. Fetch other participant details
            $stmtOther = $conn->prepare("
                SELECT u.user_id, u.full_name, u.email, u.phone, r.role_name, p.title AS property_title
                FROM conversation_participants cp
                JOIN users u ON cp.user_id = u.user_id
                JOIN roles r ON u.role_id = r.role_id
                JOIN conversations c ON cp.conversation_id = c.conversation_id
                LEFT JOIN properties p ON c.property_id = p.property_id
                WHERE cp.conversation_id = ? AND cp.user_id != ?
                LIMIT 1
            ");
            if (!$stmtOther) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmtOther->bind_param("ii", $conversationId, $studentId);
            $stmtOther->execute();
            $resOther = $stmtOther->get_result();
            $otherParticipant = $resOther->fetch_assoc();
            $stmtOther->close();

            // 3. Fetch messages chronologically
            $stmtMessages = $conn->prepare("
                SELECT 
                    m.message_id, m.conversation_id, m.sender_id, m.message_text, 
                    m.attachment_path, m.sent_at, m.read_at,
                    u.full_name AS sender_name
                FROM messages m
                JOIN users u ON m.sender_id = u.user_id
                WHERE m.conversation_id = ? AND m.deleted_at IS NULL
                ORDER BY m.sent_at ASC
            ");
            if (!$stmtMessages) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmtMessages->bind_param("i", $conversationId);
            $stmtMessages->execute();
            $resMessages = $stmtMessages->get_result();
            $messages = $resMessages->fetch_all(MYSQLI_ASSOC);
            $stmtMessages->close();

            echo json_encode([
                'success' => true,
                'message' => 'Messages retrieved successfully.',
                'data'    => [
                    'conversation_id' => $conversationId,
                    'other_user'      => $otherParticipant ?: null,
                    'messages'        => $messages
                ]
            ]);
            exit;

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Database error retrieving messages.'
            ]);
            exit;
        }
    } else {
        // Fetch conversation list for logged-in student
        try {
            $stmtList = $conn->prepare("
                SELECT 
                    c.conversation_id, c.property_id, c.last_message_at, c.created_at,
                    p.title AS property_title, p.neighborhood,
                    COALESCE(other.user_id, 0) AS other_user_id, 
                    COALESCE(other.full_name, 'Landlord') AS other_user_name, 
                    COALESCE(other.email, '') AS other_user_email, 
                    COALESCE(r.role_name, 'landlord') AS other_user_role,
                    (
                        SELECT message_text FROM messages 
                        WHERE conversation_id = c.conversation_id AND deleted_at IS NULL 
                        ORDER BY sent_at DESC LIMIT 1
                    ) AS last_message
                FROM conversations c
                JOIN conversation_participants cp_me ON c.conversation_id = cp_me.conversation_id AND cp_me.user_id = ?
                LEFT JOIN conversation_participants cp_other ON c.conversation_id = cp_other.conversation_id AND cp_other.user_id != ?
                LEFT JOIN users other ON cp_other.user_id = other.user_id
                LEFT JOIN roles r ON other.role_id = r.role_id
                LEFT JOIN properties p ON c.property_id = p.property_id
                GROUP BY c.conversation_id
                ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
            ");
            if (!$stmtList) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmtList->bind_param("ii", $studentId, $studentId);
            $stmtList->execute();
            $resList = $stmtList->get_result();
            $conversations = $resList->fetch_all(MYSQLI_ASSOC);
            $stmtList->close();

            echo json_encode([
                'success' => true,
                'message' => 'Conversations retrieved successfully.',
                'data'    => $conversations
            ]);
            exit;

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Database error retrieving conversation list.'
            ]);
            exit;
        }
    }

} elseif ($method === 'POST') {
    // Process message creation / conversation creation
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $conversationId = isset($data['conversation_id']) ? (int)$data['conversation_id'] : 0;
    $propertyId     = isset($data['property_id']) ? (int)$data['property_id'] : null;
    $landlordId     = isset($data['landlord_id']) ? (int)$data['landlord_id'] : null;
    $messageText    = isset($data['message_text']) ? trim($data['message_text']) : (isset($data['message']) ? trim($data['message']) : '');

    if (empty($messageText)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Message text cannot be empty.'
        ]);
        exit;
    }

    try {
        if ($conversationId > 0) {
            // Verify student participation
            $stmtCheck = $conn->prepare("
                SELECT 1 
                FROM conversation_participants 
                WHERE conversation_id = ? AND user_id = ?
                LIMIT 1
            ");
            if (!$stmtCheck) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmtCheck->bind_param("ii", $conversationId, $studentId);
            $stmtCheck->execute();
            $resCheck = $stmtCheck->get_result();
            if (!$resCheck->fetch_assoc()) {
                $stmtCheck->close();
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'Access denied to conversation.'
                ]);
                exit;
            }
            $stmtCheck->close();
        } else {
            // Find target landlord if property_id provided but landlord_id missing
            if ($propertyId) {
                $stmtProp = $conn->prepare("
                    SELECT p.landlord_id
                    FROM properties p
                    JOIN users u ON u.user_id = p.landlord_id
                    JOIN roles r ON r.role_id = u.role_id
                    WHERE p.property_id = ? AND r.role_name = 'landlord'
                    LIMIT 1
                ");
                if ($stmtProp) {
                    $stmtProp->bind_param("i", $propertyId);
                    $stmtProp->execute();
                    $resProp = $stmtProp->get_result();
                    $prop = $resProp->fetch_assoc();
                    if ($prop) {
                        $landlordId = (int)$prop['landlord_id'];
                    }
                    $stmtProp->close();
                }
                if (!$landlordId) {
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'message' => 'The selected property has no valid landlord.'
                    ]);
                    exit;
                }
            }

            // A direct-chat landlord target must be a landlord account.
            if (!$propertyId && $landlordId) {
                $stmtLandlord = $conn->prepare("
                    SELECT u.user_id
                    FROM users u
                    JOIN roles r ON r.role_id = u.role_id
                    WHERE u.user_id = ? AND r.role_name = 'landlord'
                    LIMIT 1
                ");
                if ($stmtLandlord) {
                    $stmtLandlord->bind_param("i", $landlordId);
                    $stmtLandlord->execute();
                    $validLandlord = $stmtLandlord->get_result()->fetch_assoc();
                    $stmtLandlord->close();
                    if (!$validLandlord) {
                        $landlordId = null;
                    }
                }
            }

            // Fallback: search active lease/application if landlordId still missing
            if (!$landlordId) {
                $stmtActive = $conn->prepare("
                    SELECT landlord_id, property_id 
                    FROM rental_agreements 
                    WHERE student_id = ? AND status = 'active' 
                    LIMIT 1
                ");
                if ($stmtActive) {
                    $stmtActive->bind_param("i", $studentId);
                    $stmtActive->execute();
                    $resActive = $stmtActive->get_result();
                    $lease = $resActive->fetch_assoc();
                    if ($lease) {
                        $landlordId = (int)$lease['landlord_id'];
                        $propertyId = (int)$lease['property_id'];
                    }
                    $stmtActive->close();
                }
            }

            if (!$landlordId) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Landlord target required to start a new chat.'
                ]);
                exit;
            }

            // Check if existing conversation exists between student and landlord.
            if ($propertyId !== null) {
                $stmtFindC = $conn->prepare("
                    SELECT c.conversation_id
                    FROM conversations c
                    JOIN conversation_participants cp1 ON c.conversation_id = cp1.conversation_id AND cp1.user_id = ?
                    JOIN conversation_participants cp2 ON c.conversation_id = cp2.conversation_id AND cp2.user_id = ?
                    WHERE c.property_id = ?
                    ORDER BY c.created_at DESC
                    LIMIT 1
                ");
                $stmtFindC->bind_param("iii", $studentId, $landlordId, $propertyId);
            } else {
                $stmtFindC = $conn->prepare("
                    SELECT c.conversation_id
                    FROM conversations c
                    JOIN conversation_participants cp1 ON c.conversation_id = cp1.conversation_id AND cp1.user_id = ?
                    JOIN conversation_participants cp2 ON c.conversation_id = cp2.conversation_id AND cp2.user_id = ?
                    ORDER BY c.created_at DESC
                    LIMIT 1
                ");
                $stmtFindC->bind_param("ii", $studentId, $landlordId);
            }
            $stmtFindC->execute();
            $resFindC = $stmtFindC->get_result();
            $existingC = $resFindC->fetch_assoc();
            $stmtFindC->close();

            if ($existingC) {
                $conversationId = (int)$existingC['conversation_id'];
            } else {
                $conn->begin_transaction();
                try {
                    // Use a literal NULL for direct conversations; binding null as an integer
                    // can otherwise become property_id = 0 under MySQLi.
                    if ($propertyId !== null) {
                        $stmtNewC = $conn->prepare("
                            INSERT INTO conversations (property_id, created_at, last_message_at)
                            VALUES (?, NOW(), NOW())
                        ");
                        $stmtNewC->bind_param("i", $propertyId);
                    } else {
                        $stmtNewC = $conn->prepare("
                            INSERT INTO conversations (property_id, created_at, last_message_at)
                            VALUES (NULL, NOW(), NOW())
                        ");
                    }
                    $stmtNewC->execute();
                    $conversationId = (int)$conn->insert_id;
                    $stmtNewC->close();

                    // The composite primary key prevents duplicate participant rows.
                    $stmtAddP = $conn->prepare("
                        INSERT INTO conversation_participants (conversation_id, user_id, joined_at)
                        VALUES (?, ?, NOW()), (?, ?, NOW())
                    ");
                    $stmtAddP->bind_param("iiii", $conversationId, $studentId, $conversationId, $landlordId);
                    $stmtAddP->execute();
                    $stmtAddP->close();
                    $conn->commit();
                } catch (Throwable $creationError) {
                    $conn->rollback();
                    throw $creationError;
                }
            }
        }

        // Insert message into `messages`
        $stmtMsg = $conn->prepare("
            INSERT INTO messages (conversation_id, sender_id, message_text, sent_at)
            VALUES (?, ?, ?, NOW())
        ");
        if (!$stmtMsg) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmtMsg->bind_param("iis", $conversationId, $studentId, $messageText);
        $stmtMsg->execute();

        $messageId = (int)$conn->insert_id;
        $stmtMsg->close();

        // Update last_message_at timestamp in `conversations`
        $stmtUpdC = $conn->prepare("
            UPDATE conversations 
            SET last_message_at = NOW() 
            WHERE conversation_id = ?
        ");
        if ($stmtUpdC) {
            $stmtUpdC->bind_param("i", $conversationId);
            $stmtUpdC->execute();
            $stmtUpdC->close();
        }

        echo json_encode([
            'success' => true,
            'message' => 'Message sent successfully.',
            'data'    => [
                'message_id'      => $messageId,
                'conversation_id' => $conversationId,
                'sender_id'       => $studentId,
                'message_text'    => $messageText,
                'sent_at'         => date('Y-m-d H:i:s')
            ]
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error sending message.'
        ]);
        exit;
    }
} else {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}
