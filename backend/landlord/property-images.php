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

    if ($propertyId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid property ID.'
        ]);
        exit;
    }

    try {
        // Simple Query 1: Verify landlord ownership of property
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

        // Simple Query 2: Fetch property images
        $stmtImgs = $conn->prepare("
            SELECT image_id, property_id, image_url, is_primary, sort_order, created_at 
            FROM property_images 
            WHERE property_id = ? 
            ORDER BY is_primary DESC, image_id ASC
        ");
        $stmtImgs->bind_param("i", $propertyId);
        $stmtImgs->execute();
        $resImgs = $stmtImgs->get_result();
        $images = $resImgs ? $resImgs->fetch_all(MYSQLI_ASSOC) : [];

        echo json_encode([
            'success' => true,
            'message' => 'Property images retrieved successfully.',
            'count'   => count($images),
            'data'    => $images
        ]);
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error retrieving property images.'
        ]);
        exit;
    }

} elseif ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $propertyId = isset($data['property_id']) ? (int)$data['property_id'] : (isset($_GET['property_id']) ? (int)$_GET['property_id'] : 0);
    $imageUrl   = isset($data['image_url']) ? trim($data['image_url']) : '';
    $isPrimary  = isset($data['is_primary']) ? (int)$data['is_primary'] : 0;

    if ($propertyId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid property ID.'
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

        // Handle file upload if present
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['image'];
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
            
            if (!in_array($file['type'], $allowedTypes)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid image type. Only JPG, PNG, and WebP are allowed.'
                ]);
                exit;
            }

            if ($file['size'] > 5 * 1024 * 1024) { // 5MB max
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Image file size exceeds 5MB limit.'
                ]);
                exit;
            }

            $uploadDir = __DIR__ . '/../../frontend/assets/images/';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }

            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'prop_' . $propertyId . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            $targetPath = $uploadDir . $filename;

            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $imageUrl = '../assets/images/' . $filename;
            }
        }

        if (empty($imageUrl)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Image URL or file upload is required.'
            ]);
            exit;
        }

        // Simple Query 2: Insert into property_images
        $stmtIns = $conn->prepare("
            INSERT INTO property_images (property_id, image_url, is_primary, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmtIns->bind_param("isi", $propertyId, $imageUrl, $isPrimary);
        $stmtIns->execute();

        $imageId = (int)$conn->insert_id;

        echo json_encode([
            'success' => true,
            'message' => 'Property image added successfully!',
            'data'    => [
                'image_id'    => $imageId,
                'property_id' => $propertyId,
                'image_url'   => $imageUrl,
                'is_primary'  => $isPrimary
            ]
        ]);
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error saving property image.'
        ]);
        exit;
    }

} elseif ($method === 'DELETE') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $imageId = isset($data['image_id']) ? (int)$data['image_id'] : (isset($_GET['image_id']) ? (int)$_GET['image_id'] : 0);

    if ($imageId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid image ID.'
        ]);
        exit;
    }

    try {
        // Simple Query 1: Find property_id for this image
        $stmtImg = $conn->prepare("SELECT property_id, image_url FROM property_images WHERE image_id = ? LIMIT 1");
        $stmtImg->bind_param("i", $imageId);
        $stmtImg->execute();
        $resImg = $stmtImg->get_result();
        $img = $resImg ? $resImg->fetch_assoc() : null;

        if (!$img) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Image not found.'
            ]);
            exit;
        }

        // Simple Query 2: Verify landlord ownership of the property
        $pId = (int)$img['property_id'];
        $stmtCheck = $conn->prepare("SELECT 1 FROM properties WHERE property_id = ? AND landlord_id = ? LIMIT 1");
        $stmtCheck->bind_param("ii", $pId, $landlordId);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();

        if (!$resCheck || !$resCheck->fetch_assoc()) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Access denied to image.'
            ]);
            exit;
        }

        // Simple Query 3: Delete image row
        $stmtDel = $conn->prepare("DELETE FROM property_images WHERE image_id = ?");
        $stmtDel->bind_param("i", $imageId);
        $stmtDel->execute();

        echo json_encode([
            'success' => true,
            'message' => 'Property image deleted successfully.'
        ]);
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error deleting property image.'
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

