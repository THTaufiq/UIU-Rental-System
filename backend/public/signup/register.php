<?php
// Start PHP session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set JSON response header
header('Content-Type: application/json');

// Include database connection
require_once __DIR__ . '/../../common/db.php';

// Only allow POST requests
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
    exit;
}

// Read input (support JSON payload and FormData/$_POST)
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    $data = $_POST;
}

// Extract form parameters
$fullName        = isset($data['full_name']) ? trim($data['full_name']) : '';
$phone           = isset($data['phone']) ? trim($data['phone']) : '';
$email           = isset($data['email']) ? trim($data['email']) : '';
$password        = isset($data['password']) ? $data['password'] : '';
$confirmPassword = isset($data['confirm_password']) ? $data['confirm_password'] : '';
$role            = isset($data['role']) ? trim(strtolower($data['role'])) : '';

// Role specific fields
$studentId = isset($data['student_id']) ? trim($data['student_id']) : '';
$program   = isset($data['program']) ? trim($data['program']) : '';
$nidNumber = isset($data['nid_number']) ? trim($data['nid_number']) : '';

// 1. Basic validation
if (empty($fullName) || empty($phone) || empty($email) || empty($password) || empty($confirmPassword) || empty($role)) {
    echo json_encode([
        'success' => false,
        'message' => 'Please fill in all required fields.'
    ]);
    exit;
}

// 2. Validate role (Public signup allowed only for student and landlord)
if (!in_array($role, ['student', 'landlord'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid role selected. Public signup is restricted to Students and Landlords.'
    ]);
    exit;
}

// 3. Email validation
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'success' => false,
        'message' => 'Please provide a valid email address.'
    ]);
    exit;
}

// 4. Password validation
if ($password !== $confirmPassword) {
    echo json_encode([
        'success' => false,
        'message' => 'Password and Confirm Password do not match.'
    ]);
    exit;
}

// 5. Role-specific validation
if ($role === 'student') {
    if (empty($studentId) || empty($program)) {
        echo json_encode([
            'success' => false,
            'message' => 'Student ID and Program selection are required for student accounts.'
        ]);
        exit;
    }
} elseif ($role === 'landlord') {
    if (empty($nidNumber)) {
        echo json_encode([
            'success' => false,
            'message' => 'NID or Trade License number is required for landlord accounts.'
        ]);
        exit;
    }
}

// 6. Document upload handling
$docPath = null;
if (isset($_FILES['verification_document']) && $_FILES['verification_document']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['verification_document'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode([
            'success' => false,
            'message' => 'An error occurred during file upload.'
        ]);
        exit;
    }

    // Validate size (Max 5MB)
    $maxSize = 5 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        echo json_encode([
            'success' => false,
            'message' => 'Uploaded document exceeds the maximum limit of 5MB.'
        ]);
        exit;
    }

    // Validate file extension & MIME type
    $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    $allowedMimeTypes  = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    $fileExt  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($fileExt, $allowedExtensions) || !in_array($mimeType, $allowedMimeTypes)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid file format. Only PDF, JPG, PNG, and WEBP documents are allowed.'
        ]);
        exit;
    }

    // Destination directory
    $uploadDir = __DIR__ . '/../../uploads/documents/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $uniqueName = 'doc_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
    $targetPath = $uploadDir . $uniqueName;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        $docPath = 'uploads/documents/' . $uniqueName;
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to save uploaded verification document.'
        ]);
        exit;
    }
}

try {
    // 7. Check duplicate email in `users` table using MySQLi
    $stmtCheckEmail = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
    if (!$stmtCheckEmail) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmtCheckEmail->bind_param("s", $email);
    $stmtCheckEmail->execute();
    $resCheckEmail = $stmtCheckEmail->get_result();
    if ($resCheckEmail->fetch_assoc()) {
        $stmtCheckEmail->close();
        echo json_encode([
            'success' => false,
            'message' => 'An account with this email address already exists.'
        ]);
        exit;
    }
    $stmtCheckEmail->close();

    // 8. Check role-specific duplicates
    if ($role === 'student') {
        $stmtCheckStudent = $conn->prepare("SELECT student_id FROM student_profiles WHERE university_student_id = ? LIMIT 1");
        if ($stmtCheckStudent) {
            $stmtCheckStudent->bind_param("s", $studentId);
            $stmtCheckStudent->execute();
            $resCheckStudent = $stmtCheckStudent->get_result();
            if ($resCheckStudent->fetch_assoc()) {
                $stmtCheckStudent->close();
                echo json_encode([
                    'success' => false,
                    'message' => 'An account with this Student ID already exists.'
                ]);
                exit;
            }
            $stmtCheckStudent->close();
        }
    } elseif ($role === 'landlord') {
        $stmtCheckNid = $conn->prepare("SELECT landlord_id FROM landlord_profiles WHERE nid_number = ? LIMIT 1");
        if ($stmtCheckNid) {
            $stmtCheckNid->bind_param("s", $nidNumber);
            $stmtCheckNid->execute();
            $resCheckNid = $stmtCheckNid->get_result();
            if ($resCheckNid->fetch_assoc()) {
                $stmtCheckNid->close();
                echo json_encode([
                    'success' => false,
                    'message' => 'An account with this NID / Trade License number already exists.'
                ]);
                exit;
            }
            $stmtCheckNid->close();
        }
    }

    // 9. Fetch matching role_id from database
    $stmtRole = $conn->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) = ? LIMIT 1");
    if (!$stmtRole) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmtRole->bind_param("s", $role);
    $stmtRole->execute();
    $resRole = $stmtRole->get_result();
    $roleRow = $resRole->fetch_assoc();
    $stmtRole->close();

    if (!$roleRow) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid role specified in the system.'
        ]);
        exit;
    }
    $roleId = (int)$roleRow['role_id'];

    // 10. Hash password
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // 11. Execute MySQLi Transaction
    $conn->begin_transaction();

    // Insert into users
    $stmtUser = $conn->prepare("
        INSERT INTO users (role_id, full_name, email, phone, password_hash, account_status)
        VALUES (?, ?, ?, ?, ?, 'pending')
    ");
    if (!$stmtUser) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmtUser->bind_param("issss", $roleId, $fullName, $email, $phone, $passwordHash);
    $stmtUser->execute();
    $newUserId = (int)$conn->insert_id;
    $stmtUser->close();

    // Insert into role-specific profile
    if ($role === 'student') {
        $stmtStudent = $conn->prepare("
            INSERT INTO student_profiles (student_id, university_student_id, program, verification_document, verification_status)
            VALUES (?, ?, ?, ?, 'pending')
        ");
        if (!$stmtStudent) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmtStudent->bind_param("isss", $newUserId, $studentId, $program, $docPath);
        $stmtStudent->execute();
        $stmtStudent->close();
    } elseif ($role === 'landlord') {
        $stmtLandlord = $conn->prepare("
            INSERT INTO landlord_profiles (landlord_id, nid_number, verification_document, verification_status, member_since)
            VALUES (?, ?, ?, 'pending', CURRENT_DATE)
        ");
        if (!$stmtLandlord) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmtLandlord->bind_param("iss", $newUserId, $nidNumber, $docPath);
        $stmtLandlord->execute();
        $stmtLandlord->close();
    }

    // Insert into user_settings
    $stmtSettings = $conn->prepare("INSERT INTO user_settings (user_id) VALUES (?)");
    if (!$stmtSettings) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmtSettings->bind_param("i", $newUserId);
    $stmtSettings->execute();
    $stmtSettings->close();

    // Commit transaction
    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Registration successful! Your account has been created.'
    ]);
    exit;

} catch (Exception $e) {
    @$conn->rollback();
    echo json_encode([
        'success' => false,
        'message' => 'A database error occurred during registration. Please try again.'
    ]);
    exit;
}
