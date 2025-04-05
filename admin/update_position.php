<?php
session_start();
require_once "../config/database.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Get and validate form data
$position_id = trim($_POST['position_id'] ?? '');
$title = trim($_POST['title'] ?? '');
$election_id = trim($_POST['election_id'] ?? '');
$description = trim($_POST['description'] ?? '');

if (empty($position_id) || empty($title) || empty($election_id) || empty($description)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit();
}

try {
    // Update position
    $sql = "UPDATE positions SET title = ?, election_id = ?, description = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$title, $election_id, $description, $position_id]);

    if ($stmt->rowCount() === 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Position not found or no changes made']);
        exit();
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Position updated successfully'
    ]);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} 