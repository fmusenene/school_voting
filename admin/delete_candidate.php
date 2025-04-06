<?php
require_once "../config/database.php"; // Use correct path and $conn
session_start();

header('Content-Type: application/json');
$response = ['success' => false, 'message' => 'An unexpected error occurred.'];

// Auth Check
if (!isset($_SESSION['admin_id'])) { $response['message'] = 'Unauthorized access.'; http_response_code(401); echo json_encode($response); exit(); }
// Method Check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $response['message'] = 'Invalid request method.'; http_response_code(405); echo json_encode($response); exit(); }

// Input Validation - **Crucially, check 'candidate_id' from POST as sent by JS**
$candidate_id = filter_input(INPUT_POST, 'candidate_id', FILTER_VALIDATE_INT); // Changed from $_GET to $_POST
if (!$candidate_id) { $response['message'] = 'Candidate ID not provided or invalid.'; http_response_code(400); echo json_encode($response); exit; }

// Use the correct database connection variable $conn
global $conn;

try {
    $conn->beginTransaction();

    // 1. Get candidate photo path *before* deleting the record
    $get_photo_sql = "SELECT photo FROM candidates WHERE id = :id";
    $get_photo_stmt = $conn->prepare($get_photo_sql);
    $get_photo_stmt->execute([':id' => $candidate_id]);
    $photo_path_relative = $get_photo_stmt->fetchColumn();

    // 2. Delete candidate from database
    $delete_sql = "DELETE FROM candidates WHERE id = :id";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->execute([':id' => $candidate_id]);

    // Check if deletion affected any rows
    if ($delete_stmt->rowCount() > 0) {
        // 3. Delete photo file *before* committing, if it exists
        $photo_deleted_successfully = true; // Assume success unless deletion fails
        if ($photo_path_relative) {
            // Construct the absolute path relative to this script's directory
            $absolute_photo_path = realpath(__DIR__ . "/../" . ltrim($photo_path_relative, '/'));
            if ($absolute_photo_path && file_exists($absolute_photo_path)) {
                if (!@unlink($absolute_photo_path)) { // Use @ to suppress default PHP warning, we log instead
                     error_log("Failed to delete photo file for candidate ID {$candidate_id}: {$absolute_photo_path}");
                    $photo_deleted_successfully = false; // Mark as failed
                }
            } else if ($absolute_photo_path) {
                error_log("Photo file record existed but file not found at: {$absolute_photo_path}");
            }
        }

        // 4. Commit transaction only if DB deletion was successful
        $conn->commit();
        $response['success'] = true;
        $response['message'] = 'Candidate deleted successfully.';
        if (!$photo_deleted_successfully && $photo_path_relative) { // Add warning only if deletion was expected but failed
            $response['message'] .= ' (Warning: associated photo file could not be deleted).';
        }
         http_response_code(200);
    } else {
        // Candidate likely didn't exist, rollback is safe
        $conn->rollBack();
        $response['message'] = 'Candidate not found or already deleted.';
        http_response_code(404); // Not Found
    }

} catch (PDOException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log("Delete Candidate PDOException: " . $e->getMessage());
    $response['message'] = 'Database error occurred during deletion.';
    http_response_code(500);
} catch (Exception $e) { // Catch potential filesystem errors
     if ($conn->inTransaction()) $conn->rollBack();
     error_log("Delete Candidate Exception: " . $e->getMessage());
     $response['message'] = 'An error occurred: ' . $e->getMessage();
     http_response_code(500);
}

echo json_encode($response);
?>