<?php
require_once "database.php";

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Updating Candidates Table</h2>";

// First, check if the column exists
$check_sql = "SHOW COLUMNS FROM candidates LIKE 'image_path'";
$result = mysqli_query($conn, $check_sql);

if (mysqli_num_rows($result) == 0) {
    // Column doesn't exist, add it
    $sql = "ALTER TABLE candidates ADD COLUMN image_path VARCHAR(255) DEFAULT NULL AFTER description";
    
    if (mysqli_query($conn, $sql)) {
        echo "<p style='color: green;'>Successfully added image_path column to candidates table.</p>";
    } else {
        echo "<p style='color: red;'>Error adding column: " . mysqli_error($conn) . "</p>";
    }
} else {
    echo "<p style='color: blue;'>image_path column already exists in the candidates table.</p>";
}

// Show current table structure
echo "<h3>Current Table Structure:</h3>";
$structure_sql = "DESCRIBE candidates";
$structure_result = mysqli_query($conn, $structure_sql);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
while ($row = mysqli_fetch_assoc($structure_result)) {
    echo "<tr>";
    echo "<td>" . $row['Field'] . "</td>";
    echo "<td>" . $row['Type'] . "</td>";
    echo "<td>" . $row['Null'] . "</td>";
    echo "<td>" . $row['Key'] . "</td>";
    echo "<td>" . $row['Default'] . "</td>";
    echo "<td>" . $row['Extra'] . "</td>";
    echo "</tr>";
}
echo "</table>";

mysqli_close($conn);
?> 