<?php
include 'db/config.php';

header('Content-Type: application/json; charset=utf-8');
// Change this in production:
// header('Access-Control-Allow-Origin: https://yourdomain.com');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$input = json_decode(file_get_contents('php://input'));
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode([
        "head" => ["code" => 400, "msg" => "Invalid JSON"]
    ]);
    exit;
}

$output = ["head" => ["code" => 400, "msg" => "Invalid request"]];

date_default_timezone_set('Asia/Calcutta');
$method = $_SERVER['REQUEST_METHOD'];

// Extract inputs safely
$unit_name    = isset($input->unit_name) ? trim($input->unit_name) : null;
$unit_code    = isset($input->unit_code) ? trim($input->unit_code) : null;
$company_id   = isset($input->company_id) ? trim($input->company_id) : null;
$user_id      = $input->user_id ?? null;
$created_name = $input->created_name ?? null;

// Action flags
$is_fetch     = $method === 'POST' && (isset($input->fetch_all) || isset($input->unit_code));
$is_create    = $method === 'POST' && $unit_name && $company_id && !isset($input->update_action) && !isset($input->delete_action);
$is_update    = in_array($method, ['POST', 'PUT']) && $unit_code && $company_id && isset($input->update_action);
$is_delete    = in_array($method, ['POST', 'DELETE']) && $unit_code && $company_id && isset($input->delete_action);

// ==================================================================
// 1. FETCH UNITS (by company_id or single unit_code)
// ==================================================================
if ($is_fetch) {
    if (empty($company_id)) {
        $output["head"]["msg"] = "company_id is required";
        goto end;
    }

    if (!empty($input->unit_code)) {
        // Fetch single unit
        $sql = "SELECT unit_code, unit_name, company_id, created_date 
                FROM unit 
                WHERE unit_code = ? AND company_id = ? AND deleted_at = 0";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $input->unit_code, $company_id);
    } else {
        // Fetch all units for company
        $sql = "SELECT unit_code, unit_name, company_id, created_date 
                FROM unit 
                WHERE company_id = ?  AND deleted_at = 0
                ORDER BY unit_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $company_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $units = $result->fetch_all(MYSQLI_ASSOC);

    if (count($units) > 0) {
        $output["head"] = ["code" => 200, "msg" => "Success"];
        $output["body"]["units"] = $units;
    } else {
        $output["head"] = ["code" => 404, "msg" => "No units found"];
    }
    $stmt->close();
    goto end;
}

// ==================================================================
// 2. CREATE UNIT
// ==================================================================
else if ($is_create) {
    if (empty($unit_name) || empty($company_id)) {
        $output["head"]["msg"] = "unit_name and company_id are required";
        goto end;
    }

    // Auto-generate unit_code: U001, U002...
    $sql = "SELECT unit_code FROM unit WHERE unit_code REGEXP '^U[0-9]+$' ORDER BY CAST(SUBSTRING(unit_code, 2) AS UNSIGNED) DESC LIMIT 1";
    $last = $conn->query($sql)->fetch_assoc();
    $next_num = $last ? ((int)substr($last['unit_code'], 1)) + 1 : 1;
    $unit_code = 'U' . str_pad($next_num, 4, '0', STR_PAD_LEFT); // U0001, U0002...

    // Check duplicate unit name
    $check = $conn->prepare("SELECT 1 FROM unit WHERE unit_name = ? AND company_id = ? AND deleted_at = 0");
    $check->bind_param("ss", $unit_name, $company_id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $output["head"]["msg"] = "Unit name already exists";
        $check->close();
        goto end;
    }
    $check->close();

    $stmt = $conn->prepare("INSERT INTO unit 
        (unit_code, unit_name, company_id, created_by, created_name, created_date) 
        VALUES (?, ?, ?, ?, ?, NOW())");

    $stmt->bind_param("sssss", $unit_code, $unit_name, $company_id, $user_id, $created_name);

    if ($stmt->execute()) {
        $output["head"] = ["code" => 200, "msg" => "Unit created successfully"];
        $output["body"] = [
            "unit_code" => $unit_code,
            "unit_name" => $unit_name
        ];
    } else {
        $output["head"] = ["code" => 500, "msg" => "Database error: " . $stmt->error];
    }
    $stmt->close();
    goto end;
}

// ==================================================================
// 3. UPDATE UNIT
// ==================================================================
else if ($is_update) {
    if (empty($unit_name)) {
        $output["head"]["msg"] = "unit_name is required for update";
        goto end;
    }

    // Check if unit exists and belongs to company
    $check = $conn->prepare("SELECT 1 FROM unit WHERE unit_code = ? AND company_id = ? AND deleted_at = 0");
    $check->bind_param("ss", $unit_code, $company_id);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        $output["head"]["msg"] = "Unit not found or access denied";
        $check->close();
        goto end;
    }
    $check->close();

    $stmt = $conn->prepare("UPDATE unit SET unit_name = ? WHERE unit_code = ? AND company_id = ?");
    $stmt->bind_param("sss", $unit_name, $unit_code, $company_id);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $output["head"] = ["code" => 200, "msg" => "Unit updated successfully"];
    } else {
        $output["head"]["msg"] = "No changes made or unit not found";
    }
    $stmt->close();
    goto end;
}

// ==================================================================
// 4. DELETE UNIT (Soft Delete)
// ==================================================================
else if ($is_delete) {
    $stmt = $conn->prepare("UPDATE unit SET deleted_at = NOW() WHERE unit_code = ? AND company_id = ? AND deleted_at = 0");
    $stmt->bind_param("ss", $unit_code, $company_id);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $output["head"] = ["code" => 200, "msg" => "Unit deleted successfully"];
    } else {
        $output["head"] = ["code" => 404, "msg" => "Unit not found or already deleted"];
    }
    $stmt->close();
    goto end;
}

// ==================================================================
// DEFAULT: Invalid Request
// ==================================================================
else {
    $output["head"]["msg"] = "Invalid action or parameters";
}

end:
$conn->close();
echo json_encode($output, JSON_UNESCAPED_UNICODE);
?>