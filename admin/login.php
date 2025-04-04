<?php
// Start session **before** any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Establish database connection ($conn PDO object)
require_once "../config/database.php"; // Relative path to config from admin folder

// --- Configuration ---
date_default_timezone_set('Asia/Manila'); // Adjust timezone if needed
error_reporting(E_ALL); // Development: Show all errors
ini_set('display_errors', 1); // Development: Display errors
// Production settings (uncomment for deployment):
// ini_set('display_errors', 0);
// error_reporting(0);

// --- Check Existing Session ---
// If already logged in, redirect to the admin dashboard
if (isset($_SESSION['admin_id'])) {
    header("Location: /school_voting/admin/index.php"); // Use absolute path from web root if possible
    exit();
}

// --- Variable Initialization ---
$error = '';
$submitted_username = ''; // To repopulate form field on error

// --- Handle Login Form Submission ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $submitted_username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? ''; // No need to trim password

    if (empty($submitted_username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        try {
            // 1. Prepare SQL statement using PDO placeholders
            $sql = "SELECT id, username, password FROM admins WHERE username = :username LIMIT 1";
            $stmt = $conn->prepare($sql);

            // 2. Bind the username parameter
            $stmt->bindParam(':username', $submitted_username, PDO::PARAM_STR);

            // 3. Execute the statement
            $stmt->execute();

            // 4. Fetch the result using PDO::FETCH_ASSOC
            // **THIS IS THE FIX for the error on line 19**
            $admin_row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($admin_row) {
                // 5. Verify the password
                if (password_verify($password, $admin_row['password'])) {
                    // Password is correct, login successful

                    // Regenerate session ID for security
                    session_regenerate_id(true);

                    // Store admin details in session
                    $_SESSION['admin_id'] = (int)$admin_row['id'];
                    $_SESSION['admin_username'] = $admin_row['username'];

                    // Redirect to the admin dashboard
                    header("Location: /school_voting/admin/index.php"); // Use absolute path
                    exit();
                } else {
                    // Password incorrect
                    $error = "Invalid username or password.";
                }
            } else {
                // Username not found
                $error = "Invalid username or password.";
            }
        } catch (PDOException $e) {
            error_log("Admin Login PDOException: " . $e->getMessage());
            $error = "A database error occurred during login. Please try again later.";
        } catch (Exception $e) {
            error_log("Admin Login Exception: " . $e->getMessage());
            $error = "An unexpected error occurred. Please try again.";
        }
    }
}

// Generate CSP nonce
if (empty($_SESSION['csp_nonce'])) {
     $_SESSION['csp_nonce'] = base64_encode(random_bytes(16));
}
$nonce = htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8');

// Background image path (relative to admin/login.php)
$background_image_path = '../assets/images/background-logos.png';
$background_style = file_exists($background_image_path)
    ? "background-image: url('" . htmlspecialchars($background_image_path) . "'); background-position: center; background-repeat: repeat; background-size: cover; background-attachment: fixed;"
    : "background-color: var(--light-color);";

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - School Voting System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">

    <style nonce="<?php echo $nonce; ?>">
        :root {
            --primary-hue: 226; --primary-color: hsl(var(--primary-hue), 76%, 58%); --primary-light: hsl(var(--primary-hue), 76%, 95%); --primary-dark: hsl(var(--primary-hue), 70%, 48%);
            --secondary-color: #858796; --success-color: #1cc88a; --info-color: #36b9cc; --warning-color: #f6c23e; --danger-color: #e74a3b; --danger-light: #feebee;
            --light-color: #f8f9fc; --white-color: #fff; --dark-color: #5a5c69; --border-color: #e3e6f0;
            --font-family-primary: "Poppins", sans-serif; --font-family-secondary: "Nunito", sans-serif;
            --shadow: 0 .3rem 1rem rgba(0,0,0,.1); --border-radius: .4rem;
        }
        html, body { height: 100%; margin: 0; }
        body {
            font-family: var(--font-family-secondary); display: flex; align-items: center; justify-content: center; position: relative;
            <?php echo $background_style; ?> padding: 1rem; overflow-x: hidden; -ms-overflow-style: none; scrollbar-width: none;
        }
        body::-webkit-scrollbar { display: none; }
        body::before { content: ''; position: fixed; inset: 0; background: rgba(248, 249, 252, 0.93); z-index: -1; } /* Slightly less opaque */

        .login-wrapper { width: 100%; max-width: 400px; margin: auto; z-index: 1; }
        .login-container { background: var(--white-color); padding: 2rem; border-radius: var(--border-radius); box-shadow: var(--shadow); border-top: 4px solid var(--primary-color); }
        .logo-container { text-align: center; margin-bottom: 1rem; }
        .logo { width: 90px; height: 90px; object-fit: contain; }
        .login-title { text-align: center; color: var(--primary-dark); margin-bottom: 1.5rem; font-weight: 700; font-family: var(--font-family-primary); font-size: 1.5rem; }
        .form-label { font-weight: 600; font-size: 0.8rem; color: var(--dark-color); margin-bottom: 0.3rem; text-transform: uppercase; letter-spacing: .5px; }
        .form-control { height: calc(1.5em + 1.2rem + 2px); padding: .6rem 1rem; border: 1px solid var(--border-color); border-radius: var(--border-radius); font-size: .95rem; transition: all .2s ease; }
        .form-control:focus { border-color: var(--primary-color); box-shadow: 0 0 0 .2rem rgba(78, 115, 223, .2); }
        .input-group-text { background-color: #f0f3f8; border: 1px solid var(--border-color); border-right: none; color: var(--secondary-color); height: calc(1.5em + 1.2rem + 2px); border-radius: var(--border-radius) 0 0 var(--border-radius);}
        .form-control { border-left: none; border-radius: 0 var(--border-radius) var(--border-radius) 0;}
        .input-group:focus-within .input-group-text, .input-group:focus-within .form-control { border-color: var(--primary-color); box-shadow: 0 0 0 .2rem rgba(78, 115, 223, .2); }
        .input-group:focus-within .input-group-text { border-right: none;}
        .input-group:focus-within .form-control { border-left: none;}

        .submit-button { background: var(--primary-color); color: white; border: none; padding: .7rem 1.5rem; width: 100%; border-radius: 50px; cursor: pointer; font-size: .95rem; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; transition: all 0.2s ease; margin-top: 1.5rem; box-shadow: 0 2px 5px rgba(78, 115, 223, 0.3); display: flex; align-items: center; justify-content: center; }
        .submit-button i { margin-right: 0.5rem; font-size: 1rem; }
        .submit-button:hover { background: var(--primary-dark); box-shadow: 0 4px 10px rgba(78, 115, 223, 0.4); transform: translateY(-1px); }
        .submit-button:active { transform: translateY(0); box-shadow: 0 2px 5px rgba(78, 115, 223, 0.3);}

        .alert { padding: .8rem 1rem; margin-bottom: 1.25rem; border-radius: var(--border-radius); font-size: 0.9rem; display: flex; align-items: flex-start; word-break: break-word; border-width: 1px; border-style: solid;}
        .alert-danger { color: #6f1c24; background-color: var(--danger-light); border-color: #f1c6cb; }
        .alert .bi { margin-right: .7rem; font-size: 1.1rem; line-height: 1.4; flex-shrink: 0;}
        .alert .btn-close { padding: 0.5rem; margin-left: auto;}

        .voter-login-link { text-align: center; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border-color); }
        .voter-login-link a { color: var(--secondary-color); text-decoration: none; font-size: 0.85rem; transition: color 0.2s; }
        .voter-login-link a:hover { color: var(--primary-color); text-decoration: underline; }
        .voter-login-link i { margin-right: .3rem; vertical-align: -1px;}

    </style>
</head>
<body>
    <div class="login-wrapper">
        <main class="login-container">
            <div class="logo-container">
                <img src="../assets/images/gombe-ss-logo.png" alt="School Logo" class="logo">
                 <h1 class="login-title">Administrator Login</h1>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <div class="input-group">
                         <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                        <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($submitted_username); ?>" required autocomplete="username">
                    </div>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                     <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
                     </div>
                </div>
                <button type="submit" class="submit-button">
                    <i class="bi bi-box-arrow-in-right"></i> Login
                </button>
            </form>

             <div class="voter-login-link mt-4">
                <a href="../index.php"> {/* Link back to student login */}
                     <i class="bi bi-person-check-fill"></i> Go to Student Voter Login
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
             const usernameInput = document.getElementById('username');
             if(usernameInput && !usernameInput.value) { // Focus only if empty
                 usernameInput.focus();
             }
         });
     </script>
</body>
</html>