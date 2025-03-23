<?php
require_once "database.php";

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone
date_default_timezone_set('Asia/Manila');

echo "<h2>Database Connection Test</h2>";

// Test database connection
if ($conn) {
    echo "Database connection successful!<br>";
} else {
    echo "Database connection failed: " . mysqli_connect_error() . "<br>";
}

// Test elections table
echo "<h3>Elections Table</h3>";
$elections_sql = "SHOW CREATE TABLE elections";
$elections_result = mysqli_query($conn, $elections_sql);
if ($elections_result) {
    $row = mysqli_fetch_assoc($elections_result);
    echo "Table structure:<br>";
    echo "<pre>" . $row['Create Table'] . "</pre>";
    
    // Check if there are any elections
    $check_sql = "SELECT * FROM elections";
    $check_result = mysqli_query($conn, $check_sql);
    echo "Number of elections: " . mysqli_num_rows($check_result) . "<br>";
    
    if (mysqli_num_rows($check_result) > 0) {
        echo "<h4>Current Elections:</h4>";
        while ($election = mysqli_fetch_assoc($check_result)) {
            echo "ID: " . $election['id'] . "<br>";
            echo "Title: " . $election['title'] . "<br>";
            echo "Status: " . $election['status'] . "<br>";
            echo "Start Date: " . $election['start_date'] . "<br>";
            echo "End Date: " . $election['end_date'] . "<br>";
            echo "<hr>";
        }
    }
} else {
    echo "Error getting table structure: " . mysqli_error($conn) . "<br>";
}

// Test voting_codes table
echo "<h3>Voting Codes Table</h3>";
$codes_sql = "SHOW CREATE TABLE voting_codes";
$codes_result = mysqli_query($conn, $codes_sql);
if ($codes_result) {
    $row = mysqli_fetch_assoc($codes_result);
    echo "Table structure:<br>";
    echo "<pre>" . $row['Create Table'] . "</pre>";
    
    // Check if there are any voting codes
    $check_sql = "SELECT * FROM voting_codes";
    $check_result = mysqli_query($conn, $check_sql);
    echo "Number of voting codes: " . mysqli_num_rows($check_result) . "<br>";
    
    if (mysqli_num_rows($check_result) > 0) {
        echo "<h4>Sample Voting Codes:</h4>";
        $count = 0;
        while ($code = mysqli_fetch_assoc($check_result)) {
            if ($count >= 5) break;
            echo "ID: " . $code['id'] . "<br>";
            echo "Code: " . $code['code'] . "<br>";
            echo "Election ID: " . $code['election_id'] . "<br>";
            echo "Is Used: " . $code['is_used'] . "<br>";
            echo "Used At: " . $code['used_at'] . "<br>";
            echo "<hr>";
            $count++;
        }
    }
} else {
    echo "Error getting table structure: " . mysqli_error($conn) . "<br>";
}

// Test timezone
echo "<h3>Timezone Information</h3>";
echo "Current timezone: " . date_default_timezone_get() . "<br>";
echo "Current date and time: " . date('Y-m-d H:i:s') . "<br>";

// Update election status based on current date
$update_sql = "UPDATE elections SET status = 'active' 
               WHERE status = 'pending' 
               AND start_date <= NOW() 
               AND end_date >= NOW()";
mysqli_query($conn, $update_sql);

$update_sql = "UPDATE elections SET status = 'completed' 
               WHERE status = 'active' 
               AND end_date < NOW()";
mysqli_query($conn, $update_sql);
?> 