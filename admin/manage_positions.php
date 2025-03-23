<?php
require_once "../config/database.php";
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("location: login.php");
    exit();
}

// Get election ID from URL
$election_id = isset($_GET['election_id']) ? (int)$_GET['election_id'] : 0;

// Handle deletion of selected positions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_positions'])) {
    if (isset($_POST['selected_positions']) && is_array($_POST['selected_positions'])) {
        $selected_positions = array_map('intval', $_POST['selected_positions']);
        $positions_string = implode(',', $selected_positions);
        
        // Delete the selected positions
        $delete_sql = "DELETE FROM positions WHERE id IN ($positions_string)";
        if (mysqli_query($conn, $delete_sql)) {
            echo "<script>alert('Selected positions deleted successfully!');</script>";
        } else {
            echo "<script>alert('Error deleting positions: " . mysqli_error($conn) . "');</script>";
        }
    }
}

// Handle position creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_position'])) {
    $title = mysqli_real_escape_string($conn, $_POST['title']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);

    $sql = "INSERT INTO positions (election_id, title, description) 
            VALUES ($election_id, '$title', '$description')";
    
    if (mysqli_query($conn, $sql)) {
        echo "<script>alert('Position created successfully!');</script>";
    } else {
        echo "<script>alert('Error creating position: " . mysqli_error($conn) . "');</script>";
    }
}

// Get election details
$election_sql = "SELECT * FROM elections WHERE id = $election_id";
$election_result = mysqli_query($conn, $election_sql);
$election = mysqli_fetch_assoc($election_result);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Positions - <?php echo $election['title']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .select-all {
            margin-right: 5px;
        }
        .position-checkbox {
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
        <h2>Manage Positions - <?php echo $election['title']; ?></h2>
        
        <!-- Create Position Form -->
        <div class="card mb-4">
            <div class="card-header">
                <h4>Create New Position</h4>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label for="title" class="form-label">Title</label>
                        <input type="text" class="form-control" id="title" name="title" required>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                    <button type="submit" name="create_position" class="btn btn-primary">Create Position</button>
                    <a href="elections.php" class="btn btn-secondary">Back to Elections</a>
                </form>
            </div>
        </div>

        <!-- List Positions -->
        <div class="card">
            <div class="card-header">
                <h4>Current Positions</h4>
            </div>
            <div class="card-body">
                <form method="POST">
                    <table class="table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="select-all" onclick="toggleAll(this, 'position-checkbox')"> Select All</th>
                                <th>Title</th>
                                <th>Description</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql = "SELECT * FROM positions WHERE election_id = $election_id ORDER BY id";
                            $result = mysqli_query($conn, $sql);
                            while ($position = mysqli_fetch_assoc($result)) {
                                echo "<tr>";
                                echo "<td><input type='checkbox' name='selected_positions[]' value='" . $position['id'] . "' class='position-checkbox'></td>";
                                echo "<td>" . $position['title'] . "</td>";
                                echo "<td>" . $position['description'] . "</td>";
                                echo "<td>
                                        <a href='edit_position.php?id=" . $position['id'] . "' class='btn btn-sm btn-primary'>Edit</a>
                                        <a href='manage_candidates.php?position_id=" . $position['id'] . "' class='btn btn-sm btn-success'>Manage Candidates</a>
                                      </td>";
                                echo "</tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                    <button type="submit" name="delete_positions" class="delete-btn" onclick="return confirm('Are you sure you want to delete the selected positions?')">Delete Selected</button>
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