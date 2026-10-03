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

$userId = isset($_GET['user_id']) ? $_GET['user_id'] : (isset($_GET['id']) ? $_GET['id'] : null);

if ($userId === null || !filter_var($userId, FILTER_VALIDATE_INT) || (int)$userId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid user ID.'
    ]);
    exit;
}

$userId = (int)$userId;

try {
    // 1. Fetch user base profile via MySQLi
    $stmtUser = $conn->prepare("
        SELECT 
            user_id, role_id, full_name, email, phone, bio, 
            institution, emergency_contact, avatar_url, 
            account_status, email_verified, phone_verified, 
            last_login_at, created_at
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmtUser->bind_param("i", $userId);
    $stmtUser->execute();
    $resUser = $stmtUser->get_result();
    $user = $resUser->fetch_assoc();
    $stmtUser->close();

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'User not found.'
        ]);
        exit;
    }

    // 2. Fetch role name
    $roleId = (int)$user['role_id'];
    $roleName = 'user';
    $stmtRole = $conn->prepare("SELECT role_name FROM roles WHERE role_id = ? LIMIT 1");
    $stmtRole->bind_param("i", $roleId);
    $stmtRole->execute();
    $resRole = $stmtRole->get_result();
    if ($r = $resRole->fetch_assoc()) {
        $roleName = strtolower($r['role_name']);
    }
    $stmtRole->close();

    $profileData = null;
    $extraStats = [];

    // 3. Separate simple queries for role-specific profiles
    if ($roleName === 'student') {
        $stmtSt = $conn->prepare("
            SELECT student_id, university_student_id, program, permanent_address, verification_status, verified_at
            FROM student_profiles
            WHERE student_id = ?
            LIMIT 1
        ");
        $stmtSt->bind_param("i", $userId);
        $stmtSt->execute();
        $resSt = $stmtSt->get_result();
        $profileData = $resSt->fetch_assoc();
        $stmtSt->close();

        // Application count for student
        $stmtAppCnt = $conn->prepare("SELECT COUNT(*) AS total_apps FROM rental_applications WHERE student_id = ?");
        $stmtAppCnt->bind_param("i", $userId);
        $stmtAppCnt->execute();
        $resAppCnt = $stmtAppCnt->get_result();
        if ($c = $resAppCnt->fetch_assoc()) {
            $extraStats['total_applications'] = (int)$c['total_apps'];
        }
        $stmtAppCnt->close();

    } else if ($roleName === 'landlord') {
        $stmtLl = $conn->prepare("
            SELECT landlord_id, trade_license_number, permanent_address, verification_status, verified_at, member_since
            FROM landlord_profiles
            WHERE landlord_id = ?
            LIMIT 1
        ");
        if ($stmtLl) {
            $stmtLl->bind_param("i", $userId);
            $stmtLl->execute();
            $resLl = $stmtLl->get_result();
            $profileData = $resLl->fetch_assoc();
            $stmtLl->close();
        }

        // Property count for landlord
        $stmtPropCnt = $conn->prepare("SELECT COUNT(*) AS total_props FROM properties WHERE landlord_id = ?");
        $stmtPropCnt->bind_param("i", $userId);
        $stmtPropCnt->execute();
        $resPropCnt = $stmtPropCnt->get_result();
        if ($c = $resPropCnt->fetch_assoc()) {
            $extraStats['total_properties'] = (int)$c['total_props'];
        }
        $stmtPropCnt->close();
    }

    echo json_encode([
        'success' => true,
        'message' => 'User details retrieved successfully.',
        'data'    => [
            'user'         => [
                'user_id'        => (int)$user['user_id'],
                'role_id'        => (int)$user['role_id'],
                'role'           => $roleName,
                'full_name'      => $user['full_name'],
                'email'          => $user['email'],
                'phone'          => $user['phone'],
                'bio'            => $user['bio'],
                'institution'    => $user['institution'],
                'emergency_contact' => $user['emergency_contact'],
                'avatar_url'     => $user['avatar_url'],
                'account_status' => $user['account_status'],
                'email_verified' => (bool)$user['email_verified'],
                'phone_verified' => (bool)$user['phone_verified'],
                'last_login_at'  => $user['last_login_at'],
                'created_at'     => $user['created_at']
            ],
            'profile'      => $profileData,
            'statistics'   => $extraStats
        ]
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error retrieving user details.'
    ]);
    exit;
}
