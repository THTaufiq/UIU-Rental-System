<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Require logged-in landlord role (role_id = 2)
requireRole('landlord');

$landlordId = getCurrentUserId();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    try {
        // Simple Query 1: Fetch landlord notifications
        $stmtList = $conn->prepare("
            SELECT notification_id, notification_type, title, message, reference_type, 
                   reference_id, is_read, created_at, read_at 
            FROM notifications 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT 50
        ");
        $stmtList->bind_param("i", $landlordId);
        $stmtList->execute();
        $resList = $stmtList->get_result();
        $notifications = $resList ? $resList->fetch_all(MYSQLI_ASSOC) : [];

        // Simple Query 2: Fetch unread notification count
        $stmtCount = $conn->prepare("
            SELECT COUNT(*) AS unread_count 
            FROM notifications 
            WHERE user_id = ? AND is_read = 0
        ");
        $stmtCount->bind_param("i", $landlordId);
        $stmtCount->execute();
        $resCount = $stmtCount->get_result();
        $rowC = $resCount ? $resCount->fetch_assoc() : null;
        $unreadCount = $rowC ? (int)$rowC['unread_count'] : 0;

        echo json_encode([
            'success'      => true,
            'message'      => 'Notifications retrieved successfully.',
            'unread_count' => $unreadCount,
            'data'         => $notifications
        ]);
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error fetching notifications.'
        ]);
        exit;
    }

} elseif ($method === 'POST' || $method === 'PUT') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $notificationId = isset($data['notification_id']) ? (int)$data['notification_id'] : 0;
    $markAll        = isset($data['mark_all']) ? (bool)$data['mark_all'] : (isset($data['action']) && $data['action'] === 'mark_all_read');

    try {
        if ($markAll) {
            // Simple Query: Mark all landlord notifications as read
            $stmtUpdAll = $conn->prepare("
                UPDATE notifications 
                SET is_read = 1, read_at = NOW() 
                WHERE user_id = ? AND is_read = 0
            ");
            $stmtUpdAll->bind_param("i", $landlordId);
            $stmtUpdAll->execute();

            echo json_encode([
                'success' => true,
                'message' => 'All notifications marked as read.'
            ]);
            exit;

        } elseif ($notificationId > 0) {
            // Simple Query: Mark single notification read (with strict ownership check)
            $stmtUpdOne = $conn->prepare("
                UPDATE notifications 
                SET is_read = 1, read_at = NOW() 
                WHERE notification_id = ? AND user_id = ?
            ");
            $stmtUpdOne->bind_param("ii", $notificationId, $landlordId);
            $stmtUpdOne->execute();

            if ($stmtUpdOne->affected_rows === 0) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Notification not found or access denied.'
                ]);
                exit;
            }

            echo json_encode([
                'success' => true,
                'message' => 'Notification marked as read.'
            ]);
            exit;

        } else {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid notification ID or action.'
            ]);
            exit;
        }

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error updating notification.'
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

