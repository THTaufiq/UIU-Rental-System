<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Require logged-in landlord role (role_id = 2)
requireRole('landlord');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    try {
        $stmt = $conn->prepare("SELECT category_id, category_name, description, is_active FROM property_categories WHERE is_active = 1 ORDER BY category_name ASC");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->execute();
        $res = $stmt->get_result();

        $categories = [];
        while ($row = $res->fetch_assoc()) {
            $categories[] = [
                'category_id'   => (int)$row['category_id'],
                'category_name' => $row['category_name'],
                'description'   => $row['description'],
                'is_active'     => (int)$row['is_active']
            ];
        }
        $stmt->close();

        echo json_encode([
            'success' => true,
            'message' => 'Property categories retrieved successfully.',
            'count'   => count($categories),
            'data'    => $categories
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error retrieving categories.'
        ]);
        exit;
    }
}

http_response_code(405);
echo json_encode([
    'success' => false,
    'message' => 'Method not allowed.'
]);
exit;
