<?php
// Forgot Password Request Page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - School Voting System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-color: #4e73df;
            --primary-dark: #2e59d9;
            --light-color: #f8f9fc;
            --white-color: #fff;
            --dark-color: #5a5c69;
            --border-color: #e3e6f0;
        }
        body {
            font-family: 'Nunito', sans-serif;
            background-color: var(--light-color);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .forgot-container {
            background: var(--white-color);
            padding: 2.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,.1);
            border-top: 5px solid var(--primary-color);
            max-width: 450px;
            width: 100%;
        }
        .logo {
            width: 80px;
            height: 80px;
            object-fit: contain;
            margin-bottom: 1rem;
        }
        .forgot-title {
            text-align: center;
            color: var(--dark-color);
            margin-bottom: 1.5rem;
            font-weight: 700;
            font-size: 1.5rem;
        }
        .form-label {
            font-weight: 600;
            font-size: 0.8rem;
            color: var(--dark-color);
            margin-bottom: 0.3rem;
            text-transform: uppercase;
        }
        .form-control {
            padding: 0.6rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 0.35rem;
            font-size: 0.95rem;
        }
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
        }
        .submit-button {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            width: 100%;
            border-radius: 50px;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-top: 1.5rem;
            transition: all 0.2s ease;
        }
        .submit-button:hover {
            background: var(--primary-dark);
        }
        .alert {
            padding: 0.9rem 1.1rem;
            margin-bottom: 1.25rem;
            border-radius: 0.35rem;
            font-size: 0.9rem;
        }
        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }
        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }
        .back-link {
            text-align: center;
            margin-top: 1.5rem;
        }
        .back-link a {
            color: var(--dark-color);
            text-decoration: none;
            font-size: 0.9rem;
        }
        .back-link a:hover {
            color: var(--primary-color);
        }
    </style>
</head>
<body>
    <div class="forgot-container">
        <div class="text-center">
            <img src="assets/images/gombe-ss-logo.png" alt="School Logo" class="logo" onerror="this.style.display='none';">
            <h1 class="forgot-title">Forgot Password</h1>
            <p class="text-muted mb-4">Enter your email address to receive a password reset link.</p>
        </div>

        <div id="message"></div>

        <form id="forgotForm">
            <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
                    <input type="email" class="form-control" id="email" required placeholder="admin@school.com">
                </div>
            </div>

            <button type="submit" class="submit-button" id="submitBtn">
                <i class="bi bi-send"></i> Send Reset Link
            </button>
        </form>

        <div class="back-link">
            <a href="admin/login.php">
                <i class="bi bi-arrow-left"></i> Back to Login
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Form submission
            document.getElementById('forgotForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                const email = document.getElementById('email').value;
                
                if (!email) {
                    showMessage('Please enter your email address.', 'danger');
                    return;
                }

                const submitBtn = document.getElementById('submitBtn');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="bi bi-spinner-border spin"></i> Sending...';

                fetch('api/request_password_reset.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        email: email
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let message = data.message;
                        // If reset link is provided, display it
                        if (data.reset_link) {
                            message += '<br><br><strong>Reset Link:</strong> <a href="' + data.reset_link + '" target="_blank">' + data.reset_link + '</a>';
                        }
                        showMessage(message, 'success');
                        document.getElementById('forgotForm').reset();
                    } else {
                        showMessage(data.message, 'danger');
                    }
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="bi bi-send"></i> Send Reset Link';
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('Network error: ' + error.message, 'danger');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="bi bi-send"></i> Send Reset Link';
                });
            });

            function showMessage(message, type) {
                const messageDiv = document.getElementById('message');
                messageDiv.innerHTML = `
                    <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                        <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}"></i>
                        ${message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                `;
            }
        });
    </script>
</body>
</html>
