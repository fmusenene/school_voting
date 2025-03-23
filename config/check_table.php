<?php
require_once "database.php";

// Check candidates table structure
$sql = "DESCRIBE candidates";
$result = mysqli_query($conn, $sql);

echo "<h2>Candidates Table Structure:</h2>";
echo "<pre>";
while ($row = mysqli_fetch_assoc($result)) {
    print_r($row);
}
echo "</pre>";

mysqli_close($conn);
?> 