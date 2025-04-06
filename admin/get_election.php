<?php
require_once "../config/database.php";
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Validate election ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid election ID']);
    exit();
}

try {
    // Prepare and execute query
    $sql = "SELECT id, title, description, start_date, end_date, status 
            FROM elections 
            WHERE id = :id";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':id', $_GET['id'], PDO::PARAM_INT);
    $stmt->execute();
    
    $election = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($election) {
        // Format dates for datetime-local input
        $election['start_date'] = date('Y-m-d\TH:i', strtotime($election['start_date']));
        $election['end_date'] = date('Y-m-d\TH:i', strtotime($election['end_date']));
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'election' => $election
        ]);
    } else {
        throw new Exception('Election not found');
    }
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving election: ' . $e->getMessage()
    ]);
} 