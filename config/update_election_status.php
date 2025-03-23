<?php
require_once "database.php";

function updateElectionStatus($conn) {
    $current_date = date('Y-m-d');
    $current_time = date('H:i:s');
    
    // Update elections that should be active (start date reached)
    $activate_sql = "UPDATE elections 
                     SET status = 'active' 
                     WHERE status = 'pending' 
                     AND start_date <= CURRENT_DATE() 
                     AND end_date >= CURRENT_DATE()";
    mysqli_query($conn, $activate_sql);
    
    // Update elections that should be completed (end date passed)
    $complete_sql = "UPDATE elections 
                     SET status = 'completed' 
                     WHERE status = 'active' 
                     AND end_date < CURRENT_DATE()";
    mysqli_query($conn, $complete_sql);
}

// Call the function
updateElectionStatus($conn);

echo "<html><head><title>Update Election Status</title>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .section { 
        background: #f8f9fa; 
        padding: 20px; 
        margin-bottom: 20px; 
        border-radius: 5px;
    }
    .header { 
        background: #4CAF50; 
        color: white; 
        padding: 10px; 
        border-radius: 5px;
        margin-bottom: 20px;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }
    th, td {
        padding: 8px;
        border: 1px solid #dee2e6;
        text-align: left;
    }
    th {
        background-color: #e9ecef;
    }
    .action-buttons {
        margin-top: 20px;
        text-align: center;
    }
    .action-buttons a {
        display: inline-block;
        padding: 10px 20px;
        margin: 0 10px;
        background: #4CAF50;
        color: white;
        text-decoration: none;
        border-radius: 5px;
    }
</style></head><body>";

echo "<div class='header'><h2>Update Election Status</h2></div>";

// Display current elections
echo "<div class='section'>";
echo "<h3>Current Elections</h3>";
$elections_sql = "SELECT * FROM elections ORDER BY created_at DESC";
$elections_result = mysqli_query($conn, $elections_sql);
if (mysqli_num_rows($elections_result) > 0) {
    echo "<table>";
    echo "<tr><th>ID</th><th>Title</th><th>Status</th><th>Start Date</th><th>End Date</th><th>Action</th></tr>";
    while ($election = mysqli_fetch_assoc($elections_result)) {
        echo "<tr>";
        echo "<td>" . $election['id'] . "</td>";
        echo "<td>" . $election['title'] . "</td>";
        echo "<td>" . $election['status'] . "</td>";
        echo "<td>" . $election['start_date'] . "</td>";
        echo "<td>" . $election['end_date'] . "</td>";
        echo "<td>";
        if ($election['status'] !== 'active') {
            echo "<form method='POST' style='display:inline;'>";
            echo "<input type='hidden' name='election_id' value='" . $election['id'] . "'>";
            echo "<input type='submit' name='activate' value='Activate' style='background: #4CAF50; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer;'>";
            echo "</form>";
        }
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No elections found!</p>";
}
echo "</div>";

// Handle activation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate']) && isset($_POST['election_id'])) {
    $election_id = mysqli_real_escape_string($conn, $_POST['election_id']);
    
    // First, set all elections to pending
    $reset_sql = "UPDATE elections SET status = 'pending'";
    mysqli_query($conn, $reset_sql);
    
    // Then activate the selected election
    $activate_sql = "UPDATE elections SET status = 'active' WHERE id = '$election_id'";
    if (mysqli_query($conn, $activate_sql)) {
        echo "<div class='section' style='background: #d4edda; color: #155724;'>";
        echo "<p>Election has been activated successfully!</p>";
        echo "</div>";
    } else {
        echo "<div class='section' style='background: #f8d7da; color: #721c24;'>";
        echo "<p>Error activating election: " . mysqli_error($conn) . "</p>";
        echo "</div>";
    }
}

echo "<div class='action-buttons'>";
echo "<a href='check_voting_codes.php'>Check Voting Codes</a>";
echo "<a href='generate_codes.php'>Generate Voting Codes</a>";
echo "</div>";

echo "</body></html>"; 