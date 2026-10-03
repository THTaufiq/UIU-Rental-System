<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Require logged-in landlord role (role_id = 2)
requireRole('landlord');

$landlordId = getCurrentUserId();
$method = $_SERVER['REQUEST_METHOD'] ?? 'POST';

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$title          = isset($data['title']) ? trim($data['title']) : '';
$description    = isset($data['description']) ? trim($data['description']) : '';
$neighborhood   = isset($data['neighborhood']) ? trim($data['neighborhood']) : '';
$fullAddress    = isset($data['full_address']) ? trim($data['full_address']) : '';
$city           = isset($data['city']) ? trim($data['city']) : 'Dhaka';
$monthlyRent    = isset($data['monthly_rent']) ? (float)$data['monthly_rent'] : 0.0;
$securityDeposit= isset($data['security_deposit']) ? (float)$data['security_deposit'] : 0.0;
$distanceKm     = isset($data['distance_from_uiu_km']) ? (float)$data['distance_from_uiu_km'] : 1.5;
$floorLabel     = isset($data['floor_label']) ? trim($data['floor_label']) : '2nd Floor';
$bedroomCount   = isset($data['bedroom_count']) ? (float)$data['bedroom_count'] : 1.0;
$bathroomCount  = isset($data['bathroom_count']) ? (float)$data['bathroom_count'] : 1.0;
$areaSqft       = isset($data['area_sqft']) ? (float)$data['area_sqft'] : 500.0;
$categoryInput  = isset($data['category_id']) ? $data['category_id'] : (isset($data['property_type']) ? $data['property_type'] : 1);
$facilityIds    = isset($data['facility_ids']) && is_array($data['facility_ids']) ? $data['facility_ids'] : [];
if (is_string($facilityIds)) {
    $decodedFacilityIds = json_decode($facilityIds, true);
    $facilityIds = is_array($decodedFacilityIds) ? $decodedFacilityIds : [];
}

// Required validations
if (empty($title) || empty($description) || empty($neighborhood) || empty($fullAddress) || $monthlyRent <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Title, description, neighborhood, full address, and valid monthly rent are required.'
    ]);
    exit;
}

try {
    $transactionStarted = false;
    // 1. Resolve category_id if name was passed
    $categoryId = 1;
    if (is_numeric($categoryInput)) {
        $categoryId = (int)$categoryInput;
    } else {
        $cName = trim($categoryInput);
        $stmtCat = $conn->prepare("SELECT category_id FROM property_categories WHERE LOWER(category_name) = LOWER(?) LIMIT 1");
        $stmtCat->bind_param("s", $cName);
        $stmtCat->execute();
        $resCat = $stmtCat->get_result();
        $cat = $resCat ? $resCat->fetch_assoc() : null;
        if ($cat) {
            $categoryId = (int)$cat['category_id'];
        }
    }

    $conn->begin_transaction();
    $transactionStarted = true;

    // 2. Simple Query: Insert Property into `properties` table
    $stmtIns = $conn->prepare("
        INSERT INTO properties (
            landlord_id, category_id, title, description, neighborhood, full_address, city, 
            distance_from_uiu_km, floor_label, bedroom_count, bathroom_count, area_sqft, 
            monthly_rent, security_deposit, status, verified, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, 
            ?, ?, ?, ?, ?, 
            ?, ?, 'pending', 0, NOW()
        )
    ");
    $stmtIns->bind_param(
        "iisssssdsddddd",
        $landlordId,
        $categoryId,
        $title,
        $description,
        $neighborhood,
        $fullAddress,
        $city,
        $distanceKm,
        $floorLabel,
        $bedroomCount,
        $bathroomCount,
        $areaSqft,
        $monthlyRent,
        $securityDeposit
    );
    $stmtIns->execute();

    $propertyId = (int)$conn->insert_id;

    // 3. Simple Queries: Insert Facilities if supplied
    if (!empty($facilityIds)) {
        $stmtFac = $conn->prepare("INSERT IGNORE INTO property_facilities (property_id, facility_id) VALUES (?, ?)");
        foreach ($facilityIds as $fid) {
            $fidInt = (int)$fid;
            if ($fidInt > 0) {
                $stmtFac->bind_param("ii", $propertyId, $fidInt);
                $stmtFac->execute();
            }
        }
    }

    $conn->commit();
    $transactionStarted = false;
    echo json_encode([
        'success' => true,
        'message' => 'Property added successfully!',
        'data'    => [
            'property_id' => $propertyId,
            'title'       => $title,
            'status'      => 'pending'
        ]
    ]);
    exit;

} catch (Throwable $e) {
    if (!empty($transactionStarted)) {
        $conn->rollback();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error adding property.'
    ]);
    exit;
}
