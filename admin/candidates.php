<?php
session_start();
require_once "../config/database.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("location: login.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'create') {
            $name = mysqli_real_escape_string($conn, $_POST['name']);
            $position_id = (int)$_POST['position_id'];
            $description = mysqli_real_escape_string($conn, $_POST['description']);
            
            // Handle image upload
            $image_path = '';
            if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
                $allowed = ['jpg', 'jpeg', 'png', 'gif'];
                $filename = $_FILES['image']['name'];
                $filetype = pathinfo($filename, PATHINFO_EXTENSION);
                
                if (in_array(strtolower($filetype), $allowed)) {
                    // Create uploads directory if it doesn't exist
                    $upload_dir = "../uploads/candidates/";
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    // Generate unique filename
                    $new_filename = uniqid() . '.' . $filetype;
                    $target_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $target_path)) {
                        $image_path = 'uploads/candidates/' . $new_filename;
                    }
                }
            }
            
            $sql = "INSERT INTO candidates (name, position_id, description, image_path) 
                    VALUES ('$name', $position_id, '$description', '$image_path')";
            
            if (mysqli_query($conn, $sql)) {
                $success = "Candidate added successfully!";
            } else {
                $error = "Error adding candidate: " . mysqli_error($conn);
            }
        } elseif ($_POST['action'] == 'delete' && isset($_POST['candidate_ids'])) {
            $candidate_ids = array_map('intval', $_POST['candidate_ids']);
            $ids_string = implode(',', $candidate_ids);
            
            // First get the image paths to delete the files
            $images_sql = "SELECT image_path FROM candidates WHERE id IN ($ids_string)";
            $images_result = mysqli_query($conn, $images_sql);
            while ($row = mysqli_fetch_assoc($images_result)) {
                if (!empty($row['image_path'])) {
                    $file_path = "../" . $row['image_path'];
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }
                }
            }
            
            // Then delete the database records
            $sql = "DELETE FROM candidates WHERE id IN ($ids_string)";
            if (mysqli_query($conn, $sql)) {
                $success = "Selected candidates deleted successfully!";
            } else {
                $error = "Error deleting candidates: " . mysqli_error($conn);
            }
        }
    }
}

// Get all positions for dropdown
$positions_sql = "SELECT p.*, e.title as election_title 
                  FROM positions p 
                  JOIN elections e ON p.election_id = e.id 
                  ORDER BY e.title, p.title";
$positions_result = mysqli_query($conn, $positions_sql);

// Get all candidates
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
    <title>Manage Candidates - School Voting System</title>
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
        .candidate-card {
            margin-bottom: 20px;
            border: none;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        .candidate-card:hover {
            transform: translateY(-5px);
        }
        .candidate-image {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .no-image {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background-color: #dee2e6;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            font-size: 40px;
            border: 3px solid #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .candidate-info {
            padding: 20px;
        }
        .candidate-name {
            font-size: 1.2rem;
            font-weight: 500;
            margin-bottom: 5px;
        }
        .candidate-description {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 15px;
        }
        .candidate-actions {
            display: flex;
            gap: 10px;
        }
        .btn-edit {
            background-color: #198754;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            transition: background-color 0.3s;
        }
        .btn-edit:hover {
            background-color: #157347;
            color: white;
        }
        .btn-delete {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            transition: background-color 0.3s;
        }
        .btn-delete:hover {
            background-color: #bb2d3b;
            color: white;
        }
        .candidate-list-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 10px;
            background-color: #fff;
            transition: all 0.3s ease;
        }
        .candidate-list-item:hover {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .candidate-image {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-right: 20px;
        }
        .no-image {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: #dee2e6;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            font-size: 24px;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-right: 20px;
        }
        .candidate-info {
            flex-grow: 1;
        }
        .candidate-name {
            font-size: 1.1rem;
            font-weight: 500;
            margin-bottom: 5px;
        }
        .candidate-position {
            color: #198754;
            font-weight: 500;
            margin-bottom: 5px;
        }
        .candidate-description {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .candidate-actions {
            display: flex;
            gap: 10px;
        }
        .btn-edit {
            background-color: #198754;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        .btn-edit:hover {
            background-color: #157347;
            color: white;
        }
        .btn-delete {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        .btn-delete:hover {
            background-color: #bb2d3b;
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
                    <h2>Manage Candidates</h2>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCandidateModal">
                        <i class="bi bi-plus-circle"></i> Add New Candidate
                    </button>
                </div>

                <?php if (isset($success)): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>

                <!-- Candidates List -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Current Candidates</h5>
                    </div>
                    <div class="card-body">
                        <?php while ($candidate = mysqli_fetch_assoc($candidates_result)): ?>
                            <div class="candidate-list-item">
                                <?php if (!empty($candidate['image_path'])): ?>
                                    <img src="../<?php echo htmlspecialchars($candidate['image_path']); ?>" 
                                         alt="<?php echo htmlspecialchars($candidate['name']); ?>" 
                                         class="candidate-image">
                                <?php else: ?>
                                    <div class="no-image">
                                        <i class="bi bi-person"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="candidate-info">
                                    <div class="candidate-name">
                                        <?php echo htmlspecialchars($candidate['name']); ?>
                                    </div>
                                    <div class="candidate-position">
                                        <?php echo htmlspecialchars($candidate['election_title'] . ' - ' . $candidate['position_title']); ?>
                                    </div>
                                    <?php if (!empty($candidate['description'])): ?>
                                        <div class="candidate-description">
                                            <?php echo htmlspecialchars($candidate['description']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="candidate-actions">
                                    <a href="edit_candidate.php?id=<?php echo $candidate['id']; ?>" 
                                       class="btn btn-edit">
                                        <i class="bi bi-pencil"></i> Edit
                                    </a>
                                    <a href="delete_candidate.php?id=<?php echo $candidate['id']; ?>" 
                                       class="btn btn-delete"
                                       onclick="return confirm('Are you sure you want to delete this candidate?')">
                                        <i class="bi bi-trash"></i> Delete
                                    </a>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Candidate Modal -->
    <div class="modal fade" id="addCandidateModal" tabindex="-1" aria-labelledby="addCandidateModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addCandidateModalLabel">Add New Candidate</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="name" class="form-label">Name</label>
                                <input type="text" class="form-control" id="name" name="name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="position_id" class="form-label">Position</label>
                                <select class="form-select" id="position_id" name="position_id" required>
                                    <option value="">Select Position</option>
                                    <?php 
                                    mysqli_data_seek($positions_result, 0);
                                    while ($position = mysqli_fetch_assoc($positions_result)): 
                                    ?>
                                        <option value="<?php echo $position['id']; ?>">
                                            <?php echo htmlspecialchars($position['election_title'] . ' - ' . $position['title']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="image" class="form-label">Candidate Picture</label>
                            <input type="file" class="form-control" id="image" name="image" accept="image/*" required>
                            <img id="imagePreview" class="preview-image">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Candidate</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle select all checkbox
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.getElementsByClassName('candidate-checkbox');
            for (let checkbox of checkboxes) {
                checkbox.checked = this.checked;
            }
            updateDeleteButton();
        });

        // Handle individual checkboxes
        document.getElementsByClassName('candidate-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', updateDeleteButton);
        });

        function updateDeleteButton() {
            const checkedBoxes = document.querySelectorAll('.candidate-checkbox:checked');
            document.getElementById('deleteBtn').style.display = checkedBoxes.length > 0 ? 'block' : 'none';
        }

        // Handle image preview
        document.getElementById('image').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('imagePreview');
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                }
                reader.readAsDataURL(file);
            }
        });

        // Reset form when modal is closed
        document.getElementById('addCandidateModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('imagePreview').style.display = 'none';
            document.getElementById('image').value = '';
            document.querySelector('#addCandidateModal form').reset();
        });
    </script>
</body>
</html> 