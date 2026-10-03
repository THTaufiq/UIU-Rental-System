<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Only allow GET request
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

// Require logged-in admin role
requireRole('admin');

try {
    // Helper function for simple count queries using MySQLi
    $getCount = function($sql, $params = [], $types = '') use ($conn) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        if (!empty($params)) {
            $bindArgs = array_merge([$types], $params);
            $refs = [];
            foreach ($bindArgs as $k => $v) {
                $refs[$k] = &$bindArgs[$k];
            }
            call_user_func_array([$stmt, 'bind_param'], $refs);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $count = 0;
        if ($row = $res->fetch_array()) {
            $count = (int)$row[0];
        }
        $stmt->close();
        return $count;
    };

    // Determine period filter for date-sensitive analytics (payments)
    $period = $_GET['period'] ?? '6months';
    $paymentDateCondition = "AND COALESCE(paid_at, created_at) >= DATE_SUB(NOW(), INTERVAL 6 MONTH)";
    if ($period === '12months' || $period === '12_months' || $period === 'Last 12 Months') {
        $paymentDateCondition = "AND COALESCE(paid_at, created_at) >= DATE_SUB(NOW(), INTERVAL 12 MONTH)";
    } elseif ($period === 'thisyear' || $period === 'this_year' || $period === 'This Year') {
        $paymentDateCondition = "AND YEAR(COALESCE(paid_at, created_at)) = YEAR(NOW())";
    }

    // 1. Users Statistics
    $totalUsers     = $getCount("SELECT COUNT(*) FROM users");
    $totalStudents  = $getCount("SELECT COUNT(*) FROM users WHERE role_id = 1");
    $totalLandlords = $getCount("SELECT COUNT(*) FROM users WHERE role_id = 2");
    $pendingUsers   = $getCount("SELECT COUNT(*) FROM users WHERE account_status = 'pending'");
    $activeUsers    = $getCount("SELECT COUNT(*) FROM users WHERE account_status = 'active'");
    $suspendedUsers = $getCount("SELECT COUNT(*) FROM users WHERE account_status = 'suspended'");

    // 2. Property Statistics
    $totalProperties     = $getCount("SELECT COUNT(*) FROM properties");
    $availableProperties = $getCount("SELECT COUNT(*) FROM properties WHERE status = 'approved'");
    $rentedProperties    = $getCount("SELECT COUNT(*) FROM properties WHERE status = 'rented'");
    $pendingProperties   = $getCount("SELECT COUNT(*) FROM properties WHERE status = 'pending'");
    $verifiedProperties  = $getCount("SELECT COUNT(*) FROM properties WHERE verified = 1");
    $unverifiedProperties= $getCount("SELECT COUNT(*) FROM properties WHERE verified = 0");

    // 3. Rental Application Statistics
    $totalApplications   = $getCount("SELECT COUNT(*) FROM rental_applications");
    $pendingApplications = $getCount("SELECT COUNT(*) FROM rental_applications WHERE status = 'pending'");
    $approvedApplications= $getCount("SELECT COUNT(*) FROM rental_applications WHERE status = 'approved'");
    $rejectedApplications= $getCount("SELECT COUNT(*) FROM rental_applications WHERE status = 'rejected'");

    // 4. Rental Agreement Statistics
    $totalAgreements     = $getCount("SELECT COUNT(*) FROM rental_agreements");
    $activeAgreements    = $getCount("SELECT COUNT(*) FROM rental_agreements WHERE status = 'active'");
    $completedAgreements = $getCount("SELECT COUNT(*) FROM rental_agreements WHERE status = 'completed'");

    // 5. Payment Statistics (Respecting Period Filter)
    $totalPayments      = $getCount("SELECT COUNT(*) FROM payments WHERE 1=1 " . $paymentDateCondition);
    $pendingPayments    = $getCount("SELECT COUNT(*) FROM payments WHERE status = 'pending' " . $paymentDateCondition);
    $completedPayments  = $getCount("SELECT COUNT(*) FROM payments WHERE status = 'paid' " . $paymentDateCondition);

    // Total Payment Amount (SUM with NULL handling in PHP, filtered by period)
    $totalPaymentAmount = 0.00;
    $stmtSum = $conn->prepare("SELECT SUM(amount) FROM payments WHERE status = 'paid' " . $paymentDateCondition);
    if ($stmtSum) {
        $stmtSum->execute();
        $resSum = $stmtSum->get_result();
        if ($rowSum = $resSum->fetch_array()) {
            $totalPaymentAmount = $rowSum[0] !== null ? (float)$rowSum[0] : 0.00;
        }
        $stmtSum->close();
    }

    // 6. Maintenance Statistics
    $totalMaintenance     = $getCount("SELECT COUNT(*) FROM maintenance_requests");
    $pendingMaintenance   = $getCount("SELECT COUNT(*) FROM maintenance_requests WHERE status = 'Pending'");
    $inProgressMaintenance= $getCount("SELECT COUNT(*) FROM maintenance_requests WHERE status = 'In Progress'");
    $resolvedMaintenance  = $getCount("SELECT COUNT(*) FROM maintenance_requests WHERE status = 'Resolved'");

    // 7. Review Statistics
    $totalReviews = $getCount("SELECT COUNT(*) FROM reviews");
    $averageRating = 0.0;
    $stmtAvg = $conn->prepare("SELECT AVG(rating) FROM reviews");
    if ($stmtAvg) {
        $stmtAvg->execute();
        $resAvg = $stmtAvg->get_result();
        if ($rowAvg = $resAvg->fetch_array()) {
            $averageRating = $rowAvg[0] !== null ? round((float)$rowAvg[0], 2) : 0.0;
        }
        $stmtAvg->close();
    }

    // 8. Contact Message Statistics
    $totalContact   = $getCount("SELECT COUNT(*) FROM contact_messages");
    $pendingContact = $getCount("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'");

    $reportData = [
        'users' => [
            'total_users'     => $totalUsers,
            'total_students'  => $totalStudents,
            'total_landlords' => $totalLandlords,
            'pending_users'   => $pendingUsers,
            'active_users'    => $activeUsers,
            'suspended_users' => $suspendedUsers,
        ],
        'properties' => [
            'total_properties'      => $totalProperties,
            'available_properties'  => $availableProperties,
            'rented_properties'     => $rentedProperties,
            'pending_properties'    => $pendingProperties,
            'verified_properties'   => $verifiedProperties,
            'unverified_properties' => $unverifiedProperties,
        ],
        'applications' => [
            'total_applications'   => $totalApplications,
            'pending_applications' => $pendingApplications,
            'approved_applications'=> $approvedApplications,
            'rejected_applications'=> $rejectedApplications,
        ],
        'agreements' => [
            'total_agreements'     => $totalAgreements,
            'active_agreements'    => $activeAgreements,
            'completed_agreements' => $completedAgreements,
        ],
        'payments' => [
            'total_payments'       => $totalPayments,
            'total_payment_amount' => $totalPaymentAmount,
            'pending_payments'     => $pendingPayments,
            'completed_payments'   => $completedPayments,
        ],
        'maintenance' => [
            'total_maintenance'     => $totalMaintenance,
            'pending_maintenance'   => $pendingMaintenance,
            'in_progress_maintenance'=> $inProgressMaintenance,
            'resolved_maintenance'  => $resolvedMaintenance,
        ],
        'reviews' => [
            'total_reviews'  => $totalReviews,
            'average_rating' => $averageRating,
        ],
        'contact' => [
            'total_contact'   => $totalContact,
            'pending_contact' => $pendingContact,
        ],
    ];

    echo json_encode([
        'success' => true,
        'message' => 'Admin reports retrieved successfully.',
        'data'    => $reportData
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error generating reports.'
    ]);
    exit;
}
