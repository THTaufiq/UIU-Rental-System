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

if ($method === 'GET') {
    try {
        // Simple Query 1: Fetch user core info
        $stmtUser = $conn->prepare("
            SELECT user_id, role_id, full_name, email, phone, avatar_url, bio, emergency_contact, 
                   account_status, email_verified, phone_verified, created_at 
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
                'message' => 'Landlord profile not found.'
            ]);
            exit;
        }

        // Simple Query 2: Fetch landlord profile details
        $stmtProf = $conn->prepare("
            SELECT nid_number, trade_license_number, permanent_address, verification_document, 
                   verification_status, member_since, payout_method, bkash_account, nagad_account, 
                   rocket_account, bank_name, bank_account_number 
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
        $landlordProfile = $resProf->fetch_assoc() ?: [
            'nid_number'            => 'N/A',
            'trade_license_number' => null,
            'permanent_address'     => '',
            'verification_document' => null,
            'verification_status'   => 'pending',
            'member_since'          => date('Y-m-d')
        ];
        $stmtProf->close();

        // Simple Query 3: Property Count
        $stmtPropCount = $conn->prepare("SELECT COUNT(*) AS cnt FROM properties WHERE landlord_id = ?");
        $stmtPropCount->bind_param("i", $landlordId);
        $stmtPropCount->execute();
        $propertyCount = (int)($stmtPropCount->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmtPropCount->close();

        // Simple Query 4: Active Tenant Count
        $stmtTenantCount = $conn->prepare("
            SELECT COUNT(DISTINCT student_id) AS cnt 
            FROM rental_agreements 
            WHERE landlord_id = ? AND status = 'active'
        ");
        $stmtTenantCount->bind_param("i", $landlordId);
        $stmtTenantCount->execute();
        $activeTenants = (int)($stmtTenantCount->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmtTenantCount->close();

        echo json_encode([
            'success' => true,
            'message' => 'Landlord profile retrieved successfully.',
            'data'    => [
                'user'             => $user,
                'landlord_profile' => $landlordProfile,
                'stats'            => [
                    'properties'     => $propertyCount,
                    'active_tenants' => $activeTenants,
                    'rating'         => 4.8
                ]
            ]
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error retrieving profile.'
        ]);
        exit;
    }

} elseif ($method === 'POST' || $method === 'PUT') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $fullName  = isset($data['full_name']) ? trim($data['full_name']) : null;
    $phone     = isset($data['phone']) ? trim($data['phone']) : null;
    $bio       = isset($data['bio']) ? trim($data['bio']) : null;
    $emergency = isset($data['emergency_contact']) ? trim($data['emergency_contact']) : null;
    $address   = isset($data['permanent_address']) ? trim($data['permanent_address']) : null;

    try {
        // Fetch current user values to avoid blank overwrites
        $stmtCur = $conn->prepare("SELECT full_name, phone, bio, emergency_contact FROM users WHERE user_id = ? LIMIT 1");
        if (!$stmtCur) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmtCur->bind_param("i", $landlordId);
        $stmtCur->execute();
        $resCur = $stmtCur->get_result();
        $curUser = $resCur->fetch_assoc();
        $stmtCur->close();

        if (!$curUser) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Landlord account not found.'
            ]);
            exit;
        }

        $newFullName  = $fullName !== null && $fullName !== '' ? $fullName : $curUser['full_name'];
        $newPhone     = $phone !== null && $phone !== '' ? $phone : $curUser['phone'];
        $newBio       = $bio !== null ? $bio : $curUser['bio'];
        $newEmergency = $emergency !== null ? $emergency : $curUser['emergency_contact'];

        // Simple Update 1: Update users table
        $stmtUpdUser = $conn->prepare("
            UPDATE users 
            SET full_name = ?, phone = ?, bio = ?, emergency_contact = ?
            WHERE user_id = ?
        ");
        if (!$stmtUpdUser) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmtUpdUser->bind_param("ssssi", $newFullName, $newPhone, $newBio, $newEmergency, $landlordId);
        $stmtUpdUser->execute();
        $stmtUpdUser->close();

        // Simple Check & Update 2: Update landlord_profiles table if permanent_address provided
        if ($address !== null) {
            $stmtCheckProf = $conn->prepare("SELECT 1 FROM landlord_profiles WHERE landlord_id = ? LIMIT 1");
            if ($stmtCheckProf) {
                $stmtCheckProf->bind_param("i", $landlordId);
                $stmtCheckProf->execute();
                $resCheckProf = $stmtCheckProf->get_result();
                $hasProf = (bool)$resCheckProf->fetch_assoc();
                $stmtCheckProf->close();

                if ($hasProf) {
                    $stmtUpdProf = $conn->prepare("
                        UPDATE landlord_profiles 
                        SET permanent_address = ?
                        WHERE landlord_id = ?
                    ");
                    if ($stmtUpdProf) {
                        $stmtUpdProf->bind_param("si", $address, $landlordId);
                        $stmtUpdProf->execute();
                        $stmtUpdProf->close();
                    }
                }
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Landlord profile updated successfully!',
            'data'    => [
                'user_id'   => $landlordId,
                'full_name' => $newFullName,
                'phone'     => $newPhone
            ]
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error updating profile.'
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
