<?php
require_once "includes/header.php";

// Check if user is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create') {
            $title = $_POST['title'];
            $description = $_POST['description'];
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'];
            
            // Determine status based on dates
            $status = 'pending';
            if (strtotime($start_date) <= time() && strtotime($end_date) >= time()) {
                $status = 'active';
            } elseif (strtotime($end_date) < time()) {
                $status = 'completed';
            }
            
            $stmt = $conn->prepare("INSERT INTO elections (title, description, start_date, end_date, status) VALUES (?, ?, ?, ?, ?)");
            if ($stmt->execute([$title, $description, $start_date, $end_date, $status])) {
                $_SESSION['success'] = "Election created successfully!";
            } else {
                $_SESSION['error'] = "Error creating election.";
            }
        } elseif ($_POST['action'] === 'delete' && isset($_POST['election_ids'])) {
            $election_ids = $_POST['election_ids'];
            $placeholders = str_repeat('?,', count($election_ids) - 1) . '?';
            
            // First delete related records
            $stmt = $conn->prepare("DELETE FROM positions WHERE election_id IN ($placeholders)");
            $stmt->execute($election_ids);
            
            $stmt = $conn->prepare("DELETE FROM voting_codes WHERE election_id IN ($placeholders)");
            $stmt->execute($election_ids);
            
            // Then delete the elections
            $stmt = $conn->prepare("DELETE FROM elections WHERE id IN ($placeholders)");
            if ($stmt->execute($election_ids)) {
                $_SESSION['success'] = "Selected elections deleted successfully!";
            } else {
                $_SESSION['error'] = "Error deleting elections.";
            }
        }
    }
    header("Location: elections.php");
    exit();
}

// Fetch all elections
$stmt = $conn->query("SELECT * FROM elections ORDER BY created_at DESC");
$elections = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Manage Elections</h2>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addElectionModal">
        <i class="bi bi-plus-lg me-2"></i>Add New Election
    </button>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php 
        echo $_SESSION['success'];
        unset($_SESSION['success']);
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php 
        echo $_SESSION['error'];
        unset($_SESSION['error']);
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" id="deleteForm">
            <input type="hidden" name="action" value="delete">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>
                                <input type="checkbox" class="form-check-input" id="selectAll">
                            </th>
                            <th>Title</th>
                            <th>Description</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($elections as $election): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="election_ids[]" value="<?php echo $election['id']; ?>" class="form-check-input">
                                </td>
                                <td><?php echo htmlspecialchars($election['title']); ?></td>
                                <td><?php echo htmlspecialchars($election['description']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($election['start_date'])); ?></td>
                                <td><?php echo date('M d, Y', strtotime($election['end_date'])); ?></td>
                                <td>
                                    <?php
                                    $statusClass = 'bg-secondary';
                                    switch ($election['status']) {
                                        case 'active':
                                            $statusClass = 'bg-success';
                                            break;
                                        case 'completed':
                                            $statusClass = 'bg-primary';
                                            break;
                                    }
                                    ?>
                                    <span class="badge <?php echo $statusClass; ?> status-badge">
                                        <?php echo ucfirst($election['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="edit_election.php?id=<?php echo $election['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="view_results.php?id=<?php echo $election['id']; ?>" class="btn btn-sm btn-info">
                                        <i class="bi bi-graph-up"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete the selected elections?')">
                <i class="bi bi-trash me-2"></i>Delete Selected
            </button>
        </form>
    </div>
</div>

<!-- Add Election Modal -->
<div class="modal fade" id="addElectionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Election</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label for="title" class="form-label">Title</label>
                        <input type="text" class="form-control" id="title" name="title" required>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3" required></textarea>
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

<?php require_once "includes/footer.php"; ?>

<script>
    // Handle select all checkbox
    document.getElementById('selectAll').addEventListener('change', function() {
        const checkboxes = document.getElementsByName('election_ids[]');
        for (let checkbox of checkboxes) {
            checkbox.checked = this.checked;
        }
    });
</script> 