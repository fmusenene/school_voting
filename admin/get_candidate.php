<?php
require_once "../config/database.php";
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Get candidate ID from request
$candidate_id = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (!$candidate_id) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing candidate ID']);
    exit();
}

try {
    // Get candidate data with position and election info
    $sql = "SELECT c.*, p.title as position_title, e.title as election_title 
            FROM candidates c
            LEFT JOIN positions p ON c.position_id = p.id
            LEFT JOIN elections e ON p.election_id = e.id
            WHERE c.id = :id";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $candidate_id]);
    $candidate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($candidate) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'candidate' => $candidate
        ]);
    } else {
        throw new Exception('Candidate not found');
    }
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching candidate: ' . $e->getMessage()
    ]);
} 