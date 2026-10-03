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
    $propertyId = isset($_GET['property_id']) ? (int)$_GET['property_id'] : 0;

    try {
        // Simple Query 1: Fetch all active facilities
        $stmtAll = $conn->prepare("SELECT facility_id, facility_name FROM facilities WHERE is_active = 1 ORDER BY facility_name ASC");
        $stmtAll->execute();
        $resAll = $stmtAll->get_result();
        $allFacilities = $resAll ? $resAll->fetch_all(MYSQLI_ASSOC) : [];

        $propertyFacilities = [];
        if ($propertyId > 0) {
            // Verify landlord ownership if property_id supplied
            $stmtCheck = $conn->prepare("SELECT 1 FROM properties WHERE property_id = ? AND landlord_id = ? LIMIT 1");
            $stmtCheck->bind_param("ii", $propertyId, $landlordId);
            $stmtCheck->execute();
            $resCheck = $stmtCheck->get_result();

            if (!$resCheck || !$resCheck->fetch_assoc()) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'Property not found or access denied.'
                ]);
                exit;
            }

            // Simple Query 2: Fetch facility IDs assigned to property
            $stmtPropFac = $conn->prepare("SELECT facility_id FROM property_facilities WHERE property_id = ?");
            $stmtPropFac->bind_param("i", $propertyId);
            $stmtPropFac->execute();
            $resPropFac = $stmtPropFac->get_result();
            if ($resPropFac) {
                while ($r = $resPropFac->fetch_assoc()) {
                    $propertyFacilities[] = (int)$r['facility_id'];
                }
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Facilities retrieved successfully.',
            'data'    => [
                'all_facilities'      => $allFacilities,
                'property_facilities' => $propertyFacilities
            ]
        ]);
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error retrieving facilities.'
        ]);
        exit;
    }

} elseif ($method === 'POST' || $method === 'DELETE') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $propertyId = isset($data['property_id']) ? (int)$data['property_id'] : (isset($_GET['property_id']) ? (int)$_GET['property_id'] : 0);
    $facilityId = isset($data['facility_id']) ? (int)$data['facility_id'] : (isset($_GET['facility_id']) ? (int)$_GET['facility_id'] : 0);
    $action     = isset($data['action']) ? strtolower(trim($data['action'])) : ($method === 'DELETE' ? 'remove' : 'add');

    if ($propertyId <= 0 || $facilityId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Property ID and facility ID are required.'
        ]);
        exit;
    }

    try {
        // Simple Query 1: Verify property ownership
        $stmtCheck = $conn->prepare("SELECT 1 FROM properties WHERE property_id = ? AND landlord_id = ? LIMIT 1");
        $stmtCheck->bind_param("ii", $propertyId, $landlordId);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();

        if (!$resCheck || !$resCheck->fetch_assoc()) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Property not found or access denied.'
            ]);
            exit;
        }

        if ($action === 'remove') {
            // Simple Query 2a: Remove facility from property
            $stmtDel = $conn->prepare("DELETE FROM property_facilities WHERE property_id = ? AND facility_id = ?");
            $stmtDel->bind_param("ii", $propertyId, $facilityId);
            $stmtDel->execute();

            echo json_encode([
                'success' => true,
                'message' => 'Facility removed from property.'
            ]);
            exit;
        } else {
            // Simple Query 2b: Add facility to property
            $stmtAdd = $conn->prepare("INSERT IGNORE INTO property_facilities (property_id, facility_id) VALUES (?, ?)");
            $stmtAdd->bind_param("ii", $propertyId, $facilityId);
            $stmtAdd->execute();

            echo json_encode([
                'success' => true,
                'message' => 'Facility assigned to property.'
            ]);
            exit;
        }

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error updating property facility.'
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

