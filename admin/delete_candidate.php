<?php
require_once "../config/database.php";

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$candidate_id = isset($_POST['candidate_id']) ? (int)$_POST['candidate_id'] : 0;
if ($candidate_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Candidate ID not provided or invalid']);
    exit;
}

mysqli_begin_transaction($conn);

try {
    // Get candidate photo path
    $sql = "SELECT photo FROM candidates WHERE id = $candidate_id LIMIT 1";
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        throw new Exception("Failed to fetch candidate: " . mysqli_error($conn));
    }
    $row = mysqli_fetch_assoc($result);
    if (!$row) {
        throw new Exception("Candidate not found");
    }
    $photo_path = $row['photo'];

    // Delete candidate
    $delete_sql = "DELETE FROM candidates WHERE id = $candidate_id";
    $delete_result = mysqli_query($conn, $delete_sql);
    if ($delete_result === false) {
        throw new Exception("Failed to delete candidate: " . mysqli_error($conn));
    }

    // Delete photo file if exists
    if ($photo_path && file_exists("../" . $photo_path)) {
        if (!unlink("../" . $photo_path)) {
            error_log("Warning: Could not delete photo file at ../" . $photo_path);
        }
    }

    mysqli_commit($conn);
    echo json_encode(['success' => true, 'message' => 'Candidate deleted successfully']);

} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
