<?php
require_once "database.php";

// Function to generate a random code
function generateCode($length = 8) {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $code;
}

// Get the active election
$election_query = "SELECT id FROM elections WHERE status = 'active' LIMIT 1";
$election_result = mysqli_query($conn, $election_query);
$election = mysqli_fetch_assoc($election_result);

if (!$election) {
    die("No active election found. Please create an active election first.");
}

$election_id = $election['id'];

// Start HTML output
echo "<html><head><title>Generated Voting Codes</title>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .code { 
        background: #f0f0f0; 
        padding: 10px; 
        margin: 5px 0; 
        border-radius: 5px;
        font-family: monospace;
        font-size: 16px;
    }
    .header { 
        background: #4CAF50; 
        color: white; 
        padding: 10px; 
        border-radius: 5px;
        margin-bottom: 20px;
    }
</style></head><body>";
echo "<div class='header'><h2>Generated Voting Codes</h2></div>";

// Generate 10 test codes
echo "<h3>Your voting codes:</h3>";
for ($i = 0; $i < 10; $i++) {
    $code = generateCode();
    $sql = "INSERT INTO voting_codes (election_id, code) VALUES (?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "is", $election_id, $code);
    
    if (mysqli_stmt_execute($stmt)) {
        echo "<div class='code'>" . $code . "</div>";
    } else {
        echo "Error generating code: " . mysqli_error($conn) . "<br>";
    }
}

echo "<p><strong>Note:</strong> These codes can only be used once. Keep them safe!</p>";
echo "</body></html>"; 