<?php
session_start();
require_once "../config/database.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("location: login.php");
    exit();
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['delete_positions'])) {
        if (isset($_POST['selected_positions']) && is_array($_POST['selected_positions'])) {
            $selected_positions = array_map('intval', $_POST['selected_positions']);
            $positions_string = implode(',', $selected_positions);
            
            // Delete the selected positions
            $delete_sql = "DELETE FROM positions WHERE id IN ($positions_string)";
            if (mysqli_query($conn, $delete_sql)) {
                $success = "Selected positions deleted successfully!";
            } else {
                $error = "Error deleting positions: " . mysqli_error($conn);
            }
        }
    } else if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create':
                $election_id = $_POST['election_id'];
                $title = mysqli_real_escape_string($conn, $_POST['title']);
                $description = mysqli_real_escape_string($conn, $_POST['description']);
                
                $sql = "INSERT INTO positions (election_id, title, description) VALUES (?, ?, ?)";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "iss", $election_id, $title, $description);
                
                if (mysqli_stmt_execute($stmt)) {
                    $success = "Position created successfully!";
                } else {
                    $error = "Error creating position: " . mysqli_error($conn);
                }
                break;
                
            case 'update':
                $id = $_POST['id'];
                $title = mysqli_real_escape_string($conn, $_POST['title']);
                $description = mysqli_real_escape_string($conn, $_POST['description']);
                
                $sql = "UPDATE positions SET title = ?, description = ? WHERE id = ?";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "ssi", $title, $description, $id);
                
                if (mysqli_stmt_execute($stmt)) {
                    $success = "Position updated successfully!";
                } else {
                    $error = "Error updating position: " . mysqli_error($conn);
                }
                break;
                
            case 'delete':
                $id = $_POST['id'];
                
                $sql = "DELETE FROM positions WHERE id = ?";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "i", $id);
                
                if (mysqli_stmt_execute($stmt)) {
                    $success = "Position deleted successfully!";
                } else {
                    $error = "Error deleting position: " . mysqli_error($conn);
                }
                break;
        }
    }
}

// Get all elections for dropdown
$elections_sql = "SELECT * FROM elections ORDER BY title";
$elections_result = mysqli_query($conn, $elections_sql);

// Get all positions with election titles
$positions_sql = "SELECT p.*, e.title as election_title 
                  FROM positions p 
                  JOIN elections e ON p.election_id = e.id 
                  ORDER BY e.title, p.title";
$positions_result = mysqli_query($conn, $positions_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Positions - School Voting System</title>
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
                        <a class="nav-link" href="elections.php">
                            <i class="bi bi-calendar-check"></i> Elections
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="positions.php">
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
                    <h2>Manage Positions</h2>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createPositionModal">
                        <i class="bi bi-plus-circle"></i> Create New Position
                    </button>
                </div>

                <?php if (isset($success)): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <form method="POST">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th><input type="checkbox" class="select-all" onclick="toggleAll(this, 'position-checkbox')"> Select All</th>
                                            <th>Election</th>
                                            <th>Title</th>
                                            <th>Description</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($row = mysqli_fetch_assoc($positions_result)): ?>
                                            <tr>
                                                <td><input type="checkbox" name="selected_positions[]" value="<?php echo $row['id']; ?>" class="position-checkbox"></td>
                                                <td><?php echo htmlspecialchars($row['election_title']); ?></td>
                                                <td><?php echo htmlspecialchars($row['title']); ?></td>
                                                <td><?php echo htmlspecialchars($row['description']); ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-primary" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editPositionModal<?php echo $row['id']; ?>">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-danger" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#deletePositionModal<?php echo $row['id']; ?>">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                                <button type="submit" name="delete_positions" class="delete-btn" onclick="return confirm('Are you sure you want to delete the selected positions?')">Delete Selected</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Position Modal -->
    <div class="modal fade" id="createPositionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Position</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create">
                        
                        <div class="mb-3">
                            <label class="form-label">Election</label>
                            <select class="form-select" name="election_id" required>
                                <option value="">Select Election</option>
                                <?php 
                                mysqli_data_seek($elections_result, 0);
                                while ($election = mysqli_fetch_assoc($elections_result)): 
                                ?>
                                    <option value="<?php echo $election['id']; ?>">
                                        <?php echo htmlspecialchars($election['title']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Title</label>
                            <input type="text" class="form-control" name="title" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Position</button>
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
    </script>
</body>
</html> 