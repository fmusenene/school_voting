<?php
// Database configuration without database name
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    // First connect without database to create it
    $pdo = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create database if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS school_voting");
    echo "Database created successfully\n";
    
    // Connect to the new database
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=school_voting", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create tables
    $tables = [
        "CREATE TABLE IF NOT EXISTS admins (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        "CREATE TABLE IF NOT EXISTS elections (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(100) NOT NULL,
            description TEXT,
            start_date DATETIME NOT NULL,
            end_date DATETIME NOT NULL,
            status ENUM('pending', 'active', 'completed') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        "CREATE TABLE IF NOT EXISTS positions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            election_id INT,
            title VARCHAR(100) NOT NULL,
            description TEXT,
            FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE
        )",
        
        "CREATE TABLE IF NOT EXISTS candidates (
            id INT PRIMARY KEY AUTO_INCREMENT,
            position_id INT,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE
        )",
        
        "CREATE TABLE IF NOT EXISTS voting_codes (
            id INT PRIMARY KEY AUTO_INCREMENT,
            election_id INT,
            code VARCHAR(16) UNIQUE NOT NULL,
            is_used BOOLEAN DEFAULT FALSE,
            used_at DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE
        )",
        
        "CREATE TABLE IF NOT EXISTS votes (
            id INT PRIMARY KEY AUTO_INCREMENT,
            election_id INT,
            position_id INT,
            candidate_id INT,
            voting_code_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE,
            FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE,
            FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE,
            FOREIGN KEY (voting_code_id) REFERENCES voting_codes(id) ON DELETE CASCADE
        )"
    ];
    
    foreach ($tables as $sql) {
        $pdo->exec($sql);
        echo "Table created successfully\n";
    }
    
    // Create default admin
    $stmt = $pdo->prepare("INSERT IGNORE INTO admins (username, password) VALUES (?, ?)");
    $stmt->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT)]);
    
    // Insert a test election
    $stmt = $pdo->prepare("INSERT INTO elections (title, description, start_date, end_date, status) 
                          VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY), ?)");
    $stmt->execute(['Test Election', 'This is a test election', 'active']);
    
    echo "Database setup completed successfully!\n";
    
} catch(PDOException $e) {
    die("Error: " . $e->getMessage() . "\n");
}
?> 