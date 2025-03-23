<?php
require_once "database.php";

// Read and execute the SQL file
$sql = file_get_contents(__DIR__ . '/database.sql');

// Split the SQL into individual statements
$statements = array_filter(array_map('trim', explode(';', $sql)));

// Execute each statement
foreach ($statements as $statement) {
    if (!empty($statement)) {
        if (mysqli_query($conn, $statement)) {
            echo "Successfully executed: " . substr($statement, 0, 50) . "...\n";
        } else {
            echo "Error executing statement: " . mysqli_error($conn) . "\n";
        }
    }
}

echo "Database setup completed!\n"; 