<?php
session_start();
require_once "../config/database.php";
require_once "../config/update_election_status.php";

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("location: login.php");
    exit();
}

// Update election statuses
updateElectionStatus($conn);

// Handle election creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_election'])) {
    // Get and sanitize input
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $start_date = trim($_POST['start_date']);
    $end_date = trim($_POST['end_date']);
    
    // Validate input
    if (empty($title) || empty($description) || empty($start_date) || empty($end_date)) {
        $error = "All fields are required.";
    } else {
        // Validate dates
        if (strtotime($end_date) < strtotime($start_date)) {
            $error = "End date cannot be before start date.";
        } else {
            // Set initial status based on dates
            $current_date = date('Y-m-d');
            $status = 'pending';
            if ($start_date <= $current_date && $end_date >= $current_date) {
                $status = 'active';
            } else if ($end_date < $current_date) {
                $status = 'completed';
            }
            
            // Prepare SQL statement
            $sql = "INSERT INTO elections (title, description, start_date, end_date, status) 
                    VALUES (?, ?, ?, ?, ?)";
            
            // Create prepared statement
            $stmt = mysqli_prepare($conn, $sql);
            
            if ($stmt) {
                // Bind parameters
                mysqli_stmt_bind_param($stmt, "sssss", $title, $description, $start_date, $end_date, $status);
                
                // Execute the statement
                if (mysqli_stmt_execute($stmt)) {
                    $success = "Election created successfully!";
                    // Redirect to refresh the page and show the new election
                    header("Location: manage_elections.php");
                    exit();
                } else {
                    $error = "Error creating election: " . mysqli_stmt_error($stmt);
                }
                
                // Close statement
                mysqli_stmt_close($stmt);
            } else {
                $error = "Error preparing statement: " . mysqli_error($conn);
            }
        }
    }
}

// Handle election deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_elections'])) {
    $selected_elections = isset($_POST['selected_elections']) ? $_POST['selected_elections'] : [];
    
    if (!empty($selected_elections)) {
        $ids = array_map('intval', $selected_elections);
        $ids_string = implode(',', $ids);
        
        // Delete related records first
        mysqli_query($conn, "DELETE FROM votes WHERE election_id IN ($ids_string)");
        mysqli_query($conn, "DELETE FROM voting_codes WHERE election_id IN ($ids_string)");
        
        // Then delete the elections
        $delete_sql = "DELETE FROM elections WHERE id IN ($ids_string)";
        if (mysqli_query($conn, $delete_sql)) {
            $success = "Selected elections deleted successfully!";
        } else {
            $error = "Error deleting elections: " . mysqli_error($conn);
        }
    }
}

// Fetch all elections
$sql = "SELECT * FROM elections ORDER BY created_at DESC";
$result = mysqli_query($conn, $sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Elections - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .status-pending { color: #ffc107; }
        .status-active { color: #28a745; }
        .status-completed { color: #6c757d; }
        .checkbox-cell { width: 40px; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 d-md-block bg-dark sidebar collapse">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link active text-white" href="manage_elections.php">
                                <i class="bi bi-calendar-event"></i> Manage Elections
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white" href="manage_positions.php">
                                <i class="bi bi-person-badge"></i> Manage Positions
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white" href="manage_candidates.php">
                                <i class="bi bi-people"></i> Manage Candidates
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white" href="manage_voting_codes.php">
                                <i class="bi bi-key"></i> Manage Voting Codes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white" href="view_results.php">
                                <i class="bi bi-graph-up"></i> View Results
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white" href="logout.php">
                                <i class="bi bi-box-arrow-right"></i> Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Manage Elections</h1>
                </div>

                <?php if (isset($success)): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>

                <!-- Create Election Form -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Create New Election</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" action="">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="title" class="form-label">Election Title</label>
                                    <input type="text" class="form-control" id="title" name="title" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="description" class="form-label">Description</label>
                                    <input type="text" class="form-control" id="description" name="description" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="start_date" class="form-label">Start Date</label>
                                    <input type="date" class="form-control" id="start_date" name="start_date" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="end_date" class="form-label">End Date</label>
                                    <input type="date" class="form-control" id="end_date" name="end_date" required>
                                </div>
                            </div>
                            <button type="submit" name="create_election" class="btn btn-primary">Create Election</button>
                        </form>
                    </div>
                </div>

                <!-- Elections List -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Current Elections</h5>
                        <form method="post" action="" class="d-inline" onsubmit="return confirm('Are you sure you want to delete the selected elections?');">
                            <button type="submit" name="delete_elections" class="btn btn-danger">
                                <i class="bi bi-trash"></i> Delete Selected
                            </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th class="checkbox-cell">
                                            <input type="checkbox" class="form-check-input" id="select-all">
                                        </th>
                                        <th>Title</th>
                                        <th>Description</th>
                                        <th>Start Date</th>
                                        <th>End Date</th>
                                        <th>Status</th>
                                        <th>Created At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                        <tr>
                                            <td class="checkbox-cell">
                                                <input type="checkbox" class="form-check-input election-checkbox" 
                                                       name="selected_elections[]" value="<?php echo $row['id']; ?>">
                                            </td>
                                            <td><?php echo htmlspecialchars($row['title']); ?></td>
                                            <td><?php echo htmlspecialchars($row['description']); ?></td>
                                            <td><?php echo date('Y-m-d', strtotime($row['start_date'])); ?></td>
                                            <td><?php echo date('Y-m-d', strtotime($row['end_date'])); ?></td>
                                            <td>
                                                <span class="status-<?php echo strtolower($row['status']); ?>">
                                                    <?php echo ucfirst($row['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('Y-m-d H:i:s', strtotime($row['created_at'])); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('select-all').addEventListener('change', function() {
            const checkboxes = document.getElementsByClassName('election-checkbox');
            for (let checkbox of checkboxes) {
                checkbox.checked = this.checked;
            }
        });
    </script>
</body>
</html> 