<?php
require_once "database.php";

// Get the active election
$election_query = "SELECT id FROM elections WHERE status = 'active' LIMIT 1";
$election_result = mysqli_query($conn, $election_query);
$election = mysqli_fetch_assoc($election_result);

if (!$election) {
    die("No active election found. Please create an active election first.");
}

$election_id = $election['id'];

// Define positions and their candidates
$positions = [
    [
        'title' => 'Student Body President',
        'candidates' => [
            'John Smith',
            'Sarah Johnson',
            'Michael Brown'
        ]
    ],
    [
        'title' => 'Vice President',
        'candidates' => [
            'Emily Davis',
            'David Wilson',
            'Lisa Anderson'
        ]
    ],
    [
        'title' => 'Secretary',
        'candidates' => [
            'Robert Taylor',
            'Jennifer White',
            'Thomas Lee'
        ]
    ]
];

// Start HTML output
echo "<html><head><title>Setup Positions and Candidates</title>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .success { color: green; }
    .error { color: red; }
    .header { 
        background: #4CAF50; 
        color: white; 
        padding: 10px; 
        border-radius: 5px;
        margin-bottom: 20px;
    }
</style></head><body>";
echo "<div class='header'><h2>Setting up Positions and Candidates</h2></div>";

// Insert positions and candidates
foreach ($positions as $position_data) {
    // Insert position
    $position_sql = "INSERT INTO positions (election_id, title) VALUES (?, ?)";
    $position_stmt = mysqli_prepare($conn, $position_sql);
    mysqli_stmt_bind_param($position_stmt, "is", $election_id, $position_data['title']);
    
    if (mysqli_stmt_execute($position_stmt)) {
        $position_id = mysqli_insert_id($conn);
        echo "<p class='success'>Created position: " . $position_data['title'] . "</p>";
        
        // Insert candidates for this position
        foreach ($position_data['candidates'] as $candidate_name) {
            $candidate_sql = "INSERT INTO candidates (position_id, name) VALUES (?, ?)";
            $candidate_stmt = mysqli_prepare($conn, $candidate_sql);
            mysqli_stmt_bind_param($candidate_stmt, "is", $position_id, $candidate_name);
            
            if (mysqli_stmt_execute($candidate_stmt)) {
                echo "<p class='success'>Added candidate: " . $candidate_name . " for " . $position_data['title'] . "</p>";
            } else {
                echo "<p class='error'>Error adding candidate: " . mysqli_error($conn) . "</p>";
            }
        }
    } else {
        echo "<p class='error'>Error creating position: " . mysqli_error($conn) . "</p>";
    }
}

echo "<p><strong>Setup completed!</strong> You can now go to <a href='../index.php'>the voting page</a> to vote.</p>";
echo "</body></html>"; 