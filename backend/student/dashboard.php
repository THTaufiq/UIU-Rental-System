<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helpers
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Require logged in student role
requireRole('student');

// Retrieve student user_id strictly from session
$studentId = getCurrentUserId();

try {
    // 1. Fetch Student User & Profile Information
    $stmtUser = $conn->prepare("
        SELECT u.user_id, u.full_name, u.email, u.phone, u.avatar_url, 
               sp.university_student_id, sp.program
        FROM users u
        LEFT JOIN student_profiles sp ON u.user_id = sp.student_id
        WHERE u.user_id = ?
        LIMIT 1
    ");
    if (!$stmtUser) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmtUser->bind_param("i", $studentId);
    $stmtUser->execute();
    $resUser = $stmtUser->get_result();
    $userProfile = $resUser->fetch_assoc();
    $stmtUser->close();

    if (!$userProfile) {
        echo json_encode([
            'success' => false,
            'message' => 'Student profile not found.'
        ]);
        exit;
    }

    // 2. Fetch Active Rental / Current Lease Information
    $stmtActiveRental = $conn->prepare("
        SELECT 
            ra.agreement_id, ra.start_date, ra.end_date, ra.monthly_rent, ra.security_deposit,
            ra.service_charge, ra.status AS agreement_status,
            p.property_id, p.title AS property_title, p.neighborhood, p.full_address, 
            p.distance_from_uiu_km,
            l.full_name AS landlord_name,
            pi.image_url AS property_image
        FROM rental_agreements ra
        JOIN properties p ON ra.property_id = p.property_id
        LEFT JOIN users l ON ra.landlord_id = l.user_id
        LEFT JOIN property_images pi ON p.property_id = pi.property_id AND pi.is_primary = 1
        WHERE ra.student_id = ? AND ra.status = 'active'
        ORDER BY ra.agreement_id DESC
        LIMIT 1
    ");
    if (!$stmtActiveRental) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmtActiveRental->bind_param("i", $studentId);
    $stmtActiveRental->execute();
    $resActiveRental = $stmtActiveRental->get_result();
    $activeRental = $resActiveRental->fetch_assoc();
    $stmtActiveRental->close();

    // 3. Application Statistics & List
    $stmtAppStats = $conn->prepare("
        SELECT 
            COUNT(*) AS total_applications,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_applications,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_applications,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_applications
        FROM rental_applications
        WHERE student_id = ?
    ");
    if (!$stmtAppStats) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmtAppStats->bind_param("i", $studentId);
    $stmtAppStats->execute();
    $resAppStats = $stmtAppStats->get_result();
    $appStats = $resAppStats->fetch_assoc();
    $stmtAppStats->close();

    $stmtApplications = $conn->prepare("
        SELECT 
            ra.application_id, ra.property_id, ra.applied_at, ra.status, ra.duration_months, ra.move_in_date,
            p.title AS property_title, p.neighborhood, p.monthly_rent,
            pi.image_url AS property_image
        FROM rental_applications ra
        JOIN properties p ON ra.property_id = p.property_id
        LEFT JOIN property_images pi ON p.property_id = pi.property_id AND pi.is_primary = 1
        WHERE ra.student_id = ?
        ORDER BY ra.applied_at DESC
    ");
    if (!$stmtApplications) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmtApplications->bind_param("i", $studentId);
    $stmtApplications->execute();
    $resApplications = $stmtApplications->get_result();
    $applications = $resApplications->fetch_all(MYSQLI_ASSOC);
    $stmtApplications->close();

    // 4. Payment History & Latest Rent Payment Status
    $stmtPayments = $conn->prepare("
        SELECT 
            pay.payment_id, pay.payment_type, pay.amount, pay.method, 
            pay.transaction_reference, pay.payment_month, pay.status, pay.paid_at, pay.created_at,
            p.title AS property_title
        FROM payments pay
        LEFT JOIN properties p ON pay.property_id = p.property_id
        WHERE pay.student_id = ?
        ORDER BY pay.created_at DESC
    ");
    if (!$stmtPayments) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmtPayments->bind_param("i", $studentId);
    $stmtPayments->execute();
    $resPayments = $stmtPayments->get_result();
    $payments = $resPayments->fetch_all(MYSQLI_ASSOC);
    $stmtPayments->close();

    // Latest rent payment status calculation
    $latestRentPayment = null;
    foreach ($payments as $pay) {
        if ($pay['payment_type'] === 'rent') {
            $latestRentPayment = $pay;
            break;
        }
    }

    // 5. Notifications List
    $stmtNotifications = $conn->prepare("
        SELECT notification_id, notification_type, title, message, is_read, created_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    if (!$stmtNotifications) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmtNotifications->bind_param("i", $studentId);
    $stmtNotifications->execute();
    $resNotifications = $stmtNotifications->get_result();
    $notifications = $resNotifications->fetch_all(MYSQLI_ASSOC);
    $stmtNotifications->close();

    // 6. Maintenance Requests Overview
    $stmtMaintenance = $conn->prepare("
        SELECT request_id, title, status, priority, created_at, updated_at
        FROM maintenance_requests
        WHERE student_id = ?
        ORDER BY updated_at DESC
        LIMIT 5
    ");
    if (!$stmtMaintenance) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmtMaintenance->bind_param("i", $studentId);
    $stmtMaintenance->execute();
    $resMaintenance = $stmtMaintenance->get_result();
    $maintenance = $resMaintenance->fetch_all(MYSQLI_ASSOC);
    $stmtMaintenance->close();

    // Construct response data structure
    echo json_encode([
        'success' => true,
        'data' => [
            'user' => [
                'user_id'               => (int)$userProfile['user_id'],
                'full_name'             => $userProfile['full_name'],
                'email'                 => $userProfile['email'],
                'phone'                 => $userProfile['phone'],
                'avatar_url'            => $userProfile['avatar_url'],
                'university_student_id' => $userProfile['university_student_id'],
                'program'               => $userProfile['program']
            ],
            'stats' => [
                'current_rent'          => $activeRental ? (float)$activeRental['monthly_rent'] : 0,
                'rent_due_date'         => $activeRental ? $activeRental['end_date'] : null,
                'latest_payment_status' => $latestRentPayment ? ucfirst($latestRentPayment['status']) : 'N/A',
                'latest_payment_month'  => $latestRentPayment ? $latestRentPayment['payment_month'] : null,
                'total_applications'    => (int)($appStats['total_applications'] ?? 0),
                'pending_applications'  => (int)($appStats['pending_applications'] ?? 0),
                'approved_applications' => (int)($appStats['approved_applications'] ?? 0),
                'rejected_applications' => (int)($appStats['rejected_applications'] ?? 0)
            ],
            'active_rental' => $activeRental ? [
                'agreement_id'         => (int)$activeRental['agreement_id'],
                'property_id'          => (int)$activeRental['property_id'],
                'property_title'       => $activeRental['property_title'],
                'neighborhood'         => $activeRental['neighborhood'],
                'full_address'         => $activeRental['full_address'],
                'distance_from_uiu_km' => (float)$activeRental['distance_from_uiu_km'],
                'monthly_rent'         => (float)$activeRental['monthly_rent'],
                'lease_end'            => $activeRental['end_date'],
                'landlord_name'        => $activeRental['landlord_name'],
                'property_image'       => $activeRental['property_image']
            ] : null,
            'applications'  => $applications,
            'payments'      => $payments,
            'notifications' => $notifications,
            'maintenance'   => $maintenance
        ]
    ]);
    exit;

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load dashboard data'
    ]);
    exit;
}
