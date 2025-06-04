<?php
require_once "database.php";

// Handle deletion of selected codes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_codes'])) {
        if (isset($_POST['selected_codes']) && is_array($_POST['selected_codes'])) {
            $selected_codes = array_map('intval', $_POST['selected_codes']);
            $codes_string = implode(',', $selected_codes);
            
            // Delete the selected codes
            $delete_sql = "DELETE FROM voting_codes WHERE id IN ($codes_string)";
            if (mysqli_query($conn, $delete_sql)) {
                echo "<script>alert('Selected codes deleted successfully!');</script>";
            } else {
                echo "<script>alert('Error deleting codes: " . mysqli_error($conn) . "');</script>";
            }
        }
    }
    
    // Handle deletion of selected positions
    if (isset($_POST['delete_positions'])) {
        if (isset($_POST['selected_positions']) && is_array($_POST['selected_positions'])) {
            $selected_positions = array_map('intval', $_POST['selected_positions']);
            $positions_string = implode(',', $selected_positions);
            
            // Delete the selected positions
            $delete_sql = "DELETE FROM positions WHERE id IN ($positions_string)";
            if (mysqli_query($conn, $delete_sql)) {
                echo "<script>alert('Selected positions deleted successfully!');</script>";
            } else {
                echo "<script>alert('Error deleting positions: " . mysqli_error($conn) . "');</script>";
            }
        }
    }
    
    // Handle deletion of selected candidates
    if (isset($_POST['delete_candidates'])) {
        if (isset($_POST['selected_candidates']) && is_array($_POST['selected_candidates'])) {
            $selected_candidates = array_map('intval', $_POST['selected_candidates']);
            $candidates_string = implode(',', $selected_candidates);
            
            // Delete the selected candidates
            $delete_sql = "DELETE FROM candidates WHERE id IN ($candidates_string)";
            if (mysqli_query($conn, $delete_sql)) {
                echo "<script>alert('Selected candidates deleted successfully!');</script>";
            } else {
                echo "<script>alert('Error deleting candidates: " . mysqli_error($conn) . "');</script>";
            }
        }
    }
}

echo "<html><head><title>Database Check</title>";
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
    .delete-btn {
        background: #dc3545;
        color: white;
        border: none;
        padding: 5px 10px;
        border-radius: 3px;
        cursor: pointer;
        margin-left: 10px;
    }
    .select-all {
        margin-right: 5px;
    }
    .checkbox {
        margin-right: 5px;
    }
</style>";
echo "<script>
function toggleAll(source, className) {
    var checkboxes = document.getElementsByClassName(className);
    for(var i=0; i<checkboxes.length; i++) {
        checkboxes[i].checked = source.checked;
    }
}
</script>";
echo "</head><body>";

echo "<div class='header'><h2>Database Check</h2></div>";

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
    echo "<form method='POST'>";
    echo "<table>";
    echo "<tr>
            <th><input type='checkbox' class='select-all' onclick='toggleAll(this, \"code-checkbox\")'> Select All</th>
            <th>ID</th>
            <th>Code</th>
            <th>Election</th>
            <th>Status</th>
            <th>Created At</th>
          </tr>";
    while ($code = mysqli_fetch_assoc($codes_result)) {
        echo "<tr>";
        echo "<td><input type='checkbox' name='selected_codes[]' value='" . $code['id'] . "' class='code-checkbox'></td>";
        echo "<td>" . $code['id'] . "</td>";
        echo "<td>" . $code['code'] . "</td>";
        echo "<td>" . $code['election_title'] . "</td>";
        echo "<td>" . ($code['is_used'] ? 'Used' : 'Available') . "</td>";
        echo "<td>" . $code['created_at'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "<button type='submit' name='delete_codes' class='delete-btn' onclick='return confirm(\"Are you sure you want to delete the selected codes?\")'>Delete Selected</button>";
    echo "</form>";
} else {
    echo "<p>No voting codes found!</p>";
}
echo "</div>";

// Check positions
echo "<div class='section'>";
echo "<h3>Positions</h3>";
$positions_sql = "SELECT p.*, e.title as election_title 
                  FROM positions p 
                  JOIN elections e ON p.election_id = e.id 
                  ORDER BY p.id ASC";
$positions_result = mysqli_query($conn, $positions_sql);
if (mysqli_num_rows($positions_result) > 0) {
    echo "<form method='POST'>";
    echo "<table>";
    echo "<tr>
            <th><input type='checkbox' class='select-all' onclick='toggleAll(this, \"position-checkbox\")'> Select All</th>
            <th>ID</th>
            <th>Title</th>
            <th>Election</th>
          </tr>";
    while ($position = mysqli_fetch_assoc($positions_result)) {
        echo "<tr>";
        echo "<td><input type='checkbox' name='selected_positions[]' value='" . $position['id'] . "' class='position-checkbox'></td>";
        echo "<td>" . $position['id'] . "</td>";
        echo "<td>" . $position['title'] . "</td>";
        echo "<td>" . $position['election_title'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "<button type='submit' name='delete_positions' class='delete-btn' onclick='return confirm(\"Are you sure you want to delete the selected positions?\")'>Delete Selected Positions</button>";
    echo "</form>";
} else {
    echo "<p>No positions found!</p>";
}
echo "</div>";

// Check candidates
echo "<div class='section'>";
echo "<h3>Candidates</h3>";
$candidates_sql = "SELECT c.*, p.title as position_title, e.title as election_title 
                   FROM candidates c 
                   JOIN positions p ON c.position_id = p.id 
                   JOIN elections e ON p.election_id = e.id 
                   ORDER BY c.id";
$candidates_result = mysqli_query($conn, $candidates_sql);
if (mysqli_num_rows($candidates_result) > 0) {
    echo "<form method='POST'>";
    echo "<table>";
    echo "<tr>
            <th><input type='checkbox' class='select-all' onclick='toggleAll(this, \"candidate-checkbox\")'> Select All</th>
            <th>ID</th>
            <th>Name</th>
            <th>Position</th>
            <th>Election</th>
          </tr>";
    while ($candidate = mysqli_fetch_assoc($candidates_result)) {
        echo "<tr>";
        echo "<td><input type='checkbox' name='selected_candidates[]' value='" . $candidate['id'] . "' class='candidate-checkbox'></td>";
        echo "<td>" . $candidate['id'] . "</td>";
        echo "<td>" . $candidate['name'] . "</td>";
        echo "<td>" . $candidate['position_title'] . "</td>";
        echo "<td>" . $candidate['election_title'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "<button type='submit' name='delete_candidates' class='delete-btn' onclick='return confirm(\"Are you sure you want to delete the selected candidates?\")'>Delete Selected Candidates</button>";
    echo "</form>";
} else {
    echo "<p>No candidates found!</p>";
}
echo "</div>";

// Check votes
echo "<div class='section'>";
echo "<h3>Votes</h3>";
$votes_sql = "SELECT v.*, vc.code as voting_code, c.name as candidate_name, p.title as position_title, e.title as election_title 
              FROM votes v 
              JOIN voting_codes vc ON v.voting_code_id = vc.id 
              JOIN candidates c ON v.candidate_id = c.id 
              JOIN positions p ON v.position_id = p.id 
              JOIN elections e ON v.election_id = e.id 
              ORDER BY v.created_at DESC";
$votes_result = mysqli_query($conn, $votes_sql);
if (mysqli_num_rows($votes_result) > 0) {
    echo "<table>";
    echo "<tr><th>ID</th><th>Voting Code</th><th>Candidate</th><th>Position</th><th>Election</th><th>Created At</th></tr>";
    while ($vote = mysqli_fetch_assoc($votes_result)) {
        echo "<tr>";
        echo "<td>" . $vote['id'] . "</td>";
        echo "<td>" . $vote['voting_code'] . "</td>";
        echo "<td>" . $vote['candidate_name'] . "</td>";
        echo "<td>" . $vote['position_title'] . "</td>";
        echo "<td>" . $vote['election_title'] . "</td>";
        echo "<td>" . $vote['created_at'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No votes found!</p>";
}
echo "</div>";

// Fix database if needed
echo "<div class='section'>";
echo "<h3>Fix Database</h3>";
echo "<form method='POST'>";
echo "<input type='submit' name='fix' value='Reset Database and Create Test Data'>";
echo "</form>";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix'])) {
    // Drop existing tables
    $drop_tables = [
        "DROP TABLE IF EXISTS `votes`",
        "DROP TABLE IF EXISTS `voting_codes`",
        "DROP TABLE IF EXISTS `candidates`",
        "DROP TABLE IF EXISTS `positions`",
        "DROP TABLE IF EXISTS `elections`",
        "DROP TABLE IF EXISTS `admins`"
    ];

    foreach ($drop_tables as $sql) {
        mysqli_query($conn, $sql);
    }

    // Create tables
    $tables = [
        "CREATE TABLE admins (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        "CREATE TABLE elections (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(100) NOT NULL,
            description TEXT,
            start_date DATETIME NOT NULL,
            end_date DATETIME NOT NULL,
            status ENUM('pending', 'active', 'completed') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        "CREATE TABLE positions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            election_id INT,
            title VARCHAR(100) NOT NULL,
            description TEXT,
            FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE
        )",
        
        "CREATE TABLE candidates (
            id INT PRIMARY KEY AUTO_INCREMENT,
            position_id INT,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE
        )",
        
        "CREATE TABLE voting_codes (
            id INT PRIMARY KEY AUTO_INCREMENT,
            election_id INT,
            code VARCHAR(16) UNIQUE NOT NULL,
            is_used BOOLEAN DEFAULT FALSE,
            used_at DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE
        )",
        
        "CREATE TABLE votes (
            id INT PRIMARY KEY AUTO_INCREMENT,
            election_id INT,
            position_id INT,
            candidate_id INT,
            voting_code_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE,
            FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE,
            FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE,
            FOREIGN KEY (voting_code_id) REFERENCES voting_codes(id) ON DELETE CASCADE
        )"
    ];

    foreach ($tables as $sql) {
        mysqli_query($conn, $sql);
    }

    // Create default admin
    $default_admin = "INSERT INTO admins (username, password) VALUES ('admin', '" . password_hash('admin123', PASSWORD_DEFAULT) . "')";
    mysqli_query($conn, $default_admin);

    // Create test election
    $test_election = "INSERT INTO elections (title, description, start_date, end_date, status) 
                      VALUES ('Test Election', 'This is a test election', NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY), 'active')";
    mysqli_query($conn, $test_election);

    echo "<p style='color: green;'>Database has been reset and test data created!</p>";
}
echo "</div>";

echo "<div class='action-buttons'>";
echo "<a href='generate_codes.php'>Generate Voting Codes</a>";
echo "<a href='setup_positions.php'>Setup Positions</a>";
echo "</div>";

echo "</body></html>"; 