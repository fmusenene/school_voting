<?php
// --- Initialization & Config ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
error_reporting(E_ALL); // Dev
ini_set('display_errors', 1); // Dev
date_default_timezone_set('Africa/Nairobi'); // EAT

require_once "../config/database.php"; // Provides $conn (mysqli connection)
// Assuming session.php provides isAdminLoggedIn() and potentially other helpers
// require_once "includes/session.php"; // Uncomment if needed and path is correct

// --- Helper Function: Send JSON Response ---
// (Ensure this function is defined or included)
function send_json_response($success, $data = null, $message = null, $data_key = 'data') {
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
    $response = ['success' => (bool)$success];
    if ($message !== null) { $response['message'] = $message; }
    // Send back specific data like username if needed by UI
    if ($data !== null && $data_key !== null) { $response[$data_key] = $data; }
    // Set appropriate HTTP status code
    if (!$success && http_response_code() === 200) { http_response_code(400); } // Default Bad Request for handled errors
    echo json_encode($response); exit();
}

// --- Main Endpoint Logic ---

// 1. Check Request Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    send_json_response(false, null, 'Invalid request method.');
}

// 2. Check Admin Login
if (!isset($_SESSION['admin_id'])) { // Using basic session check here
     http_response_code(401); // Unauthorized
     send_json_response(false, null, 'Unauthorized access.');
}
$admin_id = (int)$_SESSION['admin_id']; // Ensure admin_id is integer

// 3. Check DB Connection
if (!$conn || $conn->connect_error) {
     error_log("Profile Update DB Connection Error: " . ($conn ? $conn->connect_error : 'Unknown error'));
     http_response_code(500); // Server Error
     send_json_response(false, null, 'Database connection error.');
}

// 4. Get POST data
$username_input = trim($_POST['username'] ?? '');
$current_password = $_POST['current_password'] ?? ''; // Don't trim passwords
$new_password = $_POST['new_password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

// 5. Process Request within Transaction
$conn->begin_transaction(); // Start mysqli transaction

try {
    // --- Fetch Current Admin Data ---
    $sql_fetch_admin = "SELECT username, password FROM admins WHERE id = $admin_id";
    $result_fetch = mysqli_query($conn, $sql_fetch_admin);

    if ($result_fetch === false) {
        throw new Exception("Database error fetching current admin data: " . mysqli_error($conn));
    }
    if (mysqli_num_rows($result_fetch) === 0) {
         mysqli_free_result($result_fetch);
        throw new Exception('Admin account not found.');
    }
    $admin = mysqli_fetch_assoc($result_fetch);
    mysqli_free_result($result_fetch);

    // --- Validate Inputs and Prepare Update ---
    $update_parts = []; // Store parts for the SET clause like "column = 'value'"

    // Validate and handle username update
    if (empty($username_input)) {
        throw new Exception('Username cannot be empty.');
    }

    // Sanitize username for checks and potential update
    $username_escaped = $conn->real_escape_string($username_input);

    // Check if username is changed AND if the new one is already taken
    if ($username_input !== $admin['username']) {
        $sql_check_username = "SELECT id FROM admins WHERE username = '$username_escaped' AND id != $admin_id";
        $result_check = mysqli_query($conn, $sql_check_username);

        if ($result_check === false) {
            throw new Exception("Database error checking username uniqueness: " . mysqli_error($conn));
        }
        if (mysqli_num_rows($result_check) > 0) {
             mysqli_free_result($result_check);
            throw new Exception('Username is already taken by another account.');
        }
         mysqli_free_result($result_check);

        // Add username update part
        $update_parts[] = "username = '$username_escaped'"; // Use escaped value, add quotes
    }

    // Handle password update only if new password fields are not empty
    if (!empty($new_password) || !empty($confirm_password)) {
        // Verify current password is provided and correct
        if (empty($current_password)) {
            throw new Exception('Current password is required to change your password.');
        }
        if (!password_verify($current_password, $admin['password'])) {
             http_response_code(401); // Unauthorized or Forbidden (403) might be appropriate
            throw new Exception('The Current Password you entered is incorrect.');
        }

        // Validate new password
        if ($new_password !== $confirm_password) {
            throw new Exception('The New Password and Confirm Password fields do not match.');
        }
        if (strlen($new_password) < 8) { // Check length before hashing
            throw new Exception('New password must be at least 8 characters long.');
        }

        // Hash the new password
        $new_password_hashed = password_hash($new_password, PASSWORD_DEFAULT);
        if ($new_password_hashed === false) {
             http_response_code(500); // Indicate server error during hashing
             throw new Exception('Failed to process new password.');
        }

        // Add password update part (hash itself doesn't need SQL escaping, but needs quotes)
        $update_parts[] = "password = '$new_password_hashed'";
    }

    // --- Execute Update Query if Changes Detected ---
    if (!empty($update_parts)) {
        $sql_update = "UPDATE admins SET " . implode(", ", $update_parts) . " WHERE id = $admin_id";
        $update_result = mysqli_query($conn, $sql_update);

        if ($update_result === false) {
            throw new Exception("Database error updating profile: " . mysqli_error($conn));
        }

        // Update session if username was changed
        if ($username_input !== $admin['username']) {
            $_SESSION['admin_username'] = $username_input; // Store the raw, untrimmed input? Or the trimmed one? Using input here.
        }

        // Commit transaction
        if (!$conn->commit()) {
             throw new Exception("Transaction commit failed: " . mysqli_error($conn));
        }

        send_json_response(true, ['username' => $username_input], 'Profile updated successfully.');

    } else {
         // No database changes were needed, but operation is "successful"
         // Rollback is not needed as no changes were attempted
         $conn->rollback(); // Or just don't commit - rollback is safer if unsure
         send_json_response(true, ['username' => $admin['username']], 'No changes were detected.'); // Return original username
    }

} catch (Exception $e) {
    $conn->rollback(); // Roll back DB changes on any caught error
    error_log("Admin Profile Update Error (AdminID: $admin_id): " . $e->getMessage());
    // Use existing HTTP code if set by specific validation errors, otherwise default to 400
     if(http_response_code() === 200) { http_response_code(400); }
    send_json_response(false, null, $e->getMessage()); // Send back the specific error message

} finally {
    // Optional: Close connection if script ends here
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
        mysqli_close($conn);
    }
}
?>