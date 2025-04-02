<?php
session_start();
require_once "../config/database.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Check if ID is provided
if (!isset($_GET['id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Position ID is required']);
    exit();
}

try {
    // Get position data
    $sql = "SELECT * FROM positions WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$_GET['id']]);
    $position = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$position) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Position not found']);
        exit();
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'position' => $position
    ]);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} 