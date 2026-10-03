<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Only allow POST request
if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
    exit;
}

// Require logged-in student role
requireRole('student');

$studentId = getCurrentUserId();

// Read input payload (JSON or FormData)
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$propertyId     = isset($data['property_id']) ? $data['property_id'] : null;
$moveInDate     = isset($data['move_in_date']) ? trim($data['move_in_date']) : '';
$durationMonths = isset($data['duration_months']) ? (int)$data['duration_months'] : 0;
$message        = isset($data['message']) ? trim($data['message']) : '';

// 1. Validate property_id
if ($propertyId === null || !filter_var($propertyId, FILTER_VALIDATE_INT) || (int)$propertyId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid property ID.'
    ]);
    exit;
}
$propertyId = (int)$propertyId;

// 2. Validate move_in_date and duration
if (empty($moveInDate)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Move-in date is required.'
    ]);
    exit;
}

if ($durationMonths <= 0) {
    if (isset($data['duration'])) {
        $durationMonths = (int)preg_replace('/[^0-9]/', '', $data['duration']);
    }
    if ($durationMonths <= 0) {
        $durationMonths = 6;
    }
}

try {
    // 3. Check property existence & availability
    $stmtProp = $conn->prepare("
        SELECT property_id, title, status, minimum_stay_months 
        FROM properties 
        WHERE property_id = ?
        LIMIT 1
    ");
    if (!$stmtProp) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmtProp->bind_param("i", $propertyId);
    $stmtProp->execute();
    $resProp = $stmtProp->get_result();
    $property = $resProp->fetch_assoc();
    $stmtProp->close();

    if (!$property) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Property not found.'
        ]);
        exit;
    }

    if ($property['status'] !== 'approved') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'This property is currently not available for application.'
        ]);
        exit;
    }

    // 4. Check for duplicate pending or approved application for this property by this student
    $stmtDup = $conn->prepare("
        SELECT application_id, status 
        FROM rental_applications 
        WHERE property_id = ? AND student_id = ? AND status IN ('pending', 'approved')
        LIMIT 1
    ");
    if (!$stmtDup) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmtDup->bind_param("ii", $propertyId, $studentId);
    $stmtDup->execute();
    $resDup = $stmtDup->get_result();
    $existingApp = $resDup->fetch_assoc();
    $stmtDup->close();

    if ($existingApp) {
        echo json_encode([
            'success' => false,
            'message' => 'You already have an active or pending application for this property.'
        ]);
        exit;
    }

    // 5. Insert application into physical table `rental_applications`
    $stmtInsert = $conn->prepare("
        INSERT INTO rental_applications (property_id, student_id, move_in_date, duration_months, message, status)
        VALUES (?, ?, ?, ?, ?, 'pending')
    ");
    if (!$stmtInsert) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmtInsert->bind_param("iisis", $propertyId, $studentId, $moveInDate, $durationMonths, $message);
    $stmtInsert->execute();

    $newAppId = (int)$conn->insert_id;
    $stmtInsert->close();

    echo json_encode([
        'success' => true,
        'message' => 'Rental application submitted successfully!',
        'data'    => [
            'application_id' => $newAppId,
            'property_id'    => $propertyId,
            'status'         => 'pending'
        ]
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred while submitting application.'
    ]);
    exit;
}
