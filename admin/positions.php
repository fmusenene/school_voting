<?php
session_start();
require_once "../config/database.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: /school_voting/admin/login.php");
    exit();
}

// Get all elections for the filter dropdown
$sql = "SELECT * FROM elections ORDER BY start_date DESC";
$stmt = $conn->prepare($sql);
$stmt->execute();
$elections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get selected election (default to showing all)
$selected_election = isset($_GET['election']) ? $_GET['election'] : 'all';

// Get positions with statistics
$sql = "SELECT p.*, e.title as election_title,
        (SELECT COUNT(*) FROM candidates c WHERE c.position_id = p.id) as candidate_count,
        (SELECT COUNT(*) FROM votes v 
         INNER JOIN candidates c ON v.candidate_id = c.id 
         WHERE c.position_id = p.id) as vote_count,
        (SELECT c.name
         FROM candidates c
         INNER JOIN votes v ON c.id = v.candidate_id
         WHERE c.position_id = p.id
         GROUP BY c.id
         ORDER BY COUNT(*) DESC
         LIMIT 1) as winner_name
        FROM positions p
        LEFT JOIN elections e ON p.election_id = e.id";

if ($selected_election !== 'all') {
    $sql .= " WHERE p.election_id = ?";
}
$sql .= " ORDER BY e.start_date DESC, p.title ASC";

$stmt = $conn->prepare($sql);
if ($selected_election !== 'all') {
    $stmt->execute([$selected_election]);
} else {
    $stmt->execute();
}
$positions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get overall statistics
$stats = [
    'total_positions' => count($positions),
    'total_candidates' => 0,
    'total_votes' => 0
];

foreach ($positions as $position) {
    $stats['total_candidates'] += $position['candidate_count'];
    $stats['total_votes'] += $position['vote_count'];
}

require_once "includes/header.php";
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0 text-gray-800">All Positions</h1>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newPositionModal">
            <i class="bi bi-plus-circle"></i> New Position
        </button>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Total Positions</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['total_positions']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-person-badge fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Total Candidates</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['total_candidates']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-people fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Total Votes Cast</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['total_votes']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-check-square fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="mb-4">
        <div class="d-flex align-items-center">
            <label class="me-2 mb-0">Filter by Election:</label>
            <select class="form-select" style="width: auto;" onchange="window.location.href='?election=' + this.value">
                <option value="all" <?php echo $selected_election === 'all' ? 'selected' : ''; ?>>All Elections</option>
                <?php foreach ($elections as $election): ?>
                    <option value="<?php echo $election['id']; ?>" 
                            <?php echo $selected_election == $election['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($election['title']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Positions List -->
    <?php foreach ($positions as $position): ?>
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary"><?php echo htmlspecialchars($position['title']); ?></h6>
                <div>
                    <button type="button" class="btn btn-sm btn-info" onclick="editPosition(<?php echo $position['id']; ?>)">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <a href="candidates.php?position_id=<?php echo $position['id']; ?>&election_id=<?php echo $position['election_id']; ?>" class="btn btn-sm btn-success">
                        <i class="bi bi-people"></i>
                    </a>
                    <button type="button" class="btn btn-sm btn-danger" onclick="confirmDelete(<?php echo $position['id']; ?>)">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="small text-muted mb-2">
                    <i class="bi bi-calendar-event"></i> <?php echo htmlspecialchars($position['election_title']); ?>
                </div>
                <p class="mb-3"><?php echo htmlspecialchars($position['description']); ?></p>
                <div class="d-flex gap-3">
                    <div class="small">
                        <i class="bi bi-people text-primary"></i> <?php echo $position['candidate_count']; ?> Candidates
                    </div>
                    <div class="small">
                        <i class="bi bi-check-square text-success"></i> <?php echo $position['vote_count']; ?> Votes
                    </div>
                    <?php if ($position['vote_count'] > 0 && $position['winner_name']): ?>
                    <div class="small">
                        <i class="bi bi-trophy text-warning"></i> Winner: <?php echo htmlspecialchars($position['winner_name']); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Add Position Modal -->
<div class="modal fade" id="newPositionModal" tabindex="-1" aria-labelledby="newPositionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="newPositionModalLabel">Add New Position</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addPositionForm" method="POST" action="add_position.php">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="title" class="form-label">Position Title</label>
                        <input type="text" class="form-control" id="title" name="title" required>
                    </div>
                    <div class="mb-3">
                        <label for="election_id" class="form-label">Election</label>
                        <select class="form-select" id="election_id" name="election_id" required>
                            <option value="">Select Election</option>
                            <?php foreach ($elections as $election): ?>
                                <option value="<?php echo $election['id']; ?>">
                                    <?php echo htmlspecialchars($election['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Position</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Position Modal -->
<div class="modal fade" id="editPositionModal" tabindex="-1" aria-labelledby="editPositionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editPositionModalLabel">Edit Position</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editPositionForm" method="POST">
                <input type="hidden" id="edit_position_id" name="position_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_title" class="form-label">Position Title</label>
                        <input type="text" class="form-control" id="edit_title" name="title" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_election_id" class="form-label">Election</label>
                        <select class="form-select" id="edit_election_id" name="election_id" required>
                            <option value="">Select Election</option>
                            <?php foreach ($elections as $election): ?>
                                <option value="<?php echo $election['id']; ?>">
                                    <?php echo htmlspecialchars($election['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center pb-4">
                <i class="bi bi-exclamation-circle text-warning" style="font-size: 3rem;"></i>
                <h4 class="mt-3 mb-3">Delete Position</h4>
                <p class="mb-4">Are you sure you want to delete this position? This action cannot be undone.</p>
                <input type="hidden" id="deletePositionId">
                <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="deletePosition()">Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- Notification Toast -->
<div class="position-fixed top-50 start-50 translate-middle" style="z-index: 1060;">
    <div id="notificationToast" class="notification-toast toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex notification-content">
            <div class="toast-body d-flex align-items-center">
                <span class="notification-icon me-3">
                    <i class="bi bi-check-circle-fill"></i>
                </span>
                <span id="notificationMessage"></span>
            </div>
            <button type="button" class="btn-close btn-close-white m-auto me-3" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

<style>
/* Custom Toast Styles */
.notification-toast {
    min-width: 350px;
    padding: 0;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    animation: slideIn 0.3s ease-out;
}

.notification-toast.bg-success {
    background: linear-gradient(135deg, #28a745, #20c997) !important;
}

.notification-toast.bg-danger {
    background: linear-gradient(135deg, #dc3545, #f72d49) !important;
}

.notification-toast.bg-warning {
    background: linear-gradient(135deg, #ffc107, #ffdb4d) !important;
}

.notification-content {
    padding: 16px 8px;
}

.toast-body {
    font-size: 1rem;
    font-weight: 500;
    padding: 0;
    color: white;
}

.notification-icon {
    font-size: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
}

.bg-success .notification-icon {
    color: #98ffc1;
}

.bg-danger .notification-icon {
    color: #ffa5ae;
}

.bg-warning .notification-icon {
    color: #000000;
}

.bg-warning .toast-body {
    color: #000000;
}

.btn-close-white {
    opacity: 0.8;
    transition: opacity 0.2s;
}

.btn-close-white:hover {
    opacity: 1;
}

@keyframes slideIn {
    from {
        transform: translateY(20px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

@keyframes slideOut {
    from {
        transform: translateY(0);
        opacity: 1;
    }
    to {
        transform: translateY(-20px);
        opacity: 0;
    }
}

.toast.hide {
    animation: slideOut 0.3s ease-in forwards;
}

/* Delete Confirmation Modal Styles */
#deleteConfirmModal .modal-content {
    border: none;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
}

#deleteConfirmModal .modal-header {
    padding: 1rem 1rem 0.5rem;
}

#deleteConfirmModal .modal-body {
    padding: 2rem;
}

#deleteConfirmModal .bi-exclamation-circle {
    color: #ffc107;
}

#deleteConfirmModal h4 {
    color: #4a5568;
    font-weight: 600;
}

#deleteConfirmModal p {
    color: #718096;
    font-size: 1rem;
}

#deleteConfirmModal .btn {
    padding: 0.5rem 1.5rem;
    font-weight: 500;
    border-radius: 8px;
}

#deleteConfirmModal .btn-danger {
    background: linear-gradient(135deg, #dc3545, #f72d49);
    border: none;
}

#deleteConfirmModal .btn-danger:hover {
    background: linear-gradient(135deg, #c82333, #dc3545);
}

#deleteConfirmModal .btn-secondary {
    background: #f8f9fa;
    color: #4a5568;
    border: 1px solid #e2e8f0;
}

#deleteConfirmModal .btn-secondary:hover {
    background: #e2e8f0;
}
</style>

<script>
// Notification Function
function showNotification(message, type = 'success') {
    const toast = document.getElementById('notificationToast');
    const messageElement = document.getElementById('notificationMessage');
    const iconElement = toast.querySelector('.notification-icon i');
    
    // Remove existing background classes
    toast.classList.remove('bg-success', 'bg-danger', 'bg-warning');
    
    // Add appropriate background class
    toast.classList.add(`bg-${type}`);
    
    // Update icon based on type
    iconElement.className = 'bi';
    switch(type) {
        case 'success':
            iconElement.classList.add('bi-check-circle-fill');
            break;
        case 'danger':
            iconElement.classList.add('bi-x-circle-fill');
            break;
        case 'warning':
            iconElement.classList.add('bi-exclamation-circle-fill');
            break;
    }
    
    // Set message
    messageElement.textContent = message;
    
    // Show toast
    const bsToast = new bootstrap.Toast(toast, {
        animation: true,
        autohide: true,
        delay: 3000
    });
    bsToast.show();
}

// Add Position Form Handler
document.getElementById('addPositionForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('add_position.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Position added successfully!', 'success');
            bootstrap.Modal.getInstance(document.getElementById('newPositionModal')).hide();
            window.location.reload();
        } else {
            showNotification(data.message || 'Error adding position', 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error adding position', 'danger');
    });
});

// Edit Position Functions
function editPosition(positionId) {
    fetch(`get_position.php?id=${positionId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('edit_position_id').value = data.position.id;
                document.getElementById('edit_title').value = data.position.title;
                document.getElementById('edit_election_id').value = data.position.election_id;
                document.getElementById('edit_description').value = data.position.description;
                
                const editModal = new bootstrap.Modal(document.getElementById('editPositionModal'));
                editModal.show();
            } else {
                showNotification(data.message || 'Error loading position data', 'danger');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Error loading position data', 'danger');
        });
}

// Edit Position Form Handler
document.getElementById('editPositionForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const positionId = document.getElementById('edit_position_id').value;
    
    fetch('update_position.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Position updated successfully!', 'success');
            bootstrap.Modal.getInstance(document.getElementById('editPositionModal')).hide();
            window.location.reload();
        } else {
            showNotification(data.message || 'Error updating position', 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error updating position', 'danger');
    });
});

// Delete Position Functions
function confirmDelete(positionId) {
    document.getElementById('deletePositionId').value = positionId;
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    deleteModal.show();
}

function deletePosition() {
    const positionId = document.getElementById('deletePositionId').value;
    
    fetch(`delete_position.php?id=${positionId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Position deleted successfully!', 'success');
                bootstrap.Modal.getInstance(document.getElementById('deleteConfirmModal')).hide();
                window.location.reload();
            } else {
                showNotification(data.message || 'Error deleting position', 'danger');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Error deleting position', 'danger');
        });
}

// Update Edit button click handlers
document.querySelectorAll('.btn-primary').forEach(button => {
    if (button.getAttribute('href') && button.getAttribute('href').includes('edit_position.php')) {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const positionId = this.getAttribute('href').split('=')[1];
            editPosition(positionId);
        });
    }
});
</script>

<?php require_once "includes/footer.php"; ?> 