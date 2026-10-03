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
    $propertyId = isset($_GET['property_id']) ? (int)$_GET['property_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

    if ($propertyId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid property ID.'
        ]);
        exit;
    }

    try {
        // Simple Query 1: Verify property ownership and fetch details
        $stmtProp = $conn->prepare("
            SELECT p.property_id, p.landlord_id, p.category_id, p.title, p.description, 
                   p.neighborhood, p.full_address, p.city, p.distance_from_uiu_km, p.floor_label, 
                   p.bedroom_count, p.bathroom_count, p.area_sqft, p.monthly_rent, p.security_deposit, 
                   p.status, p.verified, p.created_at, c.category_name
            FROM properties p
            LEFT JOIN property_categories c ON p.category_id = c.category_id
            WHERE p.property_id = ? AND p.landlord_id = ?
            LIMIT 1
        ");
        $stmtProp->bind_param("ii", $propertyId, $landlordId);
        $stmtProp->execute();
        $resProp = $stmtProp->get_result();
        $prop = $resProp ? $resProp->fetch_assoc() : null;

        if (!$prop) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Property not found or access denied.'
            ]);
            exit;
        }

        // Simple Query 2: Fetch facility IDs
        $stmtFac = $conn->prepare("SELECT facility_id FROM property_facilities WHERE property_id = ?");
        $stmtFac->bind_param("i", $propertyId);
        $stmtFac->execute();
        $resFac = $stmtFac->get_result();
        $facilities = [];
        if ($resFac) {
            while ($r = $resFac->fetch_assoc()) {
                $facilities[] = (int)$r['facility_id'];
            }
        }

        // Simple Query 3: Fetch images
        $stmtImg = $conn->prepare("SELECT image_id, image_url, is_primary FROM property_images WHERE property_id = ? ORDER BY is_primary DESC");
        $stmtImg->bind_param("i", $propertyId);
        $stmtImg->execute();
        $resImg = $stmtImg->get_result();
        $images = $resImg ? $resImg->fetch_all(MYSQLI_ASSOC) : [];

        echo json_encode([
            'success' => true,
            'message' => 'Property details retrieved successfully.',
            'data'    => [
                'property'   => $prop,
                'facilities' => $facilities,
                'images'     => $images
            ]
        ]);
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error retrieving property.'
        ]);
        exit;
    }

} elseif ($method === 'POST' || $method === 'PUT') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $propertyId = isset($data['property_id']) ? (int)$data['property_id'] : (isset($_GET['property_id']) ? (int)$_GET['property_id'] : 0);

    if ($propertyId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid property ID.'
        ]);
        exit;
    }

    try {
        // Simple Query 1: Verify ownership
        $stmtCheck = $conn->prepare("SELECT property_id, category_id, title, description, neighborhood, full_address, monthly_rent, security_deposit, status FROM properties WHERE property_id = ? AND landlord_id = ? LIMIT 1");
        $stmtCheck->bind_param("ii", $propertyId, $landlordId);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();
        $curProp = $resCheck ? $resCheck->fetch_assoc() : null;

        if (!$curProp) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Property not found or access denied.'
            ]);
            exit;
        }

        $title          = isset($data['title']) && trim($data['title']) !== '' ? trim($data['title']) : $curProp['title'];
        $description    = isset($data['description']) && trim($data['description']) !== '' ? trim($data['description']) : $curProp['description'];
        $neighborhood   = isset($data['neighborhood']) && trim($data['neighborhood']) !== '' ? trim($data['neighborhood']) : $curProp['neighborhood'];
        $fullAddress    = isset($data['full_address']) && trim($data['full_address']) !== '' ? trim($data['full_address']) : $curProp['full_address'];
        $monthlyRent    = isset($data['monthly_rent']) && (float)$data['monthly_rent'] > 0 ? (float)$data['monthly_rent'] : (float)$curProp['monthly_rent'];
        $securityDeposit= isset($data['security_deposit']) ? (float)$data['security_deposit'] : (float)$curProp['security_deposit'];
        $status         = isset($data['status']) ? trim($data['status']) : $curProp['status'];

        // Simple Query 2: Update Property
        $stmtUpd = $conn->prepare("
            UPDATE properties 
            SET title = ?, description = ?, neighborhood = ?, full_address = ?,
                monthly_rent = ?, security_deposit = ?, status = ?
            WHERE property_id = ? AND landlord_id = ?
        ");
        $stmtUpd->bind_param(
            "ssssddsii",
            $title,
            $description,
            $neighborhood,
            $fullAddress,
            $monthlyRent,
            $securityDeposit,
            $status,
            $propertyId,
            $landlordId
        );
        $stmtUpd->execute();

        // Simple Query 3: Sync facilities if array provided
        if (isset($data['facility_ids']) && is_array($data['facility_ids'])) {
            $stmtDelF = $conn->prepare("DELETE FROM property_facilities WHERE property_id = ?");
            $stmtDelF->bind_param("i", $propertyId);
            $stmtDelF->execute();

            $stmtInsF = $conn->prepare("INSERT IGNORE INTO property_facilities (property_id, facility_id) VALUES (?, ?)");
            foreach ($data['facility_ids'] as $fid) {
                $fidInt = (int)$fid;
                if ($fidInt > 0) {
                    $stmtInsF->bind_param("ii", $propertyId, $fidInt);
                    $stmtInsF->execute();
                }
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Property updated successfully!',
            'data'    => [
                'property_id' => $propertyId,
                'title'       => $title,
                'status'      => $status
            ]
        ]);
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error updating property.'
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

