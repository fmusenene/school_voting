<?php
require_once "../config/database.php";

try {
    $sql = "ALTER TABLE positions ADD COLUMN max_winners INT DEFAULT 1 AFTER description";
    $conn->exec($sql);
    echo "Successfully added max_winners column";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?> 