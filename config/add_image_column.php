<?php
require_once "database.php";

// Add image_path column to candidates table
$sql = "ALTER TABLE candidates ADD COLUMN image_path VARCHAR(255) DEFAULT NULL AFTER description";

if (mysqli_query($conn, $sql)) {
    echo "Successfully added image_path column to candidates table.";
} else {
    echo "Error adding column: " . mysqli_error($conn);
}

mysqli_close($conn);
?> 