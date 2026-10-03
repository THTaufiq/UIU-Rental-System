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
        // 1. Fetch personal user settings
        $stmtUserSet = $conn->prepare("
            SELECT user_id, language, currency, email_notifications, 
                   application_notifications, payment_notifications, 
                   maintenance_notifications, chat_notifications, dark_mode, updated_at
            FROM user_settings
            WHERE user_id = ?
            LIMIT 1
        ");
        $stmtUserSet->bind_param("i", $adminId);
        $stmtUserSet->execute();
        $userSettings = $stmtUserSet->get_result()->fetch_assoc();

        if (!$userSettings) {
            // Insert default row for admin
            $stmtInsertSet = $conn->prepare("
                INSERT INTO user_settings (
                    user_id, language, currency, email_notifications, 
                    application_notifications, payment_notifications, 
                    maintenance_notifications, chat_notifications, dark_mode
                ) VALUES (
                    ?, 'English', 'BDT', 1, 1, 1, 1, 1, 0
                )
            ");
            $stmtInsertSet->bind_param("i", $adminId);
            $stmtInsertSet->execute();

            $stmtUserSet->execute();
            $userSettings = $stmtUserSet->get_result()->fetch_assoc();
        }

        // 2. Fetch platform settings
        $stmtPlat = $conn->query("SELECT setting_key, setting_value FROM platform_settings");
        $platRows = $stmtPlat ? $stmtPlat->fetch_all(MYSQLI_ASSOC) : [];
        $platformSettings = [];
        foreach ($platRows as $r) {
            $platformSettings[$r['setting_key']] = $r['setting_value'];
        }

        echo json_encode([
            'success' => true,
            'message' => 'Admin settings retrieved successfully.',
            'data'    => [
                'user_settings'     => $userSettings,
                'platform_settings' => $platformSettings
            ]
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error retrieving admin settings.'
        ]);
        exit;
    }
}

if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $action = isset($data['action']) ? trim($data['action']) : '';

    // A. Password change handler
    if ($action === 'change_password' || (isset($data['current_password']) && isset($data['new_password']))) {
        $currentPassword = isset($data['current_password']) ? trim($data['current_password']) : '';
        $newPassword     = isset($data['new_password']) ? trim($data['new_password']) : '';

        if (empty($currentPassword) || empty($newPassword)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Current password and new password are required.'
            ]);
            exit;
        }

        if (strlen($newPassword) < 6) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'New password must be at least 6 characters long.'
            ]);
            exit;
        }

        try {
            $stmtUser = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ? LIMIT 1");
            $stmtUser->bind_param("i", $adminId);
            $stmtUser->execute();
            $userRow = $stmtUser->get_result()->fetch_assoc();

            if (!$userRow || !password_verify($currentPassword, $userRow['password_hash'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Current password is incorrect.'
                ]);
                exit;
            }

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmtUpdatePass = $conn->prepare("
                UPDATE users 
                SET password_hash = ?, updated_at = NOW()
                WHERE user_id = ?
            ");
            $stmtUpdatePass->bind_param("si", $newHash, $adminId);
            $stmtUpdatePass->execute();

            echo json_encode([
                'success' => true,
                'message' => 'Password updated successfully.'
            ]);
            exit;

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Database error updating password.'
            ]);
            exit;
        }
    }

    // B. Personal user settings & platform settings update
    try {
        $conn->begin_transaction();

        // 1. Update personal user settings if present
        $hasUserSettingsInput = isset($data['language']) || isset($data['currency']) || 
                                isset($data['email_notifications']) || isset($data['dark_mode']);

        if ($hasUserSettingsInput) {
            // Check existing user settings
            $stmtCheck = $conn->prepare("SELECT user_id FROM user_settings WHERE user_id = ? LIMIT 1");
            $stmtCheck->bind_param("i", $adminId);
            $stmtCheck->execute();
            $exists = $stmtCheck->get_result()->fetch_assoc();

            if (!$exists) {
                $stmtIns = $conn->prepare("
                    INSERT INTO user_settings (user_id, language, currency, email_notifications, dark_mode)
                    VALUES (?, 'English', 'BDT', 1, 0)
                ");
                $stmtIns->bind_param("i", $adminId);
                $stmtIns->execute();
            }

            $language = isset($data['language']) ? trim($data['language']) : 'English';
            if (!in_array($language, ['English', 'বাংলা'], true)) {
                $language = 'English';
            }

            $currency = isset($data['currency']) ? trim($data['currency']) : 'BDT';
            if (!in_array($currency, ['BDT', 'USD'], true)) {
                $currency = 'BDT';
            }

            $emailNotif = isset($data['email_notifications']) ? (int)(bool)$data['email_notifications'] : 1;
            $appNotif   = isset($data['application_notifications']) ? (int)(bool)$data['application_notifications'] : 1;
            $payNotif   = isset($data['payment_notifications']) ? (int)(bool)$data['payment_notifications'] : 1;
            $maintNotif = isset($data['maintenance_notifications']) ? (int)(bool)$data['maintenance_notifications'] : 1;
            $chatNotif  = isset($data['chat_notifications']) ? (int)(bool)$data['chat_notifications'] : 1;
            $darkMode   = isset($data['dark_mode']) ? (int)(bool)$data['dark_mode'] : 0;

            $stmtUpdUserSet = $conn->prepare("
                UPDATE user_settings
                SET 
                    language = ?,
                    currency = ?,
                    email_notifications = ?,
                    application_notifications = ?,
                    payment_notifications = ?,
                    maintenance_notifications = ?,
                    chat_notifications = ?,
                    dark_mode = ?,
                    updated_at = NOW()
                WHERE user_id = ?
            ");
            $stmtUpdUserSet->bind_param("ssiiiiiii", $language, $currency, $emailNotif, $appNotif, $payNotif, $maintNotif, $chatNotif, $darkMode, $adminId);
            $stmtUpdUserSet->execute();
        }

        // 2. Update platform settings if platform keys passed
        $platformKeys = [
            'platform_name', 'support_email', 'commission_rate', 
            'service_charge', 'default_currency', 'minimum_student_age', 
            'mandatory_student_verification', 'mandatory_landlord_nid', 'maintenance_mode'
        ];

        foreach ($platformKeys as $key) {
            if (isset($data[$key])) {
                $val = is_bool($data[$key]) ? ($data[$key] ? '1' : '0') : (string)$data[$key];
                
                // Check if key exists
                $stmtKey = $conn->prepare("SELECT setting_id FROM platform_settings WHERE setting_key = ? LIMIT 1");
                $stmtKey->bind_param("s", $key);
                $stmtKey->execute();
                $row = $stmtKey->get_result()->fetch_assoc();

                if ($row) {
                    $stmtUpdKey = $conn->prepare("
                        UPDATE platform_settings 
                        SET setting_value = ?, updated_by = ?, updated_at = NOW()
                        WHERE setting_key = ?
                    ");
                    $stmtUpdKey->bind_param("sis", $val, $adminId, $key);
                    $stmtUpdKey->execute();
                } else {
                    $stmtInsKey = $conn->prepare("
                        INSERT INTO platform_settings (setting_key, setting_value, updated_by, updated_at)
                        VALUES (?, ?, ?, NOW())
                    ");
                    $stmtInsKey->bind_param("ssi", $key, $val, $adminId);
                    $stmtInsKey->execute();
                }
            }
        }

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Settings updated successfully.'
        ]);
        exit;

    } catch (Exception $e) {
        @$conn->rollback();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error updating settings.'
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
