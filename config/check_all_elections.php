<?php
require_once "database.php";

echo "<html><head><title>All Elections Status</title>";
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

echo "<div class='header'><h2>All Elections Status</h2></div>";

// Check All Elections
echo "<div class='section'>";
echo "<h3>All Elections</h3>";
$elections_sql = "SELECT * FROM elections ORDER BY created_at DESC";
$elections_result = mysqli_query($conn, $elections_sql);
if (mysqli_num_rows($elections_result) > 0) {
    echo "<table>";
    echo "<tr><th>ID</th><th>Title</th><th>Status</th><th>Start Date</th><th>End Date</th><th>Created At</th></tr>";
    while ($election = mysqli_fetch_assoc($elections_result)) {
        echo "<tr>";
        echo "<td>" . $election['id'] . "</td>";
        echo "<td>" . $election['title'] . "</td>";
        echo "<td>" . $election['status'] . "</td>";
        echo "<td>" . $election['start_date'] . "</td>";
        echo "<td>" . $election['end_date'] . "</td>";
        echo "<td>" . $election['created_at'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No elections found in the database!</p>";
}
echo "</div>";

// Check All Voting Codes
echo "<div class='section'>";
echo "<h3>All Voting Codes</h3>";
$codes_sql = "SELECT vc.*, e.title as election_title 
              FROM voting_codes vc 
              JOIN elections e ON vc.election_id = e.id 
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
    echo "<p>No voting codes found in the database!</p>";
}
echo "</div>";

// Check All Positions
echo "<div class='section'>";
echo "<h3>All Positions</h3>";
$positions_sql = "SELECT p.*, e.title as election_title 
                  FROM positions p 
                  JOIN elections e ON p.election_id = e.id 
                  ORDER BY p.id";
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
    echo "<p>No positions found in the database!</p>";
}
echo "</div>";

echo "<div class='action-buttons'>";
echo "<a href='install.php'>Create New Election</a>";
echo "<a href='generate_codes.php'>Generate Voting Codes</a>";
echo "<a href='setup_positions.php'>Setup Positions</a>";
echo "</div>";

echo "</body></html>"; 