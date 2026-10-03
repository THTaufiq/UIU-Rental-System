<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Require logged-in student role
requireRole('student');

try {
    $res = $conn->query("
        SELECT category_id, category_name 
        FROM maintenance_categories 
        ORDER BY category_id ASC
    ");
    $categories = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    echo json_encode([
        'success' => true,
        'message' => 'Maintenance categories retrieved successfully.',
        'data'    => $categories
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error retrieving maintenance categories.'
    ]);
    exit;
}
