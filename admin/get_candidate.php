<?php
require_once "../config/database.php";

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Candidate ID not provided']);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT * FROM candidates WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $candidate = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($candidate) {
        echo json_encode(['success' => true, 'candidate' => $candidate]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Candidate not found']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} 