<?php
session_start();
require_once "../config/database.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("location: login.php");
    exit();
}

// Handle deletion of selected elections
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_elections'])) {
    if (isset($_POST['selected_elections']) && is_array($_POST['selected_elections'])) {
        $selected_elections = array_map('intval', $_POST['selected_elections']);
        $elections_string = implode(',', $selected_elections);
        
        // Delete the selected elections
        $delete_sql = "DELETE FROM elections WHERE id IN ($elections_string)";
        if (mysqli_query($conn, $delete_sql)) {
            $success = "Selected elections deleted successfully!";
        } else {
            $error = "Error deleting elections: " . mysqli_error($conn);
        }
    }
}

// Handle election creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $title = mysqli_real_escape_string($conn, $_POST['title']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    
    // Set initial status based on dates
    $current_date = date('Y-m-d');
    $status = 'pending';
    if ($start_date <= $current_date && $end_date >= $current_date) {
        $status = 'active';
    } else if ($end_date < $current_date) {
        $status = 'completed';
    }

    $sql = "INSERT INTO elections (title, description, start_date, end_date, status) 
            VALUES ('$title', '$description', '$start_date', '$end_date', '$status')";
    
    if (mysqli_query($conn, $sql)) {
        $success = "Election created successfully!";
    } else {
        $error = "Error creating election: " . mysqli_error($conn);
    }
}

// Get all elections
$sql = "SELECT * FROM elections ORDER BY created_at DESC";
$result = mysqli_query($conn, $sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Elections - School Voting System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .sidebar {
            min-height: 100vh;
            background: #343a40;
            color: white;
        }
        .nav-link {
            color: rgba(255,255,255,.75);
        }
        .nav-link:hover {
            color: white;
        }
        .nav-link.active {
            color: white;
        }
        .select-all {
            margin-right: 5px;
        }
        .election-checkbox {
            margin-right: 5px;
        }
        .delete-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 px-0 position-fixed sidebar">
                <div class="p-3">
                    <h4>School Voting</h4>
                </div>
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="elections.php">
                            <i class="bi bi-calendar-check"></i> Elections
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="positions.php">
                            <i class="bi bi-person-badge"></i> Positions
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="candidates.php">
                            <i class="bi bi-people"></i> Candidates
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="voting_codes.php">
                            <i class="bi bi-key"></i> Voting Codes
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="results.php">
                            <i class="bi bi-graph-up"></i> Results
                        </a>
                    </li>
                    <li class="nav-item mt-3">
                        <a class="nav-link" href="logout.php">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Main content -->
            <div class="col-md-9 col-lg-10 ms-auto px-4 py-3">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Manage Elections</h2>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createElectionModal">
                        <i class="bi bi-plus-circle"></i> Add New Election
                    </button>
                </div>

                <?php if (isset($success)): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>

                <!-- Elections List -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <form method="POST">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th><input type="checkbox" class="select-all" onclick="toggleAll(this, 'election-checkbox')"> Select All</th>
                                            <th>Title</th>
                                            <th>Description</th>
                                            <th>Start Date</th>
                                            <th>End Date</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                            <tr>
                                                <td><input type="checkbox" name="selected_elections[]" value="<?php echo $row['id']; ?>" class="election-checkbox"></td>
                                                <td><?php echo htmlspecialchars($row['title']); ?></td>
                                                <td><?php echo htmlspecialchars($row['description']); ?></td>
                                                <td><?php echo date('M d, Y H:i', strtotime($row['start_date'])); ?></td>
                                                <td><?php echo date('M d, Y H:i', strtotime($row['end_date'])); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $row['status'] == 'active' ? 'success' : 
                                                            ($row['status'] == 'pending' ? 'warning' : 'secondary'); 
                                                    ?>">
                                                        <?php echo ucfirst($row['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-primary" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editElectionModal<?php echo $row['id']; ?>">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-danger" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#deleteElectionModal<?php echo $row['id']; ?>">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>

                                            <!-- Edit Election Modal -->
                                            <div class="modal fade" id="editElectionModal<?php echo $row['id']; ?>" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Edit Election</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <form method="post">
                                                            <div class="modal-body">
                                                                <input type="hidden" name="action" value="update">
                                                                <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                                                
                                                                <div class="mb-3">
                                                                    <label class="form-label">Title</label>
                                                                    <input type="text" class="form-control" name="title" 
                                                                           value="<?php echo htmlspecialchars($row['title']); ?>" required>
                                                                </div>
                                                                
                                                                <div class="mb-3">
                                                                    <label class="form-label">Description</label>
                                                                    <textarea class="form-control" name="description" rows="3"><?php echo htmlspecialchars($row['description']); ?></textarea>
                                                                </div>
                                                                
                                                                <div class="mb-3">
                                                                    <label class="form-label">Start Date</label>
                                                                    <input type="datetime-local" class="form-control" name="start_date" 
                                                                           value="<?php echo date('Y-m-d\TH:i', strtotime($row['start_date'])); ?>" required>
                                                                </div>
                                                                
                                                                <div class="mb-3">
                                                                    <label class="form-label">End Date</label>
                                                                    <input type="datetime-local" class="form-control" name="end_date" 
                                                                           value="<?php echo date('Y-m-d\TH:i', strtotime($row['end_date'])); ?>" required>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                <button type="submit" class="btn btn-primary">Update Election</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Delete Election Modal -->
                                            <div class="modal fade" id="deleteElectionModal<?php echo $row['id']; ?>" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Delete Election</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p>Are you sure you want to delete this election? This action cannot be undone.</p>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <form method="post">
                                                                <input type="hidden" name="action" value="delete">
                                                                <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                <button type="submit" class="btn btn-danger">Delete Election</button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                                <button type="submit" name="delete_elections" class="delete-btn" onclick="return confirm('Are you sure you want to delete the selected elections?')">Delete Selected</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Election Modal -->
    <div class="modal fade" id="createElectionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Election</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create">
                        <div class="mb-3">
                            <label for="title" class="form-label">Title</label>
                            <input type="text" class="form-control" id="title" name="title" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="datetime-local" class="form-control" id="start_date" name="start_date" required>
                        </div>
                        <div class="mb-3">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="datetime-local" class="form-control" id="end_date" name="end_date" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Election</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleAll(source, className) {
            var checkboxes = document.getElementsByClassName(className);
            for(var i=0; i<checkboxes.length; i++) {
                checkboxes[i].checked = source.checked;
            }
        }

        // Reset form when modal is closed
        document.getElementById('createElectionModal').addEventListener('hidden.bs.modal', function () {
            document.querySelector('#createElectionModal form').reset();
        });
    </script>
</body>
</html> 