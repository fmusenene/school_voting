<?php
session_start();
require_once "../config/database.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("location: login.php");
    exit();
}

// Get statistics
$stats = [
    'elections' => $conn->query("SELECT COUNT(*) as count FROM elections")->fetch(PDO::FETCH_ASSOC)['count'],
    'active_elections' => $conn->query("SELECT COUNT(*) as count FROM elections WHERE status = 'active'")->fetch(PDO::FETCH_ASSOC)['count'],
    'total_votes' => $conn->query("SELECT COUNT(*) as count FROM votes")->fetch(PDO::FETCH_ASSOC)['count'],
    'unused_codes' => $conn->query("SELECT COUNT(*) as count FROM voting_codes WHERE is_used = 0")->fetch(PDO::FETCH_ASSOC)['count']
];
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
        }
        .nav-link.active {
            color: white;
        }
        .stat-card {
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .sidebar.collapsed {
            width: 4rem;
        }
        .sidebar.collapsed .nav-link span {
            opacity: 0;
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
                        <a class="nav-link active" href="index.php">
                            <i class="bi bi-speedometer2"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="elections.php">
                            <i class="bi bi-calendar-check"></i>
                            <span>Elections</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="positions.php">
                            <i class="bi bi-person-badge"></i>
                            <span>Positions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="candidates.php">
                            <i class="bi bi-people"></i>
                            <span>Candidates</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="voting_codes.php">
                            <i class="bi bi-key"></i>
                            <span>Voting Codes</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="results.php">
                            <i class="bi bi-graph-up"></i>
                            <span>Results</span>
                        </a>
                    </li>
                    <li class="nav-item mt-3">
                        <a class="nav-link" href="logout.php">
                            <i class="bi bi-box-arrow-right"></i>
                            <span>Logout</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Main content -->
            <div class="main-content" id="mainContent">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Dashboard</h2>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body">
                                <h5 class="card-title">Total Elections</h5>
                                <h2><?php echo $stats['elections']; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body">
                                <h5 class="card-title">Active Elections</h5>
                                <h2><?php echo $stats['active_elections']; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body">
                                <h5 class="card-title">Total Votes</h5>
                                <h2><?php echo $stats['total_votes']; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body">
                                <h5 class="card-title">Unused Codes</h5>
                                <h2><?php echo $stats['unused_codes']; ?></h2>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Elections -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Recent Elections</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Start Date</th>
                                        <th>End Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $sql = "SELECT * FROM elections ORDER BY created_at DESC LIMIT 5";
                                    $result = $conn->query($sql);
                                    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                                        echo "<tr>";
                                        echo "<td>" . htmlspecialchars($row['title']) . "</td>";
                                        echo "<td>" . date('M d, Y H:i', strtotime($row['start_date'])) . "</td>";
                                        echo "<td>" . date('M d, Y H:i', strtotime($row['end_date'])) . "</td>";
                                        echo "<td><span class='badge bg-" . 
                                            ($row['status'] == 'active' ? 'success' : 
                                            ($row['status'] == 'pending' ? 'warning' : 'secondary')) . 
                                            "'>" . ucfirst($row['status']) . "</span></td>";
                                        echo "<td>
                                            <a href='edit_election.php?id=" . $row['id'] . "' class='btn btn-sm btn-primary'>Edit</a>
                                            <a href='view_results.php?id=" . $row['id'] . "' class='btn btn-sm btn-info'>Results</a>
                                        </td>";
                                        echo "</tr>";
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
            document.getElementById('mainContent').classList.toggle('expanded');
            document.querySelector('.navbar').classList.toggle('expanded');
        });
    </script>
</body>
</html> 