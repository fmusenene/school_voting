<?php
session_start();
require_once "../config/database.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("location: login.php");
    exit();
}

// Handle delete action
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    if ($_POST['action'] == "delete" && isset($_POST['position_id'])) {
        $position_id = (int)$_POST['position_id'];
        
        // Check if position has candidates
        $check_sql = "SELECT COUNT(*) as count FROM candidates WHERE position_id = ?";
        $check_stmt = mysqli_prepare($conn, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "i", $position_id);
        mysqli_stmt_execute($check_stmt);
        $result = mysqli_stmt_get_result($check_stmt);
        $row = mysqli_fetch_assoc($result);
        
        if ($row['count'] > 0) {
            $error = "Cannot delete position: There are candidates assigned to this position.";
        } else {
            $delete_sql = "DELETE FROM positions WHERE id = ?";
            $delete_stmt = mysqli_prepare($conn, $delete_sql);
            mysqli_stmt_bind_param($delete_stmt, "i", $position_id);
            
            if (mysqli_stmt_execute($delete_stmt)) {
                header("location: positions.php");
                exit();
            } else {
                $error = "Error deleting position: " . mysqli_error($conn);
            }
        }
    } else if ($_POST['action'] == "add") {
        $election_id = (int)$_POST['election_id'];
        $title = mysqli_real_escape_string($conn, $_POST['title']);
        $description = mysqli_real_escape_string($conn, $_POST['description']);
        
        $sql = "INSERT INTO positions (election_id, title, description) VALUES (?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "iss", $election_id, $title, $description);
        
        if (mysqli_stmt_execute($stmt)) {
            header("location: positions.php");
            exit();
        } else {
            $error = "Error creating position: " . mysqli_error($conn);
        }
    }
}

// Get all elections for dropdown
$elections_sql = "SELECT * FROM elections ORDER BY title";
$elections_result = mysqli_query($conn, $elections_sql);

// Get all positions with election titles
$sql = "SELECT p.*, e.title as election_title 
        FROM positions p 
        JOIN elections e ON p.election_id = e.id 
        ORDER BY e.title, p.title";
$result = mysqli_query($conn, $sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Positions - School Voting System</title>
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
        .modal-header-danger {
            background-color: #dc3545;
            color: white;
        }
        .modal-header-danger .btn-close {
            color: white;
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
                    <h2>Positions</h2>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPositionModal">
                        <i class="bi bi-plus-lg"></i> Add Position
                    </button>
                </div>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th style="width: 50px">
                                            <input type="checkbox" class="form-check-input" id="selectAll">
                                        </th>
                                        <th>Title</th>
                                        <th>Election</th>
                                        <th>Description</th>
                                        <th style="width: 150px">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="form-check-input position-checkbox" 
                                                       name="position_ids[]" value="<?php echo $row['id']; ?>">
                                            </td>
                                            <td><?php echo htmlspecialchars($row['title']); ?></td>
                                            <td><?php echo htmlspecialchars($row['election_title']); ?></td>
                                            <td><?php echo htmlspecialchars($row['description']); ?></td>
                                            <td>
                                                <div class="d-flex gap-2">
                                                    <button type="button" 
                                                            class="btn btn-sm btn-primary" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editPositionModal<?php echo $row['id']; ?>">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button type="button" 
                                                            class="btn btn-sm btn-danger" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#deletePositionModal<?php echo $row['id']; ?>">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Position Modal -->
    <div class="modal fade" id="addPositionModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Position</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        
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
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-lg"></i> Add Position
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit and Delete Modals -->
    <?php 
    mysqli_data_seek($result, 0);
    while ($row = mysqli_fetch_assoc($result)): 
    ?>
    <!-- Edit Modal -->
    <div class="modal fade" id="editPositionModal<?php echo $row['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Position</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="position_id" value="<?php echo $row['id']; ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">Election</label>
                            <select class="form-select" name="election_id" required>
                                <?php 
                                mysqli_data_seek($elections_result, 0);
                                while ($election = mysqli_fetch_assoc($elections_result)): 
                                ?>
                                    <option value="<?php echo $election['id']; ?>" 
                                            <?php echo $election['id'] == $row['election_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($election['title']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Title</label>
                            <input type="text" class="form-control" name="title" 
                                   value="<?php echo htmlspecialchars($row['title']); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3"><?php 
                                echo htmlspecialchars($row['description']); 
                            ?></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div class="modal fade" id="deletePositionModal<?php echo $row['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header modal-header-danger">
                    <h5 class="modal-title">Delete Position</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete the position:</p>
                    <p class="fw-bold"><?php echo htmlspecialchars($row['title']); ?></p>
                    <p class="text-danger">This action cannot be undone!</p>
                </div>
                <div class="modal-footer">
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="position_id" value="<?php echo $row['id']; ?>">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash"></i> Delete Position
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endwhile; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle select all checkbox
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.getElementsByClassName('position-checkbox');
            for (let checkbox of checkboxes) {
                checkbox.checked = this.checked;
            }
        });

        // Handle individual checkboxes
        document.getElementsByClassName('position-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const allChecked = Array.from(document.getElementsByClassName('position-checkbox'))
                    .every(cb => cb.checked);
                document.getElementById('selectAll').checked = allChecked;
            });
        });
    </script>
</body>
</html> 