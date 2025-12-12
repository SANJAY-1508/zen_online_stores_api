<?php
include 'db/config.php';
header( 'Content-Type: application/json; charset=utf-8' );
header( 'Access-Control-Allow-Origin: *' );
header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
header( 'Access-Control-Allow-Headers: Content-Type' );
if ( $_SERVER[ 'REQUEST_METHOD' ] === 'OPTIONS' ) {
    exit();
}
$json = file_get_contents( 'php://input' );
$obj = json_decode( $json );
$output = array();
date_default_timezone_set( 'Asia/Calcutta' );
$timestamp = date( 'Y-m-d H:i:s' );
// ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  =
// R - READ ( Fetch Company Details )
// ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  =
if ( isset( $obj->search_text ) ) {
    // FIX: Filter active records WHERE deleted_at = 0 ( assuming 0 means active, 1 means deleted )
    $sql = "SELECT `id`, `company_id`, `company_name`, `address`, `description`, `pincode`, `phone`, `mobile`, `email_id`,
`gst_no`, `state`, `city`, `img`, `bill_prefix`, `acc_number`, `acc_holder_name`, `bank_name`, `ifsc_code`,
`bank_branch`, `minimum_order_value`, `deleted_at`, `created_by`, `created_name`, `created_date`
FROM `company` WHERE `deleted_at` = 0";
    $result = $conn->query( $sql );
    if ( $result->num_rows > 0 ) {
        $companies = array();
        while ( $row = $result->fetch_assoc() ) {
            $imgLink = null;
            if ( $row[ 'img' ] != null && $row[ 'img' ] != 'null' && strlen( $row[ 'img' ] ) > 0 ) {
                $imgLink = 'http://' . $_SERVER[ 'SERVER_NAME' ] . '/zen_online_stores/uploads/company/' . $row[ 'img' ];
                $row[ 'img' ] = $imgLink;
            } else {
                $row[ 'img' ] = $imgLink;
            }
            $companies[] = $row;
        }
        $output[ 'head' ][ 'code' ] = 200;
        $output[ 'head' ][ 'msg' ] = 'Success';
        $output[ 'body' ][ 'companies' ] = $companies;
    } else {
        $output[ 'head' ][ 'code' ] = 400;
        $output[ 'head' ][ 'msg' ] = 'Company Details Not Found';
    }
}
// ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  =
// C/U - UPSERT ( Update/Insert Company Details )
// ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  ===  =
else if ( isset( $obj->company_name ) && isset( $obj->acc_holder_name ) && isset( $obj->company_profile_img ) && isset( $obj->address ) &&
isset( $obj->description ) && isset( $obj->pincode ) && isset( $obj->city ) && isset( $obj->state ) && isset( $obj->phone_number ) && isset( $obj->mobile_number ) && isset( $obj->email_id ) &&
isset( $obj->gst_number ) && isset( $obj->bill_prefix ) &&
isset( $obj->minimum_order_value ) && isset( $obj->acc_number ) && isset( $obj->bank_name ) && isset( $obj->ifsc_code ) && isset( $obj->bank_branch ) ) {
    // --- Variable Assignment ---
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
    // FIX: Add created_by and created_name from JSON ( optional, but required by schema )
    $created_by = $obj->created_by ?? null;
    $created_name = $obj->created_name ?? null;
    $input_company_id = $obj->company_id ?? null;
    if ( !empty( $company_name ) && !empty( $address ) && !empty( $pincode ) &&
    !empty( $phone_number ) && !empty( $city ) && !empty( $state )
) {
    if ( !preg_match( '/[^a-zA-Z0-9., ]+/', $company_name ) ) {
        if ( function_exists( 'numericCheck' ) && numericCheck( $phone_number ) && strlen( $phone_number ) == 10 ) {
            $sql = '';
            $profile_path = '';
            $edit_id = null;
            $old_profile_path = null;
            $is_update = false;
            // 1. Check for Existing Company
            if ( !empty( $input_company_id ) ) {
                $check_sql = 'SELECT `id`, `img` FROM `company` WHERE `company_id` = ? AND `deleted_at` = 0';
                $check_stmt = $conn->prepare( $check_sql );
                $check_stmt->bind_param( 's', $input_company_id );
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $existing_company = $check_result->fetch_assoc();
                $check_stmt->close();

                if ( $existing_company ) {
                    $edit_id = $existing_company[ 'id' ];
                    $old_profile_path = $existing_company[ 'img' ];
                    $is_update = true;
                    $company_id = $input_company_id;
                }
            }
            // 2. Handle Image Upload
            if ( !empty( $company_profile_img ) && function_exists( 'pngImageToWebP' ) ) {
                $outputFilePath = '../uploads/company/';
                $profile_path = pngImageToWebP( $company_profile_img, $outputFilePath );
            }
            $transaction_success = false;
            $success_msg = '';
            if ( !$is_update ) {
                $conn->begin_transaction();
                // Generate new company IDs
                $id_result = $conn->query( 'SELECT MAX(CAST(SUBSTRING(`company_id`, 6) AS UNSIGNED)) AS max_company_num FROM `company`' );
                $max_company_num = $id_result->fetch_assoc()[ 'max_company_num' ] ?? 0;

                // 2. Increment the numeric part
                $new_company_num = $max_company_num + 1;

                // 3. Format the new, unique company_id
                $company_id = 'COMP-' . str_pad( $new_company_num, 6, '0', STR_PAD_LEFT );
                $profile_to_use = $profile_path;

                // FIX: Use provided created_by and created_name ( or defaults if null, but schema requires non-null )
                // If null, set defaults ( e.g., 'System' or 0 ), but since JSON provides, use them
                $deleted_at_val = 0;
                $created_by_val = !empty( $created_by ) ? $created_by : 'System';
                // Fallback if null
                $created_name_val = !empty( $created_name ) ? $created_name : 'System';
                // Fallback if null
                // created_date already $timestamp

                // FIX: Include missing audit fields in INSERT
                // deleted_at = 0 ( as int ), created_by = $created_by_val, created_name = $created_name_val, created_date = $timestamp
                $sql = "INSERT INTO company (
                        company_id, company_name, img, address, description, pincode,
                        city, state, phone, mobile, email_id, gst_no, bill_prefix,
                        acc_number, acc_holder_name, bank_name, ifsc_code, bank_branch,
                        minimum_order_value, deleted_at, created_by, created_name, created_date
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
                $stmt = $conn->prepare( $sql );
                // Types: 18s + i ( min_order ) + i ( deleted_at ) + s ( created_by ) + s ( created_name ) + s ( created_date )
                $stmt->bind_param(
                    'ssssssssssssssssssiisss',
                    $company_id, $company_name, $profile_to_use, $address, $description,
                    $pincode, $city, $state, $phone_number, $mobile_number, $email_id,
                    $gst_number, $bill_prefix, $acc_number, $acc_holder_name, $bank_name,
                    $ifsc_code, $bank_branch, $minimum_order_value, $deleted_at_val, $created_by_val, $created_name_val, $timestamp
                );

                if ( $stmt->execute() ) {

                    // A2. Generate new user_id and Insert into user table

                    // 1. Generate new user_id
                    $user_id_result = $conn->query( 'SELECT MAX(CAST(SUBSTRING(`user_id`, 6) AS UNSIGNED)) AS max_user_num FROM `user`' );
                    $max_user_num = $user_id_result->fetch_assoc()[ 'max_user_num' ] ?? 0;
                    $new_user_num = $max_user_num + 1;
                    $user_id = 'USER-' . str_pad( $new_user_num, 6, '0', STR_PAD_LEFT );
                    $default_user_name = $mobile_number;
                    // Mobile number as login user_name
                    $default_name = $company_name;
                    // Company name as user's name
                        $default_password = $mobile_number;
                        $hashed_password = password_hash($default_password, PASSWORD_DEFAULT);
                      
                        // Now including the generated user_id
                        $user_sql = "INSERT INTO user (
                            user_id, company_id, name, user_name, phone, password
                        ) VALUES (?, ?, ?, ?, ?, ?)";
                      
                        $user_stmt = $conn->prepare($user_sql);
                        // 6 parameters now
                        $user_stmt->bind_param(
                            "ssssss",
                            $user_id, $company_id, $default_name, $default_user_name, $mobile_number, $hashed_password
                        );
                        if ($user_stmt->execute()) {
                            $conn->commit();
                            $transaction_success = true;
                            $success_msg = "Successfully Company Created & User Added. Default Password: " . $default_password;
                        } else {
                            $conn->rollback();
                            $success_msg = "Failed to create Company User: " . $user_stmt->error;
                        }
                        $user_stmt->close();
                    } else {
                        $conn->rollback();
                        $success_msg = "Failed to create Company: " . $stmt->error;
                    }
                    $stmt->close();
                }
                // --- Path B: UPDATE (Existing Company) ---
                else {
                    $profile_to_use = !empty($profile_path) ? $profile_path : $old_profile_path;
                  
                    $sql = "UPDATE company SET
                        company_id=?, company_name=?, img=?, address=?, description=?, pincode=?,
                        city=?, state=?, phone=?, mobile=?, email_id=?, gst_no=?, bill_prefix=?,
                        acc_number=?, acc_holder_name=?, bank_name=?, ifsc_code=?, bank_branch=?,
                        minimum_order_value=?, created_by=?, created_name=? WHERE id=? AND deleted_at = 0";
                    $stmt = $conn->prepare($sql);
                    // Updated: Added created_by and created_name to UPDATE (if provided)
                    $stmt->bind_param(
                        "ssssssssssssssssssissi",
                        $company_id, $company_name, $profile_to_use, $address, $description,
                        $pincode, $city, $state, $phone_number, $mobile_number, $email_id,
                        $gst_number, $bill_prefix, $acc_number, $acc_holder_name, $bank_name,
                        $ifsc_code, $bank_branch, $minimum_order_value, $created_by_val, $created_name_val, $edit_id
                    );
                    if ($stmt->execute()) {
                        $transaction_success = true;
                        $success_msg = "Successfully Company Details Updated";
                    } else {
                        $success_msg = "Failed: " . $stmt->error;
                    }
                    $stmt->close();
                }
                // --- Final Response Block ---
                if ($transaction_success) {
                    $output["head"]["code"] = 200;
                    $output["head"]["msg"] = $success_msg;
                    $output["body"]["company_id"] = $company_id;
                    if (!$is_update) {
                        $output["body"]["user_id"] = $user_id; // Returning the newly generated user_id
                        $output["body"]["default_username"] = $mobile_number;
                        $output["body"]["default_password"] = $mobile_number;
                    }
                } else {
                    $output["head"]["code"] = 400;
                    $output["head"]["msg"] = $success_msg;
                }
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
// D - Soft Delete Company (NEW: Set deleted_at = 1)
// =========================================================================
else if (isset($obj->delete_company)) {
    $company_id_to_delete = $obj->company_id ?? null;
    if (empty($company_id_to_delete)) {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Missing company_id for deletion.";
    } else {
        // Check if company exists and is active
        $check_sql = "SELECT `id` FROM `company` WHERE `company_id` = ? AND `deleted_at` = 0";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $company_id_to_delete);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_row = $check_result->fetch_assoc();
        $check_stmt->close();
      
        $delete_id = $check_row['id'] ?? null;
      
        if ($delete_id) {
            // Soft delete: UPDATE deleted_at = 1
            $delete_sql = "UPDATE `company` SET `deleted_at` = 1 WHERE `id` = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $delete_id);
            if ($delete_stmt->execute()) {
                $output["head"]["code"] = 200;
                $output["head"]["msg"] = "Company soft deleted successfully.";
                $output["body"]["company_id"] = $company_id_to_delete;
            } else {
                $output["head"]["code"] = 400;
                $output["head"]["msg"] = "Failed to delete company: " . $delete_stmt->error;
            }
            $delete_stmt->close();
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Company not found or already deleted.";
        }
    }
}
// =========================================================================
// D - Image Delete
// =========================================================================
else if (isset($obj->image_delete)) {
    $image_delete = $obj->image_delete;
    $company_id_to_delete = $obj->company_id ?? null;
    if (empty($company_id_to_delete)) {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Missing company_id for image deletion.";
    } else {
        // Check if company exists and is active
        $check_sql = "SELECT `id` FROM `company` WHERE `company_id` = ? AND `deleted_at` = 0";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $company_id_to_delete);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_row = $check_result->fetch_assoc();
        $check_stmt->close();
      
        $delete_id = $check_row['id'] ?? null;
      
        if ($delete_id && $image_delete === true && function_exists('ImageRemove')) {
            $status = ImageRemove('company', $delete_id );
                    if ( $status == 'company Image Removed Successfully' ) {
                        $output[ 'head' ][ 'code' ] = 200;
                        $output[ 'head' ][ 'msg' ] = 'successfully company Image deleted !.';
                    } else {
                        $output[ 'head' ][ 'code' ] = 400;
                        $output[ 'head' ][ 'msg' ] = 'faild to deleted.please try againg.';
                    }
                } else {
                    $output[ 'head' ][ 'code' ] = 400;
                    $output[ 'head' ][ 'msg' ] = 'Company not found, Parameter is Mismatch or ImageRemove function is missing.';
                }
            }
        } else {
            $output[ 'head' ][ 'code' ] = 400;
            $output[ 'head' ][ 'msg' ] = 'Parameter is Mismatch';
        }
        // Close the database connection at the end
        $conn->close();
        echo json_encode( $output, JSON_NUMERIC_CHECK );
        ?>