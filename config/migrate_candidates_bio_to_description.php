<?php
/**
 * One-time migration: candidates.bio -> candidates.description
 * Fixes admin pages that expect "description" when the DB was created via setup_database.php (bio).
 */
require_once __DIR__ . '/database.php';

if (!$conn || $conn->connect_error) {
    fwrite(STDERR, "Database connection failed.\n");
    exit(1);
}

$cols = [];
$res = mysqli_query($conn, "SHOW COLUMNS FROM candidates");
if (!$res) {
    fwrite(STDERR, 'Could not read candidates columns: ' . mysqli_error($conn) . "\n");
    exit(1);
}
while ($row = mysqli_fetch_assoc($res)) {
    $cols[$row['Field']] = true;
}
mysqli_free_result($res);

if (isset($cols['description'])) {
    echo "Column 'description' already exists — no rename needed.\n";
    exit(0);
}

if (!isset($cols['bio'])) {
    fwrite(STDERR, "Neither 'bio' nor 'description' found on candidates — check your schema.\n");
    exit(1);
}

$sql = "ALTER TABLE candidates CHANGE bio description TEXT NULL";
if (!mysqli_query($conn, $sql)) {
    fwrite(STDERR, 'Migration failed: ' . mysqli_error($conn) . "\n");
    exit(1);
}

echo "Renamed candidates.bio to candidates.description successfully.\n";
