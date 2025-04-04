<?php
require_once "../config/database.php";
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Handle file upload
function handleFileUpload($file) {
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        return '';
    }

    $upload_dir = "../uploads/candidates/";
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];

    if (!in_array($file_extension, $allowed_extensions)) {
        throw new Exception('Invalid file type. Only JPG, JPEG, PNG, and GIF files are allowed.');
    }

    $new_filename = uniqid() . '.' . $file_extension;
    $target_path = $upload_dir . $new_filename;

    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        return 'uploads/candidates/' . $new_filename;
    }

    throw new Exception('Failed to upload file.');
}

try {
    // Get candidate ID and data
    $candidate_id = isset($_POST['candidate_id']) ? (int)$_POST['candidate_id'] : null;
    $name = isset($_POST['name']) ? trim($_POST['name']) : null;
    $description = isset($_POST['description']) ? trim($_POST['description']) : null;
    $position_id = isset($_POST['position_id']) ? (int)$_POST['position_id'] : null;

    // Validate required fields
    if (!$candidate_id || !$name || !$position_id) {
        throw new Exception('Missing required fields');
    }

    // Handle photo upload if provided
    $photo_path = '';
    if (isset($_FILES['photo']) && $_FILES['photo']['size'] > 0) {
        $photo_path = handleFileUpload($_FILES['photo']);
    }

    // Prepare the update query
    if ($photo_path) {
        // If new photo uploaded, update all fields including photo
        $sql = "UPDATE candidates 
                SET name = :name, 
                    description = :description, 
                    position_id = :position_id,
                    photo = :photo
                WHERE id = :id";
        $params = [
            ':name' => $name,
            ':description' => $description,
            ':position_id' => $position_id,
            ':photo' => $photo_path,
            ':id' => $candidate_id
        ];
    } else {
        // If no new photo, update all fields except photo
        $sql = "UPDATE candidates 
                SET name = :name, 
                    description = :description, 
                    position_id = :position_id
                WHERE id = :id";
        $params = [
            ':name' => $name,
            ':description' => $description,
            ':position_id' => $position_id,
            ':id' => $candidate_id
        ];
    }

    // Execute the update
    $stmt = $conn->prepare($sql);
    if ($stmt->execute($params)) {
        // Get updated candidate data
        $select_sql = "SELECT c.*, p.title as position_title, e.title as election_title 
                      FROM candidates c
                      LEFT JOIN positions p ON c.position_id = p.id
                      LEFT JOIN elections e ON p.election_id = e.id
                      WHERE c.id = :id";
        $select_stmt = $conn->prepare($select_sql);
        $select_stmt->execute([':id' => $candidate_id]);
        $updated_candidate = $select_stmt->fetch(PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Candidate updated successfully',
            'candidate' => $updated_candidate
        ]);
    } else {
        throw new Exception('Failed to update candidate');
    }
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Error updating candidate: ' . $e->getMessage()
    ]);
} 