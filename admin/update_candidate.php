<?php
require_once "../config/database.php";

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

try {
    $pdo->beginTransaction();

    $candidate_id = $_POST['candidate_id'];
    $name = $_POST['name'];
    $description = $_POST['description'];
    $position_id = $_POST['position_id'];

    // Update basic info
    $stmt = $pdo->prepare("UPDATE candidates SET name = ?, description = ?, position_id = ? WHERE id = ?");
    $stmt->execute([$name, $description, $position_id, $candidate_id]);

    // Handle photo upload if provided
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = "../uploads/candidates/";
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_extension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $new_filename = uniqid() . '.' . $file_extension;
        $target_path = $upload_dir . $new_filename;

        if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_path)) {
            // Delete old photo if exists
            $stmt = $pdo->prepare("SELECT photo FROM candidates WHERE id = ?");
            $stmt->execute([$candidate_id]);
            $old_photo = $stmt->fetchColumn();

            if ($old_photo && file_exists("../" . $old_photo)) {
                unlink("../" . $old_photo);
            }

            // Update photo path in database
            $photo_path = "uploads/candidates/" . $new_filename;
            $stmt = $pdo->prepare("UPDATE candidates SET photo = ? WHERE id = ?");
            $stmt->execute([$photo_path, $candidate_id]);
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Candidate updated successfully']);

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} 