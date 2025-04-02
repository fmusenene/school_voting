<?php
session_start();
require_once "../config/database.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access'
    ]);
    exit();
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
    exit();
}

// Get JSON data from request body
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON data'
    ]);
    exit();
}

// Validate required fields
$required_fields = ['id', 'title', 'start_date', 'end_date', 'status'];
foreach ($required_fields as $field) {
    if (!isset($data[$field]) || empty($data[$field])) {
        echo json_encode([
            'success' => false,
            'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required'
        ]);
        exit();
    }
}

$election_id = (int)$data['id'];
$title = trim($data['title']);
$description = trim($data['description']);
$start_date = $data['start_date'];
$end_date = $data['end_date'];
$status = $data['status'];

// Validate dates
if (strtotime($end_date) <= strtotime($start_date)) {
    echo json_encode([
        'success' => false,
        'message' => 'End date must be after start date'
    ]);
    exit();
}

try {
    $sql = "UPDATE elections 
            SET title = ?, description = ?, start_date = ?, end_date = ?, status = ? 
            WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if ($stmt->execute([$title, $description, $start_date, $end_date, $status, $election_id])) {
        echo json_encode([
            'success' => true,
            'message' => 'Election updated successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Error updating election'
        ]);
    }
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} 