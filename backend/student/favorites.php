<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Require logged-in student role
requireRole('student');

$studentId = getCurrentUserId();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $propertyId = isset($_GET['property_id']) ? (int)$_GET['property_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

    try {
        if ($propertyId > 0) {
            // Check favorite status for a single property
            $stmt = $conn->prepare("
                SELECT 1 FROM favorites 
                WHERE student_id = ? AND property_id = ? 
                LIMIT 1
            ");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmt->bind_param("ii", $studentId, $propertyId);
            $stmt->execute();
            $res = $stmt->get_result();
            $isFav = (bool)$res->fetch_assoc();
            $stmt->close();

            echo json_encode([
                'success'     => true,
                'is_favorite' => $isFav,
                'property_id' => $propertyId
            ]);
            exit;
        } else {
            // Fetch list of all favorite properties for logged-in student
            $stmt = $conn->prepare("
                SELECT 
                    f.favorite_id, f.created_at AS favorited_at,
                    p.property_id, p.title, p.neighborhood, p.monthly_rent, p.bedroom_count, p.bathroom_count,
                    p.distance_from_uiu_km, p.verified, p.status,
                    pi.image_url AS property_image
                FROM favorites f
                JOIN properties p ON f.property_id = p.property_id
                LEFT JOIN property_images pi ON p.property_id = pi.property_id AND pi.is_primary = 1
                WHERE f.student_id = ?
                ORDER BY f.created_at DESC
            ");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmt->bind_param("i", $studentId);
            $stmt->execute();
            $res = $stmt->get_result();
            $favorites = $res->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            echo json_encode([
                'success' => true,
                'message' => 'Favorites retrieved successfully.',
                'data'    => $favorites
            ]);
            exit;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error fetching favorites.'
        ]);
        exit;
    }

} elseif ($method === 'POST' || $method === 'DELETE') {
    // Process add / remove / toggle favorite
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $propertyId = isset($data['property_id']) ? (int)$data['property_id'] : (isset($_GET['property_id']) ? (int)$_GET['property_id'] : 0);
    $action     = isset($data['action']) ? strtolower(trim($data['action'])) : ($method === 'DELETE' ? 'remove' : 'toggle');

    if ($propertyId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid property ID.'
        ]);
        exit;
    }

    try {
        // Check if property exists
        $stmtProp = $conn->prepare("SELECT property_id FROM properties WHERE property_id = ? LIMIT 1");
        if (!$stmtProp) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmtProp->bind_param("i", $propertyId);
        $stmtProp->execute();
        $resProp = $stmtProp->get_result();
        if (!$resProp->fetch_assoc()) {
            $stmtProp->close();
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Property not found.'
            ]);
            exit;
        }
        $stmtProp->close();

        // Check current favorite status
        $stmtCheck = $conn->prepare("
            SELECT favorite_id FROM favorites 
            WHERE student_id = ? AND property_id = ? 
            LIMIT 1
        ");
        if (!$stmtCheck) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmtCheck->bind_param("ii", $studentId, $propertyId);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();
        $existing = $resCheck->fetch_assoc();
        $stmtCheck->close();

        if ($action === 'remove' || ($action === 'toggle' && $existing)) {
            // Remove from favorites
            $stmtDel = $conn->prepare("
                DELETE FROM favorites 
                WHERE student_id = ? AND property_id = ?
            ");
            if ($stmtDel) {
                $stmtDel->bind_param("ii", $studentId, $propertyId);
                $stmtDel->execute();
                $stmtDel->close();
            }

            echo json_encode([
                'success'     => true,
                'is_favorite' => false,
                'message'     => 'Removed from favorites.'
            ]);
            exit;
        } else {
            // Add to favorites
            $stmtAdd = $conn->prepare("
                INSERT INTO favorites (student_id, property_id, created_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE created_at = NOW()
            ");
            if ($stmtAdd) {
                $stmtAdd->bind_param("ii", $studentId, $propertyId);
                $stmtAdd->execute();
                $stmtAdd->close();
            }

            echo json_encode([
                'success'     => true,
                'is_favorite' => true,
                'message'     => 'Added to favorites.'
            ]);
            exit;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error updating favorites.'
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
