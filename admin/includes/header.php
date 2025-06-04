<?php
// Session should be started before this file is included by the parent page
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in - Essential security check
if (!isset($_SESSION['admin_id'], $_SESSION['admin_username'])) {
    session_unset(); session_destroy();
    header("Location: login.php");
    exit();
}

// Generate CSP nonce if available in session
$nonce = isset($_SESSION['csp_nonce']) ? htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8') : '';

// Get admin username for display
$admin_username = htmlspecialchars($_SESSION['admin_username'], ENT_QUOTES, 'UTF-8');
$admin_id = $_SESSION['admin_id']; // Needed for form submission later

// Determine active page for sidebar highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - School Voting System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">

    <style nonce="<?php echo $nonce; ?>">
        :root {
            --primary-hue: 226; --primary-color: hsl(var(--primary-hue), 76%, 58%); --primary-light: hsl(var(--primary-hue), 76%, 95%); --primary-dark: hsl(var(--primary-hue), 70%, 48%);
            --secondary-color: #858796; --light-color: #f8f9fc; --white-color: #fff; --dark-color: #5a5c69; --gray-800: #5a5c69; --gray-900: #3a3b45; --border-color: #e3e6f0;
            --sidebar-bg: var(--gray-900); --sidebar-link-color: rgba(255,255,255,.8); --sidebar-link-hover-bg: rgba(255,255,255,.05); --sidebar-link-active-bg: var(--primary-color);
            --navbar-height: 56px; --sidebar-width: 240px; --sidebar-width-collapsed: 64px;
            --font-family-primary: "Poppins", sans-serif; --font-family-secondary: "Nunito", sans-serif;
            --shadow-sm: 0 .125rem .25rem rgba(0,0,0,.075); --shadow: 0 .15rem 1.75rem 0 rgba(58,59,69,.15); --border-radius: .35rem; --border-radius-lg: .5rem;
        }
        body { font-family: var(--font-family-secondary); padding-top: var(--navbar-height); background-color: var(--light-color); }

        /* Sidebar */
        .sidebar { width: var(--sidebar-width); height: 100vh; position: fixed; top: 0; left: 0; z-index: 1030; background-color: var(--sidebar-bg); color: var(--sidebar-link-color); transition: width 0.25s ease-in-out; overflow-x: hidden; display: flex; flex-direction: column; }
        .sidebar-header { padding: 0.875rem 1.25rem; display: flex; align-items: center; white-space: nowrap; border-bottom: 1px solid rgba(255,255,255,.1); }
        .sidebar-logo { font-size: 1.5rem; font-weight: 700; color: var(--white-color); margin-right: 0.5rem; }
        .sidebar-title { font-family: var(--font-family-primary); font-size: 1.1rem; font-weight: 600; color: var(--white-color); opacity: 1; transition: opacity 0.2s ease-out; }
        .sidebar-nav { padding: 1rem 0; flex-grow: 1; overflow-y: auto; }
        .sidebar .nav-link { color: var(--sidebar-link-color); display: flex; align-items: center; padding: 0.7rem 1.25rem; white-space: nowrap; transition: background-color 0.2s ease, color 0.2s ease; }
        .sidebar .nav-link i { font-size: 1.1rem; margin-right: 0.8rem; width: 1.5em; text-align: center; flex-shrink: 0; transition: margin 0.25s ease-in-out; }
        .sidebar .nav-link span { opacity: 1; transition: opacity 0.2s ease-out; font-size: 0.9rem; font-weight: 600; }
        .sidebar .nav-link:hover { color: var(--white-color); background-color: var(--sidebar-link-hover-bg); }
        .sidebar .nav-link.active { color: var(--white-color); background-color: var(--sidebar-link-active-bg); font-weight: 700; }
        .sidebar .nav-link.logout { color: #ffc107; } .sidebar .nav-link.logout:hover { background-color: rgba(255, 193, 7, 0.1); }
        /* Collapsed Sidebar */
        .sidebar.collapsed { width: var(--sidebar-width-collapsed); } .sidebar.collapsed .sidebar-header { justify-content: center; } .sidebar.collapsed .sidebar-title { opacity: 0; width: 0; overflow: hidden; }
        .sidebar.collapsed .nav-link { justify-content: center; padding: 0.75rem; } .sidebar.collapsed .nav-link i { margin-right: 0; } .sidebar.collapsed .nav-link span { opacity: 0; width: 0; overflow: hidden; }

        /* Main Content Area */
        .main-content { transition: margin-left 0.25s ease-in-out, width 0.25s ease-in-out; margin-left: var(--sidebar-width); width: calc(100% - var(--sidebar-width)); padding: 0; }
        .main-content.expanded { margin-left: var(--sidebar-width-collapsed); width: calc(100% - var(--sidebar-width-collapsed)); }

        /* Top Navbar */
        .navbar { position: fixed; top: 0; right: 0; height: var(--navbar-height); left: var(--sidebar-width); z-index: 1020; transition: left 0.25s ease-in-out, width 0.25s ease-in-out; width: calc(100% - var(--sidebar-width)); box-shadow: var(--shadow-sm); padding: 0 1.5rem; }
        .navbar.expanded { left: var(--sidebar-width-collapsed); width: calc(100% - var(--sidebar-width-collapsed)); }
        .navbar .menu-btn { background: none; border: none; color: rgba(255,255,255,.8); font-size: 1.4rem; cursor: pointer; padding: .25rem .5rem; margin-right: .5rem; line-height: 1; }
        .navbar .menu-btn:hover { color: var(--white-color); } .navbar .menu-btn:focus { box-shadow: none; }

        /* User Dropdown */
        .navbar .dropdown-toggle::after { display: none; }
        .navbar .nav-link { color: rgba(255,255,255,.8); display: flex; align-items: center; } .navbar .nav-link:hover { color: var(--white-color); }
        .navbar .dropdown-menu { border-radius: var(--border-radius); border: 1px solid var(--border-color); box-shadow: var(--shadow); margin-top: .5rem !important; }
        .navbar .dropdown-item { font-size: 0.9rem; padding: .5rem 1rem; display: flex; align-items: center;}
        .navbar .dropdown-item i { width: 1.5em; margin-right: .4rem; color: var(--secondary-color); text-align: center; }
        .navbar .dropdown-item:active { background-color: var(--primary-color); color: var(--white-color); }
        .navbar .dropdown-divider { border-top-color: var(--border-color); }
        .user-avatar-icon { font-size: 1.5rem; margin-left: 0.5rem; }

        /* Profile Modal Styles */
        .modal-header { background-color: var(--primary-light); border-bottom: 1px solid var(--border-color); }
        .modal-title { font-family: var(--font-family-primary); color: var(--primary-dark); }
        .modal-body { padding: 1.5rem 2rem; } /* More padding */
        .modal-body .form-label { font-size: .85rem; font-weight: 600; color: var(--dark-color); margin-bottom: .3rem;}
        .modal-body .form-control, .modal-body .input-group-text { font-size: .95rem; }
        .password-toggle-btn { cursor: pointer; }
        .form-section-divider { margin: 1.8rem 0 1.5rem 0; border-top: 1px dashed var(--border-color); position: relative; }
        .form-section-divider span { position: absolute; top: -0.7em; left: 50%; transform: translateX(-50%); background: var(--white-color); padding: 0 0.8em; color: var(--secondary-color); font-size: 0.8rem; font-weight: 600; text-transform: uppercase; }
        .modal-footer { background-color: var(--gray-100); border-top: 1px solid var(--border-color); }

        /* Password toggle styling */
        .password-input-group { position: relative; }
        .password-toggle-btn-modal {
            position: absolute; right: 1px; top: 1px; bottom: 1px; z-index: 5;
            height: calc(100% - 2px); border: none; background: transparent; padding: 0 0.75rem;
            color: var(--secondary-color); cursor: pointer; display: flex; align-items: center;
        }
        .password-toggle-btn-modal:hover { color: var(--primary-color); }
        .password-input-group .form-control { padding-right: 2.8rem; } /* Space for button */


    </style>
</head>
<body>
    <nav class="navbar navbar-expand navbar-dark bg-dark static-top shadow-sm">
        <button class="btn btn-link btn-sm text-white order-1 order-sm-0" id="sidebarToggle" aria-label="Toggle navigation">
            <i class="bi bi-list fs-4"></i>
        </button>

         <a class="navbar-brand d-none d-md-inline-block ms-3 me-auto" href="index.php">Admin Panel</a>

        <ul class="navbar-nav ms-auto align-items-center">
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="d-none d-sm-inline me-2"><?php echo $admin_username; ?></span>
                    <i class="bi bi-person-circle user-avatar-icon"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li>
                         
                        <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#profileModal">
                            <i class="bi bi-person-badge-fill"></i> Profile Settings
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item text-danger" href="logout.php">
                             <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </li>
                </ul>
            </li>
        </ul>
    </nav>

    <div class="d-flex">
        <div class="sidebar d-flex flex-column flex-shrink-0" id="sidebar">
             <div class="sidebar-header">
                <span class="sidebar-logo"><i class="bi bi-clipboard-check-fill"></i></span> 
                <span class="sidebar-title">School Voting</span>
            </div>
            <ul class="nav nav-pills flex-column sidebar-nav mb-auto">
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'index.php' ? 'active' : ''; ?>" href="index.php">
                        <i class="bi bi-speedometer2"></i> <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'elections.php' ? 'active' : ''; ?>" href="elections.php">
                        <i class="bi bi-calendar-event-fill"></i> <span>Elections</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'positions.php' ? 'active' : ''; ?>" href="positions.php">
                        <i class="bi bi-tag-fill"></i> <span>Positions</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'candidates.php' ? 'active' : ''; ?>" href="candidates.php">
                        <i class="bi bi-people-fill"></i> <span>Candidates</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'voting_codes.php' ? 'active' : ''; ?>" href="voting_codes.php">
                        <i class="bi bi-key-fill"></i> <span>Voting Codes</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'results.php' ? 'active' : ''; ?>" href="results.php">
                        <i class="bi bi-bar-chart-line-fill"></i> <span>Results</span>
                    </a>
                </li>
            </ul>
            <div class="sidebar-footer border-top p-3 mt-auto">
                 <a class="nav-link logout p-2 justify-content-center" href="logout.php" title="Logout">
                    <i class="bi bi-box-arrow-right"></i>
                    <span class="ms-2">Logout</span>
                </a>
            </div>
        </div>

        <div class="main-content" id="mainContent">
            <div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="profileModalLabel"><i class="bi bi-person-fill-gear me-2"></i>Profile Settings</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form id="profileForm" novalidate>
                            <div class="modal-body">
                                <div id="modalProfileAlertPlaceholder"></div> 

                                <input type="hidden" name="admin_id" value="<?php echo $admin_id; ?>"> 

                                <h6 class="mb-3 text-primary">Account Information</h6>
                                <div class="mb-3">
                                    <label for="profileUsername" class="form-label">Username</label>
                                    <input type="text" class="form-control" id="profileUsername" name="username" value="<?php echo $admin_username; ?>" required>
                                     <div class="invalid-feedback">Username cannot be empty.</div>
                                     <small class="form-text text-muted">You can update your username here.</small>
                                </div>



                                 <div class="form-section-divider"><span>Change Password</span></div>

                                 <div class="mb-3">
                                     <label for="currentPassword" class="form-label">Current Password</label>
                                     <div class="input-group password-input-group">
                                         <input type="password" class="form-control" id="currentPassword" name="current_password" autocomplete="current-password" aria-describedby="currentPasswordHelp">
                                         <button class="password-toggle-btn-modal" type="button" data-target="currentPassword">
                                             <i class="bi bi-eye-fill"></i>
                                         </button>
                                     </div>
                                      <small id="currentPasswordHelp" class="form-text text-muted">Required only if changing password.</small>
                                 </div>
                                 <div class="row g-3">
                                     <div class="col-md-6 mb-3">
                                         <label for="newPassword" class="form-label">New Password</label>
                                         <div class="input-group password-input-group">
                                            <input type="password" class="form-control" id="newPassword" name="new_password" autocomplete="new-password" aria-describedby="newPasswordHelp">
                                            <button class="password-toggle-btn-modal" type="button" data-target="newPassword">
                                                <i class="bi bi-eye-fill"></i>
                                            </button>
                                         </div>
                                         <small id="newPasswordHelp" class="form-text text-muted">Leave blank to keep current password.</small>
                                          <div class="invalid-feedback" id="newPasswordError"></div>
                                     </div>
                                     <div class="col-md-6 mb-3">
                                         <label for="confirmPassword" class="form-label">Confirm New Password</label>
                                         <div class="input-group password-input-group">
                                             <input type="password" class="form-control" id="confirmPassword" name="confirm_password" autocomplete="new-password">
                                             <button class="password-toggle-btn-modal" type="button" data-target="confirmPassword">
                                                 <i class="bi bi-eye-fill"></i>
                                             </button>
                                         </div>
                                         <div class="invalid-feedback" id="confirmPasswordError">Passwords do not match.</div>
                                     </div>
                                 </div>

                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary" id="saveProfileBtn">
                                    <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
                                    <i class="bi bi-save-fill me-1"></i> Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

<script nonce="<?php echo $nonce; ?>">
document.addEventListener('DOMContentLoaded', function() {
    // Profile Form Handling
    const profileForm = document.getElementById('profileForm');
    const saveProfileBtn = document.getElementById('saveProfileBtn');
    const modalAlertPlaceholder = document.getElementById('modalProfileAlertPlaceholder');
    const userDropdownName = document.querySelector('#userDropdown .d-none.d-sm-inline');
    const profileModal = document.getElementById('profileModal');
    const bsProfileModal = new bootstrap.Modal(profileModal);

    // Password toggle functionality
    document.querySelectorAll('.password-toggle-btn-modal').forEach(button => {
        button.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const input = document.getElementById(targetId);
            const icon = this.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('bi-eye-fill');
                icon.classList.add('bi-eye-slash-fill');
            } else {
                input.type = 'password';
                icon.classList.remove('bi-eye-slash-fill');
                icon.classList.add('bi-eye-fill');
            }
        });
    });

    // Show alert in modal
    function showModalAlert(message, type) {
        modalAlertPlaceholder.innerHTML = `
            <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>`;
    }

    if (profileForm) {
        profileForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            // Clear previous alerts
            modalAlertPlaceholder.innerHTML = '';
            
            // Show loading state
            const spinner = saveProfileBtn.querySelector('.spinner-border');
            const icon = saveProfileBtn.querySelector('.bi');
            spinner.classList.remove('d-none');
            icon.classList.add('d-none');
            saveProfileBtn.disabled = true;

            try {
                const response = await fetch('update_profile.php', {
                    method: 'POST',
                    body: new FormData(profileForm)
                });

                const data = await response.json();

                if (data.success) {
                    showModalAlert(data.message, 'success');
                    
                    // Update username in navbar if it was changed
                    if (data.username && userDropdownName) {
                        userDropdownName.textContent = data.username;
                    }

                    // Reset password fields
                    document.getElementById('currentPassword').value = '';
                    document.getElementById('newPassword').value = '';
                    document.getElementById('confirmPassword').value = '';

                    // Close modal after 1.5 seconds on success
                    setTimeout(() => {
                        bsProfileModal.hide();
                    }, 1500);
                } else {
                    showModalAlert(data.message, 'danger');
                }
            } catch (error) {
                showModalAlert('An error occurred. Please try again.', 'danger');
                console.error('Profile update error:', error);
            } finally {
                // Reset loading state
                spinner.classList.add('d-none');
                icon.classList.remove('d-none');
                saveProfileBtn.disabled = false;
            }
        });
    }
});
</script>
