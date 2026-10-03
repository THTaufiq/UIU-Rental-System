<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Strictly enforce GET method for read-only audit log viewing
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Audit logs are strictly read-only.'
    ]);
    exit;
}

// Require logged-in admin role
requireRole('admin');

try {
    // 1. Parse simple filters
    $actionType = isset($_GET['action_type']) ? trim($_GET['action_type']) : '';
    $tableName  = isset($_GET['table_name']) ? trim($_GET['table_name']) : '';
    $actorId    = isset($_GET['actor_user_id']) ? filter_var($_GET['actor_user_id'], FILTER_VALIDATE_INT) : null;
    $limit      = isset($_GET['limit']) ? min(max((int)$_GET['limit'], 1), 200) : 50;

    $whereClauses = [];
    $params = [];
    $types  = '';

    if ($actionType !== '') {
        $whereClauses[] = "action_type = ?";
        $params[] = $actionType;
        $types .= 's';
    }

    if ($tableName !== '') {
        $whereClauses[] = "table_name = ?";
        $params[] = $tableName;
        $types .= 's';
    }

    if ($actorId !== null && $actorId > 0) {
        $whereClauses[] = "actor_user_id = ?";
        $params[] = $actorId;
        $types .= 'i';
    }

    $sql = "SELECT audit_id, actor_user_id, action_type, table_name, record_id, old_data, new_data, ip_address, created_at FROM audit_logs";
    if (!empty($whereClauses)) {
        $sql .= " WHERE " . implode(" AND ", $whereClauses);
    }
    $sql .= " ORDER BY audit_id DESC LIMIT ?";
    $params[] = $limit;
    $types .= 'i';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("MySQLi prepare failed: " . $conn->error);
    }

    $bindArgs = array_merge([$types], $params);
    $refs = [];
    foreach ($bindArgs as $key => $value) {
        $refs[$key] = &$bindArgs[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);

    $stmt->execute();
    $result = $stmt->get_result();

    $logs = [];
    $actorIds = [];
    while ($row = $result->fetch_assoc()) {
        $auditId = (int)$row['audit_id'];
        $actId   = $row['actor_user_id'] !== null ? (int)$row['actor_user_id'] : null;
        if ($actId) {
            $actorIds[$actId] = true;
        }

        $logs[] = [
            'audit_id'      => $auditId,
            'actor_user_id' => $actId,
            'action_type'   => $row['action_type'],
            'table_name'    => $row['table_name'],
            'record_id'     => $row['record_id'] !== null ? (int)$row['record_id'] : null,
            'old_data'      => $row['old_data'],
            'new_data'      => $row['new_data'],
            'ip_address'    => $row['ip_address'],
            'created_at'    => $row['created_at']
        ];
    }
    $stmt->close();

    // 2. Fetch actor name & email in a separate simple query (NO giant JOINs)
    $userMap = [];
    if (!empty($actorIds)) {
        $idList = implode(',', array_map('intval', array_keys($actorIds)));
        $resUsers = $conn->query("SELECT user_id, full_name, email FROM users WHERE user_id IN ($idList)");
        if ($resUsers) {
            while ($uRow = $resUsers->fetch_assoc()) {
                $userMap[(int)$uRow['user_id']] = [
                    'full_name' => $uRow['full_name'],
                    'email'     => $uRow['email']
                ];
            }
        }
    }

    // Attach safe actor info
    foreach ($logs as &$logItem) {
        $aId = $logItem['actor_user_id'];
        if ($aId && isset($userMap[$aId])) {
            $logItem['actor_name']  = $userMap[$aId]['full_name'];
            $logItem['actor_email'] = $userMap[$aId]['email'];
        } else {
            $logItem['actor_name']  = 'System / Unknown';
            $logItem['actor_email'] = null;
        }
    }
    unset($logItem);

    echo json_encode([
        'success' => true,
        'message' => 'Audit logs retrieved successfully.',
        'count'   => count($logs),
        'data'    => $logs
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error retrieving audit logs.'
    ]);
    exit;
}
