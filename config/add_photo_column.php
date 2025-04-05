<?php
require_once "database.php";

try {
    // Add photo column to candidates table
    $sql = "ALTER TABLE candidates ADD COLUMN photo VARCHAR(255) DEFAULT NULL AFTER description";
    $conn->exec($sql);
    echo "Successfully added photo column to candidates table.";
} catch (PDOException $e) {
    echo "Error adding column: " . $e->getMessage();
}
?> 