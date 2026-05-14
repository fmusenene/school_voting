<?php
// Add email to existing admin account
$host = 'localhost';
$dbname = 'school_voting';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Update admin with email (replace with actual email)
    $email = 'amarumugisha@gmail.com';
    $stmt = $pdo->prepare("UPDATE admins SET email = ? WHERE username = 'admin'");
    $stmt->execute([$email]);

    echo "Admin email updated successfully to: " . $email . "\n";
    echo "You can now use this email for password reset.\n";

} catch(PDOException $e) {
    die("Error: " . $e->getMessage() . "\n");
}
?>
