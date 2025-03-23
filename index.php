<?php
session_start();
require_once "config/database.php";

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone
date_default_timezone_set('Asia/Manila');

$error = '';
$debug = ''; // For debugging information

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $voting_code = mysqli_real_escape_string($conn, $_POST['voting_code']);
    
    // Check if code exists and is valid
    $check_sql = "SELECT vc.*, e.id as election_id, e.title as election_title, e.status as election_status,
                  e.start_date, e.end_date 
                  FROM voting_codes vc 
                  JOIN elections e ON vc.election_id = e.id 
                  WHERE vc.code = '$voting_code'";
    $check_result = mysqli_query($conn, $check_sql);
    
    if (mysqli_num_rows($check_result) > 0) {
        $code_data = mysqli_fetch_assoc($check_result);
        
        if ($code_data['is_used']) {
            $error = "This voting code has already been used.";
        } else {
            // Get current date and time
            $current_datetime = new DateTime();
            $start_datetime = new DateTime($code_data['start_date']);
            $end_datetime = new DateTime($code_data['end_date']);
            
            // Check election status and dates
            if ($code_data['election_status'] !== 'active') {
                $error = "This election is not currently active.";
            } else if ($current_datetime < $start_datetime) {
                $error = "This election has not started yet.";
            } else if ($current_datetime > $end_datetime) {
                // Update election status to completed if end date has passed
                $update_sql = "UPDATE elections SET status = 'completed' WHERE id = " . $code_data['election_id'];
                mysqli_query($conn, $update_sql);
                $error = "This election has ended.";
            } else {
                // Set session variables
                $_SESSION['voting_code_id'] = $code_data['id'];
                $_SESSION['election_id'] = $code_data['election_id'];
                $_SESSION['election_title'] = $code_data['election_title'];
                
                // Debug information
                $debug .= "Setting session variables:<br>";
                $debug .= "voting_code_id: " . $code_data['id'] . "<br>";
                $debug .= "election_id: " . $code_data['election_id'] . "<br>";
                $debug .= "election_title: " . $code_data['election_title'] . "<br>";
                
                // Redirect to voting page
                header("location: vote.php");
                exit();
            }
        }
    } else {
        $error = "This voting code does not exist.";
    }
}

// Display error message if redirected with error parameter
if (isset($_GET['error'])) {
    if ($_GET['error'] === 'expired') {
        $error = "Your voting session has expired. Please enter your voting code again.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Voting System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .login-container {
            max-width: 400px;
            margin: 100px auto;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .school-logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .school-logo i {
            font-size: 48px;
            color: #343a40;
        }
        .debug-info {
            margin-top: 20px;
            padding: 10px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <div class="school-logo">
                <i class="bi bi-building"></i>
            </div>
            <h2 class="text-center mb-4">School Voting System</h2>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form method="post" action="">
                <div class="mb-3">
                    <label for="voting_code" class="form-label">Enter Your Voting Code</label>
                    <input type="text" class="form-control" id="voting_code" name="voting_code" required>
                </div>
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">Submit</button>
                </div>
            </form>
            
            <div class="text-center mt-3">
                <a href="admin/login.php" class="text-decoration-none">Admin Login</a>
            </div>
        </div>
    </div>

    <?php if ($debug): ?>
        <div class="debug-info">
            <h5>Debug Information:</h5>
            <?php echo $debug; ?>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 