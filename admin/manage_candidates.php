<?php
require_once "../config/database.php";
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("location: login.php");
    exit();
}

// Get position ID from URL
$position_id = isset($_GET['position_id']) ? (int)$_GET['position_id'] : 0;

// Handle deletion of selected candidates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_candidates'])) {
    if (isset($_POST['selected_candidates']) && is_array($_POST['selected_candidates'])) {
        $selected_candidates = array_map('intval', $_POST['selected_candidates']);
        $candidates_string = implode(',', $selected_candidates);
        
        // Delete the selected candidates
        $delete_sql = "DELETE FROM candidates WHERE id IN ($candidates_string)";
        if (mysqli_query($conn, $delete_sql)) {
            echo "<script>alert('Selected candidates deleted successfully!');</script>";
        } else {
            echo "<script>alert('Error deleting candidates: " . mysqli_error($conn) . "');</script>";
        }
    }
}

// Handle candidate creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_candidate'])) {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);

    $sql = "INSERT INTO candidates (position_id, name, description) 
            VALUES ($position_id, '$name', '$description')";
    
    if (mysqli_query($conn, $sql)) {
        echo "<script>alert('Candidate created successfully!');</script>";
    } else {
        echo "<script>alert('Error creating candidate: " . mysqli_error($conn) . "');</script>";
    }
}

// Get position and election details
$position_sql = "SELECT p.*, e.title as election_title 
                 FROM positions p 
                 JOIN elections e ON p.election_id = e.id 
                 WHERE p.id = $position_id";
$position_result = mysqli_query($conn, $position_sql);
$position = mysqli_fetch_assoc($position_result);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Candidates - <?php echo $position['title']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .select-all {
            margin-right: 5px;
        }
        .candidate-checkbox {
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
    <div class="container mt-5">
        <h2>Manage Candidates - <?php echo $position['title']; ?></h2>
        <p>Election: <?php echo $position['election_title']; ?></p>
        
        <!-- Create Candidate Form -->
        <div class="card mb-4">
            <div class="card-header">
                <h4>Add New Candidate</h4>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                    <button type="submit" name="create_candidate" class="btn btn-primary">Add Candidate</button>
                    <a href="manage_positions.php?election_id=<?php echo $position['election_id']; ?>" class="btn btn-secondary">Back to Positions</a>
                </form>
            </div>
        </div>

        <!-- List Candidates -->
        <div class="card">
            <div class="card-header">
                <h4>Current Candidates</h4>
            </div>
            <div class="card-body">
                <form method="POST">
                    <table class="table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="select-all" onclick="toggleAll(this, 'candidate-checkbox')"> Select All</th>
                                <th>Name</th>
                                <th>Description</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql = "SELECT * FROM candidates WHERE position_id = $position_id ORDER BY name";
                            $result = mysqli_query($conn, $sql);
                            while ($candidate = mysqli_fetch_assoc($result)) {
                                echo "<tr>";
                                echo "<td><input type='checkbox' name='selected_candidates[]' value='" . $candidate['id'] . "' class='candidate-checkbox'></td>";
                                echo "<td>" . $candidate['name'] . "</td>";
                                echo "<td>" . $candidate['description'] . "</td>";
                                echo "<td>
                                        <a href='edit_candidate.php?id=" . $candidate['id'] . "' class='btn btn-sm btn-primary'>Edit</a>
                                      </td>";
                                echo "</tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                    <button type="submit" name="delete_candidates" class="delete-btn" onclick="return confirm('Are you sure you want to delete the selected candidates?')">Delete Selected</button>
                </form>
            </div>
        </div>
    </div>

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