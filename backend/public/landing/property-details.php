<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection
require_once __DIR__ . '/../../common/db.php';

// Extract & validate property ID parameter
$propertyId = isset($_GET['id']) ? $_GET['id'] : (isset($_GET['property_id']) ? $_GET['property_id'] : null);

if ($propertyId === null || !filter_var($propertyId, FILTER_VALIDATE_INT) || (int)$propertyId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid property ID'
    ]);
    exit;
}

$propertyId = (int)$propertyId;

try {
    // Check if request is from admin/landlord preview context or session
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $fromParam = isset($_GET['from']) ? trim(strtolower($_GET['from'])) : '';
    $sessionRole = isset($_SESSION['role']) ? trim(strtolower($_SESSION['role'])) : '';
    $allowAllStatuses = ($fromParam === 'admin' || $fromParam === 'landlord' || $sessionRole === 'admin' || $sessionRole === 'landlord');

    // 1. Fetch Property Details & Category
    if ($allowAllStatuses) {
        $stmtProp = $conn->prepare("
            SELECT 
                p.property_id, p.landlord_id, p.category_id, p.title, p.description, 
                p.neighborhood, p.full_address, p.city, p.distance_from_uiu_km, 
                p.floor_label, p.bedroom_count, p.bathroom_count, p.area_sqft, 
                p.monthly_rent, p.security_deposit, p.service_charge, 
                p.minimum_stay_months, p.available_from, p.house_rules, 
                p.status, p.verified, p.featured, p.created_at,
                c.category_name
            FROM properties p
            JOIN property_categories c ON p.category_id = c.category_id
            WHERE p.property_id = ?
            LIMIT 1
        ");
    } else {
        $stmtProp = $conn->prepare("
            SELECT 
                p.property_id, p.landlord_id, p.category_id, p.title, p.description, 
                p.neighborhood, p.full_address, p.city, p.distance_from_uiu_km, 
                p.floor_label, p.bedroom_count, p.bathroom_count, p.area_sqft, 
                p.monthly_rent, p.security_deposit, p.service_charge, 
                p.minimum_stay_months, p.available_from, p.house_rules, 
                p.status, p.verified, p.featured, p.created_at,
                c.category_name
            FROM properties p
            JOIN property_categories c ON p.category_id = c.category_id
            WHERE p.property_id = ? AND p.status IN ('approved', 'rented')
            LIMIT 1
        ");
    }
    $stmtProp->bind_param("i", $propertyId);
    $stmtProp->execute();
    $property = $stmtProp->get_result()->fetch_assoc();

    if (!$property) {
        echo json_encode([
            'success' => false,
            'message' => 'Property not found or is currently unavailable.'
        ]);
        exit;
    }

    // 2. Fetch Property Images
    $stmtImages = $conn->prepare("
        SELECT image_id, image_url, is_primary, sort_order
        FROM property_images
        WHERE property_id = ?
        ORDER BY is_primary DESC, sort_order ASC
    ");
    $stmtImages->bind_param("i", $propertyId);
    $stmtImages->execute();
    $images = $stmtImages->get_result()->fetch_all(MYSQLI_ASSOC);

    // 3. Fetch Property Facilities
    $stmtFacilities = $conn->prepare("
        SELECT f.facility_name
        FROM property_facilities pf
        JOIN facilities f ON pf.facility_id = f.facility_id
        WHERE pf.property_id = ?
        ORDER BY f.facility_name ASC
    ");
    $stmtFacilities->bind_param("i", $propertyId);
    $stmtFacilities->execute();
    $facilitiesRows = $stmtFacilities->get_result()->fetch_all(MYSQLI_ASSOC);
    $facilities = array_column($facilitiesRows, 'facility_name');

    // 4. Fetch Landlord Public Profile
    $landlordId = (int)$property['landlord_id'];
    $stmtLandlord = $conn->prepare("
        SELECT u.user_id AS landlord_id, u.full_name, u.phone, u.email, lp.member_since
        FROM users u
        LEFT JOIN landlord_profiles lp ON u.user_id = lp.landlord_id
        WHERE u.user_id = ?
        LIMIT 1
    ");
    $stmtLandlord->bind_param("i", $landlordId);
    $stmtLandlord->execute();
    $landlord = $stmtLandlord->get_result()->fetch_assoc();

    // 5. Fetch Property Reviews & Calculate Average Rating
    $stmtReviews = $conn->prepare("
        SELECT 
            r.review_id, r.rating, r.comment, r.created_at,
            u.full_name AS reviewer_name,
            COALESCE(rr.reply_text, r.landlord_reply) AS landlord_reply,
            COALESCE(rr.created_at, r.replied_at) AS replied_at
        FROM reviews r
        JOIN users u ON r.student_id = u.user_id
        LEFT JOIN review_replies rr ON r.review_id = rr.review_id
        WHERE r.property_id = ? AND r.status = 'published'
        ORDER BY r.created_at DESC
    ");
    $stmtReviews->bind_param("i", $propertyId);
    $stmtReviews->execute();
    $reviews = $stmtReviews->get_result()->fetch_all(MYSQLI_ASSOC);

    $reviewCount = count($reviews);
    $avgRating = 4.5;
    if ($reviewCount > 0) {
        $totalSum = array_sum(array_column($reviews, 'rating'));
        $avgRating = round($totalSum / $reviewCount, 1);
    }

    // Response structure
    echo json_encode([
        'success' => true,
        'data' => [
            'property' => [
                'property_id'          => (int)$property['property_id'],
                'landlord_id'          => (int)$property['landlord_id'],
                'title'                => $property['title'],
                'description'          => $property['description'],
                'neighborhood'         => $property['neighborhood'],
                'full_address'         => $property['full_address'],
                'city'                 => $property['city'],
                'distance_from_uiu_km' => (float)$property['distance_from_uiu_km'],
                'floor_label'          => $property['floor_label'],
                'bedroom_count'        => (float)$property['bedroom_count'],
                'bathroom_count'       => (float)$property['bathroom_count'],
                'area_sqft'            => (float)$property['area_sqft'],
                'monthly_rent'         => (float)$property['monthly_rent'],
                'security_deposit'     => (float)$property['security_deposit'],
                'service_charge'       => (float)$property['service_charge'],
                'minimum_stay_months'  => (int)$property['minimum_stay_months'],
                'available_from'       => $property['available_from'],
                'house_rules'          => $property['house_rules'],
                'category_name'        => $property['category_name'],
                'status'               => $property['status'],
                'verified'             => (bool)$property['verified'],
                'featured'             => (bool)$property['featured']
            ],
            'images'     => $images,
            'facilities' => $facilities,
            'landlord'   => [
                'landlord_id'  => (int)($landlord['landlord_id'] ?? 0),
                'full_name'    => $landlord['full_name'] ?? 'Property Owner',
                'phone'        => $landlord['phone'] ?? '',
                'email'        => $landlord['email'] ?? '',
                'member_since' => $landlord['member_since'] ?? null
            ],
            'rating' => [
                'average' => $avgRating,
                'count'   => $reviewCount
            ],
            'reviews' => $reviews
        ]
    ]);
    exit;

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error while fetching property details.'
    ]);
    exit;
}
