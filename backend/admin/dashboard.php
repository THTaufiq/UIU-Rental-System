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
        'message' => 'Invalid request method.'
    ]);
    exit;
}

// Require logged-in admin role
requireRole('admin');

// Derive identity from session
$adminId = getCurrentUserId();

try {
    // 1. Fetch current admin details
    $stmtUser = $conn->prepare("
        SELECT user_id, role_id, full_name, email, phone, avatar_url, account_status, created_at
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmtUser->bind_param("i", $adminId);
    $stmtUser->execute();
    $resUser = $stmtUser->get_result();
    $adminInfo = $resUser ? $resUser->fetch_assoc() : null;

    if (!$adminInfo) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Admin user account not found.'
        ]);
        exit;
    }

    // 2. Simple individual queries for statistics
    // User stats
    $totalUsers     = (int)($conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0] ?? 0);
    $totalStudents  = (int)($conn->query("SELECT COUNT(*) FROM users WHERE role_id = 1")->fetch_row()[0] ?? 0);
    $totalLandlords = (int)($conn->query("SELECT COUNT(*) FROM users WHERE role_id = 2")->fetch_row()[0] ?? 0);
    $totalAdmins    = (int)($conn->query("SELECT COUNT(*) FROM users WHERE role_id = 3")->fetch_row()[0] ?? 0);

    $pendingUsers   = (int)($conn->query("SELECT COUNT(*) FROM users WHERE account_status = 'pending'")->fetch_row()[0] ?? 0);
    $activeUsers    = (int)($conn->query("SELECT COUNT(*) FROM users WHERE account_status = 'active'")->fetch_row()[0] ?? 0);
    $suspendedUsers = (int)($conn->query("SELECT COUNT(*) FROM users WHERE account_status = 'suspended'")->fetch_row()[0] ?? 0);

    // Property stats
    $totalProperties     = (int)($conn->query("SELECT COUNT(*) FROM properties")->fetch_row()[0] ?? 0);
    $availableProperties = (int)($conn->query("SELECT COUNT(*) FROM properties WHERE status = 'approved'")->fetch_row()[0] ?? 0);
    $rentedProperties    = (int)($conn->query("SELECT COUNT(*) FROM properties WHERE status = 'rented'")->fetch_row()[0] ?? 0);
    $pendingProperties   = (int)($conn->query("SELECT COUNT(*) FROM properties WHERE status = 'pending' OR verified = 0")->fetch_row()[0] ?? 0);

    // Revenue stats (Current month paid revenue)
    $currentYear = (int)date('Y');
    $currentMonth = (int)date('m');

    $stmtRev = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM payments
        WHERE status = 'paid'
          AND (
            (YEAR(paid_at) = ? AND MONTH(paid_at) = ?)
            OR (paid_at IS NULL AND YEAR(created_at) = ? AND MONTH(created_at) = ?)
          )
    ");
    $stmtRev->bind_param("iiii", $currentYear, $currentMonth, $currentYear, $currentMonth);
    $stmtRev->execute();
    $resRev = $stmtRev->get_result();
    $monthlyRevenue = (float)($resRev ? ($resRev->fetch_row()[0] ?? 0) : 0);
    $stmtRev->close();

    // 6-Month Platform Growth (Monthly paid revenue)
    $monthlyGrowth = [];
    for ($i = 5; $i >= 0; $i--) {
        $time = strtotime("-$i months");
        $y = (int)date('Y', $time);
        $m = (int)date('m', $time);
        $label = date('M', $time);

        $stmtM = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM payments
            WHERE status = 'paid'
              AND (
                (YEAR(paid_at) = ? AND MONTH(paid_at) = ?)
                OR (paid_at IS NULL AND YEAR(created_at) = ? AND MONTH(created_at) = ?)
              )
        ");
        $stmtM->bind_param("iiii", $y, $m, $y, $m);
        $stmtM->execute();
        $resM = $stmtM->get_result();
        $val = (float)($resM ? ($resM->fetch_row()[0] ?? 0) : 0);
        $stmtM->close();

        $monthlyGrowth[] = [
            'month' => $label,
            'value' => $val
        ];
    }

    // Operational pending stats
    $pendingApplications = (int)($conn->query("SELECT COUNT(*) FROM rental_applications WHERE status = 'pending'")->fetch_row()[0] ?? 0);
    $pendingMaintenance  = (int)($conn->query("SELECT COUNT(*) FROM maintenance_requests WHERE status = 'Pending'")->fetch_row()[0] ?? 0);

    // Admin unread notifications
    $stmtNotifCount = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmtNotifCount->bind_param("i", $adminId);
    $stmtNotifCount->execute();
    $resNotifCount = $stmtNotifCount->get_result();
    $unreadNotifications = (int)($resNotifCount ? $resNotifCount->fetch_row()[0] : 0);

    // 3. Fetch recent users (last 5)
    $resRecentUsers = $conn->query("
        SELECT user_id, full_name, email, role_id, account_status, created_at
        FROM users
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $recentUsers = $resRecentUsers ? $resRecentUsers->fetch_all(MYSQLI_ASSOC) : [];

    // 4. Fetch recent properties (last 5)
    $resRecentProps = $conn->query("
        SELECT property_id, title, monthly_rent, status, verified, created_at
        FROM properties
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $recentProperties = $resRecentProps ? $resRecentProps->fetch_all(MYSQLI_ASSOC) : [];

    // Send successful response
    echo json_encode([
        'success' => true,
        'message' => 'Admin dashboard data retrieved successfully.',
        'data'    => [
            'user' => [
                'user_id'        => (int)$adminInfo['user_id'],
                'role_id'        => (int)$adminInfo['role_id'],
                'full_name'      => $adminInfo['full_name'],
                'email'          => $adminInfo['email'],
                'phone'          => $adminInfo['phone'],
                'avatar_url'     => $adminInfo['avatar_url'],
                'account_status' => $adminInfo['account_status'],
                'created_at'     => $adminInfo['created_at']
            ],
            'statistics' => [
                'total_users'                  => $totalUsers,
                'total_students'               => $totalStudents,
                'total_landlords'              => $totalLandlords,
                'total_admins'                 => $totalAdmins,
                'pending_users'                => $pendingUsers,
                'active_users'                 => $activeUsers,
                'suspended_users'              => $suspendedUsers,
                'total_properties'             => $totalProperties,
                'available_properties'         => $availableProperties,
                'rented_properties'            => $rentedProperties,
                'pending_properties'           => $pendingProperties,
                'pending_verifications'        => $pendingProperties,
                'monthly_revenue'              => $monthlyRevenue,
                'monthly_growth'               => $monthlyGrowth,
                'pending_rental_applications'  => $pendingApplications,
                'pending_maintenance_requests' => $pendingMaintenance,
                'unread_notifications'         => $unreadNotifications
            ],
            'recent_users'      => $recentUsers,
            'recent_properties' => $recentProperties
        ]
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error retrieving admin dashboard data.'
    ]);
    exit;
}

