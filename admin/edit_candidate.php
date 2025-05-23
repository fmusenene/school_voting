<?php
require_once "../config/database.php"; // Use correct path and connection variable $conn
session_start();

// Function to safely handle file uploads (same as in add_candidate.php)
function handleFileUpload($file, $upload_subdir = 'candidates') {
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_NO_FILE) { return null; }
        error_log("File Upload Error: Code " . $file['error']);
        throw new Exception('File upload error (Code: '.$file['error'].').');
    }
    if ($file['size'] > 5 * 1024 * 1024) { throw new Exception('File is too large (Max 5MB).'); }

    $upload_dir_base = realpath(__DIR__ . "/../uploads");
    if (!$upload_dir_base) {
        error_log("Base upload directory '../uploads' not found or accessible.");
        throw new Exception('Server configuration error: Upload directory base path invalid.');
    }
    $upload_dir = $upload_dir_base . DIRECTORY_SEPARATOR . $upload_subdir . DIRECTORY_SEPARATOR;


    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0775, true)) {
             error_log("Failed to create upload directory: " . $upload_dir);
            throw new Exception('Failed to create upload directory.');
        }
    }
     if (!is_writable($upload_dir)) {
         error_log("Upload directory not writable: " . $upload_dir);
         throw new Exception('Upload directory is not writable. Please check server permissions.');
     }

    $finfo = finfo_open(FILEINFO_MIME_TYPE); $mime_type = finfo_file($finfo, $file['tmp_name']); finfo_close($finfo);
    $allowed_mime_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime_type, $allowed_mime_types, true)) { throw new Exception('Invalid file type. Only JPG, PNG, GIF, WEBP allowed.'); }

    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
     if (!in_array($file_extension, $allowed_extensions, true)) { throw new Exception('Invalid file extension.'); }

    $new_filename = uniqid('cand_', true) . '.' . $file_extension;
    $target_path_absolute = $upload_dir . $new_filename;

    if (move_uploaded_file($file['tmp_name'], $target_path_absolute)) {
        return "uploads/" . $upload_subdir . "/" . $new_filename; // Path relative to project root
    }

    $move_error = error_get_last();
    error_log("Failed to move uploaded file. Target: {$target_path_absolute}. Error: " . ($move_error['message'] ?? 'Unknown error'));
    throw new Exception('Failed to save uploaded file.');
}

// --- Main Script Logic ---
header('Content-Type: application/json');
$response = ['success' => false, 'message' => 'An unexpected error occurred.'];

// Auth Check
if (!isset($_SESSION['admin_id'])) { $response['message'] = 'Unauthorized access.'; http_response_code(401); echo json_encode($response); exit(); }
// Method Check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $response['message'] = 'Invalid request method.'; http_response_code(405); echo json_encode($response); exit(); }

// Input Validation
$candidate_id = filter_input(INPUT_POST, 'candidate_id', FILTER_VALIDATE_INT);
$name = trim($_POST['name'] ?? '');
$position_id = filter_input(INPUT_POST, 'position_id', FILTER_VALIDATE_INT);
$bio = trim($_POST['bio'] ?? '');

if (!$candidate_id || empty($name) || !$position_id) { $response['message'] = 'Missing required fields (ID, Name, Position).'; http_response_code(400); echo json_encode($response); exit; }

// Use the correct database connection variable $conn
global $conn;

try {
    $conn->beginTransaction();

    // --- Get Current Photo Path ---
    $get_photo_sql = "SELECT photo FROM candidates WHERE id = :id";
    $get_photo_stmt = $conn->prepare($get_photo_sql);
    $get_photo_stmt->execute([':id' => $candidate_id]);
    $old_photo_path_relative = $get_photo_stmt->fetchColumn();

    // --- Handle New Photo Upload ---
    $new_photo_path_relative = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $new_photo_path_relative = handleFileUpload($_FILES['photo'], 'candidates');
    }

    // --- Prepare Database Update ---
    $params = [
        ':name' => $name,
        ':bio' => $bio,
        ':position_id' => $position_id,
        ':id' => $candidate_id
    ];

    // Determine which SQL query to use based on whether a new photo was uploaded
    if ($new_photo_path_relative !== null) {
        $sql = "UPDATE candidates SET name = :name, bio = :bio, position_id = :position_id, photo = :photo WHERE id = :id";
        $params[':photo'] = $new_photo_path_relative;
    } else {
        $sql = "UPDATE candidates SET name = :name, bio = :bio, position_id = :position_id WHERE id = :id";
    }

    $stmt = $conn->prepare($sql); // Use $conn
    $update_success = $stmt->execute($params);

    if ($update_success) {
        // --- Delete Old Photo *After* DB Update Success (if new photo was uploaded) ---
        if ($new_photo_path_relative !== null && $old_photo_path_relative) {
            $absolute_old_photo_path = realpath(__DIR__ . "/../" . ltrim($old_photo_path_relative, '/'));
            if ($absolute_old_photo_path && file_exists($absolute_old_photo_path)) {
                if (!unlink($absolute_old_photo_path)) {
                     error_log("Edit Candidate: Failed to delete old photo file: {$absolute_old_photo_path}");
                     $response['warning'] = "Old photo file could not be deleted.";
                }
            }
        }

        $conn->commit(); // Commit the transaction
        $response['success'] = true;
        $response['message'] = 'Candidate updated successfully!';
         if (isset($response['warning'])) { $response['message'] .= ' (' . $response['warning'] . ')'; }
        http_response_code(200);

    } else {
        $conn->rollBack(); // Rollback if DB update failed
        error_log("Database Error (Update Candidate): " . implode(", ", $stmt->errorInfo()));
        $response['message'] = 'Database error occurred while updating candidate.';
        http_response_code(500);
    }

} catch (PDOException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log("PDO Exception (Update Candidate): " . $e->getMessage());
    $response['message'] = 'Database error: ' . $e->getMessage();
    http_response_code(500);
} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log("General Exception (Update Candidate): " . $e->getMessage());
    $response['message'] = 'Error: ' . $e->getMessage();
    http_response_code((strpos($e->getMessage(), 'upload') !== false || strpos($e->getMessage(), 'type') !== false || strpos($e->getMessage(), 'large') !== false) ? 400 : 500);
}

echo json_encode($response);
?>