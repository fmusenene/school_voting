<?php
session_start();
require_once "config/database.php";

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone
date_default_timezone_set('Asia/Manila');

// Check if user is already logged in
if (isset($_SESSION['voting_code'])) {
    header("Location: vote.php");
    exit();
}

$error = '';
$debug = ''; // For debugging information

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $voting_code = trim($_POST['voting_code']);
    
    if (!empty($voting_code)) {
        // Check if code exists and is unused
        $stmt = $conn->prepare("SELECT * FROM voting_codes WHERE code = ? AND is_used = 0");
        $stmt->execute([$voting_code]);
        $code = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($code) {
            // Check if election is active
            $stmt = $conn->prepare("SELECT * FROM elections WHERE id = ? AND status = 'active'");
            $stmt->execute([$code['election_id']]);
            $election = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($election) {
                $_SESSION['voting_code'] = $voting_code;
                $_SESSION['election_id'] = $election['id'];
                $_SESSION['election_title'] = $election['title'];
                
                // Debug information
                $debug .= "Setting session variables:<br>";
                $debug .= "voting_code: " . $voting_code . "<br>";
                $debug .= "election_id: " . $election['id'] . "<br>";
                $debug .= "election_title: " . $election['title'] . "<br>";
                
                // Redirect to voting page
                header("Location: vote.php");
                exit();
            } else {
                $error = "This voting code is not valid for any active election.";
            }
        } else {
            $error = "Invalid or already used voting code.";
        }
    } else {
        $error = "Please enter a voting code.";
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            background: #f8f9fa url('assets/images/background-logos.png') center/cover no-repeat fixed;
        }
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(248, 249, 250, 0.9);
            z-index: 0;
        }
        .login-container {
            background: rgba(255, 255, 255, 0.95);
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
            position: relative;
            z-index: 1;
        }
        .logo-container {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
        }
        .logo {
            width: 150px;
            height: 150px;
            object-fit: contain;
        }
        .login-title {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #555;
        }
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            box-sizing: border-box;
            font-size: 16px;
        }
        .submit-button {
            background: #0d6efd;
            color: white;
            border: none;
            padding: 12px;
            width: 100%;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s;
        }
        .submit-button:hover {
            background: #0b5ed7;
        }
        .admin-link {
            text-align: center;
            margin-top: 20px;
        }
        .admin-link a {
            color: #6c757d;
            text-decoration: none;
            font-size: 14px;
            transition: color 0.3s;
        }
        .admin-link a:hover {
            color: #0d6efd;
        }
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            color: #721c24;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo-container">
            <img src="assets/images/gombe-ss-logo.png" alt="Gombe S.S." class="logo">
        </div>
        
        <h2 class="login-title">Student Voting System</h2>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="form-group">
                <label for="voting_code">Enter Your Voting Code:</label>
                <input type="text" id="voting_code" name="voting_code" required 
                       placeholder="Enter your voting code" autocomplete="off">
            </div>
            <button type="submit" class="submit-button">Login to Vote</button>
        </form>

        <div class="admin-link">
            <a href="admin/login.php">Admin Login</a>
        </div>
    </div>

    <?php if ($debug): ?>
        <div class="debug-info">
            <h5>Debug Information:</h5>
            <?php echo $debug; ?>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 