<?php
session_start();
require_once "../config/database.php"; // Assumes $conn is a mysqli object

// --- Response Helper Function ---
// Simplifies sending JSON responses and exiting
function send_json_response($success, $data = null, $message = null, $data_key = 'data') {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $response = ['success' => (bool)$success];
    if ($message !== null) {
        $response['message'] = $message;
    }
    if ($success && $data !== null && $data_key !== null) {
        $response[$data_key] = $data;
    } elseif (!$success && $data !== null) {
        // $response['error_details'] = $data; // Optional for debug
    }
    echo json_encode($response);
    exit();
}

// --- File Upload Handler ---
// (Largely unchanged, but ensure it throws Exceptions on failure)
function handleFileUpload($file) {
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        // Handle cases like no file uploaded gracefully if optional
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return ''; // No file uploaded, return empty path
        }
        // Throw exception for other upload errors
        throw new Exception('File upload error: Code ' . $file['error']);
    }

    // Basic security check for file size (example: 5MB limit)
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('File is too large. Maximum size allowed is 5MB.');
    }

    $upload_dir = "../uploads/candidates/"; // Relative path from this script's location
    // Ensure the upload directory exists and is writable
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0775, true)) { // Use 0775 for better security than 0777
             throw new Exception('Failed to create upload directory.');
        }
    }
    if (!is_writable($upload_dir)) {
        throw new Exception('Upload directory is not writable.');
    }


    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];

    if (!in_array($file_extension, $allowed_extensions)) {
        throw new Exception('Invalid file type. Only JPG, JPEG, PNG, and GIF are allowed.');
    }

    // Generate a unique filename to prevent overwrites and potential security issues
    $new_filename = uniqid('candidate_', true) . '.' . $file_extension; // More unique prefix
    $target_path = $upload_dir . $new_filename;

    // Use move_uploaded_file
    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        // Return the path relative to the web root for storage in DB
        return 'uploads/candidates/' . $new_filename;
    }

    // If move_uploaded_file fails
    throw new Exception('Failed to move uploaded file.');
}

// --- Main Script Logic ---

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    send_json_response(false, null, 'Unauthorized access.');
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response(false, null, 'Invalid request method.');
}

// Check DB connection
if (!$conn || $conn->connect_error) {
     error_log("Database connection error: " . ($conn ? $conn->connect_error : 'Unknown error'));
     send_json_response(false, null, 'Database connection failed.');
}


try {
    // --- Get and Validate Inputs ---
    $candidate_id = isset($_POST['candidate_id']) ? (int)trim($_POST['candidate_id']) : 0;
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? ''); // Allow empty description
    $position_id = isset($_POST['position_id']) ? (int)trim($_POST['position_id']) : 0;

    // Basic validation
    if ($candidate_id <= 0 || empty($name) || $position_id <= 0) {
        throw new Exception('Missing or invalid required fields (Candidate ID, Name, Position ID).');
    }

    // --- Handle Optional File Upload ---
    // This will throw an exception on upload error, caught by the main catch block
    $photo_path = ''; // Initialize photo path
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
         $photo_path = handleFileUpload($_FILES['photo']);
    }


    // --- Sanitize/Escape Data for SQL ---
    $name_escaped = $conn->real_escape_string($name);
    $description_escaped = $conn->real_escape_string($description);
    // **Crucially, escape the file path string as well**
    $photo_path_escaped = $conn->real_escape_string($photo_path);

    // --- Construct Conditional UPDATE SQL ---
    $update_sql = "UPDATE candidates SET ";
    $update_sql .= "name = '$name_escaped', "; // Quote escaped string
    $update_sql .= "description = '$description_escaped', "; // Quote escaped string
    $update_sql .= "position_id = $position_id "; // Integer, no quotes

    // Only add the photo update part if a new photo was successfully uploaded
    if (!empty($photo_path)) { // Check if handleFileUpload returned a non-empty path
        $update_sql .= ", photo = '$photo_path_escaped' "; // Quote escaped string path
    }

    $update_sql .= " WHERE id = $candidate_id"; // Integer, no quotes

    // --- Execute UPDATE Query ---
    $update_result = $conn->query($update_sql);

    if ($update_result === false) {
        // Throw exception on update failure
        throw new Exception("Database error during update: " . $conn->error . " | SQL: " . $update_sql);
    }

    // Update successful (or no rows affected but query OK), now fetch updated data

    // --- Construct SELECT Query ---
    $select_sql = "SELECT c.*, p.title as position_title, e.title as election_title
                   FROM candidates c
                   LEFT JOIN positions p ON c.position_id = p.id
                   LEFT JOIN elections e ON p.election_id = e.id
                   WHERE c.id = $candidate_id"; // Embed integer ID

    // --- Execute SELECT Query ---
    $select_result = $conn->query($select_sql);

    if ($select_result === false) {
        // Log error, but maybe the update still worked partially? Decide on desired behavior.
        // For now, report error fetching the final state.
        error_log("Update query succeeded, but failed to fetch updated candidate (ID: $candidate_id): " . $conn->error);
        throw new Exception("Database error fetching updated data: " . $conn->error);
    }

    // --- Fetch and Return Updated Data ---
    if ($select_result->num_rows > 0) {
        $updated_candidate = $select_result->fetch_assoc();
        $select_result->free();
        send_json_response(true, $updated_candidate, 'Candidate updated successfully.', 'candidate');
    } else {
        // Should not happen if update targeted an existing ID, but handle defensively
        $select_result->free();
        throw new Exception("Failed to retrieve candidate data after update (ID: $candidate_id not found).");
    }

} catch (Exception $e) {
    // Catch exceptions from file upload, validation, DB errors, or fetch errors
    // Don't attempt rollback as no transaction was started for this single update
    error_log("Error updating candidate (ID: " . ($candidate_id ?? 'unknown') . "): " . $e->getMessage()); // Log the actual error
    send_json_response(false, null, 'Error updating candidate: ' . $e->getMessage()); // Send specific error back
} finally {
     // Optional: Close the connection
     if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
         $conn->close();
     }
}

?>