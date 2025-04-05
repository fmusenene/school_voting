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
$title = mysqli_real_escape_string($conn, trim($_POST['title'] ?? ''));
$election_id = mysqli_real_escape_string($conn, trim($_POST['election_id'] ?? ''));
$description = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));

if (empty($title) || empty($election_id) || empty($description)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit();
}

// Insert new position
$sql = "INSERT INTO positions (title, election_id, description) VALUES ('$title', '$election_id', '$description')";

if (mysqli_query($conn, $sql)) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Position added successfully',
        'position_id' => mysqli_insert_id($conn)
    ]);
} else {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . mysqli_error($conn)
    ]);
}