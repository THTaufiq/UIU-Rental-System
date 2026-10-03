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

try {
    // 1. Fetch roles map using MySQLi
    $rolesMap = [];
    $resRoles = $conn->query("SELECT role_id, role_name FROM roles");
    if ($resRoles) {
        while ($r = $resRoles->fetch_assoc()) {
            $rolesMap[(int)$r['role_id']] = strtolower($r['role_name']);
        }
    }

    // 2. Parse query parameters
    $roleFilter   = isset($_GET['role']) ? strtolower(trim($_GET['role'])) : 'all';
    $statusFilter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';
    $search       = isset($_GET['search']) ? trim($_GET['search']) : '';

    $whereClauses = [];
    $params = [];
    $types = '';

    // Role filter
    if ($roleFilter !== 'all' && !empty($roleFilter)) {
        $foundRoleId = null;
        foreach ($rolesMap as $rid => $rname) {
            if ($rname === $roleFilter) {
                $foundRoleId = $rid;
                break;
            }
        }
        if ($foundRoleId !== null) {
            $whereClauses[] = "user_id IN (SELECT user_id FROM users WHERE role_id = ?)";
            $params[] = $foundRoleId;
            $types .= 'i';
        }
    }

    // Account status filter
    $allowedStatuses = ['pending', 'active', 'suspended', 'rejected', 'deleted'];
    if ($statusFilter !== 'all' && in_array($statusFilter, $allowedStatuses, true)) {
        $whereClauses[] = "account_status = ?";
        $params[] = $statusFilter;
        $types .= 's';
    }

    // Search filter
    if ($search !== '') {
        $whereClauses[] = "(full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= 'sss';
    }

    $sql = "SELECT user_id, role_id, full_name, email, phone, account_status, created_at FROM users";
    if (!empty($whereClauses)) {
        $sql .= " WHERE " . implode(" AND ", $whereClauses);
    }
    $sql .= " ORDER BY user_id DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("MySQLi prepare failed: " . $conn->error);
    }

    if (!empty($params)) {
        $bindArgs = array_merge([$types], $params);
        $refs = [];
        foreach ($bindArgs as $key => $value) {
            $refs[$key] = &$bindArgs[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $rid = (int)$row['role_id'];
        $roleName = isset($rolesMap[$rid]) ? $rolesMap[$rid] : 'user';
        
        $users[] = [
            'user_id'        => (int)$row['user_id'],
            'role_id'        => $rid,
            'role'           => $roleName,
            'full_name'      => $row['full_name'],
            'email'          => $row['email'],
            'phone'          => $row['phone'],
            'account_status' => $row['account_status'],
            'created_at'     => $row['created_at']
        ];
    }
    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'Users list retrieved successfully.',
        'count'   => count($users),
        'data'    => $users
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error retrieving users list.'
    ]);
    exit;
}
