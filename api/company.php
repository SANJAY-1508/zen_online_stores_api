<?php

include 'db/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit();
}

$json = file_get_contents('php://input');
$obj = json_decode($json);
$output = array();

date_default_timezone_set('Asia/Calcutta');
$timestamp = date('Y-m-d H:i:s');

// =========================================================================
// R - READ (Fetch Company Details)
// =========================================================================

if (isset($obj->search_text)) {
    $sql = "SELECT `id`, `company_id`, `company_name`, `address`, `description`, `pincode`, `phone`, `mobile`, `email_id`,
`gst_no`, `state`, `city`, `img`, `bill_prefix`, `acc_number`, `acc_holder_name`, `bank_name`, `ifsc_code`,
`bank_branch`, `minimum_order_value`, `deleted_at`, `created_by`, `created_name`, `created_date`
FROM `company` WHERE 1"; // Note: This query always fetches ALL records if WHERE 1 is used without a limit/ID

    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        // Fetch only the first row, as typically there is only one company record
        if ($row = $result->fetch_assoc()) {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "Success";
            $output["body"]["company"] = $row;
            
            $imgLink = null;
            if ($row["img"] != null && $row["img"] != 'null' && strlen($row["img"]) > 0) {
                // Ensure SERVER_NAME is correct for image path
                $imgLink = "http://" . $_SERVER['SERVER_NAME'] . "/zen_online_stores/uploads/company/" . $row["img"]; 
                $output["body"]["company"]["img"] = $imgLink;
            } else {
                $output["body"]["company"]["img"] = $imgLink;
            }
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Company Details Not Found";
    }
} 
// =========================================================================
// C/U - UPSERT (Update/Insert Company Details)
// =========================================================================
else if (isset($obj->company_name) && isset($obj->acc_holder_name) && isset($obj->company_profile_img) && isset($obj->address)  &&   
         isset($obj->description) &&  isset($obj->pincode) && isset($obj->city) && isset($obj->state) && isset($obj->phone_number) && isset($obj->mobile_number) &&  isset($obj->email_id) &&    
         isset($obj->gst_number) && isset($obj->bill_prefix) &&    
         isset($obj->minimum_order_value) &&  isset($obj->acc_number) && isset($obj->bank_name) && isset($obj->ifsc_code) && isset($obj->bank_branch)) {

    
    $company_name = $obj->company_name;
    $company_profile_img = $obj->company_profile_img;
    $address = $obj->address;
    $description = $obj->description;
    $email_id = $obj->email_id;
    $bill_prefix = $obj->bill_prefix;
    $minimum_order_value = $obj->minimum_order_value;
    $pincode = $obj->pincode;
    $city = $obj->city;
    $state = $obj->state;
    $phone_number = $obj->phone_number;
    $mobile_number = $obj->mobile_number;
    $gst_number = $obj->gst_number;
    $acc_number = $obj->acc_number;
    $acc_holder_name = $obj->acc_holder_name;
    $bank_name = $obj->bank_name;
    $ifsc_code = $obj->ifsc_code;
    $bank_branch = $obj->bank_branch;

    if (!empty($company_name) && !empty($address) && !empty($pincode) &&
        !empty($phone_number) && !empty($city) && !empty($state)
    ) {
        if (!preg_match('/[^a-zA-Z0-9., ]+/', $company_name)) {
            // Assuming numericCheck function is defined in db/config.php or elsewhere
            if (function_exists('numericCheck') && numericCheck($phone_number) && strlen($phone_number) == 10) { 

                $edit_id = 1; // Target ID is hardcoded as 1 for the main company record
                $sql = "";
                $profile_path = "";
                $is_insert = false;

                // Check existing company
                $check_sql = "SELECT COUNT(`id`) AS count, `company_id`, `img` FROM `company` WHERE `id` = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("i", $edit_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $check_row = $check_result->fetch_assoc();
                $count = $check_row['count'];
                $company_id = $check_row['company_id'];
                $old_profile_path = $check_row['img'];
                $check_stmt->close();

                // Auto-generate company_id
                if ($count == 0 || empty($company_id)) {
                    // This generates COMP-000001
                    $company_id = 'COMP-' . str_pad($edit_id, 6, '0', STR_PAD_LEFT); 
                }

                // Handle image upload
                if (!empty($company_profile_img) && function_exists('pngImageToWebP')) {
                    $outputFilePath = "../uploads/company/";
                    // Assuming pngImageToWebP returns the new filename/path
                    $profile_path = pngImageToWebP($company_profile_img, $outputFilePath); 
                }

                // INSERT (19 placeholders)
                if ($count == 0) {
                    $sql = "INSERT INTO company (
                        company_id, company_name, img, address, description, pincode,
                        city, state, phone, mobile, email_id, gst_no, bill_prefix,
                        acc_number, acc_holder_name, bank_name, ifsc_code, bank_branch,
                        minimum_order_value
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

                    $stmt = $conn->prepare($sql);
                    
                    // FIX: 18 's' (for all Varchar/Text fields) + 1 'i' (for minimum_order_value) = 19 characters
                    $stmt->bind_param(
                        "ssssssssssssssssssi", 
                        $company_id, $company_name, $profile_path, $address, $description,
                        $pincode, $city, $state, $phone_number, $mobile_number, $email_id,
                        $gst_number, $bill_prefix, $acc_number, $acc_holder_name, $bank_name,
                        $ifsc_code, $bank_branch, $minimum_order_value
                    );

                    $success_msg = "Successfully Company Details Created";
                }

                // UPDATE WITH NEW IMAGE (20 placeholders)
                else if (!empty($company_profile_img)) {

                    $sql = "UPDATE company SET
                        company_id=?, company_name=?, img=?, address=?, description=?, pincode=?,
                        city=?, state=?, phone=?, mobile=?, email_id=?, gst_no=?, bill_prefix=?,
                        acc_number=?, acc_holder_name=?, bank_name=?, ifsc_code=?, bank_branch=?,
                        minimum_order_value=? WHERE id=?";

                    $stmt = $conn->prepare($sql);
                   $stmt->bind_param(
        "ssssssssssssssssssii",
        $company_id, $company_name, $profile_path, $address, $description,
        $pincode, $city, $state, $phone_number, $mobile_number, $email_id,
        $gst_number, $bill_prefix, $acc_number, $acc_holder_name, $bank_name,
        $ifsc_code, $bank_branch, 
        $minimum_order_value, // Should be an 'i'
        $edit_id // Should be an 'i'
    );

                    $success_msg = "Successfully Company Details Updated";
                }

                // UPDATE WITHOUT IMAGE (20 placeholders)
                else {

                    $sql = "UPDATE company SET
                        company_id=?, company_name=?, img=?, address=?, description=?, pincode=?,
                        city=?, state=?, phone=?, mobile=?, email_id=?, gst_no=?, bill_prefix=?,
                        acc_number=?, acc_holder_name=?, bank_name=?, ifsc_code=?, bank_branch=?,
                        minimum_order_value=? WHERE id=?";

                    $stmt = $conn->prepare($sql);
                    
                    // FIX: 18 's' + 1 'i' (for minimum_order_value) + 1 'i' (for $edit_id) = 20 characters
                    $stmt->bind_param(
                        "sssssssssssssssssii", 
                        $company_id, $company_name, $old_profile_path, $address, $description,
                        $pincode, $city, $state, $phone_number, $mobile_number, $email_id,
                        $gst_number, $bill_prefix, $acc_number, $acc_holder_name, $bank_name,
                        $ifsc_code, $bank_branch, $minimum_order_value, $edit_id
                    );

                    $success_msg = "Successfully Company Details Updated";
                }

                if ($stmt->execute()) {
                    $output["head"]["code"] = 200;
                    $output["head"]["msg"] = $success_msg;
                } else {
                    $output["head"]["code"] = 400;
                    $output["head"]["msg"] = "Failed: " . $stmt->error;
                }

                $stmt->close();
            } else {
                $output["head"]["code"] = 400;
                $output["head"]["msg"] = "Invalid Phone Number.";
            }
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Company name Should be Alphanumeric.";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Please provide all the required details.";
    }
}

// =========================================================================
// D - Image Delete
// =========================================================================

else if (isset($obj->image_delete)) {

    $image_delete = $obj->image_delete;

    // Assuming ImageRemove function is defined elsewhere
    if ($image_delete === true && function_exists('ImageRemove')) { 

        $status = ImageRemove('company', 1);
        if ($status == "company Image Removed Successfully") {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "successfully company Image deleted !.";
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "faild to deleted.please try againg.";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Parameter is Mismatch or ImageRemove function is missing.";
    }
} else {
    $output["head"]["code"] = 400;
    $output["head"]["msg"] = "Parameter is Mismatch";
}

// Close the database connection at the end
$conn->close();

echo json_encode($output, JSON_NUMERIC_CHECK);
?>