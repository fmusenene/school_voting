<?php
require_once "includes/header.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("location: login.php");
    exit();
}

// Check if election ID is provided
if (!isset($_GET['id'])) {
    header("location: elections.php");
    exit();
}

$election_id = (int)$_GET['id'];

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $title = $_POST['title'];
    $description = $_POST['description'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    
    // Set status based on dates
    $current_date = date('Y-m-d');
    $status = 'pending';
    if ($start_date <= $current_date && $end_date >= $current_date) {
        $status = 'active';
    } else if ($end_date < $current_date) {
        $status = 'completed';
    }

    $sql = "UPDATE elections SET title = ?, description = ?, start_date = ?, end_date = ?, status = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if ($stmt->execute([$title, $description, $start_date, $end_date, $status, $election_id])) {
        $success = "Election updated successfully!";
    } else {
        $error = "Error updating election";
    }
}

// Get election details
$sql = "SELECT * FROM elections WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$election_id]);
$election = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$election) {
    header("location: elections.php");
    exit();
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Edit Election</h2>
    <a href="elections.php" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> Back to Elections
    </a>
</div>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="post">
            <div class="mb-3">
                <label class="form-label">Title</label>
                <input type="text" class="form-control" name="title" 
                       value="<?php echo htmlspecialchars($election['title']); ?>" required>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Description</label>
                <textarea class="form-control" name="description" rows="3"><?php 
                    echo htmlspecialchars($election['description']); 
                ?></textarea>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Start Date</label>
                <input type="datetime-local" class="form-control" name="start_date" 
                       value="<?php echo date('Y-m-d\TH:i', strtotime($election['start_date'])); ?>" required>
            </div>
            
            <div class="mb-3">
                <label class="form-label">End Date</label>
                <input type="datetime-local" class="form-control" name="end_date" 
                       value="<?php echo date('Y-m-d\TH:i', strtotime($election['end_date'])); ?>" required>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Current Status</label>
                <input type="text" class="form-control" value="<?php echo ucfirst($election['status']); ?>" readonly>
                <small class="text-muted">Status is automatically updated based on the election dates.</small>
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Save Changes
                </button>
                <a href="elections.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once "includes/footer.php"; ?> 