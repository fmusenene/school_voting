<?php
require_once "database.php";

echo "<html><head><title>Fix Voting Codes</title>";
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

echo "<div class='header'><h2>Fix Voting Codes</h2></div>";

// Check active election
echo "<div class='section'>";
echo "<h3>Active Election</h3>";
$election_sql = "SELECT * FROM elections WHERE status = 'active' AND start_date <= NOW() AND end_date >= NOW()";
$election_result = mysqli_query($conn, $election_sql);
if (mysqli_num_rows($election_result) > 0) {
    $election = mysqli_fetch_assoc($election_result);
    echo "<p>Active Election: " . $election['title'] . "</p>";
    echo "<p>Start Date: " . $election['start_date'] . "</p>";
    echo "<p>End Date: " . $election['end_date'] . "</p>";
} else {
    echo "<p>No active election found!</p>";
}
echo "</div>";

// Check voting codes
echo "<div class='section'>";
echo "<h3>Voting Codes</h3>";
$codes_sql = "SELECT vc.*, e.title as election_title 
              FROM voting_codes vc 
              JOIN elections e ON vc.election_id = e.id 
              ORDER BY vc.created_at DESC";
$codes_result = mysqli_query($conn, $codes_sql);
if (mysqli_num_rows($codes_result) > 0) {
    echo "<table>";
    echo "<tr><th>ID</th><th>Code</th><th>Election</th><th>Status</th><th>Created At</th></tr>";
    while ($code = mysqli_fetch_assoc($codes_result)) {
        echo "<tr>";
        echo "<td>" . $code['id'] . "</td>";
        echo "<td>" . $code['code'] . "</td>";
        echo "<td>" . $code['election_title'] . "</td>";
        echo "<td>" . ($code['is_used'] ? 'Used' : 'Available') . "</td>";
        echo "<td>" . $code['created_at'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No voting codes found!</p>";
}
echo "</div>";

// Generate new codes if needed
echo "<div class='section'>";
echo "<h3>Generate New Codes</h3>";
echo "<form method='POST'>";
echo "<input type='number' name='num_codes' value='10' min='1' max='100' required>";
echo "<input type='submit' name='generate' value='Generate Codes'>";
echo "</form>";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    $num_codes = intval($_POST['num_codes']);
    $election_id = $election['id'];
    
    // Generate codes
    for ($i = 0; $i < $num_codes; $i++) {
        $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
        $sql = "INSERT INTO voting_codes (election_id, code, is_used) VALUES (?, ?, 0)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "is", $election_id, $code);
        mysqli_stmt_execute($stmt);
    }
    
    echo "<p style='color: green;'>Successfully generated $num_codes new voting codes!</p>";
}
echo "</div>";

// Reset used codes if needed
echo "<div class='section'>";
echo "<h3>Reset Used Codes</h3>";
echo "<form method='POST'>";
echo "<input type='submit' name='reset' value='Reset All Used Codes'>";
echo "</form>";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset'])) {
    $reset_sql = "UPDATE voting_codes SET is_used = 0, used_at = NULL";
    if (mysqli_query($conn, $reset_sql)) {
        echo "<p style='color: green;'>Successfully reset all used codes!</p>";
    } else {
        echo "<p style='color: red;'>Error resetting codes: " . mysqli_error($conn) . "</p>";
    }
}
echo "</div>";

echo "<div class='action-buttons'>";
echo "<a href='check_voting_codes.php'>Check Voting Codes</a>";
echo "<a href='setup_positions.php'>Setup Positions</a>";
echo "</div>";

echo "</body></html>"; 