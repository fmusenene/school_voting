<?php
session_start();
require_once "../config/database.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get POST data
$admin_id = $_SESSION['admin_id'];
$username = trim($_POST['username'] ?? '');
$current_password = $_POST['current_password'] ?? '';
$new_password = $_POST['new_password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

try {
    // Start transaction
    $conn->beginTransaction();

    // First, get the current admin data
    $stmt = $conn->prepare("SELECT username, password FROM admins WHERE id = ?");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        throw new Exception('Admin account not found');
    }

    // Initialize update fields array
    $updateFields = [];
    $params = [];

    // Validate and handle username update
    if (empty($username)) {
        throw new Exception('Username cannot be empty');
    }

    // Check if username is changed and if it's already taken
    if ($username !== $admin['username']) {
        $stmt = $conn->prepare("SELECT id FROM admins WHERE username = ? AND id != ?");
        $stmt->execute([$username, $admin_id]);
        if ($stmt->fetch()) {
            throw new Exception('Username is already taken');
        }
        $updateFields[] = "username = ?";
        $params[] = $username;
    }

    // Handle password update if provided
    if (!empty($new_password) || !empty($confirm_password)) {
        // Verify current password is provided and correct
        if (empty($current_password)) {
            throw new Exception('Current password is required to change password');
        }

        if (!password_verify($current_password, $admin['password'])) {
            throw new Exception('Current password is incorrect');
        }

        // Validate new password
        if ($new_password !== $confirm_password) {
            throw new Exception('New passwords do not match');
        }

        if (strlen($new_password) < 8) {
            throw new Exception('New password must be at least 8 characters long');
        }

        // Add password to update fields
        $updateFields[] = "password = ?";
        $params[] = password_hash($new_password, PASSWORD_DEFAULT);
    }

    // If there are fields to update
    if (!empty($updateFields)) {
        // Add admin_id to params
        $params[] = $admin_id;

        // Construct and execute update query
        $sql = "UPDATE admins SET " . implode(", ", $updateFields) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        // Update session if username was changed
        if ($username !== $admin['username']) {
            $_SESSION['admin_username'] = $username;
        }

        // Commit transaction
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Profile updated successfully',
            'username' => $username // Send back updated username for UI update
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'No changes were made',
            'username' => $username
        ]);
    }

} catch (Exception $e) {
    // Rollback transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} catch (PDOException $e) {
    // Rollback transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    error_log("Profile Update Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again.'
    ]);
} 