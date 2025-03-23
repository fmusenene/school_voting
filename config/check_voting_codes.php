<?php
require_once "database.php";

echo "<html><head><title>Voting Codes Status</title>";
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

echo "<div class='header'><h2>Voting Codes Status</h2></div>";

// Check Active Election
echo "<div class='section'>";
echo "<h3>Active Election</h3>";
$active_election_sql = "SELECT * FROM elections WHERE status = 'active' AND start_date <= NOW() AND end_date >= NOW()";
$active_election_result = mysqli_query($conn, $active_election_sql);
if (mysqli_num_rows($active_election_result) > 0) {
    $election = mysqli_fetch_assoc($active_election_result);
    echo "<p>Active Election: " . $election['title'] . "</p>";
    echo "<p>Start Date: " . $election['start_date'] . "</p>";
    echo "<p>End Date: " . $election['end_date'] . "</p>";
} else {
    echo "<p>No active election found!</p>";
}
echo "</div>";

// Check All Voting Codes for Active Election
echo "<div class='section'>";
echo "<h3>Voting Codes for Active Election</h3>";
$codes_sql = "SELECT vc.*, e.title as election_title 
              FROM voting_codes vc 
              JOIN elections e ON vc.election_id = e.id 
              WHERE e.status = 'active' 
              AND e.start_date <= NOW() 
              AND e.end_date >= NOW()
              ORDER BY vc.created_at DESC";
$codes_result = mysqli_query($conn, $codes_sql);
if (mysqli_num_rows($codes_result) > 0) {
    echo "<table>";
    echo "<tr><th>Code</th><th>Election</th><th>Status</th><th>Created At</th></tr>";
    while ($code = mysqli_fetch_assoc($codes_result)) {
        echo "<tr>";
        echo "<td>" . $code['code'] . "</td>";
        echo "<td>" . $code['election_title'] . "</td>";
        echo "<td>" . ($code['is_used'] ? 'Used' : 'Available') . "</td>";
        echo "<td>" . $code['created_at'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No voting codes found for active election!</p>";
}
echo "</div>";

// Check Specific Code
echo "<div class='section'>";
echo "<h3>Check Specific Code</h3>";
echo "<form method='POST'>";
echo "<input type='text' name='code' placeholder='Enter voting code' required>";
echo "<input type='submit' value='Check Code'>";
echo "</form>";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code'])) {
    $code = mysqli_real_escape_string($conn, $_POST['code']);
    $check_sql = "SELECT vc.*, e.title as election_title, e.status as election_status, e.start_date, e.end_date 
                  FROM voting_codes vc 
                  JOIN elections e ON vc.election_id = e.id 
                  WHERE vc.code = '$code'";
    $check_result = mysqli_query($conn, $check_sql);
    
    if (mysqli_num_rows($check_result) > 0) {
        $code_info = mysqli_fetch_assoc($check_result);
        echo "<p>Code found!</p>";
        echo "<p>Election: " . $code_info['election_title'] . "</p>";
        echo "<p>Status: " . ($code_info['is_used'] ? 'Used' : 'Available') . "</p>";
        echo "<p>Election Status: " . $code_info['election_status'] . "</p>";
        echo "<p>Start Date: " . $code_info['start_date'] . "</p>";
        echo "<p>End Date: " . $code_info['end_date'] . "</p>";
    } else {
        echo "<p>Code not found in database!</p>";
    }
}
echo "</div>";

echo "<div class='action-buttons'>";
echo "<a href='generate_codes.php'>Generate New Voting Codes</a>";
echo "<a href='setup_positions.php'>Setup Positions</a>";
echo "</div>";

echo "</body></html>"; 