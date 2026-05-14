<?php
// Password Reset Form Page
// This page is accessed via the email link
$token = $_GET['token'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - School Voting System</title>
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
        .reset-container {
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
        .reset-title {
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
        .password-requirements {
            margin-top: 0.75rem;
            padding: 0.75rem;
            background-color: #f8f9fc;
            border-radius: 0.35rem;
            font-size: 0.85rem;
        }
        .requirement-item {
            display: flex;
            align-items: center;
            margin-bottom: 0.4rem;
            color: var(--dark-color);
        }
        .requirement-item:last-child {
            margin-bottom: 0;
        }
        .requirement-icon {
            margin-right: 0.5rem;
            font-size: 0.9rem;
            color: #dc3545;
            transition: color 0.3s ease;
        }
        .requirement-icon.valid {
            color: #28a745;
        }
        .requirement-text {
            flex: 1;
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="text-center">
            <img src="assets/images/gombe-ss-logo.png" alt="School Logo" class="logo" onerror="this.style.display='none';">
            <h1 class="reset-title">Reset Password</h1>
        </div>

        <div id="message"></div>

        <form id="resetForm">
            <input type="hidden" id="token" value="<?php echo htmlspecialchars($token); ?>">
            
            <div class="mb-3">
                <label for="password" class="form-label">New Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <input type="password" class="form-control" id="password" required minlength="8">
                    <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
                <div class="password-requirements">
                    <div class="requirement-item">
                        <i class="bi bi-x-circle requirement-icon" id="req-length"></i>
                        <span class="requirement-text">At least 8 characters</span>
                    </div>
                    <div class="requirement-item">
                        <i class="bi bi-x-circle requirement-icon" id="req-uppercase"></i>
                        <span class="requirement-text">At least one uppercase letter</span>
                    </div>
                    <div class="requirement-item">
                        <i class="bi bi-x-circle requirement-icon" id="req-lowercase"></i>
                        <span class="requirement-text">At least one lowercase letter</span>
                    </div>
                    <div class="requirement-item">
                        <i class="bi bi-x-circle requirement-icon" id="req-number"></i>
                        <span class="requirement-text">At least one number</span>
                    </div>
                    <div class="requirement-item">
                        <i class="bi bi-x-circle requirement-icon" id="req-special"></i>
                        <span class="requirement-text">At least one special character</span>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label for="confirmPassword" class="form-label">Confirm Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <input type="password" class="form-control" id="confirmPassword" required minlength="8">
                </div>
            </div>

            <button type="submit" class="submit-button" id="submitBtn">
                <i class="bi bi-check-circle"></i> Reset Password
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
            const token = document.getElementById('token').value;
            
            if (!token) {
                showMessage('Invalid reset link. Please request a new password reset.', 'danger');
                document.getElementById('resetForm').style.display = 'none';
            }

            // Toggle password visibility
            document.getElementById('togglePassword').addEventListener('click', function() {
                const passwordInput = document.getElementById('password');
                const icon = this.querySelector('i');

                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    icon.classList.remove('bi-eye');
                    icon.classList.add('bi-eye-slash');
                } else {
                    passwordInput.type = 'password';
                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');
                }
            });

            // Password strength checker
            const passwordInput = document.getElementById('password');
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                checkPasswordRequirements(password);
            });

            function checkPasswordRequirements(password) {
                // Check length
                const hasLength = password.length >= 8;
                updateRequirement('req-length', hasLength);

                // Check uppercase
                const hasUppercase = /[A-Z]/.test(password);
                updateRequirement('req-uppercase', hasUppercase);

                // Check lowercase
                const hasLowercase = /[a-z]/.test(password);
                updateRequirement('req-lowercase', hasLowercase);

                // Check number
                const hasNumber = /[0-9]/.test(password);
                updateRequirement('req-number', hasNumber);

                // Check special character
                const hasSpecial = /[!@#$%^&*(),.?":{}|<>]/.test(password);
                updateRequirement('req-special', hasSpecial);

                return hasLength && hasUppercase && hasLowercase && hasNumber && hasSpecial;
            }

            function updateRequirement(elementId, isValid) {
                const element = document.getElementById(elementId);
                if (isValid) {
                    element.classList.remove('bi-x-circle');
                    element.classList.add('bi-check-circle-fill', 'valid');
                } else {
                    element.classList.remove('bi-check-circle-fill', 'valid');
                    element.classList.add('bi-x-circle');
                }
            }

            // Form submission
            document.getElementById('resetForm').addEventListener('submit', function(e) {
                e.preventDefault();

                const password = document.getElementById('password').value;
                const confirmPassword = document.getElementById('confirmPassword').value;

                // Check all password requirements
                const meetsRequirements = checkPasswordRequirements(password);
                if (!meetsRequirements) {
                    showMessage('Please ensure your password meets all requirements.', 'danger');
                    return;
                }

                if (password !== confirmPassword) {
                    showMessage('Passwords do not match.', 'danger');
                    return;
                }

                const submitBtn = document.getElementById('submitBtn');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="bi bi-spinner-border spin"></i> Processing...';

                fetch('api/reset_password.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        token: token,
                        password: password
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage(data.message, 'success');
                        document.getElementById('resetForm').style.display = 'none';
                        setTimeout(() => {
                            window.location.href = 'admin/login.php';
                        }, 2000);
                    } else {
                        showMessage(data.message, 'danger');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> Reset Password';
                    }
                })
                .catch(error => {
                    showMessage('An error occurred. Please try again.', 'danger');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> Reset Password';
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
