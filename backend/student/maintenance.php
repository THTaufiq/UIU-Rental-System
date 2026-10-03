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
    $action = $_GET['action'] ?? '';
    
    // Check if client requested categories
    if ($action === 'categories') {
        try {
            $resCat = $conn->query("SELECT category_id, category_name FROM maintenance_categories ORDER BY category_id ASC");
            $categories = $resCat ? $resCat->fetch_all(MYSQLI_ASSOC) : [];
            echo json_encode([
                'success' => true,
                'data'    => $categories
            ]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to load categories']);
            exit;
        }
    }

    // Default GET: Fetch student maintenance request history
    getMaintenanceHistory($conn, $studentId);
    exit;

} elseif ($method === 'POST') {
    // Process Maintenance Request Submission
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $title        = isset($data['title']) ? trim($data['title']) : '';
    $description  = isset($data['description']) ? trim($data['description']) : '';
    $categoryVal  = isset($data['category_id']) ? $data['category_id'] : (isset($data['category']) ? $data['category'] : '');
    $priority     = isset($data['priority']) ? trim($data['priority']) : 'Medium';

    if (empty($title)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Issue title is required.'
        ]);
        exit;
    }

    if (empty($description)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Issue description is required.'
        ]);
        exit;
    }

    // Validate Priority enum ('Low', 'Medium', 'High', 'Urgent')
    $allowedPriorities = ['Low', 'Medium', 'High', 'Urgent'];
    $normalizedPriority = ucfirst(strtolower($priority));
    if (!in_array($normalizedPriority, $allowedPriorities)) {
        $normalizedPriority = 'Medium';
    }

    try {
        // Resolve Category ID
        $categoryId = null;
        if (is_numeric($categoryVal) && (int)$categoryVal > 0) {
            $catIdInt = (int)$categoryVal;
            $stmtCatCheck = $conn->prepare("SELECT category_id FROM maintenance_categories WHERE category_id = ? LIMIT 1");
            if ($stmtCatCheck) {
                $stmtCatCheck->bind_param("i", $catIdInt);
                $stmtCatCheck->execute();
                $resCat = $stmtCatCheck->get_result();
                $cat = $resCat->fetch_assoc();
                if ($cat) {
                    $categoryId = (int)$cat['category_id'];
                }
                $stmtCatCheck->close();
            }
        }

        if (!$categoryId && !empty($categoryVal)) {
            // Find by category_name (e.g. "Plumbing", "Electrical")
            $catNameStr = trim($categoryVal);
            $stmtCatName = $conn->prepare("SELECT category_id FROM maintenance_categories WHERE category_name = ? LIMIT 1");
            if ($stmtCatName) {
                $stmtCatName->bind_param("s", $catNameStr);
                $stmtCatName->execute();
                $resCat = $stmtCatName->get_result();
                $cat = $resCat->fetch_assoc();
                if ($cat) {
                    $categoryId = (int)$cat['category_id'];
                }
                $stmtCatName->close();
            }
        }

        // Fallback default category if not matched (e.g., 'Other' or 1)
        if (!$categoryId) {
            $resCatDefault = $conn->query("SELECT category_id FROM maintenance_categories LIMIT 1");
            $cat = $resCatDefault ? $resCatDefault->fetch_assoc() : null;
            $categoryId = $cat ? (int)$cat['category_id'] : 1;
        }

        // Verify Student Active Property / Agreement Relationship
        // Check active lease first
        $stmtProperty = $conn->prepare("
            SELECT ra.property_id, ra.landlord_id
            FROM rental_agreements ra
            WHERE ra.student_id = ? AND ra.status = 'active'
            ORDER BY ra.created_at DESC
            LIMIT 1
        ");
        if (!$stmtProperty) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmtProperty->bind_param("i", $studentId);
        $stmtProperty->execute();
        $resProp = $stmtProperty->get_result();
        $activeRental = $resProp->fetch_assoc();
        $stmtProperty->close();

        // If no active lease, check approved application
        if (!$activeRental) {
            $stmtApp = $conn->prepare("
                SELECT ra.property_id, p.landlord_id
                FROM rental_applications ra
                JOIN properties p ON ra.property_id = p.property_id
                WHERE ra.student_id = ? AND ra.status = 'approved'
                ORDER BY ra.applied_at DESC
                LIMIT 1
            ");
            if ($stmtApp) {
                $stmtApp->bind_param("i", $studentId);
                $stmtApp->execute();
                $resApp = $stmtApp->get_result();
                $activeRental = $resApp->fetch_assoc();
                $stmtApp->close();
            }
        }

        if (!$activeRental) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'No active property or approved lease found to submit a maintenance request.'
            ]);
            exit;
        }

        $propertyId = (int)$activeRental['property_id'];
        $landlordId = (int)$activeRental['landlord_id'];

        // Direct SQL INSERT into `maintenance_requests`
        $stmtInsert = $conn->prepare("
            INSERT INTO maintenance_requests (
                property_id,
                student_id,
                landlord_id,
                category_id,
                title,
                description,
                priority,
                status,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
        ");
        if (!$stmtInsert) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmtInsert->bind_param("iiiisss", $propertyId, $studentId, $landlordId, $categoryId, $title, $description, $normalizedPriority);
        $stmtInsert->execute();

        $requestId = (int)$conn->insert_id;
        $stmtInsert->close();

        echo json_encode([
            'success' => true,
            'message' => 'Maintenance request submitted successfully!',
            'data'    => [
                'request_id'  => $requestId,
                'property_id' => $propertyId,
                'category_id' => $categoryId,
                'title'       => $title,
                'priority'    => $normalizedPriority,
                'status'      => 'Pending'
            ]
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error submitting maintenance request.'
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

function getMaintenanceHistory($conn, $studentId) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                mr.request_id,
                mr.property_id,
                mr.category_id,
                mc.category_name,
                mr.title,
                mr.description,
                mr.priority,
                mr.status,
                mr.attachment_path,
                mr.landlord_notes,
                mr.landlord_note,
                mr.created_at,
                mr.updated_at,
                mr.resolved_at,
                p.title AS property_title,
                p.neighborhood,
                landlord.full_name AS landlord_name
            FROM maintenance_requests mr
            JOIN maintenance_categories mc ON mr.category_id = mc.category_id
            JOIN properties p ON mr.property_id = p.property_id
            JOIN users landlord ON mr.landlord_id = landlord.user_id
            WHERE mr.student_id = ?
            ORDER BY mr.created_at DESC
        ");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $res = $stmt->get_result();
        $requests = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode([
            'success' => true,
            'message' => 'Maintenance history retrieved successfully.',
            'data'    => $requests
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error retrieving maintenance history.'
        ]);
    }
}
