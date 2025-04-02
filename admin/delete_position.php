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
    $position_id = $_GET['id'];

    // Start transaction
    $conn->beginTransaction();

    // First delete related votes
    $sql = "DELETE v FROM votes v 
            INNER JOIN candidates c ON v.candidate_id = c.id 
            WHERE c.position_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$position_id]);

    // Then delete related candidates
    $sql = "DELETE FROM candidates WHERE position_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$position_id]);

    // Finally delete the position
    $sql = "DELETE FROM positions WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$position_id]);

    // Commit transaction
    $conn->commit();

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Position deleted successfully'
    ]);
} catch (PDOException $e) {
    // Rollback transaction on error
    $conn->rollBack();
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} 