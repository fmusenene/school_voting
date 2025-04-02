<?php
// Check if user is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: /school_voting/admin/login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - School Voting System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .sidebar {
            min-height: 100vh;
            background: #343a40;
            color: white;
            transition: all 0.3s;
            width: 16.666667%;
            position: fixed;
            left: 0;
            top: 0;
            z-index: 1000;
        }
        .nav-link {
            color: rgba(255,255,255,.75);
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            position: relative;
            width: 100%;
        }
        .nav-link i {
            font-size: 1.2rem;
            margin-right: 0.75rem;
            min-width: 1.2rem;
            text-align: center;
        }
        .nav-link span {
            transition: opacity 0.3s;
        }
        .nav-link:hover {
            color: white;
            background-color: rgba(255,255,255,.1);
        }
        .nav-link.active {
            color: white;
            background-color: #0d6efd;
        }
        .stat-card {
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .sidebar.collapsed {
            width: 4rem;
        }
        .sidebar.collapsed .nav-link {
            padding: 0.75rem;
            justify-content: center;
        }
        .sidebar.collapsed .nav-link span {
            opacity: 0;
            width: 0;
            display: none;
        }
        .sidebar.collapsed .nav-link i {
            margin-right: 0;
        }
        .sidebar.collapsed .full-title {
            display: none;
        }
        .sidebar.collapsed .short-title {
            display: block;
        }
        .full-title {
            display: block;
        }
        .short-title {
            display: none;
        }
        .main-content {
            transition: all 0.3s;
            margin-left: 16.666667%;
            padding: 20px;
            width: calc(100% - 16.666667%);
        }
        .main-content.expanded {
            margin-left: 4rem;
            width: calc(100% - 4rem);
        }
        .menu-btn {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
        }
        .menu-btn:hover {
            color: rgba(255,255,255,.75);
        }
        .navbar {
            position: fixed;
            top: 0;
            right: 0;
            left: 16.666667%;
            z-index: 999;
            transition: all 0.3s;
        }
        .navbar.expanded {
            left: 4rem;
        }
        body {
            padding-top: 56px;
        }
    </style>
</head>
<body>
    <!-- Top Navbar -->
    <nav class="navbar navbar-expand-lg bg-dark text-white">
        <div class="container-fluid">
            <div class="d-flex align-items-center">
                <button class="menu-btn" id="sidebarToggle">
                    <i class="bi bi-list"></i>
                </button>
            </div>
            <div class="ms-auto">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="sidebar" id="sidebar">
                <div class="p-3">
                    <h4 class="d-flex align-items-center">
                        <span class="full-title">School Voting</span>
                        <span class="short-title">SV</span>
                    </h4>
                </div>
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" href="/school_voting/admin/index.php">
                            <i class="bi bi-speedometer2"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'elections.php' ? 'active' : ''; ?>" href="/school_voting/admin/elections.php">
                            <i class="bi bi-calendar-check"></i>
                            <span>Elections</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'positions.php' ? 'active' : ''; ?>" href="/school_voting/admin/positions.php">
                            <i class="bi bi-person-badge"></i>
                            <span>Positions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'candidates.php' ? 'active' : ''; ?>" href="/school_voting/admin/candidates.php">
                            <i class="bi bi-people"></i>
                            <span>Candidates</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'voting_codes.php' ? 'active' : ''; ?>" href="/school_voting/admin/voting_codes.php">
                            <i class="bi bi-key"></i>
                            <span>Voting Codes</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'results.php' ? 'active' : ''; ?>" href="/school_voting/admin/results.php">
                            <i class="bi bi-graph-up"></i>
                            <span>Results</span>
                        </a>
                    </li>
                    <li class="nav-item mt-3">
                        <a class="nav-link" href="/school_voting/admin/logout.php">
                            <i class="bi bi-box-arrow-right"></i>
                            <span>Logout</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Main content -->
            <div class="main-content" id="mainContent"> 