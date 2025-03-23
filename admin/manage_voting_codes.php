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

// Handle deletion of selected codes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_codes'])) {
    if (isset($_POST['selected_codes']) && is_array($_POST['selected_codes'])) {
        $selected_codes = array_map('intval', $_POST['selected_codes']);
        $codes_string = implode(',', $selected_codes);
        
        // Delete the selected codes
        $delete_sql = "DELETE FROM voting_codes WHERE id IN ($codes_string)";
        if (mysqli_query($conn, $delete_sql)) {
            echo "<script>alert('Selected codes deleted successfully!');</script>";
        } else {
            echo "<script>alert('Error deleting codes: " . mysqli_error($conn) . "');</script>";
        }
    }
}

// Handle code generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_codes'])) {
    $num_codes = (int)$_POST['num_codes'];
    $codes = [];
    
    for ($i = 0; $i < $num_codes; $i++) {
        $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
        $codes[] = "($election_id, '$code')";
    }
    
    $sql = "INSERT INTO voting_codes (election_id, code) VALUES " . implode(',', $codes);
    
    if (mysqli_query($conn, $sql)) {
        echo "<script>alert('Voting codes generated successfully!');</script>";
    } else {
        echo "<script>alert('Error generating codes: " . mysqli_error($conn) . "');</script>";
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
    <title>Manage Voting Codes - <?php echo $election['title']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .select-all {
            margin-right: 5px;
        }
        .code-checkbox {
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
        .code-list {
            font-family: monospace;
            white-space: pre-wrap;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <h2>Manage Voting Codes - <?php echo $election['title']; ?></h2>
        
        <!-- Generate Codes Form -->
        <div class="card mb-4">
            <div class="card-header">
                <h4>Generate New Voting Codes</h4>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label for="num_codes" class="form-label">Number of Codes to Generate</label>
                        <input type="number" class="form-control" id="num_codes" name="num_codes" min="1" max="100" value="10" required>
                    </div>
                    <button type="submit" name="generate_codes" class="btn btn-primary">Generate Codes</button>
                    <a href="elections.php" class="btn btn-secondary">Back to Elections</a>
                </form>
            </div>
        </div>

        <!-- List Voting Codes -->
        <div class="card">
            <div class="card-header">
                <h4>Current Voting Codes</h4>
            </div>
            <div class="card-body">
                <form method="POST">
                    <table class="table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="select-all" onclick="toggleAll(this, 'code-checkbox')"> Select All</th>
                                <th>Code</th>
                                <th>Status</th>
                                <th>Created At</th>
                                <th>Used At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql = "SELECT * FROM voting_codes WHERE election_id = $election_id ORDER BY created_at DESC";
                            $result = mysqli_query($conn, $sql);
                            while ($code = mysqli_fetch_assoc($result)) {
                                echo "<tr>";
                                echo "<td><input type='checkbox' name='selected_codes[]' value='" . $code['id'] . "' class='code-checkbox'></td>";
                                echo "<td>" . $code['code'] . "</td>";
                                echo "<td>" . ($code['is_used'] ? '<span class="text-danger">Used</span>' : '<span class="text-success">Available</span>') . "</td>";
                                echo "<td>" . $code['created_at'] . "</td>";
                                echo "<td>" . ($code['used_at'] ? $code['used_at'] : '-') . "</td>";
                                echo "</tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                    <button type="submit" name="delete_codes" class="delete-btn" onclick="return confirm('Are you sure you want to delete the selected codes?')">Delete Selected</button>
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