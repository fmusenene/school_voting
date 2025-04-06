<?php
// --- Initialization & Config ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
error_reporting(E_ALL); // Dev
ini_set('display_errors', 1); // Dev
date_default_timezone_set('Africa/Nairobi'); // EAT

require_once "../config/database.php"; // Provides $conn (mysqli connection)
require_once "includes/session.php"; // Provides isAdminLoggedIn() - Ensure this is correct path

// --- Helper Function: Send JSON Response ---
function send_json_response($success, $data = null, $message = null, $data_key = 'data') {
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
    $response = ['success' => (bool)$success];
    if ($message !== null) { $response['message'] = $message; }
    if ($success && $data !== null && $data_key !== null) { $response[$data_key] = $data; }
    if (!$success && http_response_code() === 200) { http_response_code(400); }
    echo json_encode($response); exit();
}

// --- Helper Function: Handle File Upload ---
// (Same as defined previously, ensure it's included or defined here)
function handleFileUpload($file_input_name) {
    if (!isset($_FILES[$file_input_name]) || $_FILES[$file_input_name]['error'] === UPLOAD_ERR_NO_FILE) return '';
    $file = $_FILES[$file_input_name];
    if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('File upload error: Code ' . $file['error']);
    if ($file['size'] > 5 * 1024 * 1024) throw new Exception('File too large (Max 5MB).');

    $project_root = dirname(__DIR__);
    $upload_dir_absolute = $project_root . '/uploads/candidates/';
    $upload_path_relative_for_db = 'uploads/candidates/';

    if (!is_dir($upload_dir_absolute)) { if (!mkdir($upload_dir_absolute, 0775, true)) throw new Exception('Failed to create upload directory.'); }
    if (!is_writable($upload_dir_absolute)) throw new Exception('Upload directory not writable.');

    $file_info = pathinfo($file['name']);
    $file_extension = strtolower($file_info['extension'] ?? '');
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($file_extension, $allowed_extensions)) throw new Exception('Invalid file type.');

    $new_filename = uniqid('cand_', true) . '.' . $file_extension;
    $target_path_absolute = $upload_dir_absolute . $new_filename;

    if (move_uploaded_file($file['tmp_name'], $target_path_absolute)) {
        return $upload_path_relative_for_db . $new_filename;
    } else { throw new Exception('Failed to move uploaded file.'); }
}

// --- Helper Function: Delete Photo File ---
// (Same as defined previously, ensure it's included or defined here)
function deletePhotoFile($relative_photo_path) {
     if (empty($relative_photo_path)) return false;
     $project_root = dirname(__DIR__);
     $absolute_path = $project_root . '/' . ltrim($relative_photo_path, '/');
     // Basic path check
     if (strpos(realpath($absolute_path), realpath($project_root . '/uploads/candidates')) !== 0) {
         error_log("Attempt to delete invalid file path: " . $absolute_path); return false;
     }
     if (file_exists($absolute_path) && is_file($absolute_path)) {
         if (!unlink($absolute_path)) { error_log("Failed to delete file: " . $absolute_path); return false; }
         return true;
     } return false;
}


// --- Main Endpoint Logic ---

// 1. Check Request Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response(false, null, 'Invalid request method.');
}

// 2. Check Admin Login (Assuming session is started)
if (!isAdminLoggedIn()) {
     http_response_code(401); // Unauthorized
     send_json_response(false, null, 'Unauthorized access.');
}

// 3. Check DB Connection
if (!$conn || $conn->connect_error) {
     error_log("Edit Candidate DB Connection Error: " . ($conn ? $conn->connect_error : 'Unknown error'));
     http_response_code(500); // Server error
     send_json_response(false, null, 'Database connection error.');
}

// 4. Process Request within Transaction
$conn->begin_transaction(); // Start mysqli transaction

try {
    // --- Get and Validate Inputs ---
    $candidate_id = isset($_POST['candidate_id']) ? (int)trim($_POST['candidate_id']) : 0;
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? ''); // Allow empty
    $position_id = isset($_POST['position_id']) ? (int)trim($_POST['position_id']) : 0;

    if ($candidate_id <= 0 || empty($name) || $position_id <= 0) {
        throw new Exception('Missing or invalid required fields (Candidate ID, Name, Position ID).');
    }

    // --- Handle Optional File Upload ---
    // This might throw an Exception, which will be caught and cause rollback
    $new_photo_path_db = handleFileUpload('photo'); // 'photo' is the input name

    // --- Fetch Old Photo Path (Needed if replacing photo) ---
    $old_photo_path = null;
    if (!empty($new_photo_path_db)) {
        $sql_old_photo = "SELECT photo FROM candidates WHERE id = $candidate_id";
        $res_old_photo = mysqli_query($conn, $sql_old_photo);
        if ($res_old_photo === false) {
             // Don't fail the whole operation just because we couldn't fetch the old path?
             // Log it, but proceed with the update. Old file won't be deleted.
             error_log("Could not fetch old photo path for candidate $candidate_id: " . mysqli_error($conn));
        } elseif (mysqli_num_rows($res_old_photo) > 0) {
            $old_photo_row = mysqli_fetch_assoc($res_old_photo);
            $old_photo_path = $old_photo_row['photo']; // Could be null/empty
        }
         if($res_old_photo) mysqli_free_result($res_old_photo);
    }

    // --- Sanitize/Escape Data for SQL ---
    $name_escaped = mysqli_real_escape_string($conn, $name);
    $description_escaped = mysqli_real_escape_string($conn, $description);

    // --- Construct Single Conditional UPDATE SQL ---
    $sql = "UPDATE candidates SET ";
    $sql .= "name = '$name_escaped', ";              // Quote escaped string
    $sql .= "description = '$description_escaped', "; // Quote escaped string
    $sql .= "position_id = $position_id ";           // Integer, no quotes

    // Only add photo update if a new one was uploaded
    if (!empty($new_photo_path_db)) {
        $photo_path_escaped = mysqli_real_escape_string($conn, $new_photo_path_db);
        $sql .= ", photo = '$photo_path_escaped' "; // Quote escaped string path
    }
    // WHERE clause
    $sql .= " WHERE id = $candidate_id";             // Integer, no quotes

    // --- Execute UPDATE Query ---
    $update_result = mysqli_query($conn, $sql);

    if ($update_result === false) {
        // If update failed, attempt to delete the NEWLY uploaded photo if it exists
        if (!empty($new_photo_path_db)) {
            deletePhotoFile($new_photo_path_db); // Attempt cleanup
        }
        // Throw exception to trigger rollback
        throw new Exception("Database error during update: " . mysqli_error($conn));
    }

    // --- Commit Transaction ---
    if (!$conn->commit()) {
         throw new Exception("Transaction commit failed: " . mysqli_error($conn));
    }

    // --- Delete Old Photo (AFTER successful commit) ---
    if (!empty($new_photo_path_db) && !empty($old_photo_path)) {
        deletePhotoFile($old_photo_path); // Attempt to delete old file
    }

    // --- Send Success Response ---
    // Optionally fetch and return the updated candidate data here if needed by the frontend
    send_json_response(true, null, 'Candidate updated successfully.');

} catch (Exception $e) {
    $conn->rollback(); // Roll back DB changes on any error
    error_log("Edit Candidate Failed (ID: $candidate_id): " . $e->getMessage()); // Log the error
    // Don't attempt to delete newly uploaded file here again if rollback occurred
    send_json_response(false, null, $e->getMessage()); // Send specific error message back
} finally {
    // Optional: Close connection if script ends here
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
        mysqli_close($conn);
    }
}
?>