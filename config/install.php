<?php
require_once "database.php";

// Drop existing tables if they exist
$drop_tables = [
    "DROP TABLE IF EXISTS `votes`",
    "DROP TABLE IF EXISTS `voting_codes`",
    "DROP TABLE IF EXISTS `candidates`",
    "DROP TABLE IF EXISTS `positions`",
    "DROP TABLE IF EXISTS `elections`",
    "DROP TABLE IF EXISTS `admins`"
];

foreach ($drop_tables as $sql) {
    if (mysqli_query($conn, $sql)) {
        echo "Successfully dropped table\n";
    } else {
        echo "Error dropping table: " . mysqli_error($conn) . "\n";
    }
}

// Create tables
$tables = [
    "CREATE TABLE admins (
        id INT PRIMARY KEY AUTO_INCREMENT,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    
    "CREATE TABLE elections (
        id INT PRIMARY KEY AUTO_INCREMENT,
        title VARCHAR(100) NOT NULL,
        description TEXT,
        start_date DATETIME NOT NULL,
        end_date DATETIME NOT NULL,
        status ENUM('pending', 'active', 'completed') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    
    "CREATE TABLE positions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        election_id INT,
        title VARCHAR(100) NOT NULL,
        description TEXT,
        FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE
    )",
    
    "CREATE TABLE candidates (
        id INT PRIMARY KEY AUTO_INCREMENT,
        position_id INT,
        name VARCHAR(100) NOT NULL,
        description TEXT,
        FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE
    )",
    
    "CREATE TABLE voting_codes (
        id INT PRIMARY KEY AUTO_INCREMENT,
        election_id INT,
        code VARCHAR(16) UNIQUE NOT NULL,
        is_used BOOLEAN DEFAULT FALSE,
        used_at DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE
    )",
    
    "CREATE TABLE votes (
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
    if (mysqli_query($conn, $sql)) {
        echo "Successfully created table\n";
    } else {
        echo "Error creating table: " . mysqli_error($conn) . "\n";
    }
}

// Create default admin
$default_admin = "INSERT IGNORE INTO admins (username, password) VALUES ('admin', '" . password_hash('admin123', PASSWORD_DEFAULT) . "')";
mysqli_query($conn, $default_admin);

// Insert a test election
$test_election = "INSERT INTO `elections` (`title`, `description`, `start_date`, `end_date`, `status`) 
                  VALUES ('Test Election', 'This is a test election', NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY), 'active')";

if (mysqli_query($conn, $test_election)) {
    echo "Successfully created test election\n";
} else {
    echo "Error creating test election: " . mysqli_error($conn) . "\n";
}

echo "Database installation completed!\n"; 