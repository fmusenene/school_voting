<?php
require_once 'database.php';

try {
    // Create database if it doesn't exist
    $pdo = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME;
    $pdo->exec($sql);
    
    // Switch to the created database
    $pdo->exec("USE " . DB_NAME);
    
    // Create admins table
    $sql = "CREATE TABLE IF NOT EXISTS admins (
        id INT PRIMARY KEY AUTO_INCREMENT,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $pdo->exec($sql);
    
    // Create elections table
    $sql = "CREATE TABLE IF NOT EXISTS elections (
        id INT PRIMARY KEY AUTO_INCREMENT,
        title VARCHAR(100) NOT NULL,
        description TEXT,
        start_date DATETIME NOT NULL,
        end_date DATETIME NOT NULL,
        status ENUM('pending', 'active', 'completed') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $pdo->exec($sql);
    
    // Create positions table
    $sql = "CREATE TABLE IF NOT EXISTS positions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        election_id INT NOT NULL,
        title VARCHAR(100) NOT NULL,
        description TEXT,
        max_votes INT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE
    )";
    $pdo->exec($sql);
    
    // Create candidates table
    $sql = "CREATE TABLE IF NOT EXISTS candidates (
        id INT PRIMARY KEY AUTO_INCREMENT,
        position_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        photo VARCHAR(255),
        bio TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE
    )";
    $pdo->exec($sql);
    
    // Create voting_codes table
    $sql = "CREATE TABLE IF NOT EXISTS voting_codes (
        id INT PRIMARY KEY AUTO_INCREMENT,
        code VARCHAR(10) NOT NULL UNIQUE,
        election_id INT NOT NULL,
        is_used BOOLEAN DEFAULT FALSE,
        used_at DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE
    )";
    $pdo->exec($sql);
    
    // Create votes table
    $sql = "CREATE TABLE IF NOT EXISTS votes (
        id INT PRIMARY KEY AUTO_INCREMENT,
        candidate_id INT NOT NULL,
        voting_code_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE,
        FOREIGN KEY (voting_code_id) REFERENCES voting_codes(id) ON DELETE CASCADE
    )";
    $pdo->exec($sql);
    
    // Insert default admin user if not exists
    $sql = "INSERT IGNORE INTO admins (username, password) VALUES (?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT)]);
    
    echo "Database setup completed successfully!\n";
    echo "Default admin credentials:\n";
    echo "Username: admin\n";
    echo "Password: admin123\n";
    
} catch(PDOException $e) {
    die("Setup failed: " . $e->getMessage() . "\n");
}
?> 