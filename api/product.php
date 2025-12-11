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
$product_img    = isset($obj->product_img) ? $obj->product_img : null;
$product_code   = isset($obj->product_code) ? trim($obj->product_code) : null;
$unit_code      = isset($obj->unit_code) ? trim($obj->unit_code) : null;
$category_code  = isset($obj->category_code) ? trim($obj->category_code) : null;

// Convert incoming numerical strings to floats/ints for safety
$product_price  = isset($obj->product_price) ? floatval($obj->product_price) : null;
$product_stock  = isset($obj->product_stock) ? intval($obj->product_stock) : null;
$product_disc   = isset($obj->product_disc) ? floatval($obj->product_disc) : null;
$product_disc_amt = isset($obj->product_disc_amt) ? floatval($obj->product_disc_amt) : null;

$id             = isset($obj->id) ? $obj->id : null; 
$user_id        = isset($obj->user_id) ? $obj->user_id : null; 
$created_name   = isset($obj->created_name) ? $obj->created_name : null; 


// Helper flags to distinguish actions (relies on product_id for U/D)
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

// =========================================================================
// R - READ (LIST/FETCH) - Uses LEFT JOIN
// =========================================================================

if ($method === 'POST' && $is_read_action) {
    
    if (empty($company_id)) {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Company ID is required to fetch products.";
        goto end_script;
    }

    // IMPORTANT: Assuming your unit table is named 'unit' based on the FK error
    $select_cols = "p.*, u.unit_name, c.category_name";
    $from_tables = "`product` p 
                    LEFT JOIN `unit` u ON p.unit_code = u.unit_code AND u.deleted_at = 0
                    LEFT JOIN `category` c ON p.category_code = c.category_code AND c.deleted_at = 0";
    $where_clause = "p.company_id = ? AND p.deleted_at = 0";

    if (isset($obj->id) && $obj->id !== null) {
        // FETCH SINGLE PRODUCT by internal ID
        $sql = "SELECT {$select_cols} FROM {$from_tables} WHERE {$where_clause} AND p.id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $company_id, $id);
    } else if (isset($obj->product_id) && $obj->product_id !== null) {
        // FETCH SINGLE PRODUCT by external product_id
        $sql = "SELECT {$select_cols} FROM {$from_tables} WHERE {$where_clause} AND p.product_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $company_id, $product_id);
    } else {
        // FETCH ALL PRODUCTS for a company
        $sql = "SELECT {$select_cols} FROM {$from_tables} WHERE {$where_clause} ORDER BY p.product_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $company_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $products = [];

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
        $output["head"]["code"] = 200;
        $output["head"]["msg"] = "Success";
        $output["body"]["products"] = $products;
    } else {
        $output["head"]["code"] = 404;
        $output["head"]["msg"] = "No products found.";
    }
    $stmt->close();
} 
// =========================================================================
// C - CREATE (Product)
// =========================================================================
else if ($method === 'POST' && $is_create_action) {

    if (!empty($product_name) && !empty($product_code) && !empty($unit_code) && !empty($category_code)) {

        // 1. Check if Product Code already exists for this company
        $check_sql = "SELECT `id` FROM `product` WHERE `product_code` = ? AND `company_id` = ? AND `deleted_at` = 0";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ss", $product_code, $company_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Product with code '$product_code' already exists for this company.";
            $check_stmt->close();
            goto end_script;
        }
        $check_stmt->close();
        $new_product_id = generateProductId($conn);
        $insert_sql = "INSERT INTO `product` (`product_id`, `company_id`, `product_name`, `product_img`, `product_code`, `unit_code`, `category_code`, `product_price`, `product_stock`, `product_disc`, `product_disc_amt`, `created_by`, `created_name`) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("sssssssssssss", 
            $new_product_id, $company_id, $product_name, $product_img, $product_code, $unit_code, 
            $category_code, $product_price, $product_stock, $product_disc, $product_disc_amt, 
            $user_id, $created_name
        );

        if ($insert_stmt->execute()) {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "Product created successfully.";
            $output["body"]["new_id"] = $conn->insert_id;
            $output["body"]["product_id"] = $new_product_id;
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Failed to create product. Error: " . $insert_stmt->error;
        }
        $insert_stmt->close();

    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Product Name, Product Code, Unit Code, and Category Code are required.";
    }
}
// =========================================================================
// U - UPDATE (Product) - Uses product_id
// =========================================================================
else if (($method === 'POST' || $method === 'PUT') && $is_update_action) {

    $target_product_id = $product_id;

    if (!empty($target_product_id) && !empty($product_name) && !empty($product_code) && !empty($unit_code) && !empty($category_code)) {
        
        // 1. Check for duplicate product code, EXCLUDING the product being updated by product_id
        $check_sql = "SELECT `id` FROM `product` WHERE `product_code` = ? AND `company_id` = ? AND `product_id` != ? AND `deleted_at` = 0";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("sss", $product_code, $company_id, $target_product_id); 
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Product code '$product_code' is already used by another product.";
            $check_stmt->close();
            goto end_script;
        }
        $check_stmt->close();

        // 2. Update the product record using product_id in the WHERE clause
        $update_sql = "UPDATE `product` SET 
                       `product_name`=?, `product_img`=?, `product_code`=?, 
                       `unit_code`=?, `category_code`=?, `product_price`=?, 
                       `product_stock`=?, `product_disc`=?, `product_disc_amt`=?
                       WHERE `product_id` = ? AND `company_id` = ?";
        
        $update_stmt = $conn->prepare($update_sql);
        // Bind parameters: 11 fields (9 update values + product_id + company_id)
        $update_stmt->bind_param("sssssssssss", 
            $product_name, $product_img, $product_code, $unit_code, $category_code, 
            $product_price, $product_stock, $product_disc, $product_disc_amt, 
            $target_product_id, $company_id
        );

        if ($update_stmt->execute()) {
            if ($update_stmt->affected_rows > 0) {
                $output["head"]["code"] = 200;
                $output["head"]["msg"] = "Product updated successfully.";
            } else {
                $output["head"]["code"] = 200;
                $output["head"]["msg"] = "Product updated successfully (No changes made or product_id not found).";
            }
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Failed to update product. Error: " . $update_stmt->error;
        }
        $update_stmt->close();

    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Required fields (including product_id) are missing for update.";
    }

}
// =========================================================================
// D - DELETE (Product - Soft Delete) - Uses product_id
// =========================================================================
else if (($method === 'POST' || $method === 'DELETE') && $is_delete_action) {

    // Perform Soft Delete using product_id
    $delete_sql = "UPDATE `product` SET `deleted_at` = 1 
                   WHERE `product_id` = ? AND `company_id` = ?";
    
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("ss", $product_id, $company_id);

    if ($delete_stmt->execute()) {
        if ($delete_stmt->affected_rows > 0) {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "Product deleted successfully.";
        } else {
            $output["head"]["code"] = 404;
            $output["head"]["msg"] = "Product not found or already deleted.";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Failed to delete product. Error: " . $delete_stmt->error;
    }
    $delete_stmt->close();

} 
// =========================================================================
// Mismatch / Fallback
// =========================================================================
else {
    $output["head"]["code"] = 400;
    $output["head"]["msg"] = "Parameter or request method is Mismatch for the operation requested.";
}

end_script:
$conn->close();

echo json_encode($output, JSON_NUMERIC_CHECK);
?>