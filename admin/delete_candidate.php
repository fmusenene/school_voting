<?php
require_once "../config/database.php";

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!isset($_POST['candidate_id'])) {
    echo json_encode(['success' => false, 'message' => 'Candidate ID not provided']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Get candidate photo path
    $stmt = $pdo->prepare("SELECT photo FROM candidates WHERE id = ?");
    $stmt->execute([$_POST['candidate_id']]);
    $photo_path = $stmt->fetchColumn();

    // Delete candidate
    $stmt = $pdo->prepare("DELETE FROM candidates WHERE id = ?");
    $stmt->execute([$_POST['candidate_id']]);

    // Delete photo file if exists
    if ($photo_path && file_exists("../" . $photo_path)) {
        unlink("../" . $photo_path);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Candidate deleted successfully']);

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} 