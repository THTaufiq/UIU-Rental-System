<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Only allow GET request
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
    exit;
}

// Require logged-in admin role
requireRole('admin');

$propertyId = isset($_GET['property_id']) ? $_GET['property_id'] : (isset($_GET['id']) ? $_GET['id'] : null);

if ($propertyId === null || !filter_var($propertyId, FILTER_VALIDATE_INT) || (int)$propertyId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid property ID.'
    ]);
    exit;
}

$propertyId = (int)$propertyId;

try {
    // 1. Fetch main property record using MySQLi
    $stmtProp = $conn->prepare("
        SELECT 
            property_id, landlord_id, category_id, title, description, 
            neighborhood, full_address, city, latitude, longitude, 
            distance_from_uiu_km, floor_label, bedroom_count, bathroom_count, 
            area_sqft, monthly_rent, security_deposit, service_charge, 
            minimum_stay_months, available_from, house_rules, status, 
            verified, featured, total_views, created_at, updated_at
        FROM properties
        WHERE property_id = ?
        LIMIT 1
    ");
    $stmtProp->bind_param("i", $propertyId);
    $stmtProp->execute();
    $resProp = $stmtProp->get_result();
    $property = $resProp->fetch_assoc();
    $stmtProp->close();

    if (!$property) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Property not found.'
        ]);
        exit;
    }

    $landlordId = (int)$property['landlord_id'];
    $categoryId = (int)$property['category_id'];

    // 2. Fetch Landlord basic details
    $landlord = null;
    $stmtLand = $conn->prepare("
        SELECT user_id, full_name, email, phone, avatar_url, account_status, created_at
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");
    if ($stmtLand) {
        $stmtLand->bind_param("i", $landlordId);
        $stmtLand->execute();
        $resLand = $stmtLand->get_result();
        $landlord = $resLand->fetch_assoc();
        $stmtLand->close();
    }

    // 3. Fetch Category details
    $category = null;
    $stmtCat = $conn->prepare("
        SELECT category_id, category_name, description
        FROM property_categories
        WHERE category_id = ?
        LIMIT 1
    ");
    if ($stmtCat) {
        $stmtCat->bind_param("i", $categoryId);
        $stmtCat->execute();
        $resCat = $stmtCat->get_result();
        $category = $resCat->fetch_assoc();
        $stmtCat->close();
    }

    // 4. Fetch Property Images
    $images = [];
    $stmtImg = $conn->prepare("
        SELECT image_id, image_url, is_primary, sort_order, created_at
        FROM property_images
        WHERE property_id = ?
        ORDER BY is_primary DESC, sort_order ASC
    ");
    if ($stmtImg) {
        $stmtImg->bind_param("i", $propertyId);
        $stmtImg->execute();
        $resImg = $stmtImg->get_result();
        while ($imgRow = $resImg->fetch_assoc()) {
            $images[] = [
                'image_id'   => (int)$imgRow['image_id'],
                'image_url'  => $imgRow['image_url'],
                'is_primary' => (bool)$imgRow['is_primary'],
                'sort_order' => (int)$imgRow['sort_order']
            ];
        }
        $stmtImg->close();
    }

    // 5. Fetch Property Facilities
    $facilities = [];
    $stmtFac = $conn->prepare("
        SELECT pf.facility_id, f.facility_name
        FROM property_facilities pf
        JOIN facilities f ON pf.facility_id = f.facility_id
        WHERE pf.property_id = ?
    ");
    if ($stmtFac) {
        $stmtFac->bind_param("i", $propertyId);
        $stmtFac->execute();
        $resFac = $stmtFac->get_result();
        while ($facRow = $resFac->fetch_assoc()) {
            $facilities[] = [
                'facility_id'   => (int)$facRow['facility_id'],
                'facility_name' => $facRow['facility_name']
            ];
        }
        $stmtFac->close();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Property details retrieved successfully.',
        'data'    => [
            'property'   => $property,
            'landlord'   => $landlord,
            'category'   => $category,
            'images'     => $images,
            'facilities' => $facilities
        ]
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error retrieving property details.'
    ]);
    exit;
}
