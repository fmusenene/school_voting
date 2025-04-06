<?php
require_once "../config/database.php";

$sql = "ALTER TABLE positions ADD COLUMN max_winners INT DEFAULT 1 AFTER description";
if (mysqli_query($conn, $sql)) {
    echo "Successfully added max_winners column";
} else {
    echo "Error: " . mysqli_error($conn);
}
?>
