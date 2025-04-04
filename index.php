<?php
// Start session **before** any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Establish database connection ($conn PDO object)
require_once "config/database.php";

// --- Configuration & Initial Setup ---
date_default_timezone_set('Asia/Manila'); // Adjust timezone as needed
error_reporting(E_ALL); // Development: Show all errors
ini_set('display_errors', 1); // Development: Display errors
// ini_set('display_errors', 0); // Production: Hide errors
// error_reporting(0); // Production: Report no errors

// --- Check Existing Session ---
// If already logged in (essential session vars set), check validity before redirecting
if (isset($_SESSION['voting_code'], $_SESSION['election_id'], $_SESSION['voting_code_id'])) {
    try {
        // Check if the election associated with the session is still active
        $check_election_stmt = $conn->prepare("SELECT status FROM elections WHERE id = :election_id");
        $check_election_stmt->bindParam(':election_id', $_SESSION['election_id'], PDO::PARAM_INT);
        $check_election_stmt->execute();
        $current_election_status = $check_election_stmt->fetchColumn();

        // Also re-check if code was somehow used concurrently
        $check_code_stmt = $conn->prepare("SELECT is_used FROM voting_codes WHERE id = :code_id");
        $check_code_stmt->bindParam(':code_id', $_SESSION['voting_code_id'], PDO::PARAM_INT);
        $check_code_stmt->execute();
        $current_code_used = $check_code_stmt->fetchColumn();

        // Code status must be explicitly 0 (false/not used)
        if ($current_election_status === 'active' && $current_code_used === 0) {
            header("Location: vote.php");
            exit();
        } else {
            // Invalid session state found, clear it and stay on login page
            $redirect_error_code = ($current_election_status !== 'active') ? 'election_inactive' : 'already_voted';
            $error_message = ($redirect_error_code === 'election_inactive')
                 ? "The election associated with your previous session is no longer active."
                 : "Your voting code was used after you logged in or is invalid. Please try logging in again.";

            session_unset(); // Unset $_SESSION variables
            session_destroy(); // Destroy the session data

            // Restart session briefly ONLY to set the flash message
            if (session_status() === PHP_SESSION_NONE) session_start();
            $_SESSION['login_error'] = $error_message;
            // Redirect back to self to display error without POST data issues
            header("Location: index.php");
            exit();
        }
    } catch (PDOException $e) {
        error_log("Session Check Error (index.php): " . $e->getMessage());
        // Allow showing login page, but log the error
    }
}

// --- Variable Initialization ---
$error = ''; // User-facing error message
$success_message = ''; // For success feedback
$submitted_code = ''; // To repopulate form field on error

// Check for flash error messages stored in session (after potential redirect)
if (isset($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']); // Clear the message after displaying
}
// Check for success message from vote.php redirect
if (isset($_GET['voted']) && $_GET['voted'] === 'success') {
     $success_message = "Your vote was successfully submitted. Thank you for participating!";
}

// --- Handle Login Form Submission ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $submitted_code = trim($_POST['voting_code'] ?? '');

    if (empty($submitted_code)) {
        $error = "Please enter your voting code.";
    } else {
        try {
            // 1. Check if the code exists, is unused, get ID and election_id
            $stmt_code = $conn->prepare("SELECT id, election_id FROM voting_codes WHERE code = :code AND is_used = 0 LIMIT 1");
            if (!$stmt_code) {
                error_log("SQL Error (index.php): " . implode(" | ", $conn->errorInfo()));
                throw new Exception("Failed to prepare the SQL statement.");
            }
            $stmt_code->bindParam(':code', $submitted_code, PDO::PARAM_STR);
            $stmt_code->execute();
            $code_data = $stmt_code->fetch(PDO::FETCH_ASSOC);

            if (!$code_data) {
                // Check if code exists but is already used
                $stmt_check_used = $conn->prepare("SELECT id FROM voting_codes WHERE code = :code AND is_used = 1 LIMIT 1");
                $stmt_check_used->bindParam(':code', $submitted_code, PDO::PARAM_STR);
                $stmt_check_used->execute();
                $error = $stmt_check_used->fetch() ? "This voting code has already been used." : "Invalid voting code entered.";
            } else {
                // Code valid & unused, check election status
                $election_id = $code_data['election_id'];
                $voting_code_db_id = $code_data['id'];

                // 2. Check associated election status ('active' ONLY - simplified as requested)
                $stmt_election = $conn->prepare("SELECT id, title FROM elections WHERE id = :election_id AND status = 'active' LIMIT 1");
                $stmt_election->bindParam(':election_id', $election_id, PDO::PARAM_INT);
                $stmt_election->execute();
                $election_data = $stmt_election->fetch(PDO::FETCH_ASSOC);

                if ($election_data) {
                    // Login Successful! Set session variables.
                    $_SESSION['voting_code'] = $submitted_code;
                    $_SESSION['election_id'] = (int)$election_data['id'];
                    $_SESSION['election_title'] = $election_data['title'];
                    $_SESSION['voting_code_id'] = (int)$voting_code_db_id; // Store the code's database ID

                    session_regenerate_id(true); // Prevent session fixation

                    header("Location: vote.php"); // Redirect to the voting page
                    exit();

                } else {
                    $error = "This voting code is not for a currently active election.";
                }
            }

        } catch (PDOException $e) {
            error_log("Login PDOException (index.php): " . $e->getMessage());
            $error = "A database error occurred. Please try again later.";
        } catch (Exception $e) {
            error_log("Login Exception (index.php): " . $e->getMessage());
            $error = "An unexpected error occurred.";
        }
    }
}

// Generate CSP nonce
if (empty($_SESSION['csp_nonce'])) {
     $_SESSION['csp_nonce'] = base64_encode(random_bytes(16));
}
$nonce = htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8');

// Background image path
$background_image_path = 'assets/images/background-logos.png';
$background_style = file_exists($background_image_path)
    ? "background-image: url('" . htmlspecialchars($background_image_path) . "'); background-position: center; background-repeat: repeat; background-size: cover; background-attachment: fixed;"
    : "background-color: var(--light-color);";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Voting System - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">

    <style nonce="<?php echo $nonce; ?>">
        :root {
            --primary-hue: 226; --primary-color: hsl(var(--primary-hue), 76%, 58%); --primary-light: hsl(var(--primary-hue), 76%, 95%); --primary-dark: hsl(var(--primary-hue), 70%, 48%);
            --secondary-color: #858796; --success-color: #1cc88a; --success-light: #e8f9f4; --danger-color: #e74a3b; --danger-light: #feebee;
            --light-color: #f8f9fc; --white-color: #fff; --dark-color: #5a5c69; --border-color: #e3e6f0;
            --font-family-primary: "Poppins", sans-serif; --font-family-secondary: "Nunito", sans-serif;
            --shadow: 0 .3rem 1rem rgba(0,0,0,.1); --border-radius: .4rem;
        }
        html, body { height: 100%; margin: 0; }
        body { font-family: var(--font-family-secondary); display: flex; align-items: center; justify-content: center; position: relative; <?php echo $background_style; ?> padding: 1rem; overflow-x: hidden; -ms-overflow-style: none; scrollbar-width: none; }
        body::-webkit-scrollbar { display: none; }
        body::before { content: ''; position: fixed; inset: 0; background: rgba(248, 249, 252, 0.92); z-index: -1; }
        .login-wrapper { width: 100%; max-width: 430px; margin: auto; z-index: 1; }
        .login-container { background: var(--white-color); padding: 2rem 2.5rem; border-radius: var(--border-radius); box-shadow: var(--shadow); border-top: 5px solid var(--primary-color); }
        .logo-container { text-align: center; margin-bottom: 1.5rem; }
        .logo { width: 100px; height: 100px; object-fit: contain; }
        .login-title { text-align: center; color: var(--primary-dark); margin-bottom: .5rem; font-weight: 700; font-family: var(--font-family-primary); font-size: 1.6rem; }
        .login-subtitle { text-align: center; color: var(--secondary-color); margin-bottom: 2rem; font-size: 1rem;}
        .form-label { font-weight: 600; font-size: 0.85rem; color: var(--dark-color); margin-bottom: 0.4rem; text-transform: uppercase; letter-spacing: .5px;}
        .form-control { height: calc(1.5em + 1.4rem + 2px); padding: .7rem 1rem; border: 1px solid var(--border-color); border-radius: var(--border-radius); font-size: 1rem; transition: all .2s ease;}
        .form-control:focus { border-color: var(--primary-color); box-shadow: 0 0 0 .2rem rgba(78, 115, 223, .25); }
        .input-group-text { background-color: #f0f3f8; border: 1px solid var(--border-color); border-right: none; color: var(--secondary-color); height: calc(1.5em + 1.4rem + 2px);}
        .form-control { border-left: none; padding-left: 0.5rem;}
        .input-group:focus-within .input-group-text, .input-group:focus-within .form-control { border-color: var(--primary-color); box-shadow: 0 0 0 .2rem rgba(78, 115, 223, .25); }
        .input-group:focus-within .input-group-text { border-right: none;}
        .input-group:focus-within .form-control { border-left: none;}
        .submit-button { background: var(--primary-color); color: white; border: none; padding: .75rem 1.5rem; width: 100%; border-radius: 50px; cursor: pointer; font-size: 1rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; transition: all 0.2s ease; margin-top: 1.5rem; box-shadow: 0 2px 5px rgba(78, 115, 223, 0.3); display: flex; align-items: center; justify-content: center; }
        .submit-button i { margin-right: 0.5rem; font-size: 1.1rem; }
        .submit-button:hover { background: var(--primary-dark); box-shadow: 0 4px 10px rgba(78, 115, 223, 0.4); transform: translateY(-1px); }
        .submit-button:active { transform: translateY(0); box-shadow: 0 2px 5px rgba(78, 115, 223, 0.3);}
        .admin-link { text-align: center; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border-color); }
        .admin-link a { color: var(--secondary-color); text-decoration: none; font-size: 0.85rem; transition: color 0.2s; }
        .admin-link a:hover { color: var(--primary-color); text-decoration: underline; }
        .admin-link i { margin-right: .3rem; vertical-align: -1px;}
        .alert { padding: .9rem 1.1rem; margin-bottom: 1.25rem; border-radius: var(--border-radius); font-size: 0.9rem; display: flex; align-items: flex-start; word-break: break-word; border-width: 1px; border-style: solid;}
        .alert-danger { color: #6f1c24; background-color: var(--danger-light); border-color: #f1c6cb; }
        .alert-success { color: #0b5533; background-color: var(--success-light); border-color: #b7e4d1; }
        .alert .bi { margin-right: .7rem; font-size: 1.2rem; line-height: 1.4; flex-shrink: 0;}
        .alert .btn-close { padding: 0.6rem; margin-left: auto;}
    </style>
</head>
<body>
    <div class="login-wrapper">
        <main class="login-container">
            <div class="logo-container">
                <img src="assets/images/gombe-ss-logo.png" alt="School Logo" class="logo">
            </div>

            <h1 class="login-title">Student Voting</h1>
            <p class="login-subtitle">Enter your unique voting code to cast your vote.</p>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-x-octagon-fill"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
             <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill"></i>
                    <div><?php echo htmlspecialchars($success_message); ?></div>
                     <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <div class="mb-4">
                    <label for="voting_code" class="form-label">Voting Code</label>
                     <div class="input-group">
                         <span class="input-group-text"><i class="bi bi-key-fill"></i></span>
                         <input type="text" class="form-control" id="voting_code" name="voting_code"
                               value="<?php echo htmlspecialchars($submitted_code); ?>"
                               required
                               placeholder="Enter code here"
                               autocomplete="off"
                               aria-label="Voting Code Input">
                     </div>
                </div>
                <button type="submit" class="submit-button">
                     <i class="bi bi-box-arrow-in-right"></i> Login & Vote
                </button>
            </form>

            <div class="admin-link mt-4">
                <a href="admin/login.php">
                     <i class="bi bi-person-fill-lock"></i> Administrator Login
                </a>
            </div>
        </main>
        <footer class="text-center text-muted mt-3">
            <small>&copy; <?php echo date("Y"); ?> School Voting System.</small>
        </footer>
    </div>

    <script nonce="<?php echo $nonce; ?>" src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

     <script nonce="<?php echo $nonce; ?>">
         document.addEventListener('DOMContentLoaded', function() {
             const codeInput = document.getElementById('voting_code');
             if(codeInput && !codeInput.value) codeInput.focus();

             const successAlert = document.querySelector('.alert-success');
             if (successAlert) {
                setTimeout(() => {
                    const alertInstance = bootstrap.Alert.getOrCreateInstance(successAlert);
                    if (alertInstance) alertInstance.close();
                }, 5000); // Auto-dismiss after 5 seconds
             }
         });
     </script>
</body>
</html>