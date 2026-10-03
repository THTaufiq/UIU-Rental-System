<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Only allow POST request
if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
    exit;
}

// Require logged-in landlord role
requireRole('landlord');

// Derive identity from session
$landlordId = getCurrentUserId();

// Read payload
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$appId = isset($data['application_id']) ? $data['application_id'] : null;

if ($appId === null || !filter_var($appId, FILTER_VALIDATE_INT) || (int)$appId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid application ID.'
    ]);
    exit;
}

$appId = (int)$appId;

try {
    // 1. Retrieve application & property info with ownership check
    $stmtApp = $conn->prepare("
        SELECT 
            ra.application_id,
            ra.property_id,
            ra.student_id,
            ra.move_in_date,
            ra.duration_months,
            ra.status AS application_status,
            p.landlord_id,
            p.title AS property_title,
            p.monthly_rent,
            p.security_deposit,
            p.service_charge,
            p.status AS property_status
        FROM rental_applications ra
        JOIN properties p ON ra.property_id = p.property_id
        WHERE ra.application_id = ?
        LIMIT 1
    ");
    $stmtApp->bind_param("i", $appId);
    $stmtApp->execute();
    $resApp = $stmtApp->get_result();
    $app = $resApp ? $resApp->fetch_assoc() : null;

    if (!$app) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Application not found.'
        ]);
        exit;
    }

    // Ownership check
    if ((int)$app['landlord_id'] !== (int)$landlordId) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Access denied. You do not own this property.'
        ]);
        exit;
    }

    // Business rule check: Application status must be pending
    if ($app['application_status'] !== 'pending') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Application is not in pending status.'
        ]);
        exit;
    }

    // Business rule check: Property availability
    if ($app['property_status'] === 'rented' || $app['property_status'] === 'unavailable') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Property is no longer available.'
        ]);
        exit;
    }

    // Check for existing active agreement on this property
    $pId = (int)$app['property_id'];
    $stmtAgCheck = $conn->prepare("
        SELECT agreement_id 
        FROM rental_agreements 
        WHERE property_id = ? AND status = 'active'
        LIMIT 1
    ");
    $stmtAgCheck->bind_param("i", $pId);
    $stmtAgCheck->execute();
    $resAg = $stmtAgCheck->get_result();
    if ($resAg && $resAg->fetch_assoc()) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Property already has an active rental agreement.'
        ]);
        exit;
    }

    // Begin transaction
    $conn->begin_transaction();

    // 2. Update application status
    $stmtUpdateApp = $conn->prepare("
        UPDATE rental_applications 
        SET status = 'approved', reviewed_at = NOW(), reviewed_by = ?
        WHERE application_id = ?
    ");
    $stmtUpdateApp->bind_param("ii", $landlordId, $appId);
    $stmtUpdateApp->execute();

    // Calculate dates
    $startDate = (!empty($app['move_in_date'])) ? $app['move_in_date'] : date('Y-m-d');
    $duration = ((int)$app['duration_months'] > 0) ? (int)$app['duration_months'] : 6;
    $endDate = date('Y-m-d', strtotime("+$duration months", strtotime($startDate)));

    $studentId = (int)$app['student_id'];
    $monthlyRent = (float)$app['monthly_rent'];
    $securityDeposit = (float)$app['security_deposit'];
    $serviceCharge = (float)$app['service_charge'];
    $terms = 'Standard rental agreement terms and conditions apply.';

    // 3. Create rental agreement
    $stmtInsertAgr = $conn->prepare("
        INSERT INTO rental_agreements (
            application_id, property_id, student_id, landlord_id,
            start_date, end_date, duration_months,
            monthly_rent, security_deposit, service_charge,
            terms, status, created_at, finalized_at
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, 'active', NOW(), NOW()
        )
    ");
    $stmtInsertAgr->bind_param(
        "iiiissiddds",
        $appId,
        $pId,
        $studentId,
        $landlordId,
        $startDate,
        $endDate,
        $duration,
        $monthlyRent,
        $securityDeposit,
        $serviceCharge,
        $terms
    );
    $stmtInsertAgr->execute();
    $newAgrId = (int)$conn->insert_id;

    // 4. Update property status to rented
    $stmtPropUpdate = $conn->prepare("
        UPDATE properties 
        SET status = 'rented' 
        WHERE property_id = ?
    ");
    $stmtPropUpdate->bind_param("i", $pId);
    $stmtPropUpdate->execute();

    // 5. Notify student
    $notifMsg = 'Your application for property "' . $app['property_title'] . '" has been approved!';
    $stmtNotif = $conn->prepare("
        INSERT INTO notifications (
            user_id, notification_type, title, message, reference_type, reference_id, is_read, created_at
        ) VALUES (
            ?, 'application', 'Application Approved!', ?, 'rental_application', ?, 0, NOW()
        )
    ");
    $stmtNotif->bind_param("isi", $studentId, $notifMsg, $appId);
    $stmtNotif->execute();

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Application approved successfully!',
        'data'    => [
            'application_id' => $appId,
            'agreement_id'   => $newAgrId,
            'status'         => 'approved'
        ]
    ]);
    exit;

} catch (Throwable $e) {
    @$conn->rollback();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred while approving application.'
    ]);
    exit;
}

