<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Require logged-in admin role
requireRole('admin');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    try {
        $stmt = $conn->prepare("SELECT category_id, category_name, description, is_active, created_at FROM property_categories ORDER BY category_name ASC");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->execute();
        $res = $stmt->get_result();

        $categories = [];
        while ($row = $res->fetch_assoc()) {
            $catId = (int)$row['category_id'];

            // Get total properties count using category
            $stmtP = $conn->prepare("SELECT COUNT(*) AS total_properties FROM properties WHERE category_id = ?");
            $totalProperties = 0;
            if ($stmtP) {
                $stmtP->bind_param("i", $catId);
                $stmtP->execute();
                $resP = $stmtP->get_result();
                if ($pRow = $resP->fetch_assoc()) {
                    $totalProperties = (int)$pRow['total_properties'];
                }
                $stmtP->close();
            }

            $row['category_id'] = $catId;
            $row['is_active'] = (int)$row['is_active'];
            $row['total_properties'] = $totalProperties;
            $categories[] = $row;
        }
        $stmt->close();

        echo json_encode([
            'success' => true,
            'message' => 'Property categories retrieved successfully.',
            'count'   => count($categories),
            'data'    => $categories
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error retrieving categories.'
        ]);
        exit;
    }
}

if ($method === 'POST' || $method === 'DELETE') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $action = strtolower(trim($input['action'] ?? $_GET['action'] ?? ($method === 'DELETE' ? 'delete' : '')));

    if ($action === '' && isset($input['category_id'])) {
        $action = 'update';
    } elseif ($action === '') {
        $action = 'create';
    }

    if ($action === 'create') {
        $name = trim($input['category_name'] ?? '');
        $desc = trim($input['description'] ?? '');
        $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;

        if ($name === '') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Category name is required.'
            ]);
            exit;
        }

        if (strlen($name) > 80) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Category name must not exceed 80 characters.'
            ]);
            exit;
        }

        try {
            // Check duplicate name
            $stmtCheck = $conn->prepare("SELECT COUNT(*) AS cnt FROM property_categories WHERE LOWER(category_name) = LOWER(?)");
            if (!$stmtCheck) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmtCheck->bind_param("s", $name);
            $stmtCheck->execute();
            $resCheck = $stmtCheck->get_result();
            $checkRow = $resCheck->fetch_assoc();
            $stmtCheck->close();

            if ($checkRow && (int)$checkRow['cnt'] > 0) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'message' => 'Category name already exists.'
                ]);
                exit;
            }

            $stmtIns = $conn->prepare("INSERT INTO property_categories (category_name, description, is_active) VALUES (?, ?, ?)");
            if (!$stmtIns) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmtIns->bind_param("ssi", $name, $desc, $isActive);
            $stmtIns->execute();
            $newId = $conn->insert_id;
            $stmtIns->close();

            // Optional audit log
            $adminUserId = getCurrentUserId();
            $stmtAudit = $conn->prepare("INSERT INTO audit_logs (actor_user_id, action_type, table_name, record_id, new_data) VALUES (?, 'CREATE_CATEGORY', 'property_categories', ?, ?)");
            if ($stmtAudit) {
                $newDataJson = json_encode(['category_name' => $name, 'description' => $desc, 'is_active' => $isActive]);
                $stmtAudit->bind_param("iis", $adminUserId, $newId, $newDataJson);
                $stmtAudit->execute();
                $stmtAudit->close();
            }

            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Category created successfully.',
                'data'    => [
                    'category_id'   => $newId,
                    'category_name' => $name,
                    'description'   => $desc,
                    'is_active'     => $isActive
                ]
            ]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Database error creating category.'
            ]);
            exit;
        }
    }

    if ($action === 'update') {
        $catId = filter_var($input['category_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim($input['category_name'] ?? '');
        $desc = trim($input['description'] ?? '');
        $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;

        if (!$catId || $catId <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid category ID.'
            ]);
            exit;
        }

        if ($name === '') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Category name is required.'
            ]);
            exit;
        }

        if (strlen($name) > 80) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Category name must not exceed 80 characters.'
            ]);
            exit;
        }

        try {
            // Check existence
            $stmtEx = $conn->prepare("SELECT category_id FROM property_categories WHERE category_id = ?");
            if (!$stmtEx) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmtEx->bind_param("i", $catId);
            $stmtEx->execute();
            $resEx = $stmtEx->get_result();
            if (!$resEx->fetch_assoc()) {
                $stmtEx->close();
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Category not found.'
                ]);
                exit;
            }
            $stmtEx->close();

            // Check duplicate name
            $stmtCheck = $conn->prepare("SELECT COUNT(*) AS cnt FROM property_categories WHERE LOWER(category_name) = LOWER(?) AND category_id != ?");
            if (!$stmtCheck) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmtCheck->bind_param("si", $name, $catId);
            $stmtCheck->execute();
            $resCheck = $stmtCheck->get_result();
            $checkRow = $resCheck->fetch_assoc();
            $stmtCheck->close();

            if ($checkRow && (int)$checkRow['cnt'] > 0) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'message' => 'Category name already exists.'
                ]);
                exit;
            }

            $stmtUp = $conn->prepare("UPDATE property_categories SET category_name = ?, description = ?, is_active = ? WHERE category_id = ?");
            if (!$stmtUp) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmtUp->bind_param("ssii", $name, $desc, $isActive, $catId);
            $stmtUp->execute();
            $stmtUp->close();

            // Optional audit log
            $adminUserId = getCurrentUserId();
            $stmtAudit = $conn->prepare("INSERT INTO audit_logs (actor_user_id, action_type, table_name, record_id, new_data) VALUES (?, 'UPDATE_CATEGORY', 'property_categories', ?, ?)");
            if ($stmtAudit) {
                $newDataJson = json_encode(['category_name' => $name, 'description' => $desc, 'is_active' => $isActive]);
                $stmtAudit->bind_param("iis", $adminUserId, $catId, $newDataJson);
                $stmtAudit->execute();
                $stmtAudit->close();
            }

            echo json_encode([
                'success' => true,
                'message' => 'Category updated successfully.'
            ]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Database error updating category.'
            ]);
            exit;
        }
    }

    if ($action === 'delete') {
        $catId = filter_var($input['category_id'] ?? $_GET['category_id'] ?? null, FILTER_VALIDATE_INT);

        if (!$catId || $catId <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid category ID.'
            ]);
            exit;
        }

        try {
            // Check existence
            $stmtEx = $conn->prepare("SELECT category_id FROM property_categories WHERE category_id = ?");
            if (!$stmtEx) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmtEx->bind_param("i", $catId);
            $stmtEx->execute();
            $resEx = $stmtEx->get_result();
            if (!$resEx->fetch_assoc()) {
                $stmtEx->close();
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Category not found.'
                ]);
                exit;
            }
            $stmtEx->close();

            // Check if properties use this category
            $stmtProp = $conn->prepare("SELECT COUNT(*) AS cnt FROM properties WHERE category_id = ?");
            if (!$stmtProp) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmtProp->bind_param("i", $catId);
            $stmtProp->execute();
            $resProp = $stmtProp->get_result();
            $propRow = $resProp->fetch_assoc();
            $stmtProp->close();

            if ($propRow && (int)$propRow['cnt'] > 0) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'message' => 'This category cannot be deleted because it is currently used by existing properties.'
                ]);
                exit;
            }

            $stmtDel = $conn->prepare("DELETE FROM property_categories WHERE category_id = ?");
            if (!$stmtDel) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmtDel->bind_param("i", $catId);
            $stmtDel->execute();
            $stmtDel->close();

            // Optional audit log
            $adminUserId = getCurrentUserId();
            $stmtAudit = $conn->prepare("INSERT INTO audit_logs (actor_user_id, action_type, table_name, record_id) VALUES (?, 'DELETE_CATEGORY', 'property_categories', ?)");
            if ($stmtAudit) {
                $stmtAudit->bind_param("ii", $adminUserId, $catId);
                $stmtAudit->execute();
                $stmtAudit->close();
            }

            echo json_encode([
                'success' => true,
                'message' => 'Category deleted successfully.'
            ]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Database error deleting category.'
            ]);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid action.'
    ]);
    exit;
}

http_response_code(405);
echo json_encode([
    'success' => false,
    'message' => 'Method not allowed.'
]);
exit;
