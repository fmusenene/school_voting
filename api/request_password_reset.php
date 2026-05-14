<?php
// Password Reset Request API
header('Content-Type: application/json');

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/password_reset_errors.log');

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
        error_log("Invalid request method: " . $_SERVER["REQUEST_METHOD"]);
        echo json_encode(["success" => false, "message" => "Invalid request method"]);
        exit();
    }

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');

    error_log("Password reset request for email: " . $email);

    if (empty($email)) {
        echo json_encode(["success" => false, "message" => "Email is required"]);
        exit();
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(["success" => false, "message" => "Invalid email format"]);
        exit();
    }

    // Check if admin exists with this email
    $stmt = $pdo->prepare("SELECT id, username, email FROM admins WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        error_log("No admin found with email: " . $email);
        // Don't reveal if email exists for security
        echo json_encode(["success" => true, "message" => "If an account with this email exists, a password reset link has been sent."]);
        exit();
    }

    error_log("Admin found: " . $admin['username']);

    // Generate secure random token
    $token = bin2hex(random_bytes(32));
    $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

    // Update admin with reset token
    $stmt = $pdo->prepare("UPDATE admins SET reset_token = ?, reset_token_expiry = ? WHERE id = ?");
    $stmt->execute([$token, $expiry, $admin['id']]);

    error_log("Reset token generated for admin ID: " . $admin['id']);

    // Generate reset link
    $reset_link = "http://" . $_SERVER['HTTP_HOST'] . "/schoolvoting/reset_password_form.php?token=" . $token;

    // Send email
    $to = $admin['email'];
    $subject = "Password Reset Request - School Voting System";
    $message = "
    <html>
    <head>
    <title>Password Reset</title>
    </head>
    <body>
    <h2>Password Reset Request</h2>
    <p>Hello " . htmlspecialchars($admin['username']) . ",</p>
    <p>You have requested a password reset for your School Voting System admin account.</p>
    <p>Click the link below to reset your password:</p>
    <p><a href='" . $reset_link . "'>" . $reset_link . "</a></p>
    <p>This link will expire in 1 hour.</p>
    <p>If you did not request this password reset, please ignore this email.</p>
    <p>Best regards,<br>School Voting System Team</p>
    </body>
    </html>
    ";

    // Email headers
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: noreply@schoolvoting.com" . "\r\n";

    // Send email
    $mail_sent = mail($to, $subject, $message, $headers);
    error_log("Mail send result: " . ($mail_sent ? 'success' : 'failed'));

    if ($mail_sent) {
        echo json_encode(["success" => true, "message" => "Password reset link has been sent to your email."]);
    } else {
        // For testing purposes, return the reset link in the response
        // In production, you should configure the mail server properly
        error_log("Email sending failed. Reset link: " . $reset_link);
        echo json_encode([
            "success" => true, 
            "message" => "Email sending is not configured. Reset link: " . $reset_link,
            "reset_link" => $reset_link
        ]);
    }

} catch(PDOException $e) {
    error_log("PDO Exception: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
} catch(Exception $e) {
    error_log("General Exception: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
}
?>
