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
    try {
        // 1. Simple Query: Fetch conversation IDs where landlord is participant
        $stmtConvs = $conn->prepare("
            SELECT c.conversation_id, c.property_id, c.last_message_at, c.created_at
            FROM conversation_participants cp
            JOIN conversations c ON cp.conversation_id = c.conversation_id
            WHERE cp.user_id = ? AND (cp.is_archived = 0 OR cp.is_archived IS NULL)
            ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
        ");
        $stmtConvs->bind_param("i", $landlordId);
        $stmtConvs->execute();
        $resConvs = $stmtConvs->get_result();
        $conversations = $resConvs ? $resConvs->fetch_all(MYSQLI_ASSOC) : [];

        $resultList = [];
        foreach ($conversations as $conv) {
            $cid = (int)$conv['conversation_id'];

            // 2. Simple Query: Get other participant (student)
            $stmtOther = $conn->prepare("
                SELECT u.user_id, u.full_name, u.email, u.phone, u.avatar_url
                FROM conversation_participants cp
                JOIN users u ON cp.user_id = u.user_id
                WHERE cp.conversation_id = ? AND cp.user_id != ?
                LIMIT 1
            ");
            $stmtOther->bind_param("ii", $cid, $landlordId);
            $stmtOther->execute();
            $resOther = $stmtOther->get_result();
            $student = $resOther ? $resOther->fetch_assoc() : null;

            // 3. Simple Query: Get property title if property_id set
            $propTitle = '';
            if (!empty($conv['property_id'])) {
                $pid = (int)$conv['property_id'];
                $stmtP = $conn->prepare("SELECT title FROM properties WHERE property_id = ? LIMIT 1");
                $stmtP->bind_param("i", $pid);
                $stmtP->execute();
                $resP = $stmtP->get_result();
                $pRow = $resP ? $resP->fetch_assoc() : null;
                if ($pRow) $propTitle = $pRow['title'];
            }

            // 4. Simple Query: Get latest message
            $stmtMsg = $conn->prepare("
                SELECT message_id, sender_id, message_text, sent_at
                FROM messages
                WHERE conversation_id = ? AND deleted_at IS NULL
                ORDER BY sent_at DESC
                LIMIT 1
            ");
            $stmtMsg->bind_param("i", $cid);
            $stmtMsg->execute();
            $resMsg = $stmtMsg->get_result();
            $lastMsg = $resMsg ? $resMsg->fetch_assoc() : null;

            $resultList[] = [
                'conversation_id' => $cid,
                'property_id'     => $conv['property_id'],
                'property_title'  => $propTitle,
                'created_at'      => $conv['created_at'],
                'last_message_at' => $conv['last_message_at'],
                'student'         => $student ?: null,
                'last_message'    => $lastMsg ?: null
            ];
        }

        echo json_encode([
            'success'         => true,
            'message'         => 'Conversations retrieved successfully.',
            'current_user_id' => $landlordId,
            'data'            => $resultList
        ]);
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error retrieving conversations.'
        ]);
        exit;
    }

} elseif ($method === 'POST') {
    // Open/start conversation with student
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $studentId  = isset($data['student_id']) ? (int)$data['student_id'] : (isset($data['user_id']) ? (int)$data['user_id'] : 0);
    $tenantName = isset($data['tenant']) ? trim($data['tenant']) : (isset($data['tenant_name']) ? trim($data['tenant_name']) : '');
    $propertyId = isset($data['property_id']) ? (int)$data['property_id'] : null;

    if ($studentId <= 0 && !empty($tenantName)) {
        // Look up student by name
        $nameSearch = '%' . $tenantName . '%';
        $stmtName = $conn->prepare("SELECT user_id FROM users WHERE full_name LIKE ? LIMIT 1");
        $stmtName->bind_param("s", $nameSearch);
        $stmtName->execute();
        $resName = $stmtName->get_result();
        $uRow = $resName ? $resName->fetch_assoc() : null;
        if ($uRow) {
            $studentId = (int)$uRow['user_id'];
        }
    }

    if ($studentId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Valid student ID or tenant name is required.'
        ]);
        exit;
    }

    try {
        // Resolve and validate the requested student account.
        $stmtStudent = $conn->prepare("
            SELECT u.user_id
            FROM users u
            JOIN roles r ON r.role_id = u.role_id
            WHERE u.user_id = ? AND r.role_name = 'student'
            LIMIT 1
        ");
        $stmtStudent->bind_param("i", $studentId);
        $stmtStudent->execute();
        $validStudent = $stmtStudent->get_result()->fetch_assoc();
        $stmtStudent->close();
        if (!$validStudent) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'The selected user is not a valid student.'
            ]);
            exit;
        }

        // When a property is supplied, it must belong to this landlord.
        if ($propertyId !== null) {
            $stmtProperty = $conn->prepare("
                SELECT property_id
                FROM properties
                WHERE property_id = ? AND landlord_id = ?
                LIMIT 1
            ");
            $stmtProperty->bind_param("ii", $propertyId, $landlordId);
            $stmtProperty->execute();
            $validProperty = $stmtProperty->get_result()->fetch_assoc();
            $stmtProperty->close();
            if (!$validProperty) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'The selected property does not belong to this landlord.'
                ]);
                exit;
            }
        }

        // Check for existing conversation between landlord and student
        if ($propertyId !== null) {
            $stmtCheck = $conn->prepare("
                SELECT c.conversation_id
                FROM conversations c
                JOIN conversation_participants cp1 ON cp1.conversation_id = c.conversation_id
                    AND cp1.user_id = ?
                JOIN conversation_participants cp2 ON cp2.conversation_id = c.conversation_id
                    AND cp2.user_id = ?
                WHERE c.property_id = ?
                ORDER BY c.created_at DESC
                LIMIT 1
            ");
            $stmtCheck->bind_param("iii", $landlordId, $studentId, $propertyId);
        } else {
            $stmtCheck = $conn->prepare("
                SELECT c.conversation_id
                FROM conversations c
                JOIN conversation_participants cp1 ON cp1.conversation_id = c.conversation_id
                    AND cp1.user_id = ?
                JOIN conversation_participants cp2 ON cp2.conversation_id = c.conversation_id
                    AND cp2.user_id = ?
                ORDER BY c.created_at DESC
                LIMIT 1
            ");
            $stmtCheck->bind_param("ii", $landlordId, $studentId);
        }
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();
        $existing = $resCheck ? $resCheck->fetch_assoc() : null;

        if ($existing) {
            echo json_encode([
                'success' => true,
                'message' => 'Existing conversation found.',
                'data'    => [
                    'conversation_id' => (int)$existing['conversation_id']
                ]
            ]);
            exit;
        }

        // Create new conversation
        $conn->begin_transaction();

        if ($propertyId !== null) {
            $stmtInsC = $conn->prepare("INSERT INTO conversations (property_id, created_at, last_message_at) VALUES (?, NOW(), NOW())");
            $stmtInsC->bind_param("i", $propertyId);
        } else {
            $stmtInsC = $conn->prepare("INSERT INTO conversations (property_id, created_at, last_message_at) VALUES (NULL, NOW(), NOW())");
        }
        $stmtInsC->execute();
        $newCid = (int)$conn->insert_id;

        $stmtInsP = $conn->prepare("INSERT INTO conversation_participants (conversation_id, user_id, joined_at) VALUES (?, ?, NOW())");
        $stmtInsP->bind_param("ii", $newCid, $landlordId);
        $stmtInsP->execute();
        $stmtInsP->bind_param("ii", $newCid, $studentId);
        $stmtInsP->execute();

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Conversation created successfully.',
            'data'    => [
                'conversation_id' => $newCid
            ]
        ]);
        exit;

    } catch (Throwable $e) {
        @$conn->rollback();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error creating conversation.'
        ]);
        exit;
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}
