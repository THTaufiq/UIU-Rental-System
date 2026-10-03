<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Require logged-in admin role
requireRole('admin');

// Derive identity from session
$adminId = getCurrentUserId();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    try {
        // Fetch admin user profile
        $stmt = $conn->prepare("
            SELECT 
                user_id, role_id, full_name, email, phone, bio, 
                institution, emergency_contact, avatar_url, 
                account_status, email_verified, phone_verified, created_at
            FROM users
            WHERE user_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Admin profile not found.'
            ]);
            exit;
        }

        // Fetch recent audit logs for this admin if available
        $stmtLogs = $conn->prepare("
            SELECT audit_id, action_type, table_name, record_id, created_at
            FROM audit_logs
            WHERE actor_user_id = ?
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $stmtLogs->bind_param("i", $adminId);
        $stmtLogs->execute();
        $auditLogs = $stmtLogs->get_result()->fetch_all(MYSQLI_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => 'Admin profile retrieved successfully.',
            'data'    => [
                'user'       => $user,
                'audit_logs' => $auditLogs
            ]
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error retrieving admin profile.'
        ]);
        exit;
    }
}

if ($method === 'POST') {
    // Read JSON payload or $_POST
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $fullName         = isset($data['full_name']) ? trim($data['full_name']) : '';
    $phone            = isset($data['phone']) ? trim($data['phone']) : null;
    $bio              = isset($data['bio']) ? trim($data['bio']) : null;
    $institution      = isset($data['institution']) ? trim($data['institution']) : null;
    $emergencyContact = isset($data['emergency_contact']) ? trim($data['emergency_contact']) : null;
    $avatarUrl        = isset($data['avatar_url']) ? trim($data['avatar_url']) : null;

    if (empty($fullName)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Full name cannot be empty.'
        ]);
        exit;
    }

    try {
        // Fetch current profile first to keep unprovided fields
        $stmtFetch = $conn->prepare("SELECT full_name, phone, bio, institution, emergency_contact, avatar_url FROM users WHERE user_id = ? LIMIT 1");
        $stmtFetch->bind_param("i", $adminId);
        $stmtFetch->execute();
        $curr = $stmtFetch->get_result()->fetch_assoc();

        if (!$curr) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Admin profile not found.'
            ]);
            exit;
        }

        $newFullName = !empty($fullName) ? $fullName : $curr['full_name'];
        $newPhone = ($phone !== null) ? $phone : $curr['phone'];
        $newBio = ($bio !== null) ? $bio : $curr['bio'];
        $newInstitution = ($institution !== null) ? $institution : $curr['institution'];
        $newEmergency = ($emergencyContact !== null) ? $emergencyContact : $curr['emergency_contact'];
        $newAvatar = ($avatarUrl !== null) ? $avatarUrl : $curr['avatar_url'];

        // Perform UPDATE query
        $stmtUpdate = $conn->prepare("
            UPDATE users
            SET 
                full_name = ?,
                phone = ?,
                bio = ?,
                institution = ?,
                emergency_contact = ?,
                avatar_url = ?,
                updated_at = NOW()
            WHERE user_id = ?
        ");
        $stmtUpdate->bind_param("ssssssi", $newFullName, $newPhone, $newBio, $newInstitution, $newEmergency, $newAvatar, $adminId);
        $stmtUpdate->execute();

        echo json_encode([
            'success' => true,
            'message' => 'Admin profile updated successfully.',
            'data'    => [
                'user_id'           => $adminId,
                'full_name'         => $newFullName,
                'phone'             => $newPhone,
                'bio'               => $newBio,
                'institution'       => $newInstitution,
                'emergency_contact' => $newEmergency,
                'avatar_url'        => $newAvatar
            ]
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error updating admin profile.'
        ]);
        exit;
    }
}

// Any other method
http_response_code(405);
echo json_encode([
    'success' => false,
    'message' => 'Invalid request method.'
]);
exit;
