<?php
require_once "../config/database.php"; // Ensure path is correct relative to admin folder
session_start();

// Function to safely handle file uploads
function handleFileUpload($file, $upload_subdir = 'candidates') {
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return null; // No file is not an error
        }
        error_log("File Upload Error: Code " . $file['error']);
        throw new Exception('File upload error (Code: '.$file['error'].').');
    }

    if ($file['size'] > 5 * 1024 * 1024) { // Max 5 MB
        throw new Exception('File is too large (Max 5MB).');
    }

    $upload_dir_base = realpath(__DIR__ . "/../uploads"); // Base uploads directory (relative to this script's dir)
    if (!$upload_dir_base) {
        error_log("Base upload directory '../uploads' not found or accessible.");
        throw new Exception('Server configuration error: Upload directory base path invalid.');
    }
    $upload_dir = $upload_dir_base . DIRECTORY_SEPARATOR . $upload_subdir . DIRECTORY_SEPARATOR;


    // Create directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0775, true)) { // Use 0775 for permissions
             error_log("Failed to create upload directory: " . $upload_dir);
            throw new Exception('Failed to create upload directory.');
        }
    }
     // Check writability after attempting creation
     if (!is_writable($upload_dir)) {
         error_log("Upload directory not writable: " . $upload_dir);
         // Try changing permissions (use with caution, depends on server setup)
         // chmod($upload_dir, 0775);
         // if (!is_writable($upload_dir)) {
             throw new Exception('Upload directory is not writable. Please check server permissions.');
         //}
     }

    // Validate MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed_mime_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime_type, $allowed_mime_types, true)) {
        throw new Exception('Invalid file type. Only JPG, PNG, GIF, WEBP allowed.');
    }

    // Validate extension
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
     if (!in_array($file_extension, $allowed_extensions, true)) {
         throw new Exception('Invalid file extension.');
     }


    // Generate unique filename and target path
    $new_filename = uniqid('cand_', true) . '.' . $file_extension;
    $target_path_absolute = $upload_dir . $new_filename;

    // Move the uploaded file
    if (move_uploaded_file($file['tmp_name'], $target_path_absolute)) {
        // Return path relative to the *project root* for database storage
        return "uploads/" . $upload_subdir . "/" . $new_filename;
    }

    // Log detailed error if move failed
    $move_error = error_get_last();
    error_log("Failed to move uploaded file. Target: {$target_path_absolute}. Error: " . ($move_error['message'] ?? 'Unknown error'));
    throw new Exception('Failed to save uploaded file.');
}


// --- Main Script Logic ---
header('Content-Type: application/json');
$response = ['success' => false, 'message' => 'An unexpected error occurred.'];

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    $response['message'] = 'Unauthorized access.';
    http_response_code(401);
    echo json_encode($response);
    exit();
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method.';
    http_response_code(405);
    echo json_encode($response);
    exit();
}

// Get and validate input data
$name = trim($_POST['name'] ?? '');
$position_id = filter_input(INPUT_POST, 'position_id', FILTER_VALIDATE_INT);
$bio = trim($_POST['bio'] ?? ''); // Optional

if (empty($name) || !$position_id) {
    $response['message'] = 'Candidate Name and Position are required.';
     http_response_code(400);
    echo json_encode($response);
    exit;
}

// Database connection should be $conn from database.php
global $conn; // Make $conn available

try {
    // Handle photo upload
    $photo_path_relative_to_root = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) { // Check it's not just an empty file input
         $photo_path_relative_to_root = handleFileUpload($_FILES['photo'], 'candidates');
    }

    // Insert into database
    $sql = "INSERT INTO candidates (name, position_id, bio, photo) VALUES (:name, :position_id, :bio, :photo)";
    $stmt = $conn->prepare($sql); // Use $conn

    $stmt->bindParam(':name', $name, PDO::PARAM_STR);
    $stmt->bindParam(':position_id', $position_id, PDO::PARAM_INT);
    $stmt->bindParam(':bio', $bio, PDO::PARAM_STR);
    // Bind photo path (can be null if no file uploaded/error)
    $stmt->bindParam(':photo', $photo_path_relative_to_root, PDO::PARAM_STR);

    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = 'Candidate added successfully!';
        http_response_code(201); // Created
    } else {
        error_log("Database Error (Add Candidate): " . implode(", ", $stmt->errorInfo()));
        $response['message'] = 'Database error occurred while adding candidate.';
         http_response_code(500);
    }

} catch (PDOException $e) {
    error_log("PDO Exception (Add Candidate): " . $e->getMessage());
    $response['message'] = 'Database error: ' . $e->getMessage();
     http_response_code(500);
} catch (Exception $e) {
     error_log("General Exception (Add Candidate): " . $e->getMessage());
     $response['message'] = 'Error: ' . $e->getMessage();
     http_response_code(400); // Likely a user error (bad file) or config error
}

echo json_encode($response);
?>