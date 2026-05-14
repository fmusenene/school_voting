<?php
// Database configuration - using PDO
$host = 'localhost';
$dbname = 'school_voting';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check and add reset_token column
    $check_column = $pdo->query("SHOW COLUMNS FROM admins LIKE 'reset_token'");
    if ($check_column->rowCount() == 0) {
        $sql = "ALTER TABLE admins ADD COLUMN reset_token VARCHAR(255) NULL";
        $pdo->exec($sql);
        echo "reset_token column added successfully.\n";
    } else {
        echo "reset_token column already exists.\n";
    }

    // Check and add reset_token_expiry column
    $check_column = $pdo->query("SHOW COLUMNS FROM admins LIKE 'reset_token_expiry'");
    if ($check_column->rowCount() == 0) {
        $sql = "ALTER TABLE admins ADD COLUMN reset_token_expiry DATETIME NULL";
        $pdo->exec($sql);
        echo "reset_token_expiry column added successfully.\n";
    } else {
        echo "reset_token_expiry column already exists.\n";
    }

    // Check and add email column
    $check_column = $pdo->query("SHOW COLUMNS FROM admins LIKE 'email'");
    if ($check_column->rowCount() == 0) {
        $sql = "ALTER TABLE admins ADD COLUMN email VARCHAR(255) NULL";
        $pdo->exec($sql);
        echo "email column added successfully.\n";
    } else {
        echo "email column already exists.\n";
    }

    echo "\nPassword reset columns setup completed!\n";

} catch(PDOException $e) {
    die("Error: " . $e->getMessage() . "\n");
}
?>
