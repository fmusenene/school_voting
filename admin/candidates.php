<?php
require_once "includes/header.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("location: login.php");
    exit();
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['delete_candidates'])) {
        if (isset($_POST['selected_candidates']) && is_array($_POST['selected_candidates'])) {
            $selected_candidates = array_map('intval', $_POST['selected_candidates']);
            $candidates_string = implode(',', $selected_candidates);
            
            // Delete the selected candidates
            $delete_sql = "DELETE FROM candidates WHERE id IN ($candidates_string)";
            if ($conn->exec($delete_sql)) {
                $success = "Selected candidates deleted successfully!";
            } else {
                $error = "Error deleting candidates";
            }
        }
    } else if (isset($_POST['action']) && $_POST['action'] === 'create') {
        $name = $_POST['name'];
        $position_id = $_POST['position_id'];
        $description = $_POST['description'];

        $sql = "INSERT INTO candidates (name, position_id, description) 
                VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        if ($stmt->execute([$name, $position_id, $description])) {
            $success = "Candidate created successfully!";
        } else {
            $error = "Error creating candidate";
        }
    }
}

// Get all positions for dropdown
$positions_sql = "SELECT p.*, e.title as election_title 
                  FROM positions p 
                  LEFT JOIN elections e ON p.election_id = e.id 
                  ORDER BY e.title, p.title";
$positions_result = $conn->query($positions_sql);

// Get all candidates with position and election titles
$candidates_sql = "SELECT c.*, p.title as position_title, e.title as election_title 
                   FROM candidates c 
                   LEFT JOIN positions p ON c.position_id = p.id 
                   LEFT JOIN elections e ON p.election_id = e.id 
                   ORDER BY c.id DESC";
$candidates_result = $conn->query($candidates_sql);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Manage Candidates</h2>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCandidateModal">
        <i class="bi bi-plus-lg"></i> Add New Candidate
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
        <form method="post">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" class="form-check-input select-all"></th>
                            <th>Name</th>
                            <th>Position</th>
                            <th>Election</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $candidates_result->fetch(PDO::FETCH_ASSOC)): ?>
                            <tr>
                                <td><input type="checkbox" name="selected_candidates[]" value="<?php echo $row['id']; ?>" class="form-check-input candidate-checkbox"></td>
                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                <td><?php echo htmlspecialchars($row['position_title']); ?></td>
                                <td><?php echo htmlspecialchars($row['election_title']); ?></td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <a href="edit_candidate.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" name="delete_candidates" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete the selected candidates?')">
                <i class="bi bi-trash"></i> Delete Selected
            </button>
        </form>
    </div>
</div>

<!-- Add Candidate Modal -->
<div class="modal fade" id="addCandidateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Candidate</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Position</label>
                        <select class="form-select" name="position_id" required>
                            <option value="">Select Position</option>
                            <?php while ($position = $positions_result->fetch(PDO::FETCH_ASSOC)): ?>
                                <option value="<?php echo $position['id']; ?>">
                                    <?php echo htmlspecialchars($position['title'] . ' (' . $position['election_title'] . ')'); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
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

<script>
document.querySelector('.select-all').addEventListener('change', function() {
    document.querySelectorAll('.candidate-checkbox').forEach(checkbox => {
        checkbox.checked = this.checked;
    });
});
</script>

<?php require_once "includes/footer.php"; ?> 