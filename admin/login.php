<?php
// Start session **before** any output
if (session_status() === PHP_SESSION_NONE) {
session_start();
}

// Establish database connection ($conn PDO object)
require_once "../config/database.php"; // Relative path from admin folder

// --- Configuration ---
date_default_timezone_set('Asia/Manila'); // Adjust timezone if needed
error_reporting(E_ALL); // Dev
ini_set('display_errors', 1); // Dev
// ini_set('display_errors', 0); // Prod
// error_reporting(0); // Prod

// --- Check Existing Session ---
if (isset($_SESSION['admin_id'])) {
    header("Location: index.php"); // Redirect to dashboard within admin folder
    exit();
}

// --- Variable Initialization ---
$error = '';
$submitted_username = '';

// --- Handle Login Form Submission ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $submitted_username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($submitted_username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        try {
            $sql = "SELECT id, username, password FROM admins WHERE username = :username LIMIT 1";
    $stmt = $conn->prepare($sql);

            if ($stmt === false) throw new PDOException("Failed to prepare login statement.");

            $stmt->bindParam(':username', $submitted_username, PDO::PARAM_STR);
            $execute_success = $stmt->execute();

             if (!$execute_success) {
                 $errorInfo = $stmt->errorInfo();
                 error_log("Admin Login PDO Execute Error: " . ($errorInfo[2] ?? 'Unknown error'));
                 throw new PDOException("Failed to execute login query.");
             }

            $admin_row = $stmt->fetch(PDO::FETCH_ASSOC); // Correct PDO fetch

            if ($admin_row && password_verify($password, $admin_row['password'])) {
                session_regenerate_id(true); // Prevent session fixation
                $_SESSION['admin_id'] = (int)$admin_row['id'];
                $_SESSION['admin_username'] = $admin_row['username'];
                header("Location: index.php"); // Redirect to dashboard
            exit();
            } else {
                $error = "Invalid username or password.";
            }
        } catch (PDOException $e) {
            error_log("Admin Login PDOException: " . $e->getMessage());
            $error = "A database error occurred. Please try again later.";
        } catch (Exception $e) {
            error_log("Admin Login Exception: " . $e->getMessage());
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
            --secondary-color: #858796; --danger-color: #e74a3b; --danger-light: #feebee;
            --light-color: #f8f9fc; --white-color: #fff; --dark-color: #5a5c69; --border-color: #e3e6f0;
            --font-family-primary: "Poppins", sans-serif; --font-family-secondary: "Nunito", sans-serif;
            --shadow: 0 .3rem 1rem rgba(0,0,0,.1); --shadow-lg: 0 1rem 3rem rgba(0,0,0,.175); --border-radius: .4rem; --border-radius-lg: .6rem;
        }
        html, body { height: 100%; margin: 0; }
        body {
            font-family: var(--font-family-secondary); display: flex; align-items: center; justify-content: center; position: relative;
            <?php echo $background_style; ?> padding: 1rem; overflow-x: hidden; -ms-overflow-style: none; scrollbar-width: none;
        }
        body::-webkit-scrollbar { display: none; }
        body::before { content: ''; position: fixed; inset: 0; background: rgba(248, 249, 252, 0.93); z-index: -1; }

        .main-wrapper { max-width: 1000px; width: 100%; margin: auto; } /* Wrapper for login + features */
        .login-wrapper { max-width: 420px; margin: 0 auto 3rem auto; z-index: 1; } /* Center login form */
        .login-container { background: var(--white-color); padding: 2rem 2.5rem; border-radius: var(--border-radius); box-shadow: var(--shadow); border-top: 5px solid var(--primary-color); }
        .logo-container { text-align: center; margin-bottom: 1rem; }
        .logo { width: 90px; height: 90px; object-fit: contain; margin-bottom: .5rem;}
        .login-title { text-align: center; color: var(--primary-dark); margin-bottom: 2rem; font-weight: 700; font-family: var(--font-family-primary); font-size: 1.6rem; }
        .form-label { font-weight: 600; font-size: 0.8rem; color: var(--dark-color); margin-bottom: 0.3rem; text-transform: uppercase; letter-spacing: .5px; }
        .form-control { height: calc(1.5em + 1.2rem + 2px); padding: .6rem 1rem; border: 1px solid var(--border-color); border-radius: var(--border-radius); font-size: .95rem; transition: all .2s ease;}
        .form-control:focus { border-color: var(--primary-color); box-shadow: 0 0 0 .2rem rgba(78, 115, 223, .25); z-index: 3;} /* Ensure focus outline is above icon */
        .input-group-text { background-color: #f0f3f8; border: 1px solid var(--border-color); border-right: none; color: var(--secondary-color); height: calc(1.5em + 1.2rem + 2px); border-radius: var(--border-radius) 0 0 var(--border-radius);}
        .input-group .form-control { border-left: none; border-radius: 0 var(--border-radius) var(--border-radius) 0;}
        .input-group:focus-within .input-group-text, .input-group:focus-within .form-control { border-color: var(--primary-color); box-shadow: 0 0 0 .2rem rgba(78, 115, 223, .25); }
        .input-group:focus-within .input-group-text { border-right: none;}
        .input-group:focus-within .form-control { border-left: none;}

        /* Password Toggle Button */
        .password-toggle-btn {
            position: absolute; right: 0; top: 0; bottom: 0; z-index: 10; /* Above input */
            height: 100%; border: none; background: transparent;
            padding: 0 0.75rem; color: var(--secondary-color); cursor: pointer;
            display: flex; align-items: center; border-radius: 0 var(--border-radius) var(--border-radius) 0;
        }
        .password-toggle-btn:hover { color: var(--primary-color); }
        .password-toggle-btn:focus { outline: none; box-shadow: none; } /* Prevent default focus */
        .input-group .form-control[type="password"], .input-group .form-control[type="text"] { padding-right: 2.8rem; /* Space for button */ }

        .submit-button { background: var(--primary-color); color: white; border: none; padding: .75rem 1.5rem; width: 100%; border-radius: 50px; cursor: pointer; font-size: .95rem; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; transition: all 0.2s ease; margin-top: 1.5rem; box-shadow: 0 2px 5px rgba(78, 115, 223, 0.3); display: flex; align-items: center; justify-content: center; }
        .submit-button i { margin-right: 0.5rem; font-size: 1rem; }
        .submit-button:hover { background: var(--primary-dark); box-shadow: 0 4px 10px rgba(78, 115, 223, 0.4); transform: translateY(-1px); }
        .submit-button:active { transform: translateY(0); box-shadow: 0 2px 5px rgba(78, 115, 223, 0.3);}
        .alert { padding: .9rem 1.1rem; margin-bottom: 1.25rem; border-radius: var(--border-radius); font-size: 0.9rem; display: flex; align-items: flex-start; word-break: break-word; border-width: 1px; border-style: solid;}
        .alert-danger { color: #6f1c24; background-color: var(--danger-light); border-color: #f1c6cb; }
        .alert .bi { margin-right: .7rem; font-size: 1.1rem; line-height: 1.4; flex-shrink: 0;}
        .alert .btn-close { padding: 0.6rem; margin-left: auto;}
        .voter-login-link { text-align: center; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border-color); }
        .voter-login-link a { color: var(--secondary-color); text-decoration: none; font-size: 0.85rem; transition: color 0.2s; }
        .voter-login-link a:hover { color: var(--primary-color); text-decoration: underline; }
        .voter-login-link i { margin-right: .3rem; vertical-align: -1px;}

         /* Feature Cards (Copied & Adjusted from previous context) */
         .feature-section { margin-top: 3rem; }
         .feature-card { transition: all 0.3s ease-in-out; background: var(--white-color); border-radius: var(--border-radius); height: 100%; border: 1px solid var(--border-color); box-shadow: var(--shadow-sm);}
         .feature-card:hover { transform: translateY(-8px); box-shadow: var(--shadow) !important; }
         .feature-icon { transition: all 0.3s ease; width: 60px; height: 60px; background: var(--primary-light); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; }
         .feature-icon i { font-size: 1.8rem; color: var(--primary-dark); transition: transform 0.3s ease;}
         .feature-card:hover .feature-icon { transform: scale(1.1) rotate(-5deg); background: var(--primary-color); }
         .feature-card:hover .feature-icon i { color: var(--white-color); }
         .feature-card .card-title { font-size: 1.15rem; font-weight: 700; font-family: var(--font-family-primary); color: var(--primary-dark); margin-bottom: .75rem; }
         .feature-card .card-text { color: var(--dark-color); font-size: 0.9rem; line-height: 1.6; }
         @media (max-width: 768px) { .feature-card { margin-bottom: 1.5rem; } .login-container { padding: 1.5rem; } }
    </style>
</head>
<body>
    <div class="main-wrapper container">
        <div class="row justify-content-center">
            <div class="col-lg-5 col-md-8"> 
                <div class="login-wrapper">
                    <main class="login-container">
        <div class="logo-container">
                            <img src="../assets/images/gombe-ss-logo.png" alt="School Logo" class="logo" onerror="this.style.display='none';">
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
                                     
                                     <button class="password-toggle-btn" type="button" id="togglePasswordBtn" aria-label="Toggle password visibility">
                                        <i class="bi bi-eye-fill"></i>
                                    </button>
                                </div>
                            </div>
                            <button type="submit" class="submit-button">
                                <i class="bi bi-box-arrow-in-right"></i> Login
                            </button>
                        </form>

                        <div class="voter-login-link mt-4">
                            <a href="../index.php">
                                 <i class="bi bi-people-fill"></i> Back to Student Voter Login
                            </a>
                        </div>
                    </main>
                     <footer class="text-center text-muted mt-3">
                        <small>&copy; <?php echo date("Y"); ?> School Voting System.</small>
                    </footer>
                </div>
            </div>
        </div>

         <div class="row justify-content-center feature-section">
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="card feature-card border-0">
                    <div class="card-body text-center p-4">
                        <div class="feature-icon"> <i class="bi bi-shield-lock-fill"></i> </div>
                        <h4 class="card-title mb-3">Secure Election</h4>
                        <p class="card-text">Ensures the integrity of every vote using secure codes and processes.</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="card feature-card border-0">
                    <div class="card-body text-center p-4">
                         <div class="feature-icon"> <i class="bi bi-graph-up-arrow"></i> </div>
                        <h4 class="card-title mb-3">Real-time Results</h4>
                        <p class="card-text">Monitor election progress and view results instantly as they come in.</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="card feature-card border-0">
                    <div class="card-body text-center p-4">
                         <div class="feature-icon"> <i class="bi bi-people-fill"></i> </div>
                        <h4 class="card-title mb-3">Easy Management</h4>
                        <p class="card-text">Effortlessly manage elections, positions, candidates, and voters.</p>
                    </div>
                </div>
            </div>
    </div>

    </div> <script nonce="<?php echo $nonce; ?>" src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
     <script nonce="<?php echo $nonce; ?>">
          document.addEventListener('DOMContentLoaded', function() {
             const usernameInput = document.getElementById('username');
             if(usernameInput && !usernameInput.value) usernameInput.focus();

             // Password Toggle Functionality
             const passwordInput = document.getElementById('password');
             const toggleBtn = document.getElementById('togglePasswordBtn');
             const toggleIcon = toggleBtn ? toggleBtn.querySelector('i') : null;

             if (passwordInput && toggleBtn && toggleIcon) {
                 toggleBtn.addEventListener('click', function() {
                     // Toggle the type attribute
                     const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                     passwordInput.setAttribute('type', type);

                     // Toggle the icon
                     toggleIcon.classList.toggle('bi-eye-fill');
                     toggleIcon.classList.toggle('bi-eye-slash-fill');

                     // Set aria-label for accessibility
                     this.setAttribute('aria-label', type === 'password' ? 'Show password' : 'Hide password');
                 });
             }
         });
     </script>
</body>
</html> 