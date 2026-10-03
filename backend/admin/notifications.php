<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Require logged-in admin role
requireRole('admin');

// Derive identity from session
$adminId = getCurrentUserId();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    try {
        // Fetch notifications for the admin
        $stmtList = $conn->prepare("
            SELECT 
                notification_id, notification_type, title, message, 
                reference_type, reference_id, is_read, created_at, read_at
            FROM notifications
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $stmtList->bind_param("i", $adminId);
        $stmtList->execute();
        $notifications = $stmtList->get_result()->fetch_all(MYSQLI_ASSOC);

        // Calculate unread count
        $stmtCount = $conn->prepare("
            SELECT COUNT(*) 
            FROM notifications 
            WHERE user_id = ? AND is_read = 0
        ");
        $stmtCount->bind_param("i", $adminId);
        $stmtCount->execute();
        $row = $stmtCount->get_result()->fetch_row();
        $unreadCount = (int)($row[0] ?? 0);

        echo json_encode([
            'success' => true,
            'message' => 'Admin notifications retrieved successfully.',
            'count'   => count($notifications),
            'data'    => [
                'notifications' => $notifications,
                'unread_count'  => $unreadCount
            ]
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error retrieving admin notifications.'
        ]);
        exit;
    }
}

if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $action = isset($data['action']) ? trim($data['action']) : '';
    $notifId = isset($data['notification_id']) ? $data['notification_id'] : null;

    try {
        if ($action === 'mark_read' || $notifId !== null) {
            if ($notifId === null || !filter_var($notifId, FILTER_VALIDATE_INT) || (int)$notifId <= 0) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid notification ID.'
                ]);
                exit;
            }

            $notifId = (int)$notifId;

            // Verify notification belongs to the admin
            $stmtCheck = $conn->prepare("
                SELECT notification_id 
                FROM notifications 
                WHERE notification_id = ? AND user_id = ? 
                LIMIT 1
            ");
            $stmtCheck->bind_param("ii", $notifId, $adminId);
            $stmtCheck->execute();

            if (!$stmtCheck->get_result()->fetch_assoc()) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'Access denied. Notification does not belong to you.'
                ]);
                exit;
            }

            // Mark as read
            $stmtUpdate = $conn->prepare("
                UPDATE notifications 
                SET is_read = 1, read_at = NOW() 
                WHERE notification_id = ? AND user_id = ?
            ");
            $stmtUpdate->bind_param("ii", $notifId, $adminId);
            $stmtUpdate->execute();

            echo json_encode([
                'success' => true,
                'message' => 'Notification marked as read.'
            ]);
            exit;
        }

        if ($action === 'mark_all_read') {
            $stmtUpdateAll = $conn->prepare("
                UPDATE notifications 
                SET is_read = 1, read_at = NOW() 
                WHERE user_id = ? AND is_read = 0
            ");
            $stmtUpdateAll->bind_param("i", $adminId);
            $stmtUpdateAll->execute();

            echo json_encode([
                'success' => true,
                'message' => 'All notifications marked as read.'
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid notification action.'
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error updating notifications.'
        ]);
        exit;
    }
}

// Any other method
http_response_code(405);
echo json_encode([
    'success' => false,
    'message' => 'Invalid request method.'
]);
exit;
