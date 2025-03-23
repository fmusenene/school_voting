<?php
require_once "database.php";

echo "<html><head><title>Database Status</title>";
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
</style></head><body>";

echo "<div class='header'><h2>Database Status Check</h2></div>";

// Check Elections
echo "<div class='section'>";
echo "<h3>Active Elections</h3>";
$elections_sql = "SELECT * FROM elections WHERE status = 'active'";
$elections_result = mysqli_query($conn, $elections_sql);
if (mysqli_num_rows($elections_result) > 0) {
    echo "<table>";
    echo "<tr><th>ID</th><th>Title</th><th>Status</th><th>Start Date</th><th>End Date</th></tr>";
    while ($election = mysqli_fetch_assoc($elections_result)) {
        echo "<tr>";
        echo "<td>" . $election['id'] . "</td>";
        echo "<td>" . $election['title'] . "</td>";
        echo "<td>" . $election['status'] . "</td>";
        echo "<td>" . $election['start_date'] . "</td>";
        echo "<td>" . $election['end_date'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No active elections found!</p>";
}
echo "</div>";

// Check Voting Codes
echo "<div class='section'>";
echo "<h3>Available Voting Codes</h3>";
$codes_sql = "SELECT vc.*, e.title as election_title 
              FROM voting_codes vc 
              JOIN elections e ON vc.election_id = e.id 
              WHERE vc.is_used = 0 AND e.status = 'active'";
$codes_result = mysqli_query($conn, $codes_sql);
if (mysqli_num_rows($codes_result) > 0) {
    echo "<table>";
    echo "<tr><th>Code</th><th>Election</th><th>Status</th></tr>";
    while ($code = mysqli_fetch_assoc($codes_result)) {
        echo "<tr>";
        echo "<td>" . $code['code'] . "</td>";
        echo "<td>" . $code['election_title'] . "</td>";
        echo "<td>" . ($code['is_used'] ? 'Used' : 'Available') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No available voting codes found!</p>";
}
echo "</div>";

// Check Positions
echo "<div class='section'>";
echo "<h3>Positions</h3>";
$positions_sql = "SELECT p.*, e.title as election_title 
                  FROM positions p 
                  JOIN elections e ON p.election_id = e.id 
                  WHERE e.status = 'active'";
$positions_result = mysqli_query($conn, $positions_sql);
if (mysqli_num_rows($positions_result) > 0) {
    echo "<table>";
    echo "<tr><th>ID</th><th>Title</th><th>Election</th></tr>";
    while ($position = mysqli_fetch_assoc($positions_result)) {
        echo "<tr>";
        echo "<td>" . $position['id'] . "</td>";
        echo "<td>" . $position['title'] . "</td>";
        echo "<td>" . $position['election_title'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No positions found!</p>";
}
echo "</div>";

echo "</body></html>"; 