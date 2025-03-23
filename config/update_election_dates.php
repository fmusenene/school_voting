<?php
require_once "database.php";

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone
date_default_timezone_set('Asia/Manila');

// Get current date and time
$current_date = date('Y-m-d');
$start_date = $current_date . ' 00:00:00';
$end_date = date('Y-m-d', strtotime('+1 day')) . ' 00:00:00';

// Update election dates
$update_sql = "UPDATE elections 
               SET start_date = '$start_date',
                   end_date = '$end_date',
                   status = 'active'
               WHERE id = 3";

if (mysqli_query($conn, $update_sql)) {
    echo "Election dates updated successfully!<br>";
    echo "Start date: " . $start_date . "<br>";
    echo "End date: " . $end_date . "<br>";
} else {
    echo "Error updating election dates: " . mysqli_error($conn);
}

// Display current election status
$check_sql = "SELECT * FROM elections WHERE id = 3";
$result = mysqli_query($conn, $check_sql);
if ($election = mysqli_fetch_assoc($result)) {
    echo "<h3>Current Election Status:</h3>";
    echo "ID: " . $election['id'] . "<br>";
    echo "Title: " . $election['title'] . "<br>";
    echo "Status: " . $election['status'] . "<br>";
    echo "Start Date: " . $election['start_date'] . "<br>";
    echo "End Date: " . $election['end_date'] . "<br>";
}
?> 