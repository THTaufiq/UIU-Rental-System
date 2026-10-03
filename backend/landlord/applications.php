<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Require logged-in landlord role
requireRole('landlord');

// Always identify landlord using $_SESSION['user_id']
$landlordId = getCurrentUserId();

try {
    // 1. Get properties owned by logged-in landlord
    $stmtProps = $conn->prepare("
        SELECT property_id, title 
        FROM properties 
        WHERE landlord_id = ?
    ");
    $stmtProps->bind_param("i", $landlordId);
    $stmtProps->execute();
    $resProps = $stmtProps->get_result();
    $properties = $resProps ? $resProps->fetch_all(MYSQLI_ASSOC) : [];

    if (empty($properties)) {
        echo json_encode([
            'success' => true,
            'message' => 'No properties found for this landlord.',
            'data'    => []
        ]);
        exit;
    }

    $propMap = [];
    $propIds = [];
    foreach ($properties as $p) {
        $propIds[] = (int)$p['property_id'];
        $propMap[$p['property_id']] = $p['title'];
    }

    // 2. Fetch applications for these property IDs
    $placeholders = implode(',', array_fill(0, count($propIds), '?'));
    $types = str_repeat('i', count($propIds));
    $stmtApps = $conn->prepare("
        SELECT 
            ra.application_id,
            ra.property_id,
            ra.student_id,
            ra.move_in_date,
            ra.duration_months,
            ra.message,
            ra.status,
            ra.rejection_reason,
            ra.applied_at,
            ra.reviewed_at,
            p.title AS property_title,
            u.full_name AS student_name,
            u.email AS student_email,
            u.phone AS student_phone,
            sp.university_student_id,
            sp.program
        FROM rental_applications ra
        JOIN properties p ON ra.property_id = p.property_id
        JOIN users u ON ra.student_id = u.user_id
        LEFT JOIN student_profiles sp ON u.user_id = sp.student_id
        WHERE ra.property_id IN ($placeholders)
        ORDER BY ra.applied_at DESC
    ");
    $stmtApps->bind_param($types, ...$propIds);
    $stmtApps->execute();
    $resApps = $stmtApps->get_result();
    $applications = $resApps ? $resApps->fetch_all(MYSQLI_ASSOC) : [];

    echo json_encode([
        'success' => true,
        'message' => 'Applications retrieved successfully.',
        'data'    => $applications
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred while fetching applications.'
    ]);
    exit;
}

