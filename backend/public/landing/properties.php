<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection
require_once __DIR__ . '/../../common/db.php';

// Extract query parameter filters
$search        = isset($_GET['search']) ? trim($_GET['search']) : '';
$minPrice      = isset($_GET['min_price']) && is_numeric($_GET['min_price']) ? (float)$_GET['min_price'] : 0;
$maxPrice      = isset($_GET['max_price']) && is_numeric($_GET['max_price']) ? (float)$_GET['max_price'] : 0;
$maxDistance   = isset($_GET['max_distance']) && is_numeric($_GET['max_distance']) ? (float)$_GET['max_distance'] : 0;
$verifiedOnly  = isset($_GET['verified_only']) && ($_GET['verified_only'] === '1' || $_GET['verified_only'] === 'true');
$availableOnly = isset($_GET['available_only']) && ($_GET['available_only'] === '1' || $_GET['available_only'] === 'true');

// Room Types / Categories filter
$roomTypes = [];
if (isset($_GET['room_types']) && !empty($_GET['room_types'])) {
    if (is_array($_GET['room_types'])) {
        $roomTypes = $_GET['room_types'];
    } else {
        $roomTypes = explode(',', $_GET['room_types']);
    }
    $roomTypes = array_map('trim', $roomTypes);
    $roomTypes = array_filter($roomTypes);
}

// Whitelisted sorting options
$sortBy  = isset($_GET['sort']) ? trim($_GET['sort']) : 'newest';
$sortMap = [
    'price_low'  => 'p.monthly_rent ASC',
    'price_high' => 'p.monthly_rent DESC',
    'distance'   => 'p.distance_from_uiu_km ASC',
    'rating'     => 'avg_rating DESC',
    'newest'     => 'p.created_at DESC'
];
$orderBy = isset($sortMap[$sortBy]) ? $sortMap[$sortBy] : 'p.created_at DESC';

try {
    // Build SQL query for physical tables
    $sql = "
        SELECT 
            p.property_id, p.title, p.description, p.neighborhood, p.full_address, p.city,
            p.distance_from_uiu_km, p.monthly_rent, p.security_deposit, p.service_charge,
            p.bedroom_count, p.bathroom_count, p.area_sqft, p.status, p.verified, p.featured,
            p.created_at,
            c.category_name,
            (
                SELECT image_url 
                FROM property_images pi 
                WHERE pi.property_id = p.property_id 
                ORDER BY pi.is_primary DESC, pi.sort_order ASC 
                LIMIT 1
            ) AS image_url,
            COALESCE(ROUND(AVG(r.rating), 1), 4.5) AS avg_rating,
            COUNT(r.review_id) AS total_reviews
        FROM properties p
        JOIN property_categories c ON p.category_id = c.category_id
        LEFT JOIN reviews r ON p.property_id = r.property_id AND r.status = 'published'
        WHERE 1=1
    ";

    $types = '';
    $params = [];

    // Filter by property visibility & availability
    if ($availableOnly) {
        $sql .= " AND p.status = 'approved'";
    } else {
        $sql .= " AND p.status IN ('approved', 'rented')";
    }

    // Verified filter
    if ($verifiedOnly) {
        $sql .= " AND p.verified = 1";
    }

    // Price range filters
    if ($minPrice > 0) {
        $sql .= " AND p.monthly_rent >= ?";
        $types .= "d";
        $params[] = $minPrice;
    }
    if ($maxPrice > 0) {
        $sql .= " AND p.monthly_rent <= ?";
        $types .= "d";
        $params[] = $maxPrice;
    }

    // Distance filter
    if ($maxDistance > 0) {
        $sql .= " AND p.distance_from_uiu_km <= ?";
        $types .= "d";
        $params[] = $maxDistance;
    }

    // Search query
    if (!empty($search)) {
        $sql .= " AND (p.title LIKE ? OR p.neighborhood LIKE ? OR p.full_address LIKE ? OR c.category_name LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $types .= "ssss";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    // Room type / category filter
    if (!empty($roomTypes)) {
        $typeConditions = [];
        foreach ($roomTypes as $type) {
            $typeConditions[] = "c.category_name LIKE ?";
            $types .= "s";
            $params[] = '%' . $type . '%';
        }
        $sql .= " AND (" . implode(' OR ', $typeConditions) . ")";
    }

    // Group By & Order By
    $sql .= " GROUP BY p.property_id, p.title, p.description, p.neighborhood, p.full_address, p.city, p.distance_from_uiu_km, p.monthly_rent, p.security_deposit, p.service_charge, p.bedroom_count, p.bathroom_count, p.area_sqft, p.status, p.verified, p.featured, p.created_at, c.category_name ORDER BY " . $orderBy;

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $properties = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Format output array for frontend compatibility
    $formattedData = [];
    foreach ($properties as $prop) {
        $formattedData[] = [
            'property_id'          => (int)$prop['property_id'],
            'id'                   => (int)$prop['property_id'],
            'title'                => $prop['title'],
            'location'             => $prop['neighborhood'] . ', ' . $prop['city'],
            'neighborhood'         => $prop['neighborhood'],
            'full_address'         => $prop['full_address'],
            'price'                => (float)$prop['monthly_rent'],
            'monthly_rent'         => (float)$prop['monthly_rent'],
            'distance'             => (float)$prop['distance_from_uiu_km'],
            'distance_from_uiu_km' => (float)$prop['distance_from_uiu_km'],
            'category_name'        => $prop['category_name'],
            'type'                 => $prop['category_name'],
            'verified'             => (bool)$prop['verified'],
            'available'            => ($prop['status'] === 'approved'),
            'status'               => $prop['status'],
            'rating'               => (float)$prop['avg_rating'],
            'reviews'              => (int)$prop['total_reviews'],
            'image_url'            => $prop['image_url'],
            'img'                  => $prop['image_url']
        ];
    }

    echo json_encode([
        'success' => true,
        'count'   => count($formattedData),
        'data'    => $formattedData
    ]);
    exit;

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch property listings.'
    ]);
    exit;
}
