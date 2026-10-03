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

try {
    // 1. Build lookup maps using simple MySQLi queries
    $landlordMap = [];
    $resL = $conn->query("SELECT user_id, full_name, email FROM users");
    if ($resL) {
        while ($row = $resL->fetch_assoc()) {
            $landlordMap[(int)$row['user_id']] = [
                'full_name' => $row['full_name'],
                'email'     => $row['email']
            ];
        }
    }

    $categoryMap = [];
    $resC = $conn->query("SELECT category_id, category_name FROM property_categories");
    if ($resC) {
        while ($row = $resC->fetch_assoc()) {
            $categoryMap[(int)$row['category_id']] = $row['category_name'];
        }
    }

    // 2. Parse query filters
    $search     = isset($_GET['search']) ? trim($_GET['search']) : '';
    $status     = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';
    $verified   = isset($_GET['verified']) ? trim($_GET['verified']) : 'all';
    $categoryId = isset($_GET['category_id']) ? $_GET['category_id'] : (isset($_GET['type']) ? $_GET['type'] : 'all');

    $whereClauses = [];
    $params = [];
    $types  = '';

    // Search filter
    if ($search !== '') {
        $whereClauses[] = "(title LIKE ? OR full_address LIKE ? OR neighborhood LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= 'sss';
    }

    // Status filter
    $allowedStatuses = ['pending', 'approved', 'rejected', 'removed', 'rented', 'unavailable'];
    if ($status !== 'all' && in_array($status, $allowedStatuses, true)) {
        $whereClauses[] = "status = ?";
        $params[] = $status;
        $types .= 's';
    }

    // Verified filter
    if ($verified !== 'all' && ($verified === '1' || $verified === '0')) {
        $whereClauses[] = "verified = ?";
        $params[] = (int)$verified;
        $types .= 'i';
    }

    // Category filter
    if ($categoryId !== 'all' && filter_var($categoryId, FILTER_VALIDATE_INT)) {
        $whereClauses[] = "category_id = ?";
        $params[] = (int)$categoryId;
        $types .= 'i';
    }

    $sql = "SELECT property_id, landlord_id, category_id, title, description, neighborhood, full_address, city, distance_from_uiu_km, bedroom_count, bathroom_count, area_sqft, monthly_rent, security_deposit, service_charge, status, verified, created_at FROM properties";
    if (!empty($whereClauses)) {
        $sql .= " WHERE " . implode(" AND ", $whereClauses);
    }
    $sql .= " ORDER BY property_id DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("MySQLi prepare failed: " . $conn->error);
    }

    if (!empty($params)) {
        $bindArgs = array_merge([$types], $params);
        $refs = [];
        foreach ($bindArgs as $key => $value) {
            $refs[$key] = &$bindArgs[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $properties = [];
    while ($row = $result->fetch_assoc()) {
        $pid   = (int)$row['property_id'];
        $lid   = (int)$row['landlord_id'];
        $cid   = (int)$row['category_id'];

        $landlordName = isset($landlordMap[$lid]) ? $landlordMap[$lid]['full_name'] : 'Unknown Landlord';
        $categoryName = isset($categoryMap[$cid]) ? $categoryMap[$cid] : 'General';

        // Fetch primary image for property
        $primaryImg = null;
        $stmtImg = $conn->prepare("SELECT image_url FROM property_images WHERE property_id = ? ORDER BY is_primary DESC, image_id ASC LIMIT 1");
        if ($stmtImg) {
            $stmtImg->bind_param("i", $pid);
            $stmtImg->execute();
            $resImg = $stmtImg->get_result();
            if ($imgRow = $resImg->fetch_assoc()) {
                $primaryImg = $imgRow['image_url'];
            }
            $stmtImg->close();
        }

        $properties[] = [
            'property_id'           => $pid,
            'landlord_id'          => $lid,
            'landlord_name'        => $landlordName,
            'category_id'          => $cid,
            'category_name'        => $categoryName,
            'title'                => $row['title'],
            'description'          => $row['description'],
            'neighborhood'         => $row['neighborhood'],
            'full_address'         => $row['full_address'],
            'city'                 => $row['city'],
            'distance_from_uiu_km' => (float)$row['distance_from_uiu_km'],
            'bedroom_count'        => (float)$row['bedroom_count'],
            'bathroom_count'       => (float)$row['bathroom_count'],
            'area_sqft'            => (float)$row['area_sqft'],
            'monthly_rent'         => (float)$row['monthly_rent'],
            'security_deposit'     => (float)$row['security_deposit'],
            'service_charge'       => (float)$row['service_charge'],
            'status'               => $row['status'],
            'verified'             => (bool)$row['verified'],
            'primary_image'        => $primaryImg,
            'image_url'            => $primaryImg,
            'created_at'           => $row['created_at']
        ];
    }
    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'Admin properties list retrieved successfully.',
        'count'   => count($properties),
        'data'    => $properties
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error retrieving properties list.'
    ]);
    exit;
}
