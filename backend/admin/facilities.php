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
        $stmt = $conn->prepare("SELECT facility_id, facility_name, is_active FROM facilities ORDER BY facility_name ASC");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->execute();
        $res = $stmt->get_result();

        $facilities = [];
        while ($row = $res->fetch_assoc()) {
            $facId = (int)$row['facility_id'];

            // Get count of unique properties using this facility
            $stmtP = $conn->prepare("SELECT COUNT(DISTINCT property_id) AS total_properties FROM property_facilities WHERE facility_id = ?");
            $totalProperties = 0;
            if ($stmtP) {
                $stmtP->bind_param("i", $facId);
                $stmtP->execute();
                $resP = $stmtP->get_result();
                if ($pRow = $resP->fetch_assoc()) {
                    $totalProperties = (int)$pRow['total_properties'];
                }
                $stmtP->close();
            }

            $row['facility_id'] = $facId;
            $row['is_active'] = (int)$row['is_active'];
            $row['total_properties'] = $totalProperties;
            $facilities[] = $row;
        }
        $stmt->close();

        echo json_encode([
            'success' => true,
            'message' => 'Facilities retrieved successfully.',
            'count'   => count($facilities),
            'data'    => $facilities
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error retrieving facilities.'
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

    if ($action === '' && isset($input['facility_id'])) {
        $action = 'update';
    } elseif ($action === '') {
        $action = 'create';
    }

    if ($action === 'create') {
        $name = trim($input['facility_name'] ?? '');
        $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;

        if ($name === '') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Facility name is required.'
            ]);
            exit;
        }

        if (strlen($name) > 80) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Facility name must not exceed 80 characters.'
            ]);
            exit;
        }

        try {
            // Check duplicate name
            $stmtCheck = $conn->prepare("SELECT COUNT(*) AS cnt FROM facilities WHERE LOWER(facility_name) = LOWER(?)");
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
                    'message' => 'Facility name already exists.'
                ]);
                exit;
            }

            $stmtIns = $conn->prepare("INSERT INTO facilities (facility_name, is_active) VALUES (?, ?)");
            if (!$stmtIns) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmtIns->bind_param("si", $name, $isActive);
            $stmtIns->execute();
            $newId = $conn->insert_id;
            $stmtIns->close();

            // Optional audit log
            $adminUserId = getCurrentUserId();
            $stmtAudit = $conn->prepare("INSERT INTO audit_logs (actor_user_id, action_type, table_name, record_id, new_data) VALUES (?, 'CREATE_FACILITY', 'facilities', ?, ?)");
            if ($stmtAudit) {
                $newDataJson = json_encode(['facility_name' => $name, 'is_active' => $isActive]);
                $stmtAudit->bind_param("iis", $adminUserId, $newId, $newDataJson);
                $stmtAudit->execute();
                $stmtAudit->close();
            }

            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Facility created successfully.',
                'data'    => [
                    'facility_id'   => $newId,
                    'facility_name' => $name,
                    'is_active'     => $isActive
                ]
            ]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Database error creating facility.'
            ]);
            exit;
        }
    }

    if ($action === 'update') {
        $facId = filter_var($input['facility_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim($input['facility_name'] ?? '');
        $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;

        if (!$facId || $facId <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid facility ID.'
            ]);
            exit;
        }

        if ($name === '') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Facility name is required.'
            ]);
            exit;
        }

        if (strlen($name) > 80) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Facility name must not exceed 80 characters.'
            ]);
            exit;
        }

        try {
            // Check existence
            $stmtEx = $conn->prepare("SELECT facility_id FROM facilities WHERE facility_id = ?");
            if (!$stmtEx) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmtEx->bind_param("i", $facId);
            $stmtEx->execute();
            $resEx = $stmtEx->get_result();
            if (!$resEx->fetch_assoc()) {
                $stmtEx->close();
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Facility not found.'
                ]);
                exit;
            }
            $stmtEx->close();

            // Check duplicate name
            $stmtCheck = $conn->prepare("SELECT COUNT(*) AS cnt FROM facilities WHERE LOWER(facility_name) = LOWER(?) AND facility_id != ?");
            if (!$stmtCheck) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmtCheck->bind_param("si", $name, $facId);
            $stmtCheck->execute();
            $resCheck = $stmtCheck->get_result();
            $checkRow = $resCheck->fetch_assoc();
            $stmtCheck->close();

            if ($checkRow && (int)$checkRow['cnt'] > 0) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'message' => 'Facility name already exists.'
                ]);
                exit;
            }

            $stmtUp = $conn->prepare("UPDATE facilities SET facility_name = ?, is_active = ? WHERE facility_id = ?");
            if (!$stmtUp) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmtUp->bind_param("sii", $name, $isActive, $facId);
            $stmtUp->execute();
            $stmtUp->close();

            // Optional audit log
            $adminUserId = getCurrentUserId();
            $stmtAudit = $conn->prepare("INSERT INTO audit_logs (actor_user_id, action_type, table_name, record_id, new_data) VALUES (?, 'UPDATE_FACILITY', 'facilities', ?, ?)");
            if ($stmtAudit) {
                $newDataJson = json_encode(['facility_name' => $name, 'is_active' => $isActive]);
                $stmtAudit->bind_param("iis", $adminUserId, $facId, $newDataJson);
                $stmtAudit->execute();
                $stmtAudit->close();
            }

            echo json_encode([
                'success' => true,
                'message' => 'Facility updated successfully.'
            ]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Database error updating facility.'
            ]);
            exit;
        }
    }

    if ($action === 'delete') {
        $facId = filter_var($input['facility_id'] ?? $_GET['facility_id'] ?? null, FILTER_VALIDATE_INT);

        if (!$facId || $facId <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid facility ID.'
            ]);
            exit;
        }

        try {
            // Check existence
            $stmtEx = $conn->prepare("SELECT facility_id FROM facilities WHERE facility_id = ?");
            if (!$stmtEx) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmtEx->bind_param("i", $facId);
            $stmtEx->execute();
            $resEx = $stmtEx->get_result();
            if (!$resEx->fetch_assoc()) {
                $stmtEx->close();
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Facility not found.'
                ]);
                exit;
            }
            $stmtEx->close();

            // Check if properties use this facility
            $stmtProp = $conn->prepare("SELECT COUNT(*) AS cnt FROM property_facilities WHERE facility_id = ?");
            if (!$stmtProp) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmtProp->bind_param("i", $facId);
            $stmtProp->execute();
            $resProp = $stmtProp->get_result();
            $propRow = $resProp->fetch_assoc();
            $stmtProp->close();

            if ($propRow && (int)$propRow['cnt'] > 0) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'message' => 'Facility cannot be deleted because properties are using it.'
                ]);
                exit;
            }

            $stmtDel = $conn->prepare("DELETE FROM facilities WHERE facility_id = ?");
            if (!$stmtDel) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmtDel->bind_param("i", $facId);
            $stmtDel->execute();
            $stmtDel->close();

            // Optional audit log
            $adminUserId = getCurrentUserId();
            $stmtAudit = $conn->prepare("INSERT INTO audit_logs (actor_user_id, action_type, table_name, record_id) VALUES (?, 'DELETE_FACILITY', 'facilities', ?)");
            if ($stmtAudit) {
                $stmtAudit->bind_param("ii", $adminUserId, $facId);
                $stmtAudit->execute();
                $stmtAudit->close();
            }

            echo json_encode([
                'success' => true,
                'message' => 'Facility deleted successfully.'
            ]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Database error deleting facility.'
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
