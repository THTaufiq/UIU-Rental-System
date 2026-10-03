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
        // Fetch user core info and payout columns from users table
        $stmtUser = $conn->prepare("
            SELECT user_id, full_name, email, phone, avatar_url,
                   bkash_number, nagad_number, bank_name, bank_account_no, bank_routing_no
            FROM users 
            WHERE user_id = ? 
            LIMIT 1
        ");
        if ($stmtUser) {
            $stmtUser->bind_param("i", $landlordId);
            $stmtUser->execute();
            $resUser = $stmtUser->get_result();
            $user = $resUser->fetch_assoc();
            $stmtUser->close();
        }

        // Simple Query 1: Fetch user settings
        $stmtSet = $conn->prepare("
            SELECT user_id, language, currency, email_notifications, application_notifications, 
                   payment_notifications, maintenance_notifications, chat_notifications, dark_mode 
            FROM user_settings 
            WHERE user_id = ? 
            LIMIT 1
        ");
        if (!$stmtSet) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmtSet->bind_param("i", $landlordId);
        $stmtSet->execute();
        $resSet = $stmtSet->get_result();
        $settings = $resSet->fetch_assoc();
        $stmtSet->close();

        if (!$settings) {
            $settings = [
                'user_id'                   => $landlordId,
                'language'                  => 'English',
                'currency'                  => 'BDT',
                'email_notifications'       => 1,
                'application_notifications' => 1,
                'payment_notifications'     => 1,
                'maintenance_notifications' => 1,
                'chat_notifications'        => 1,
                'dark_mode'                 => 0
            ];
        }

        // Simple Query 2: Fetch landlord payout information
        $stmtPayout = $conn->prepare("
            SELECT payout_method, bkash_account, nagad_account, rocket_account, bank_name, bank_account_number 
            FROM landlord_profiles 
            WHERE landlord_id = ? 
            LIMIT 1
        ");
        if (!$stmtPayout) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmtPayout->bind_param("i", $landlordId);
        $stmtPayout->execute();
        $resPayout = $stmtPayout->get_result();
        $payout = $resPayout->fetch_assoc() ?: [
            'payout_method'       => 'bkash',
            'bkash_account'       => '',
            'nagad_account'       => '',
            'rocket_account'      => '',
            'bank_name'           => '',
            'bank_account_number' => ''
        ];
        $stmtPayout->close();

        $mergedPayout = [
            'payout_method'       => $payout['payout_method'] ?? 'bkash',
            'bkash_number'        => $user['bkash_number'] ?? ($payout['bkash_account'] ?? ''),
            'bkash_account'       => $payout['bkash_account'] ?? ($user['bkash_number'] ?? ''),
            'nagad_number'        => $user['nagad_number'] ?? ($payout['nagad_account'] ?? ''),
            'nagad_account'       => $payout['nagad_account'] ?? ($user['nagad_number'] ?? ''),
            'bank_name'           => $user['bank_name'] ?? ($payout['bank_name'] ?? ''),
            'bank_account_no'     => $user['bank_account_no'] ?? ($payout['bank_account_number'] ?? ''),
            'bank_account_number' => $payout['bank_account_number'] ?? ($user['bank_account_no'] ?? ''),
            'bank_routing_no'     => $user['bank_routing_no'] ?? ''
        ];

        echo json_encode([
            'success' => true,
            'message' => 'Landlord settings retrieved successfully.',
            'data'    => [
                'user'     => $user ?: ['user_id' => $landlordId, 'full_name' => 'Landlord', 'email' => ''],
                'settings' => $settings,
                'payout'   => $mergedPayout
            ]
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error retrieving settings.'
        ]);
        exit;
    }

} elseif ($method === 'POST' || $method === 'PUT') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $action = isset($data['action']) ? strtolower(trim($data['action'])) : 'preferences';

    try {
        if ($action === 'change_password') {
            $currentPassword = isset($data['current_password']) ? trim($data['current_password']) : '';
            $newPassword     = isset($data['new_password']) ? trim($data['new_password']) : '';
            $confirmPassword = isset($data['confirm_password']) ? trim($data['confirm_password']) : '';

            if (empty($currentPassword) || empty($newPassword)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Both current password and new password are required.'
                ]);
                exit;
            }

            if (!empty($confirmPassword) && $newPassword !== $confirmPassword) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'New password and confirm password do not match.'
                ]);
                exit;
            }

            // Fetch password hash
            $stmtUser = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ? LIMIT 1");
            if (!$stmtUser) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmtUser->bind_param("i", $landlordId);
            $stmtUser->execute();
            $resUser = $stmtUser->get_result();
            $user = $resUser->fetch_assoc();
            $stmtUser->close();

            if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Current password is incorrect.'
                ]);
                exit;
            }

            // Hash new password and update
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmtUpdPass = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
            if (!$stmtUpdPass) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmtUpdPass->bind_param("si", $newHash, $landlordId);
            $stmtUpdPass->execute();
            $stmtUpdPass->close();

            echo json_encode([
                'success' => true,
                'message' => 'Password changed successfully!'
            ]);
            exit;

        } elseif ($action === 'payout') {
            $payoutMethod = isset($data['payout_method']) ? trim($data['payout_method']) : 'bkash';
            $bkash        = isset($data['bkash_number']) ? trim($data['bkash_number']) : (isset($data['bkash_account']) ? trim($data['bkash_account']) : '');
            $nagad        = isset($data['nagad_number']) ? trim($data['nagad_number']) : (isset($data['nagad_account']) ? trim($data['nagad_account']) : '');
            $bankName     = isset($data['bank_name']) ? trim($data['bank_name']) : '';
            $bankAccount  = isset($data['bank_account_no']) ? trim($data['bank_account_no']) : (isset($data['bank_account_number']) ? trim($data['bank_account_number']) : '');
            $bankRouting  = isset($data['bank_routing_no']) ? trim($data['bank_routing_no']) : '';

            // 1. UPDATE users table for current authenticated landlord
            $stmtUpdUser = $conn->prepare("
                UPDATE users 
                SET 
                    bkash_number = ?,
                    nagad_number = ?,
                    bank_name = ?,
                    bank_account_no = ?,
                    bank_routing_no = ?
                WHERE user_id = ?
            ");
            if (!$stmtUpdUser) {
                throw new Exception("Prepare failed on users update: " . $conn->error);
            }
            $stmtUpdUser->bind_param("sssssi", $bkash, $nagad, $bankName, $bankAccount, $bankRouting, $landlordId);
            $stmtUpdUser->execute();
            $stmtUpdUser->close();

            // 2. UPDATE or INSERT landlord_profiles table for current authenticated landlord
            $stmtCheckP = $conn->prepare("SELECT 1 FROM landlord_profiles WHERE landlord_id = ? LIMIT 1");
            if ($stmtCheckP) {
                $stmtCheckP->bind_param("i", $landlordId);
                $stmtCheckP->execute();
                $resCheckP = $stmtCheckP->get_result();
                $hasP = (bool)$resCheckP->fetch_assoc();
                $stmtCheckP->close();

                if ($hasP) {
                    $stmtUpdP = $conn->prepare("
                        UPDATE landlord_profiles 
                        SET payout_method = ?, bkash_account = ?, nagad_account = ?, 
                            bank_name = ?, bank_account_number = ? 
                        WHERE landlord_id = ?
                    ");
                    if ($stmtUpdP) {
                        $stmtUpdP->bind_param("sssssi", $payoutMethod, $bkash, $nagad, $bankName, $bankAccount, $landlordId);
                        $stmtUpdP->execute();
                        $stmtUpdP->close();
                    }
                } else {
                    $stmtInsP = $conn->prepare("
                        INSERT INTO landlord_profiles (landlord_id, nid_number, payout_method, bkash_account, nagad_account, bank_name, bank_account_number)
                        VALUES (?, 'N/A', ?, ?, ?, ?, ?)
                    ");
                    if ($stmtInsP) {
                        $stmtInsP->bind_param("isssss", $landlordId, $payoutMethod, $bkash, $nagad, $bankName, $bankAccount);
                        $stmtInsP->execute();
                        $stmtInsP->close();
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'message' => 'Payout preferences saved successfully.'
            ]);
            exit;

        } else {
            // Update notification and general settings
            $language      = isset($data['language']) ? trim($data['language']) : 'English';
            $currency      = isset($data['currency']) ? trim($data['currency']) : 'BDT';
            $emailNotif    = isset($data['email_notifications']) ? (int)$data['email_notifications'] : 1;
            $appNotif      = isset($data['application_notifications']) ? (int)$data['application_notifications'] : 1;
            $payNotif      = isset($data['payment_notifications']) ? (int)$data['payment_notifications'] : 1;
            $maintNotif    = isset($data['maintenance_notifications']) ? (int)$data['maintenance_notifications'] : 1;
            $chatNotif     = isset($data['chat_notifications']) ? (int)$data['chat_notifications'] : 1;
            $darkMode      = isset($data['dark_mode']) ? (int)$data['dark_mode'] : 0;

            $stmtCheckS = $conn->prepare("SELECT 1 FROM user_settings WHERE user_id = ? LIMIT 1");
            if ($stmtCheckS) {
                $stmtCheckS->bind_param("i", $landlordId);
                $stmtCheckS->execute();
                $resCheckS = $stmtCheckS->get_result();
                $hasS = (bool)$resCheckS->fetch_assoc();
                $stmtCheckS->close();

                if ($hasS) {
                    $stmtUpdS = $conn->prepare("
                        UPDATE user_settings 
                        SET language = ?, currency = ?, email_notifications = ?, 
                            application_notifications = ?, payment_notifications = ?, 
                            maintenance_notifications = ?, chat_notifications = ?, dark_mode = ? 
                        WHERE user_id = ?
                    ");
                    if ($stmtUpdS) {
                        $stmtUpdS->bind_param("ssiiiiiii", $language, $currency, $emailNotif, $appNotif, $payNotif, $maintNotif, $chatNotif, $darkMode, $landlordId);
                        $stmtUpdS->execute();
                        $stmtUpdS->close();
                    }
                } else {
                    $stmtInsS = $conn->prepare("
                        INSERT INTO user_settings (user_id, language, currency, email_notifications, application_notifications, payment_notifications, maintenance_notifications, chat_notifications, dark_mode)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    if ($stmtInsS) {
                        $stmtInsS->bind_param("issiiiiii", $landlordId, $language, $currency, $emailNotif, $appNotif, $payNotif, $maintNotif, $chatNotif, $darkMode);
                        $stmtInsS->execute();
                        $stmtInsS->close();
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'message' => 'Settings saved successfully!'
            ]);
            exit;
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error saving settings.'
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
