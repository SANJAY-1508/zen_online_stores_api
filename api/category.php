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
$category_name = isset($obj->category_name) ? trim($obj->category_name) : null;
$category_code = isset($obj->category_code) ? trim($obj->category_code) : null;
$company_id = isset($obj->company_id) ? trim($obj->company_id) : null;
$category_id = isset($obj->category_id) ? $obj->category_id : null; 
$user_id = isset($obj->user_id) ? $obj->user_id : null; 
$created_name = isset($obj->created_name) ? $obj->created_name : null; 

// Helper flags to distinguish actions
$method = $_SERVER['REQUEST_METHOD'];
$is_read_action = isset($obj->fetch_all) || (isset($obj->category_id) && !isset($obj->update_action) && !isset($obj->delete_action));
$is_create_action = $category_name && $category_code && $company_id && !isset($obj->category_id) && !isset($obj->update_action) && !isset($obj->delete_action);
$is_update_action = $category_id && $company_id && isset($obj->update_action);
$is_delete_action = $category_id && $company_id && isset($obj->delete_action);


// =========================================================================
// R - READ (LIST/FETCH)
// =========================================================================

if ($method === 'POST' && $is_read_action) {
    
    if (empty($company_id)) {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Company ID is required to fetch categories.";
        goto end_script;
    }

    if (isset($obj->category_id) && $obj->category_id !== null) {
        // FETCH SINGLE CATEGORY
        $sql = "SELECT `id`, `category_name`, `category_code`, `company_id`, `created_date` 
                FROM `category` 
                WHERE `id` = ? AND `company_id` = ? AND `deleted_at` = 0";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $category_id, $company_id);
    } else {
        // FETCH ALL CATEGORIES for a company
        $sql = "SELECT `id`, `category_name`, `category_code`, `company_id`, `created_date` 
                FROM `category` 
                WHERE `company_id` = ? AND `deleted_at` = 0 ORDER BY `category_name` ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $company_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $categories = [];

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $categories[] = $row;
        }
        $output["head"]["code"] = 200;
        $output["head"]["msg"] = "Success";
        $output["body"]["categories"] = $categories;
    } else {
        $output["head"]["code"] = 404;
        $output["head"]["msg"] = "No categories found.";
    }
    $stmt->close();

} 
// =========================================================================
// C - CREATE (Category)
// =========================================================================
else if ($method === 'POST' && $is_create_action) {

    if (!empty($category_name) && !empty($category_code) && !empty($company_id)) {

        // 1. Check if Category already exists (Duplicate prevention)
        $check_sql = "SELECT `id` FROM `category` WHERE `category_code` = ? AND `company_id` = ? AND `deleted_at` = 0";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ss", $category_code, $company_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Category with code '$category_code' already exists for this company.";
            $check_stmt->close();
            goto end_script;
        }
        $check_stmt->close();

        // 2. Insert the new Category record
        $insert_sql = "INSERT INTO `category` (`category_name`, `category_code`, `company_id`, `created_by`, `created_name`) 
                       VALUES (?, ?, ?, ?, ?)";
        
        $insert_stmt = $conn->prepare($insert_sql);
        // s s s i s : 3 strings, 1 int, 1 string
        $insert_stmt->bind_param("sssis", 
            $category_name, $category_code, $company_id, $user_id, $created_name
        );

        if ($insert_stmt->execute()) {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "Category created successfully.";
            $output["body"]["new_id"] = $conn->insert_id;
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Failed to create category. Error: " . $insert_stmt->error;
        }
        $insert_stmt->close();

    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Category Name, Category Code, and Company ID are required.";
    }
}
// =========================================================================
// U - UPDATE (Category)
// =========================================================================
else if (($method === 'POST' || $method === 'PUT') && $is_update_action) {

    if (!empty($category_name) && !empty($category_code)) {
        
        // 1. Check for duplicate category code (if code is being changed)
        $check_sql = "SELECT `id` FROM `category` WHERE `category_code` = ? AND `company_id` = ? AND `id` != ? AND `deleted_at` = 0";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ssi", $category_code, $company_id, $category_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Category code '$category_code' is already used by another category.";
            $check_stmt->close();
            goto end_script;
        }
        $check_stmt->close();

        // 2. Update the Category record
        $update_sql = "UPDATE `category` SET `category_name` = ?, `category_code` = ? 
                       WHERE `id` = ? AND `company_id` = ?";
        
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ssis", 
            $category_name, $category_code, $category_id, $company_id
        );

        if ($update_stmt->execute()) {
            if ($update_stmt->affected_rows > 0) {
                $output["head"]["code"] = 200;
                $output["head"]["msg"] = "Category updated successfully.";
            } else {
                $output["head"]["code"] = 200;
                $output["head"]["msg"] = "Category updated successfully (No changes made).";
            }
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Failed to update category. Error: " . $update_stmt->error;
        }
        $update_stmt->close();

    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Category Name and Category Code are required for update.";
    }

}
// =========================================================================
// D - DELETE (Category - Soft Delete)
// =========================================================================
else if (($method === 'POST' || $method === 'DELETE') && $is_delete_action) {

    // Perform Soft Delete
    $delete_sql = "UPDATE `category` SET `deleted_at` = 1 
                   WHERE `id` = ? AND `company_id` = ?";
    
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("is", $category_id, $company_id);

    if ($delete_stmt->execute()) {
        if ($delete_stmt->affected_rows > 0) {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "Category deleted successfully.";
        } else {
            $output["head"]["code"] = 404;
            $output["head"]["msg"] = "Category not found or already deleted.";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Failed to delete category. Error: " . $delete_stmt->error;
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
// Close the database connection at the end
$conn->close();

echo json_encode($output, JSON_NUMERIC_CHECK);
?>