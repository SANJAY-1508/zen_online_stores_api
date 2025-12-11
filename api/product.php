<?php
include 'db/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit();
}

$json = file_get_contents('php://input');
$obj = json_decode($json);
$output = array();

date_default_timezone_set('Asia/Calcutta');

// Input variables 
$company_id     = isset($obj->company_id) ? trim($obj->company_id) : null;
$product_id     = isset($obj->product_id) ? trim($obj->product_id) : null; 
$product_name   = isset($obj->product_name) ? trim($obj->product_name) : null;
$product_img    = isset($obj->product_img) ? $obj->product_img : null; // Base64 string
$product_code   = isset($obj->product_code) ? trim($obj->product_code) : null;
$unit_code      = isset($obj->unit_code) ? trim($obj->unit_code) : null;
$category_code  = isset($obj->category_code) ? trim($obj->category_code) : null;

$product_price  = isset($obj->product_price) ? floatval($obj->product_price) : null;
$product_stock  = isset($obj->product_stock) ? intval($obj->product_stock) : null;
$product_disc   = isset($obj->product_disc) ? floatval($obj->product_disc) : null;
$product_disc_amt = isset($obj->product_disc_amt) ? floatval($obj->product_disc_amt) : null;

$id             = isset($obj->id) ? $obj->id : null; 
$user_id        = isset($obj->user_id) ? $obj->user_id : null; 
$created_name   = isset($obj->created_name) ? $obj->created_name : null; 

// Helper flags
$method = $_SERVER['REQUEST_METHOD'];
$is_read_action = isset($obj->fetch_all) || (isset($obj->id) && !isset($obj->update_action) && !isset($obj->delete_action));
$is_create_action = $product_name && $product_code && $company_id && $unit_code && $category_code && !isset($obj->id) && !isset($obj->product_id) && !isset($obj->update_action) && !isset($obj->delete_action);
$is_update_action = $product_id && $company_id && isset($obj->update_action); 
$is_delete_action = $product_id && $company_id && isset($obj->delete_action);

// Utility function to generate a product ID (e.g., PROD-000001)
function generateProductId($conn) {
    // Fetches the MAX(id) and uses it to generate the next product_id string
    $sql = "SELECT MAX(`id`) as max_id FROM `product`";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    $next_id = ($row['max_id'] ?? 0) + 1;
    return 'PROD-' . str_pad($next_id, 6, '0', STR_PAD_LEFT);
}


// ===================================================================
// Image Upload Function (Base64 → WebP)
// ===================================================================
function saveBase64Image($base64String, $uploadDir = "../uploads/products/")
{
    if (empty($base64String)) return null;

    // Create directory if not exists
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Remove data URL prefix
    if (preg_match('/^data:image\/[a-z]+;base64,/', $base64String)) {
        $base64String = preg_replace('/^data:image\/[a-z]+;base64,/', '', $base64String);
    }

    $imageData = base64_decode($base64String);
    if ($imageData === false) return null;

    $source = @imagecreatefromstring($imageData);
    if ($source === false) return null;

    $timestamp = str_replace([' ', ':'], '-', date('Y-m-d H:i:s'));
    $filename = $timestamp . '.webp';
    $filepath = $uploadDir . $filename;

    $success = imagewebp($source, $filepath, 80); // 80% quality
    imagedestroy($source);

    return $success ? $filename : null;
}

// ===================================================================
// R - READ (Fetch Products + Full Image URL)
// ===================================================================
if ($method === 'POST' && $is_read_action) {
    
    if (empty($company_id)) {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Company ID is required.";
        goto end_script;
    }

    $select_cols = "p.*, u.unit_name, c.category_name";
    $from_tables = "`product` p 
                    LEFT JOIN `unit` u ON p.unit_code = u.unit_code AND u.deleted_at = 0
                    LEFT JOIN `category` c ON p.category_code = c.category_code AND c.deleted_at = 0";
    $where_clause = "p.company_id = ? AND p.deleted_at = 0";

    if (isset($obj->id) && $obj->id !== null) {
        $sql = "SELECT {$select_cols} FROM {$from_tables} WHERE {$where_clause} AND p.id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $company_id, $id);
    } else if (isset($obj->product_id) && $obj->product_id !== null) {
        $sql = "SELECT {$select_cols} FROM {$from_tables} WHERE {$where_clause} AND p.product_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $company_id, $product_id);
    } else {
        $sql = "SELECT {$select_cols} FROM {$from_tables} WHERE {$where_clause} ORDER BY p.product_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $company_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $products = [];

    $baseUrl = "http://" . $_SERVER['SERVER_NAME'] . "/zen_online_stores/uploads/products/";

    while ($row = $result->fetch_assoc()) {
        if (!empty($row['product_img'])) {
            $row['product_img_url'] = $baseUrl . $row['product_img'];
        } else {
            $row['product_img_url'] = null;
        }
        $products[] = $row;
    }

    $output["head"]["code"] = 200;
    $output["head"]["msg"] = "Success";
    $output["body"]["products"] = $products;

    $stmt->close();
}

// ===================================================================
// C - CREATE
// ===================================================================
else if ($method === 'POST' && $is_create_action) {

    if (empty($product_name) || empty($product_code) || empty($unit_code) || empty($category_code)) {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Required fields are missing.";
        goto end_script;
    }

    // Check duplicate product_code
    $check = $conn->prepare("SELECT id FROM product WHERE product_code = ? AND company_id = ? AND deleted_at = 0");
    $check->bind_param("ss", $product_code, $company_id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Product code already exists.";
        $check->close();
        goto end_script;
    }
    $check->close();

    // Handle image upload
    $savedImageName = saveBase64Image($product_img);

    $new_product_id = generateProductId($conn);

    $stmt = $conn->prepare("INSERT INTO `product` 
        (`product_id`, `company_id`, `product_name`, `product_img`, `product_code`, `unit_code`, `category_code`, 
         `product_price`, `product_stock`, `product_disc`, `product_disc_amt`, `created_by`, `created_name`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->bind_param("sssssssssssss", 
        $new_product_id, $company_id, $product_name, $savedImageName, $product_code, $unit_code, 
        $category_code, $product_price, $product_stock, $product_disc, $product_disc_amt, 
        $user_id, $created_name
    );

    if ($stmt->execute()) {
        $output["head"]["code"] = 200;
        $output["head"]["msg"] = "Product created successfully.";
        $output["body"]["product_id"] = $new_product_id;
        $output["body"]["image_url"] = $savedImageName ? "http://{$_SERVER['SERVER_NAME']}/zen_online_stores/uploads/products/{$savedImageName}" : null;
    } else {
        $output["head"]["code"] = 500;
        $output["head"]["msg"] = "DB Error: " . $stmt->error;
    }
    $stmt->close();
}

// ===================================================================
// U - UPDATE
// ===================================================================
else if (($method === 'POST' || $method === 'PUT') && $is_update_action) {

    if (empty($product_id) || empty($product_name) || empty($product_code)) {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Product ID, Name and Code are required for update.";
        goto end_script;
    }

    // Check duplicate code (exclude current product)
    $check = $conn->prepare("SELECT id FROM product WHERE product_code = ? AND company_id = ? AND product_id != ? AND deleted_at = 0");
    $check->bind_param("sss", $product_code, $company_id, $product_id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Product code already used by another product.";
        $check->close();
        goto end_script;
    }
    $check->close();

    // Handle new image if provided
    $finalImageName = null;
    if (!empty($product_img)) {
        $finalImageName = saveBase64Image($product_img);
        if ($finalImageName === null) {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Invalid image data.";
            goto end_script;
        }
    }

    // Build dynamic SET clause
    $sets = [];
    $types = "";
    $params = [];

    $fields = [
        'product_name' => $product_name,
        'product_code' => $product_code,
        'unit_code' => $unit_code,
        'category_code' => $category_code,
        'product_price' => $product_price,
        'product_stock' => $product_stock,
        'product_disc' => $product_disc,
        'product_disc_amt' => $product_disc_amt
    ];

    foreach ($fields as $col => $val) {
        if ($val !== null) {
            $sets[] = "`$col` = ?";
            $params[] = $val;
            $types .= is_numeric($val) ? "d" : "s";
        }
    }

    if ($finalImageName !== null) {
        $sets[] = "`product_img` = ?";
        $params[] = $finalImageName;
        $types .= "s";
    }

    if (empty($sets)) {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "No fields to update.";
        goto end_script;
    }

    $setClause = implode(", ", $sets);
    $sql = "UPDATE `product` SET $setClause WHERE `product_id` = ? AND `company_id` = ?";
    $params[] = $product_id;
    $params[] = $company_id;
    $types .= "ss";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $output["head"]["code"] = 200;
        $output["head"]["msg"] = "Product updated successfully.";
        if ($finalImageName) {
            $output["body"]["image_url"] = "http://{$_SERVER['SERVER_NAME']}/zen_online_stores/uploads/products/{$finalImageName}";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "No changes made or product not found.";
    }
    $stmt->close();
}

// ===================================================================
// D - DELETE (Soft)
// ===================================================================
else if (($method === 'POST' || $method === 'DELETE') && $is_delete_action) {
    $stmt = $conn->prepare("UPDATE `product` SET `deleted_at` = 1 WHERE `product_id` = ? AND `company_id` = ?");
    $stmt->bind_param("ss", $product_id, $company_id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $output["head"]["code"] = 200;
        $output["head"]["msg"] = "Product deleted successfully.";
    } else {
        $output["head"]["code"] = 404;
        $output["head"]["msg"] = "Product not found.";
    }
    $stmt->close();
}

// ===================================================================
// Fallback
// ===================================================================
else {
    $output["head"]["code"] = 400;
    $output["head"]["msg"] = "Invalid request or parameters missing.";
}

end_script:
$conn->close();
echo json_encode($output, JSON_NUMERIC_CHECK | JSON_UNESCAPED_SLASHES);
?>