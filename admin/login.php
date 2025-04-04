<?php
session_start();
require_once "../config/database.php";

// Check if user is already logged in
if (isset($_SESSION['admin_id'])) {
    header("Location: /school_voting/admin/index.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    $sql = "SELECT id, username, password FROM admins WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$username]);

    try {
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Fetch mode failed: " . $e->getMessage());
        die("Error fetching data: " . $e->getMessage());
    }

    //$row 
    if ($stmt->rowCount() > 0) {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $row = null;
    }
    // Check if the user exists and verify the password
    if ($row && password_verify($password, $row['password'])) {
        $_SESSION['admin_id'] = $row['id'];
        $_SESSION['admin_username'] = $row['username'];
        header("Location: /school_voting/admin/index.php");
        exit();
    } else {
        $error = "Invalid username or password.";
    }

    
    if ($row ) {
        if (password_verify($password, $row['password'])) {
            $_SESSION['admin_id'] = $row['id'];
            $_SESSION['admin_username'] = $row['username'];
            header("Location: /school_voting/admin/index.php");
            exit();
        }
    }
    $error = "Invalid username or password.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - School Voting System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            background: #f8f9fa url('../assets/images/background-logos.png') center/cover no-repeat fixed;
        }
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(248, 249, 250, 0.92);
            z-index: 0;
        }
        .login-container {
            background: #ffffff;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 0 25px rgba(0,0,0,0.1);
            width: 100%;
            position: relative;
            z-index: 1;
        }
        .logo-container {
            text-align: center;
            margin-bottom: 1.5rem;
            padding: 1rem;
        }
        .logo {
            width: 120px;
            height: 120px;
            object-fit: contain;
            margin-bottom: 1rem;
        }
        .login-title {
            text-align: center;
            color: #2c3e50;
            margin-bottom: 1.5rem;
            font-size: 1.75rem;
            font-weight: 600;
        }
        .form-group {
            margin-bottom: 1.25rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #495057;
            font-size: 0.95rem;
            font-weight: 500;
        }
        .form-group input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1.5px solid #e9ecef;
            border-radius: 8px;
            box-sizing: border-box;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        .form-group input:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);
            outline: none;
        }
        .submit-button {
            background: #0d6efd;
            color: white;
            border: none;
            padding: 0.875rem;
            width: 100%;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s ease;
            margin-top: 1.5rem;
        }
        .submit-button:hover {
            background: #0b5ed7;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.15);
        }
        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 8px;
            color: #721c24;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
        }
        /* Feature Cards Animation */
        .feature-card {
            transition: all 0.3s ease-in-out;
            background: #fff;
            overflow: hidden;
            position: relative;
            border-radius: 15px;
            height: 100%;
        }
        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 1rem 3rem rgba(0,0,0,0.15)!important;
        }
        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(
                90deg,
                transparent,
                rgba(255,255,255,0.2),
                transparent
            );
            transition: 0.5s;
        }
        .feature-card:hover::before {
            left: 100%;
        }
        .feature-icon {
            transition: all 0.3s ease;
            width: 80px;
            height: 80px;
            background: rgba(13, 110, 253, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }
        .feature-icon i {
            font-size: 2.5rem;
            color: #0d6efd;
        }
        .feature-card:hover .feature-icon {
            transform: scale(1.1) rotate(5deg);
            background: rgba(13, 110, 253, 0.15);
        }
        .feature-card .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #2c3e50;
            transition: all 0.3s ease;
        }
        .feature-card:hover .card-title {
            color: #0d6efd;
        }
        .feature-card .card-text {
            color: #6c757d;
            font-size: 0.95rem;
            line-height: 1.6;
        }
        @media (max-width: 768px) {
            .feature-card {
                margin-bottom: 1.5rem;
            }
            .login-container {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-4">
                <div class="login-container">
                    <div class="logo-container">
                        <img src="../assets/images/gombe-ss-logo.png" alt="Gombe S.S." class="logo">
                        <h2 class="login-title">Admin Login</h2>
                    </div>
                    
                    <?php if (isset($error)): ?>
                        <div class="alert"><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                        <div class="form-group">
                            <label for="username">Username</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0">
                                    <i class="bi bi-person text-muted"></i>
                                </span>
                                <input type="text" id="username" name="username" class="form-control border-start-0" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="password">Password</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0">
                                    <i class="bi bi-lock text-muted"></i>
                                </span>
                                <input type="password" id="password" name="password" class="form-control border-start-0" required>
                            </div>
                        </div>
                        <button type="submit" class="submit-button">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Login
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Feature Cards -->
        <div class="row justify-content-center mt-5">
            <div class="col-md-4 mb-4">
                <div class="card feature-card border-0 shadow-sm">
                    <div class="card-body text-center p-4">
                        <div class="feature-icon">
                            <i class="bi bi-shield-lock"></i>
                        </div>
                        <h4 class="card-title mb-3">Secure Election</h4>
                        <p class="card-text">
                            Advanced security measures ensure the integrity of every vote and protect against unauthorized access.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-4">
                <div class="card feature-card border-0 shadow-sm">
                    <div class="card-body text-center p-4">
                        <div class="feature-icon">
                            <i class="bi bi-graph-up"></i>
                        </div>
                        <h4 class="card-title mb-3">Real-time Results</h4>
                        <p class="card-text">
                            Get instant access to election results as votes are cast, with live updates and accurate statistics.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-4">
                <div class="card feature-card border-0 shadow-sm">
                    <div class="card-body text-center p-4">
                        <div class="feature-icon">
                            <i class="bi bi-check2-circle"></i>
                        </div>
                        <h4 class="card-title mb-3">Fair & Transparent</h4>
                        <p class="card-text">
                            Every vote counts equally with complete transparency in the voting process and verifiable results.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 