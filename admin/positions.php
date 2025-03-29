<?php
require_once "includes/header.php";

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['delete_positions'])) {
        if (isset($_POST['selected_positions']) && is_array($_POST['selected_positions'])) {
            $selected_positions = array_map('intval', $_POST['selected_positions']);
            $positions_string = implode(',', $selected_positions);
            
            // Delete the selected positions
            $delete_sql = "DELETE FROM positions WHERE id IN ($positions_string)";
            if ($conn->exec($delete_sql)) {
                $success = "Selected positions deleted successfully!";
            } else {
                $error = "Error deleting positions";
            }
        }
    } else if (isset($_POST['action']) && $_POST['action'] === 'create') {
        $title = $_POST['title'];
        $description = $_POST['description'];
        $max_winners = $_POST['max_winners'];
        $election_id = $_POST['election_id'];

        $sql = "INSERT INTO positions (title, description, max_winners, election_id) 
                VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        if ($stmt->execute([$title, $description, $max_winners, $election_id])) {
            $success = "Position created successfully!";
        } else {
            $error = "Error creating position";
        }
    }
}

// Get all elections for dropdown
$elections_sql = "SELECT * FROM elections ORDER BY created_at DESC";
$elections_result = $conn->query($elections_sql);

// Get all positions with election titles
$positions_sql = "SELECT p.*, e.title as election_title 
                  FROM positions p 
                  LEFT JOIN elections e ON p.election_id = e.id 
                  ORDER BY p.id DESC";
$positions_result = $conn->query($positions_sql);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Manage Positions</h2>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPositionModal">
        <i class="bi bi-plus-lg"></i> Add New Position
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
                            <th>Title</th>
                            <th>Election</th>
                            <th>Max Winners</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $positions_result->fetch(PDO::FETCH_ASSOC)): ?>
                            <tr>
                                <td><input type="checkbox" name="selected_positions[]" value="<?php echo $row['id']; ?>" class="form-check-input position-checkbox"></td>
                                <td><?php echo htmlspecialchars($row['title']); ?></td>
                                <td><?php echo htmlspecialchars($row['election_title']); ?></td>
                                <td><?php echo $row['max_winners']; ?></td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <a href="edit_position.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" name="delete_positions" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete the selected positions?')">
                <i class="bi bi-trash"></i> Delete Selected
            </button>
        </form>
    </div>
</div>

<!-- Add Position Modal -->
<div class="modal fade" id="addPositionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Position</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    
                    <div class="mb-3">
                        <label class="form-label">Title</label>
                        <input type="text" class="form-control" name="title" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Max Winners</label>
                        <input type="number" class="form-control" name="max_winners" min="1" value="1" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Election</label>
                        <select class="form-select" name="election_id" required>
                            <option value="">Select Election</option>
                            <?php while ($election = $elections_result->fetch(PDO::FETCH_ASSOC)): ?>
                                <option value="<?php echo $election['id']; ?>">
                                    <?php echo htmlspecialchars($election['title']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
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

<script>
document.querySelector('.select-all').addEventListener('change', function() {
    document.querySelectorAll('.position-checkbox').forEach(checkbox => {
        checkbox.checked = this.checked;
    });
});
</script>

<?php require_once "includes/footer.php"; ?> 