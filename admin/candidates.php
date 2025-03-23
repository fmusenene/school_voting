<?php
session_start();
require_once "../config/database.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("location: login.php");
    exit();
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    if ($_POST['action'] == "add" || $_POST['action'] == "edit") {
        $position_id = (int)$_POST['position_id'];
        $name = mysqli_real_escape_string($conn, $_POST['name']);
        $description = mysqli_real_escape_string($conn, $_POST['description']);
        $image_path = "";
        
        // Handle image upload
        if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
            $target_dir = "../uploads/candidates/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION));
            $new_filename = uniqid() . '.' . $file_extension;
            $target_file = $target_dir . $new_filename;
            
            // Check if image file is actual image
            $check = getimagesize($_FILES["image"]["tmp_name"]);
            if ($check !== false) {
                if (move_uploaded_file($_FILES["image"]["tmp_name"], $target_file)) {
                    $image_path = "uploads/candidates/" . $new_filename;
                }
            }
        }
        
        if ($_POST['action'] == "add") {
            $sql = "INSERT INTO candidates (position_id, name, description, image_path) VALUES (?, ?, ?, ?)";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "isss", $position_id, $name, $description, $image_path);
        } else {
            $candidate_id = (int)$_POST['candidate_id'];
            if ($image_path) {
                $sql = "UPDATE candidates SET position_id = ?, name = ?, description = ?, image_path = ? WHERE id = ?";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "isssi", $position_id, $name, $description, $image_path, $candidate_id);
            } else {
                $sql = "UPDATE candidates SET position_id = ?, name = ?, description = ? WHERE id = ?";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "issi", $position_id, $name, $description, $candidate_id);
            }
        }
        
        if (mysqli_stmt_execute($stmt)) {
            header("location: candidates.php");
            exit();
        } else {
            $error = "Error " . ($_POST['action'] == "add" ? "creating" : "updating") . " candidate: " . mysqli_error($conn);
        }
    } else if ($_POST['action'] == "delete" && isset($_POST['candidate_id'])) {
        $candidate_id = (int)$_POST['candidate_id'];
        
        // Get image path before deleting
        $image_sql = "SELECT image_path FROM candidates WHERE id = ?";
        $image_stmt = mysqli_prepare($conn, $image_sql);
        mysqli_stmt_bind_param($image_stmt, "i", $candidate_id);
        mysqli_stmt_execute($image_stmt);
        $image_result = mysqli_stmt_get_result($image_stmt);
        $image_row = mysqli_fetch_assoc($image_result);
        
        // Delete the candidate
        $delete_sql = "DELETE FROM candidates WHERE id = ?";
        $delete_stmt = mysqli_prepare($conn, $delete_sql);
        mysqli_stmt_bind_param($delete_stmt, "i", $candidate_id);
        
        if (mysqli_stmt_execute($delete_stmt)) {
            // Delete the image file if it exists
            if ($image_row['image_path']) {
                $image_file = "../" . $image_row['image_path'];
                if (file_exists($image_file)) {
                    unlink($image_file);
                }
            }
            header("location: candidates.php");
            exit();
        } else {
            $error = "Error deleting candidate: " . mysqli_error($conn);
        }
    }
}

// Get all positions for dropdown
$positions_sql = "SELECT p.*, e.title as election_title 
                 FROM positions p 
                 JOIN elections e ON p.election_id = e.id 
                 ORDER BY e.title, p.title";
$positions_result = mysqli_query($conn, $positions_sql);

// Get all candidates with position and election titles
$candidates_sql = "SELECT c.*, p.title as position_title, e.title as election_title 
                   FROM candidates c 
                   JOIN positions p ON c.position_id = p.id 
                   JOIN elections e ON p.election_id = e.id 
                   ORDER BY e.title, p.title, c.name";
$candidates_result = mysqli_query($conn, $candidates_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Candidates - School Voting System</title>
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
        .candidate-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .no-image {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            font-size: 1.5rem;
            margin: 0 auto;
        }
        .table > :not(caption) > * > * {
            padding: 1rem;
            vertical-align: middle;
        }
        .btn-group {
            gap: 0.25rem;
        }
        .table-hover tbody tr:hover {
            background-color: rgba(0,0,0,.02);
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
                        <a class="nav-link" href="positions.php">
                            <i class="bi bi-person-badge"></i> Positions
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="candidates.php">
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
                    <h2>Candidates</h2>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCandidateModal">
                        <i class="bi bi-plus-lg"></i> Add Candidate
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
                                        <th style="width: 100px">Photo</th>
                                        <th>Name</th>
                                        <th>Position</th>
                                        <th>Election</th>
                                        <th>Description</th>
                                        <th style="width: 150px">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($candidate = mysqli_fetch_assoc($candidates_result)): ?>
                                        <tr>
                                            <td class="text-center">
                                                <?php if ($candidate['image_path']): ?>
                                                    <img src="../<?php echo htmlspecialchars($candidate['image_path']); ?>" 
                                                         alt="<?php echo htmlspecialchars($candidate['name']); ?>"
                                                         class="candidate-image">
                                                <?php else: ?>
                                                    <div class="no-image">
                                                        <i class="bi bi-person"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($candidate['name']); ?></td>
                                            <td><?php echo htmlspecialchars($candidate['position_title']); ?></td>
                                            <td><?php echo htmlspecialchars($candidate['election_title']); ?></td>
                                            <td><?php echo htmlspecialchars($candidate['description']); ?></td>
                                            <td>
                                                <div class="btn-group">
                                                    <button type="button" 
                                                            class="btn btn-sm btn-primary" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editCandidateModal<?php echo $candidate['id']; ?>">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button type="button" 
                                                            class="btn btn-sm btn-danger" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#deleteCandidateModal<?php echo $candidate['id']; ?>">
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

    <!-- Add Candidate Modal -->
    <div class="modal fade" id="addCandidateModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Candidate</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="mb-3">
                            <label class="form-label">Position</label>
                            <select class="form-select" name="position_id" required>
                                <option value="">Select Position</option>
                                <?php 
                                mysqli_data_seek($positions_result, 0);
                                while ($position = mysqli_fetch_assoc($positions_result)): 
                                ?>
                                    <option value="<?php echo $position['id']; ?>">
                                        <?php echo htmlspecialchars($position['title'] . ' - ' . $position['election_title']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Photo</label>
                            <input type="file" class="form-control" name="image" accept="image/*">
                            <small class="text-muted">Optional. Recommended size: 200x200 pixels</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-lg"></i> Add Candidate
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit and Delete Modals -->
    <?php 
    mysqli_data_seek($candidates_result, 0);
    while ($candidate = mysqli_fetch_assoc($candidates_result)): 
    ?>
    <!-- Edit Modal -->
    <div class="modal fade" id="editCandidateModal<?php echo $candidate['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Candidate</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="candidate_id" value="<?php echo $candidate['id']; ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">Position</label>
                            <select class="form-select" name="position_id" required>
                                <?php 
                                mysqli_data_seek($positions_result, 0);
                                while ($position = mysqli_fetch_assoc($positions_result)): 
                                ?>
                                    <option value="<?php echo $position['id']; ?>" 
                                            <?php echo $position['id'] == $candidate['position_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($position['title'] . ' - ' . $position['election_title']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input type="text" class="form-control" name="name" 
                                   value="<?php echo htmlspecialchars($candidate['name']); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3"><?php 
                                echo htmlspecialchars($candidate['description']); 
                            ?></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Photo</label>
                            <?php if ($candidate['image_path']): ?>
                                <div class="mb-2">
                                    <img src="../<?php echo htmlspecialchars($candidate['image_path']); ?>" 
                                         alt="Current photo" class="candidate-image">
                                </div>
                            <?php endif; ?>
                            <input type="file" class="form-control" name="image" accept="image/*">
                            <small class="text-muted">Optional. Leave empty to keep current photo</small>
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
    <div class="modal fade" id="deleteCandidateModal<?php echo $candidate['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header modal-header-danger">
                    <h5 class="modal-title">Delete Candidate</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete the candidate:</p>
                    <p class="fw-bold"><?php echo htmlspecialchars($candidate['name']); ?></p>
                    <p class="text-danger">This action cannot be undone!</p>
                </div>
                <div class="modal-footer">
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="candidate_id" value="<?php echo $candidate['id']; ?>">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash"></i> Delete Candidate
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endwhile; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 