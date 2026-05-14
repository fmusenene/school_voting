<?php
// Password Reset Confirmation API
header('Content-Type: application/json');

// Database configuration
$host = 'localhost';
$dbname = 'school_voting';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Only accept POST requests
    if ($_SERVER["REQUEST_METHOD"] != "POST") {
        echo json_encode(["success" => false, "message" => "Invalid request method"]);
        exit();
    }

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    $token = trim($input['token'] ?? '');
    $new_password = trim($input['password'] ?? '');

    if (empty($token)) {
        echo json_encode(["success" => false, "message" => "Reset token is required"]);
        exit();
    }

    if (empty($new_password)) {
        echo json_encode(["success" => false, "message" => "New password is required"]);
        exit();
    }

    // Validate password strength
    if (strlen($new_password) < 8) {
        echo json_encode(["success" => false, "message" => "Password must be at least 8 characters long"]);
        exit();
    }

    if (!preg_match('/[A-Z]/', $new_password)) {
        echo json_encode(["success" => false, "message" => "Password must contain at least one uppercase letter"]);
        exit();
    }

    if (!preg_match('/[a-z]/', $new_password)) {
        echo json_encode(["success" => false, "message" => "Password must contain at least one lowercase letter"]);
        exit();
    }

    if (!preg_match('/[0-9]/', $new_password)) {
        echo json_encode(["success" => false, "message" => "Password must contain at least one number"]);
        exit();
    }

    if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $new_password)) {
        echo json_encode(["success" => false, "message" => "Password must contain at least one special character"]);
        exit();
    }

    // Check if token exists and is valid
    $stmt = $pdo->prepare("SELECT id, username, reset_token_expiry FROM admins WHERE reset_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        echo json_encode(["success" => false, "message" => "Invalid or expired reset token"]);
        exit();
    }

    // Check if token has expired
    if (strtotime($admin['reset_token_expiry']) < time()) {
        echo json_encode(["success" => false, "message" => "Reset token has expired. Please request a new password reset."]);
        exit();
    }

    // Hash the new password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

    // Update password and clear reset token
    $stmt = $pdo->prepare("UPDATE admins SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ?");
    $stmt->execute([$hashed_password, $admin['id']]);

    echo json_encode(["success" => true, "message" => "Password has been reset successfully. You can now login with your new password."]);

} catch(PDOException $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
} catch(Exception $e) {
    echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
}
?>
