<?php declare(strict_types=0); // Enforce strict types

// --- Initialization & Config ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Error Reporting (Development vs Production)
// Dev settings:
error_reporting(E_ALL);
ini_set('display_errors', '1');
// Production settings:
// error_reporting(0);
// ini_set('display_errors', '0');
// ini_set('log_errors', '1');

// Set timezone consistently
date_default_timezone_set('Africa/Nairobi'); // EAT

// --- Includes ---
require_once "config/database.php"; // Provides $conn (mysqli object)
// require_once "includes/init.php"; // Include if needed
// require_once "includes/session.php"; // Include if it contains helpers used here

// --- Pre-Check DB Connection ---
// Essential before any database operations
if (!$conn || $conn->connect_error) {
    error_log("Voter Login DB Connection Error: " . ($conn ? $conn->connect_error : 'Check config'));
    // Display a user-friendly error page, do not expose details
    die("A critical database connection error occurred. Please inform the administrator.");
}

// --- Check Existing Session & Re-validate ---
// If user has session variables, verify they are still valid before redirecting
if (isset($_SESSION['voting_code'], $_SESSION['election_id'], $_SESSION['voting_code_id'])) {
    $valid_session = false; // Assume invalid until proven otherwise
    $session_error_message = "Your previous session is no longer valid. Please log in again."; // Default message
    $session_election_id = (int)$_SESSION['election_id'];
    $session_code_id = (int)$_SESSION['voting_code_id'];

    try {
        // Re-check election status (must be active)
        $sql_check_election = "SELECT status FROM elections WHERE id = $session_election_id LIMIT 1";
        $result_election = $conn->query($sql_check_election);
        $current_election_status = null;
        if ($result_election && $result_election->num_rows > 0) {
             $election_row = $result_election->fetch_assoc();
             $current_election_status = $election_row['status'];
             $result_election->free_result();
        } elseif($result_election === false) {
             // Log DB error but treat session as invalid
             error_log("Session Check Error (Election Query): " . $conn->error);
             $session_error_message = "Error validating your session election.";
        }

        // Re-check code status (must NOT be used - is_used = 0)
        $sql_check_code = "SELECT is_used FROM voting_codes WHERE id = $session_code_id LIMIT 1";
        $result_code = $conn->query($sql_check_code);
        $current_code_used = null; // Treat as used if not found or error
        if ($result_code && $result_code->num_rows > 0) {
             $code_row = $result_code->fetch_assoc();
             $current_code_used = (int)$code_row['is_used']; // Cast to int (0 or 1)
             $result_code->free_result();
        } elseif($result_code === false) {
             error_log("Session Check Error (Code Query): " . $conn->error);
             $session_error_message = "Error validating your session code.";
        } else {
             // Code ID from session doesn't exist anymore
             $session_error_message = "Your voting code is no longer valid.";
             $current_code_used = 1; // Treat non-existent code as used/invalid
        }

        // Determine if session is valid based on checks
        if ($current_election_status === 'active' && $current_code_used === 0) {
            $valid_session = true;
        } else {
            // Refine error message if possible
            if ($current_election_status !== 'active' && $current_election_status !== null) { 
            } elseif ($current_code_used !== 0) { 
            }
        }

    } catch (mysqli_sql_exception $e) {
        error_log("Session Check DB Error (index.php): " . $e->getMessage());
        $session_error_message = "A database error occurred validating your session.";
        $valid_session = false; // Ensure session is invalidated on DB error
    } catch (Exception $e) {
         error_log("Session Check General Error (index.php): " . $e->getMessage());
         $session_error_message = "An unexpected error occurred validating your session.";
         $valid_session = false;
    }

    // --- Action Based on Session Validity ---
    if ($valid_session) {
        // Session is still good, redirect to voting page
        header("Location: vote.php");
        exit();
    } else {
        // Session is invalid, destroy it, set flash message, and redirect to self (login page)
        $error_message_for_redirect = $session_error_message; // Store message before destroying session
        session_unset();
        session_destroy();
        // Restart session briefly JUST to store the flash message
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_SESSION['login_error'] = $error_message_for_redirect;
        // Redirect to the login page itself to display the message
        header("Location: index.php"); // Redirect back to self
        exit();
    }
} // End existing session check

// --- Initialize Variables for Login Page Display ---
$error = ''; // User-facing error message from form submission
$success_message = ''; // Success message (e.g., after successful vote)
$submitted_code = ''; // To repopulate form field on error

// Check for flash error messages stored in session (after potential redirect from session check)
if (isset($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']); // Clear the message after retrieving
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
        // Sanitize code for use in SQL query
        $submitted_code_escaped = $conn->real_escape_string($submitted_code);

        try {
            // 1. Check if the code exists and is unused, get ID and election_id
            $sql_code_check = "SELECT id, election_id, is_used
                               FROM voting_codes
                               WHERE code = '$submitted_code_escaped'
                               LIMIT 1"; // Check regardless of is_used first

            $result_code_check = $conn->query($sql_code_check);
            if ($result_code_check === false) {
                 throw new mysqli_sql_exception("DB error checking code: " . $conn->error, $conn->errno);
            }

            if ($result_code_check->num_rows === 0) {
                // Code does not exist at all
                $error = "Invalid voting code entered.";
                $result_code_check->free_result();
            } else {
                // Code exists, check if used
                $code_data = $result_code_check->fetch_assoc();
                $result_code_check->free_result();

                if (!empty($code_data['is_used'])) { // Check if is_used = 1
                     $error = "This voting code has already been used.";
                } else {
                    // Code is valid and unused, now check the election status
                    $election_id = (int)$code_data['election_id'];
                    $voting_code_db_id = (int)$code_data['id'];

                    // 2. Check associated election status (must be 'active')
                    $sql_election_check = "SELECT id, title
                                           FROM elections
                                           WHERE id = $election_id AND status = 'active'
                                           LIMIT 1";
                    $result_election_check = $conn->query($sql_election_check);

                    if ($result_election_check === false) {
                         throw new mysqli_sql_exception("DB error checking election status: " . $conn->error, $conn->errno);
                    }

                    if ($result_election_check->num_rows > 0) {
                        // Election is active! Login Successful!
                        $election_data = $result_election_check->fetch_assoc();
                        $result_election_check->free_result();

                        // Set session variables
                        $_SESSION['voting_code'] = $submitted_code; // Store the code they entered
                        $_SESSION['election_id'] = $election_id;
                        $_SESSION['election_title'] = $election_data['title'];
                        $_SESSION['voting_code_id'] = $voting_code_db_id; // Store the code's database ID

                        session_regenerate_id(true); // Enhance security: Prevent session fixation

                        // Redirect to the voting page
                        header("Location: vote.php");
                        exit();
                    } else {
                         // Code is valid, unused, but election is not active
                         if($result_election_check) $result_election_check->free_result();
                         $error = "This voting code is not for a currently active election.";
                    }
                }
            }

        } catch (mysqli_sql_exception $db_ex) { // Catch specific DB errors
            error_log("Login DB Error (index.php): " . $db_ex->getMessage() . " (Code: " . $db_ex->getCode() . ")");
            $error = "A database error occurred. Please try again later or contact support.";
        } catch (Exception $e) { // Catch other general errors
            error_log("Login General Error (index.php): " . $e->getMessage());
            $error = "An unexpected error occurred. Please try again.";
        }
    }
} // End POST handling

// --- Generate CSP nonce for HTML output ---
// Regenerate if needed, ensure it happens *after* potential redirects and session restarts
if (empty($_SESSION['csp_nonce'])) {
     $_SESSION['csp_nonce'] = base64_encode(random_bytes(16));
}
$nonce = htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8');

// --- Background image path ---
$background_image_path = 'assets/images/background-logos.png'; // Check this path is correct relative to index.php
$background_style = file_exists($background_image_path)
    ? "background-image: url('" . htmlspecialchars($background_image_path, ENT_QUOTES, 'UTF-8') . "'); background-position: center; background-repeat: repeat; background-size: cover; background-attachment: fixed;"
    : "background-color: #f8f9fc;"; // Fallback color

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
    <?php /* Add Content Security Policy header if possible, using $nonce */ ?>
    <style nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
        /* --- Styles (Copied from original, slightly adjusted) --- */
        :root {
            --primary-hue: 226; --primary-color: hsl(var(--primary-hue), 76%, 58%); --primary-light: hsl(var(--primary-hue), 76%, 95%); --primary-dark: hsl(var(--primary-hue), 70%, 48%);
            --secondary-color: #6c757d; --success-color: #198754; --success-light: #d1e7dd; --danger-color: #dc3545; --danger-light: #f8d7da;
            --light-color: #f8f9fc; --white-color: #fff; --dark-color: #5a5c69; --border-color: #dee2e6;
            --font-family-primary: "Poppins", sans-serif; --font-family-secondary: "Nunito", sans-serif;
            --shadow: 0 .3rem 1rem rgba(0,0,0,.1); --border-radius: .4rem;
        }
        html, body { height: 100%; margin: 0; }
        body { font-family: var(--font-family-secondary); display: flex; align-items: center; justify-content: center; position: relative; <?php echo $background_style; ?> padding: 1rem; overflow-x: hidden; -ms-overflow-style: none; scrollbar-width: none; }
        body::-webkit-scrollbar { display: none; }
        body::before { content: ''; position: fixed; inset: 0; background: rgba(248, 249, 252, 0.94); z-index: -1; } /* Slightly less opaque */
        .login-wrapper { width: 100%; max-width: 430px; margin: auto; z-index: 1; }
        .login-container { background: var(--white-color); padding: 2rem 2.5rem; border-radius: var(--border-radius); box-shadow: var(--shadow); border-top: 5px solid var(--primary-color); }
        .logo-container { text-align: center; margin-bottom: 1.5rem; }
        .logo { max-width: 100px; max-height: 100px; object-fit: contain; } /* Use max-width/height */
        .login-title { text-align: center; color: var(--primary-dark); margin-bottom: .5rem; font-weight: 700; font-family: var(--font-family-primary); font-size: 1.6rem; }
        .login-subtitle { text-align: center; color: var(--secondary-color); margin-bottom: 2rem; font-size: 1rem;}
        .form-label { font-weight: 600; font-size: 0.85rem; color: var(--dark-color); margin-bottom: 0.4rem; text-transform: uppercase; letter-spacing: .5px;}
        .form-control { height: calc(1.5em + 1.2rem + 2px); padding: .6rem 1rem; border: 1px solid var(--border-color); border-radius: var(--border-radius); font-size: 1rem; transition: all .2s ease;} /* Slightly smaller height */
        .form-control:focus { border-color: var(--primary-color); box-shadow: 0 0 0 .2rem rgba(13, 110, 253, .25); }
        .input-group-text { background-color: #f0f3f8; border: 1px solid var(--border-color); border-right: none; color: var(--secondary-color); height: calc(1.5em + 1.2rem + 2px); border-radius: var(--border-radius) 0 0 var(--border-radius);}
        /* Combined input group styling */
        .input-group .form-control { border-left: none; padding-left: 0.5rem; border-radius: 0 var(--border-radius) var(--border-radius) 0;}
        .input-group:focus-within .input-group-text { border-color: var(--primary-color); box-shadow: 0 0 0 .2rem rgba(13, 110, 253, .25); border-right: none; z-index: 3;}
        .input-group:focus-within .form-control { border-color: var(--primary-color); box-shadow: 0 0 0 .2rem rgba(13, 110, 253, .25); border-left: none; z-index: 3;}

        .submit-button { background: var(--primary-color); color: white; border: none; padding: .75rem 1.5rem; width: 100%; border-radius: 50px; cursor: pointer; font-size: 1rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; transition: all 0.2s ease; margin-top: 1.5rem; box-shadow: 0 2px 5px rgba(13, 110, 253, 0.3); display: flex; align-items: center; justify-content: center; }
        .submit-button i { margin-right: 0.5rem; font-size: 1.1rem; }
        .submit-button:hover { background: var(--primary-dark); box-shadow: 0 4px 10px rgba(13, 110, 253, 0.4); transform: translateY(-1px); }
        .submit-button:active { transform: translateY(0); box-shadow: 0 2px 5px rgba(13, 110, 253, 0.3);}
        .admin-link { text-align: center; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border-color); }
        .admin-link a { color: var(--secondary-color); text-decoration: none; font-size: 0.85rem; transition: color 0.2s; }
        .admin-link a:hover { color: var(--primary-color); text-decoration: underline; }
        .admin-link i { margin-right: .3rem; vertical-align: -1px;}
        .alert { padding: .9rem 1.1rem; margin-bottom: 1.25rem; border-radius: var(--border-radius); font-size: 0.9rem; display: flex; align-items: flex-start; word-break: break-word; border-width: 1px; border-style: solid;}
        .alert-danger { color: #842029; background-color: var(--danger-light); border-color: #f5c2c7; }
        .alert-success { color: #0f5132; background-color: var(--success-light); border-color: #badbcc; }
        .alert .bi { margin-right: .7rem; font-size: 1.2rem; line-height: 1.4; flex-shrink: 0;}
        .alert .btn-close { padding: 0.8rem 1rem; margin-left: auto;} /* Adjusted padding */
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

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-x-octagon-fill"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill"></i>
                    <div><?php echo htmlspecialchars($success_message); ?></div>
                     <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" novalidate>
                <div class="mb-4">
                    <label for="voting_code" class="form-label">Voting Code</label>
                     <div class="input-group">
                         <span class="input-group-text" id="code-addon"><i class="bi bi-key-fill"></i></span>
                         <input type="text" class="form-control" id="voting_code" name="voting_code"
                                value="<?php echo htmlspecialchars($submitted_code); ?>"
                                required
                                placeholder="Enter code here"
                                autocomplete="off"
                                aria-label="Voting Code Input"
                                aria-describedby="code-addon">
                    </div>
                    <?php /* Basic HTML5 validation only, server-side check is primary */ ?>
                    <div class="invalid-feedback" style="display: none;">Please enter your voting code.</div>
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
        <footer class="text-center text-muted mt-4">
            <small>&copy; <?php echo date("Y"); ?> School Voting System.</small>
        </footer>
    </div>

    <script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

    <script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
          document.addEventListener('DOMContentLoaded', function() {
               // Focus on input field if empty
               const codeInput = document.getElementById('voting_code');
               if(codeInput && !codeInput.value) {
                    try { codeInput.focus(); } catch(e) { /* Ignore focus errors */ }
               }

               // Auto-dismiss success alert after a delay
               const successAlert = document.querySelector('.alert-success');
               if (successAlert) {
                    setTimeout(() => {
                        const alertInstance = bootstrap.Alert.getOrCreateInstance(successAlert);
                        if (alertInstance) {
                             try { alertInstance.close(); } catch(e) { /* Ignore close errors */ }
                        }
                    }, 5000); // 5 seconds
               }
               // Optional: Auto-dismiss error alert too
               const errorAlert = document.querySelector('.alert-danger');
               if (errorAlert) {
                    setTimeout(() => {
                         const alertInstance = bootstrap.Alert.getOrCreateInstance(errorAlert);
                         if (alertInstance) {
                              try { alertInstance.close(); } catch(e) { /* Ignore close errors */ }
                         }
                    }, 10000); // 10 seconds for errors
               }
          });
     </script>
</body>
</html>
<?php
// Close the database connection if it's still open
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $conn->close();
}
?>