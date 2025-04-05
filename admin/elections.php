<?php
session_start();
require_once "../config/database.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: /school_voting/admin/login.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'];
    $status = $_POST['status'];
    
    try {
        $sql = "INSERT INTO elections (title, description, start_date, end_date, status) 
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
            if ($stmt->execute([$title, $description, $start_date, $end_date, $status])) {
                $_SESSION['success'] = "Election created successfully!";
            } else {
            $_SESSION['error'] = "Error creating election";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
    }
    
    header("Location: elections.php");
    exit();
}

// Get elections statistics
$stats_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
FROM elections";
$stats = $conn->query($stats_sql)->fetch(PDO::FETCH_ASSOC);

// Get all elections with their positions and votes count
$elections_sql = "SELECT 
    e.*,
    COUNT(DISTINCT p.id) as position_count,
    COUNT(DISTINCT v.id) as vote_count
FROM elections e
LEFT JOIN positions p ON e.id = p.election_id
LEFT JOIN candidates c ON p.id = c.position_id
LEFT JOIN votes v ON c.id = v.candidate_id
GROUP BY e.id
ORDER BY e.created_at DESC";
$elections = $conn->query($elections_sql)->fetchAll(PDO::FETCH_ASSOC);

require_once "includes/header.php";
?>

<style>
.page-header {
    background: linear-gradient(135deg, #6B73FF 0%, #000DFF 100%);
    color: white;
    padding: 2rem;
    border-radius: 10px;
    margin-bottom: 2rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.stats-card {
    background: white;
    border-radius: 10px;
    padding: 1rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: transform 0.2s;
}

.stats-card:hover {
    transform: translateY(-5px);
}

.stats-icon {
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    margin-bottom: 0.6rem;
}

.stats-number {
    font-size: 1.8rem;
    font-weight: 600;
    margin: 0.2rem 0;
    color: #2c3e50;
    line-height: 1;
}

.stats-label {
    color: #7f8c8d;
    font-size: 0.9rem;
    margin: 0;
    margin-bottom: 0.2rem;
}

.table-card {
    background: white;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.table {
    margin-bottom: 0;
}

.table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #2c3e50;
    padding: 1rem;
}

.table td {
    padding: 1rem;
    vertical-align: middle;
}

.status-badge {
    padding: 0.4rem 1rem;
    border-radius: 50px;
    font-size: 0.85rem;
    font-weight: 500;
}

.status-active {
    background: #e3fcef;
    color: #00a854;
}

.status-pending {
    background: #fff7e6;
    color: #fa8c16;
}

.status-completed {
    background: #f0f0f0;
    color: #595959;
}

.btn-action {
    padding: 0.4rem;
    border-radius: 6px;
    color: #2c3e50;
    background: #f8f9fa;
    border: none;
    transition: all 0.2s;
}

.btn-action:hover {
    background: #e9ecef;
    color: #1a252f;
}

.new-election-btn {
    background: #1890ff;
    color: white;
    border: none;
    padding: 0.5rem 1rem;
    border-radius: 5px;
    font-weight: 500;
    transition: background 0.2s;
}

.new-election-btn:hover {
    background: #096dd9;
    color: white;
    text-decoration: none;
}

.modal-content {
    border: none;
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.modal-header {
    padding: 1.5rem;
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    padding: 1.5rem;
}

.btn-danger {
    background: #dc3545;
    color: white;
    border: none;
    padding: 0.6rem 2rem;
    border-radius: 6px;
    font-weight: 500;
}

.btn-danger:hover {
    background: #c82333;
    color: white;
}

.modal-dialog-centered {
    display: flex;
    align-items: center;
    min-height: calc(100% - 1rem);
}

@media (min-width: 576px) {
    .modal-dialog-centered {
        min-height: calc(100% - 3.5rem);
    }
}

.form-label {
    font-weight: 500;
    color: #2c3e50;
    margin-bottom: 0.5rem;
}

.form-control {
    border: 2px solid #e9ecef;
    border-radius: 8px;
    padding: 0.6rem 1rem;
    transition: all 0.2s;
}

.form-control:focus {
    border-color: #6B73FF;
    box-shadow: 0 0 0 0.2rem rgba(107, 115, 255, 0.25);
}

.btn-close {
    color: white;
    opacity: 1;
}

.btn-save {
    background: #1890ff;
    color: white;
    border: none;
    padding: 0.6rem 2rem;
    border-radius: 6px;
    font-weight: 500;
}

.btn-save:hover {
    background: #096dd9;
}

.btn-cancel {
    background: #6c757d;
    color: white;
    border: none;
    padding: 0.6rem 2rem;
    border-radius: 6px;
    font-weight: 500;
}

.btn-cancel:hover {
    background: #5a6268;
}
</style>

<div class="container-fluid py-4">
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

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">Elections Management</h1>
        <button type="button" class="new-election-btn" data-bs-toggle="modal" data-bs-target="#createElectionModal">
            <i class="bi bi-plus-circle"></i> New Election
        </button>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: #e3f2fd; color: #1976d2;">
                    <i class="bi bi-calendar2-check"></i>
                </div>
                <h3 class="stats-number"><?php echo $stats['total']; ?></h3>
                <p class="stats-label">Total Elections</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: #e3fcef; color: #00a854;">
                    <i class="bi bi-play-circle"></i>
                </div>
                <h3 class="stats-number"><?php echo $stats['active']; ?></h3>
                <p class="stats-label">Active Elections</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: #fff7e6; color: #fa8c16;">
                    <i class="bi bi-clock"></i>
                </div>
                <h3 class="stats-number"><?php echo $stats['pending']; ?></h3>
                <p class="stats-label">Pending Elections</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: #f0f0f0; color: #595959;">
                    <i class="bi bi-check2-circle"></i>
                </div>
                <h3 class="stats-number"><?php echo $stats['completed']; ?></h3>
                <p class="stats-label">Completed Elections</p>
            </div>
        </div>
    </div>

    <!-- Elections Table -->
    <div class="table-card">
            <div class="table-responsive">
            <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Status</th>
                        <th>Duration</th>
                        <th>Positions</th>
                        <th>Votes</th>
                        <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($elections as $election): ?>
                            <tr>
                                <td>
                                <div class="fw-500"><?php echo htmlspecialchars($election['title']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($election['description']); ?></small>
                                </td>
                            <td>
                                <span class="status-badge status-<?php echo $election['status']; ?>">
                                        <?php echo ucfirst($election['status']); ?>
                                    </span>
                                </td>
                                <td>
                                <div><?php echo date('M d, Y', strtotime($election['start_date'])); ?></div>
                                <small class="text-muted"><?php echo date('M d, Y', strtotime($election['end_date'])); ?></small>
                            </td>
                            <td>
                                <span class="fw-500"><?php echo $election['position_count']; ?></span>
                            </td>
                            <td>
                                <span class="fw-500"><?php echo $election['vote_count']; ?></span>
                            </td>
                            <td class="text-end">
                                <div class="d-flex gap-2 justify-content-end">
                                    <a href="#" class="btn btn-action edit-election" 
                                       data-id="<?php echo $election['id']; ?>"
                                       data-title="<?php echo htmlspecialchars($election['title']); ?>"
                                       data-description="<?php echo htmlspecialchars($election['description']); ?>"
                                       data-start-date="<?php echo date('Y-m-d', strtotime($election['start_date'])); ?>"
                                       data-end-date="<?php echo date('Y-m-d', strtotime($election['end_date'])); ?>"
                                       data-status="<?php echo $election['status']; ?>"
                                       title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="view_results.php?id=<?php echo $election['id']; ?>" 
                                       class="btn btn-action" title="View Results">
                                        <i class="bi bi-bar-chart"></i>
                                    </a>
                                    <button type="button" 
                                            class="btn btn-action text-danger" 
                                            onclick="deleteElection(<?php echo $election['id']; ?>)"
                                            title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
    </div>
</div>

<!-- Create Election Modal -->
<div class="modal fade" id="createElectionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New Election</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Election Title</label>
                        <input type="text" class="form-control" name="title" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="start_date" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status" required>
                            <option value="pending">Pending</option>
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-save">Save Election</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Election Modal -->
<div class="modal fade" id="editElectionModal" tabindex="-1" aria-labelledby="editElectionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editElectionModalLabel">Edit Election</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editElectionForm">
                    <input type="hidden" id="editElectionId" name="id">
                    <div class="mb-3">
                        <label class="form-label">Election Title</label>
                        <input type="text" class="form-control" id="editTitle" name="title" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" id="editDescription" name="description" rows="3"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="editStartDate" name="start_date" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" id="editEndDate" name="end_date" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" id="editStatus" name="status" required>
                            <option value="pending">Pending</option>
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-save" id="saveElectionChanges">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmationModal" tabindex="-1" aria-labelledby="deleteConfirmationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title" id="deleteConfirmationModalLabel">Delete Election</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center py-4">
                <div class="mb-4">
                    <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 3rem;"></i>
                </div>
                <h4 class="mb-3">Are you sure?</h4>
                <p class="text-muted mb-0">This action cannot be undone. All associated data including positions, candidates, and votes will be permanently deleted.</p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDelete">Delete Election</button>
            </div>
        </div>
    </div>
</div>

<script>
let electionToDelete = null;

function deleteElection(id) {
    electionToDelete = id;
    const modal = new bootstrap.Modal(document.getElementById('deleteConfirmationModal'));
    modal.show();
}

document.addEventListener('DOMContentLoaded', function() {
    // Handle edit button clicks
    document.querySelectorAll('.edit-election').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const electionId = this.getAttribute('data-id');
            const electionTitle = this.getAttribute('data-title');
            const electionDescription = this.getAttribute('data-description');
            const electionStartDate = this.getAttribute('data-start-date');
            const electionEndDate = this.getAttribute('data-end-date');
            const electionStatus = this.getAttribute('data-status');

            // Populate the modal form
            document.getElementById('editElectionId').value = electionId;
            document.getElementById('editTitle').value = electionTitle;
            document.getElementById('editDescription').value = electionDescription;
            document.getElementById('editStartDate').value = electionStartDate;
            document.getElementById('editEndDate').value = electionEndDate;
            document.getElementById('editStatus').value = electionStatus;

            // Show the modal
            const modal = new bootstrap.Modal(document.getElementById('editElectionModal'));
            modal.show();
        });
    });

    // Handle form submission
    document.getElementById('saveElectionChanges').addEventListener('click', function() {
        const form = document.getElementById('editElectionForm');
        const formData = new FormData(form);

        // Validate dates
        const startDate = new Date(formData.get('start_date'));
        const endDate = new Date(formData.get('end_date'));
        
        if (endDate <= startDate) {
            alert('End date must be after start date');
            return;
        }

        // Show loading state
        const saveButton = this;
        const originalText = saveButton.innerHTML;
        saveButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...';
        saveButton.disabled = true;

        // Create form data object
        const data = {
            id: formData.get('id'),
            title: formData.get('title'),
            description: formData.get('description'),
            start_date: formData.get('start_date'),
            end_date: formData.get('end_date'),
            status: formData.get('status')
        };

        // Send the request
        fetch('edit_election.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success message
                const alert = document.createElement('div');
                alert.className = 'alert alert-success alert-dismissible fade show';
                alert.innerHTML = `
                    ${data.message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                `;
                document.querySelector('.container-fluid').insertBefore(alert, document.querySelector('.container-fluid').firstChild);

                // Close the modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('editElectionModal'));
                modal.hide();

                // Reload the page after a short delay
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                throw new Error(data.message || 'Error updating election');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            const alert = document.createElement('div');
            alert.className = 'alert alert-danger alert-dismissible fade show';
            alert.innerHTML = `
                ${error.message || 'An error occurred while saving changes.'}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            `;
            document.querySelector('.container-fluid').insertBefore(alert, document.querySelector('.container-fluid').firstChild);
        })
        .finally(() => {
            // Reset button state
            saveButton.innerHTML = originalText;
            saveButton.disabled = false;
        });
    });

    // Handle delete confirmation
    document.getElementById('confirmDelete').addEventListener('click', function() {
        if (electionToDelete) {
            window.location.href = 'delete_election.php?id=' + electionToDelete;
        }
    });
});
</script> 

<?php require_once "includes/footer.php"; ?> 