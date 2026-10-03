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

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

try {
    // Simple Query 1: Fetch properties owned by logged-in landlord
    $stmtProps = $conn->prepare("
        SELECT p.property_id, p.landlord_id, p.category_id, p.title, p.description, 
               p.neighborhood, p.full_address, p.city, p.distance_from_uiu_km, p.floor_label, 
               p.bedroom_count, p.bathroom_count, p.area_sqft, p.monthly_rent, p.security_deposit, 
               p.status, p.verified, p.created_at, c.category_name
        FROM properties p
        LEFT JOIN property_categories c ON p.category_id = c.category_id
        WHERE p.landlord_id = ?
        ORDER BY p.created_at DESC
    ");
    $stmtProps->bind_param("i", $landlordId);
    $stmtProps->execute();
    $resProps = $stmtProps->get_result();
    $properties = $resProps ? $resProps->fetch_all(MYSQLI_ASSOC) : [];

    // Attach primary image and facility names for each property using small simple queries
    foreach ($properties as &$prop) {
        $pid = (int)$prop['property_id'];

        // Simple Query 2a: Get main image
        $stmtImg = $conn->prepare("
            SELECT image_url 
            FROM property_images 
            WHERE property_id = ? 
            ORDER BY is_primary DESC, image_id ASC 
            LIMIT 1
        ");
        $stmtImg->bind_param("i", $pid);
        $stmtImg->execute();
        $resImg = $stmtImg->get_result();
        $img = $resImg ? $resImg->fetch_assoc() : null;
        $prop['image_url'] = $img ? $img['image_url'] : null;

        // Simple Query 2b: Get facilities
        $stmtFac = $conn->prepare("
            SELECT f.facility_id, f.facility_name
            FROM property_facilities pf
            JOIN facilities f ON pf.facility_id = f.facility_id
            WHERE pf.property_id = ?
        ");
        $stmtFac->bind_param("i", $pid);
        $stmtFac->execute();
        $resFac = $stmtFac->get_result();
        $prop['facilities'] = $resFac ? $resFac->fetch_all(MYSQLI_ASSOC) : [];
    }
    unset($prop);

    echo json_encode([
        'success' => true,
        'message' => 'Landlord properties retrieved successfully.',
        'count'   => count($properties),
        'data'    => $properties
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error fetching properties.'
    ]);
    exit;
}

