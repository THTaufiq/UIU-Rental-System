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

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

try {
    // 1. Simple Query: Fetch Landlord Basic User Info
    $stmtUser = $conn->prepare("
        SELECT user_id, full_name, email, phone, avatar_url, created_at 
        FROM users 
        WHERE user_id = ? 
        LIMIT 1
    ");
    if (!$stmtUser) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmtUser->bind_param("i", $landlordId);
    $stmtUser->execute();
    $resUser = $stmtUser->get_result();
    $user = $resUser->fetch_assoc();
    $stmtUser->close();

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Landlord account not found.'
        ]);
        exit;
    }

    // 2. Simple Query: Fetch Landlord Profile Details
    $stmtProf = $conn->prepare("
        SELECT nid_number, trade_license_number, permanent_address, verification_status, member_since,
               payout_method, bkash_account, nagad_account, rocket_account, bank_name, bank_account_number
        FROM landlord_profiles 
        WHERE landlord_id = ? 
        LIMIT 1
    ");
    if (!$stmtProf) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmtProf->bind_param("i", $landlordId);
    $stmtProf->execute();
    $resProf = $stmtProf->get_result();
    $profile = $resProf->fetch_assoc() ?: [];
    $stmtProf->close();

    // 3. Simple Queries: Property Statistics
    $stmtTotalProps = $conn->prepare("SELECT COUNT(*) AS cnt FROM properties WHERE landlord_id = ?");
    $stmtTotalProps->bind_param("i", $landlordId);
    $stmtTotalProps->execute();
    $totalProperties = (int)($stmtTotalProps->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmtTotalProps->close();

    $stmtAvailProps = $conn->prepare("SELECT COUNT(*) AS cnt FROM properties WHERE landlord_id = ? AND status = 'available'");
    $stmtAvailProps->bind_param("i", $landlordId);
    $stmtAvailProps->execute();
    $availableProperties = (int)($stmtAvailProps->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmtAvailProps->close();

    $stmtRentedProps = $conn->prepare("SELECT COUNT(*) AS cnt FROM properties WHERE landlord_id = ? AND status = 'rented'");
    $stmtRentedProps->bind_param("i", $landlordId);
    $stmtRentedProps->execute();
    $rentedProperties = (int)($stmtRentedProps->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmtRentedProps->close();

    $stmtPendingProps = $conn->prepare("SELECT COUNT(*) AS cnt FROM properties WHERE landlord_id = ? AND status = 'pending'");
    $stmtPendingProps->bind_param("i", $landlordId);
    $stmtPendingProps->execute();
    $pendingProperties = (int)($stmtPendingProps->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmtPendingProps->close();

    // 4. Simple Query: Get List of Landlord's Property IDs
    $stmtPropIds = $conn->prepare("SELECT property_id FROM properties WHERE landlord_id = ?");
    $stmtPropIds->bind_param("i", $landlordId);
    $stmtPropIds->execute();
    $resPropIds = $stmtPropIds->get_result();
    $propIds = [];
    while ($row = $resPropIds->fetch_assoc()) {
        $propIds[] = (int)$row['property_id'];
    }
    $stmtPropIds->close();

    $totalApps = 0;
    $pendingApps = 0;
    $approvedApps = 0;
    $rejectedApps = 0;

    if (!empty($propIds)) {
        // Build simple placeholders for IN clause
        $inClause = implode(',', array_fill(0, count($propIds), '?'));
        $types = str_repeat('i', count($propIds));

        $stmtTotApp = $conn->prepare("SELECT COUNT(*) AS cnt FROM rental_applications WHERE property_id IN ($inClause)");
        $stmtTotApp->bind_param($types, ...$propIds);
        $stmtTotApp->execute();
        $totalApps = (int)($stmtTotApp->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmtTotApp->close();

        $stmtPendApp = $conn->prepare("SELECT COUNT(*) AS cnt FROM rental_applications WHERE property_id IN ($inClause) AND status = 'pending'");
        $stmtPendApp->bind_param($types, ...$propIds);
        $stmtPendApp->execute();
        $pendingApps = (int)($stmtPendApp->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmtPendApp->close();

        $stmtApprApp = $conn->prepare("SELECT COUNT(*) AS cnt FROM rental_applications WHERE property_id IN ($inClause) AND status = 'approved'");
        $stmtApprApp->bind_param($types, ...$propIds);
        $stmtApprApp->execute();
        $approvedApps = (int)($stmtApprApp->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmtApprApp->close();

        $stmtRejApp = $conn->prepare("SELECT COUNT(*) AS cnt FROM rental_applications WHERE property_id IN ($inClause) AND status = 'rejected'");
        $stmtRejApp->bind_param($types, ...$propIds);
        $stmtRejApp->execute();
        $rejectedApps = (int)($stmtRejApp->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmtRejApp->close();
    }

    // 5. Simple Queries: Maintenance Statistics
    $stmtTotMaint = $conn->prepare("SELECT COUNT(*) AS cnt FROM maintenance_requests WHERE landlord_id = ?");
    $stmtTotMaint->bind_param("i", $landlordId);
    $stmtTotMaint->execute();
    $totalMaintenance = (int)($stmtTotMaint->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmtTotMaint->close();

    $stmtPendMaint = $conn->prepare("SELECT COUNT(*) AS cnt FROM maintenance_requests WHERE landlord_id = ? AND status IN ('Pending', 'In Progress')");
    $stmtPendMaint->bind_param("i", $landlordId);
    $stmtPendMaint->execute();
    $pendingMaintenance = (int)($stmtPendMaint->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmtPendMaint->close();

    $stmtResMaint = $conn->prepare("SELECT COUNT(*) AS cnt FROM maintenance_requests WHERE landlord_id = ? AND status = 'Resolved'");
    $stmtResMaint->bind_param("i", $landlordId);
    $stmtResMaint->execute();
    $resolvedMaintenance = (int)($stmtResMaint->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmtResMaint->close();

    // 6. Simple Queries: Payment Summary & Recent Payments
    $stmtPaySum = $conn->prepare("
        SELECT COUNT(*) AS total_payments, COALESCE(SUM(amount), 0) AS total_revenue 
        FROM payments 
        WHERE landlord_id = ? AND status = 'paid'
    ");
    $stmtPaySum->bind_param("i", $landlordId);
    $stmtPaySum->execute();
    $paySummary = $stmtPaySum->get_result()->fetch_assoc();
    $stmtPaySum->close();

    $stmtRecentPayments = $conn->prepare("
        SELECT p.payment_id, p.amount, p.method, p.status, p.payment_month, p.created_at,
               u.full_name AS tenant_name, prop.title AS property_title
        FROM payments p
        JOIN users u ON p.student_id = u.user_id
        JOIN properties prop ON p.property_id = prop.property_id
        WHERE p.landlord_id = ?
        ORDER BY p.created_at DESC
        LIMIT 5
    ");
    $stmtRecentPayments->bind_param("i", $landlordId);
    $stmtRecentPayments->execute();
    $recentPayments = $stmtRecentPayments->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtRecentPayments->close();

    // 7. Simple Query: Recent Notifications
    $stmtNotifs = $conn->prepare("
        SELECT notification_id, notification_type, title, message, is_read, created_at 
        FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmtNotifs->bind_param("i", $landlordId);
    $stmtNotifs->execute();
    $recentNotifs = $stmtNotifs->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtNotifs->close();

    // 8. Dynamic Occupancy Rate Calculation
    $occupiedProperties = $rentedProperties; // status = 'rented'
    $calculatedAvailable = max(0, $totalProperties - $occupiedProperties);
    $occupancyRate = ($totalProperties > 0) ? round(($occupiedProperties / $totalProperties) * 100, 2) : 0.0;

    // 9. Dynamic 6-Month Monthly Revenue Calculation
    $endMonth = new DateTime('first day of this month');
    $monthsMap = [];
    for ($i = 5; $i >= 0; $i--) {
        $dt = (clone $endMonth)->modify("-$i month");
        $key = $dt->format('Y-m');
        $monthsMap[$key] = [
            'year'       => (int)$dt->format('Y'),
            'month'      => (int)$dt->format('n'),
            'month_name' => $dt->format('M'),
            'label'      => $dt->format('M Y'),
            'revenue'    => 0.0
        ];
    }

    $stmtMonthlyRev = $conn->prepare("
        SELECT 
            DATE_FORMAT(COALESCE(paid_at, payment_month, created_at), '%Y-%m') AS ym,
            COALESCE(SUM(amount), 0) AS monthly_total
        FROM payments
        WHERE landlord_id = ? AND status = 'paid'
        GROUP BY ym
    ");
    $stmtMonthlyRev->bind_param("i", $landlordId);
    $stmtMonthlyRev->execute();
    $resMonthlyRev = $stmtMonthlyRev->get_result();
    while ($row = $resMonthlyRev->fetch_assoc()) {
        $ym = $row['ym'];
        if (isset($monthsMap[$ym])) {
            $monthsMap[$ym]['revenue'] = (float)$row['monthly_total'];
        }
    }
    $stmtMonthlyRev->close();

    $sixMonthRevenue = array_values($monthsMap);
    $latestMonthData = end($sixMonthRevenue);

    // 10. Output Simple JSON Response
    echo json_encode([
        'success' => true,
        'message' => 'Landlord dashboard data retrieved successfully.',
        'data'    => [
            'user' => [
                'user_id'             => (int)$user['user_id'],
                'full_name'           => $user['full_name'],
                'email'               => $user['email'],
                'phone'               => $user['phone'],
                'avatar_url'          => $user['avatar_url'],
                'verification_status' => $profile['verification_status'] ?? 'pending'
            ],
            'properties' => [
                'total'     => $totalProperties,
                'available' => $availableProperties,
                'rented'    => $rentedProperties,
                'pending'   => $pendingProperties
            ],
            'occupancy' => [
                'total_properties'    => $totalProperties,
                'occupied_properties' => $occupiedProperties,
                'available_properties' => $calculatedAvailable,
                'occupancy_rate'      => $occupancyRate
            ],
            'monthly_revenue' => [
                'months'               => $sixMonthRevenue,
                'latest_month_label'   => $latestMonthData['label'] ?? '',
                'latest_month_revenue' => $latestMonthData['revenue'] ?? 0.0
            ],
            'applications' => [
                'total'    => $totalApps,
                'pending'  => $pendingApps,
                'approved' => $approvedApps,
                'rejected' => $rejectedApps
            ],
            'maintenance' => [
                'total'    => $totalMaintenance,
                'pending'  => $pendingMaintenance,
                'resolved' => $resolvedMaintenance
            ],
            'payments' => [
                'total_count'     => (int)($paySummary['total_payments'] ?? 0),
                'total_revenue'   => (float)($paySummary['total_revenue'] ?? 0),
                'recent_payments' => $recentPayments
            ],
            'recent_notifications' => $recentNotifs
        ]
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error retrieving dashboard information.'
    ]);
    exit;
}
