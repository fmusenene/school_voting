<?php
session_start();
require_once "../config/database.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("location: login.php");
    exit();
}

// Function to generate unique voting codes
function generateVotingCode() {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $code;
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['delete_codes'])) {
        if (isset($_POST['selected_codes']) && is_array($_POST['selected_codes'])) {
            $selected_codes = array_map('intval', $_POST['selected_codes']);
            $codes_string = implode(',', $selected_codes);
            
            // Delete the selected codes
            $delete_sql = "DELETE FROM voting_codes WHERE id IN ($codes_string)";
            if (mysqli_query($conn, $delete_sql)) {
                $success = "Selected codes deleted successfully!";
            } else {
                $error = "Error deleting codes: " . mysqli_error($conn);
            }
        }
    } else if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'generate':
                $election_id = $_POST['election_id'];
                $count = (int)$_POST['count'];
                
                $success_count = 0;
                $error_count = 0;
                
                for ($i = 0; $i < $count; $i++) {
                    $code = generateVotingCode();
                    
                    // Check if code already exists
                    $check_sql = "SELECT id FROM voting_codes WHERE code = ?";
                    $check_stmt = mysqli_prepare($conn, $check_sql);
                    mysqli_stmt_bind_param($check_stmt, "s", $code);
                    mysqli_stmt_execute($check_stmt);
                    $check_result = mysqli_stmt_get_result($check_stmt);
                    
                    if (mysqli_num_rows($check_result) == 0) {
                        $sql = "INSERT INTO voting_codes (election_id, code) VALUES (?, ?)";
                        $stmt = mysqli_prepare($conn, $sql);
                        mysqli_stmt_bind_param($stmt, "is", $election_id, $code);
                        
                        if (mysqli_stmt_execute($stmt)) {
                            $success_count++;
                        } else {
                            $error_count++;
                        }
                    } else {
                        $i--; // Try again with a new code
                    }
                }
                
                if ($success_count > 0) {
                    $success = "Successfully generated $success_count voting codes.";
                    if ($error_count > 0) {
                        $success .= " Failed to generate $error_count codes.";
                    }
                } else {
                    $error = "Failed to generate any voting codes.";
                }
                break;
                
            case 'delete':
                $id = $_POST['id'];
                
                $sql = "DELETE FROM voting_codes WHERE id = ?";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "i", $id);
                
                if (mysqli_stmt_execute($stmt)) {
                    $success = "Voting code deleted successfully!";
                } else {
                    $error = "Error deleting voting code: " . mysqli_error($conn);
                }
                break;
        }
    }
}

// Get all elections for dropdown
$elections_sql = "SELECT * FROM elections ORDER BY title";
$elections_result = mysqli_query($conn, $elections_sql);

// Get all voting codes with election information
$codes_sql = "SELECT vc.*, e.title as election_title 
              FROM voting_codes vc 
              JOIN elections e ON vc.election_id = e.id 
              ORDER BY e.title, vc.created_at DESC";
$codes_result = mysqli_query($conn, $codes_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Voting Codes - School Voting System</title>
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
        .voting-code {
            font-family: monospace;
            font-size: 1.1em;
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
                        <a class="nav-link active" href="voting_codes.php">
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
                    <h2>Manage Voting Codes</h2>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#generateCodesModal">
                        <i class="bi bi-plus-circle"></i> Generate New Codes
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
                                            <th><input type="checkbox" class="select-all" onclick="toggleAll(this, 'code-checkbox')"> Select All</th>
                                            <th>Election</th>
                                            <th>Code</th>
                                            <th>Status</th>
                                            <th>Created At</th>
                                            <th>Used At</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($row = mysqli_fetch_assoc($codes_result)): ?>
                                            <tr>
                                                <td><input type="checkbox" name="selected_codes[]" value="<?php echo $row['id']; ?>" class="code-checkbox"></td>
                                                <td><?php echo htmlspecialchars($row['election_title']); ?></td>
                                                <td><?php echo htmlspecialchars($row['code']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $row['is_used'] ? 'danger' : 'success'; ?>">
                                                        <?php echo $row['is_used'] ? 'Used' : 'Available'; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M d, Y H:i', strtotime($row['created_at'])); ?></td>
                                                <td><?php echo $row['used_at'] ? date('M d, Y H:i', strtotime($row['used_at'])) : '-'; ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                                <button type="submit" name="delete_codes" class="delete-btn" onclick="return confirm('Are you sure you want to delete the selected codes?')">Delete Selected</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Generate Codes Modal -->
    <div class="modal fade" id="generateCodesModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Generate Voting Codes</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="generate">
                        
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
                            <label class="form-label">Number of Codes</label>
                            <input type="number" class="form-control" name="count" min="1" max="100" value="1" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Generate Codes</button>
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