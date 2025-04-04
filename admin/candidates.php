<?php
session_start();
require_once "../config/database.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: /school_voting/admin/login.php");
    exit();
}

require_once "includes/header.php";

// Get selected position and election
$selected_position = isset($_GET['position_id']) ? $_GET['position_id'] : 'all';
$selected_election = isset($_GET['election_id']) ? $_GET['election_id'] : 'all';

// Get all elections for the filter dropdown
$sql = "SELECT * FROM elections ORDER BY start_date DESC";
$stmt = $conn->prepare($sql);
$stmt->execute();
$elections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all positions for the filter dropdown
$positions_sql = "SELECT p.*, e.title as election_title 
                 FROM positions p 
                 LEFT JOIN elections e ON p.election_id = e.id";
if ($selected_election !== 'all') {
    $positions_sql .= " WHERE p.election_id = ?";
}
$positions_sql .= " ORDER BY e.start_date DESC, p.title ASC";

$stmt = $conn->prepare($positions_sql);
if ($selected_election !== 'all') {
    $stmt->execute([$selected_election]);
} else {
    $stmt->execute();
}
$positions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get candidates with position and election info
$sql = "SELECT c.*, p.title as position_title, e.title as election_title, 
               (SELECT COUNT(*) FROM votes v WHERE v.candidate_id = c.id) as vote_count
        FROM candidates c
        LEFT JOIN positions p ON c.position_id = p.id
        LEFT JOIN elections e ON p.election_id = e.id
        WHERE 1=1";

$params = [];

if ($selected_election !== 'all') {
    $sql .= " AND p.election_id = ?";
    $params[] = $selected_election;
}

if ($selected_position !== 'all') {
    $sql .= " AND c.position_id = ?";
    $params[] = $selected_position;
}

$sql .= " ORDER BY e.start_date DESC, p.title ASC, c.name ASC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = [
    'total_candidates' => count($candidates),
    'total_positions' => count($positions),
    'total_votes' => 0
];

foreach ($candidates as $candidate) {
    $stats['total_votes'] += $candidate['vote_count'];
}

// Get candidates per election
$election_stats_sql = "SELECT 
                        e.title as election_title,
                        COUNT(c.id) as candidate_count
                      FROM elections e
                      LEFT JOIN positions p ON e.id = p.election_id
                      LEFT JOIN candidates c ON p.id = c.position_id
                      GROUP BY e.id, e.title
                      ORDER BY e.start_date DESC";
$election_stats_stmt = $conn->prepare($election_stats_sql);
$election_stats_stmt->execute();
$election_stats = $election_stats_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Set header for JSON response
    header('Content-Type: application/json');
    
    if (isset($_POST['delete_candidates'])) {
        if (isset($_POST['selected_candidates']) && is_array($_POST['selected_candidates'])) {
            $selected_candidates = array_map('intval', $_POST['selected_candidates']);
            $candidates_string = implode(',', $selected_candidates);
            
            // Delete the selected candidates
            $delete_sql = "DELETE FROM candidates WHERE id IN ($candidates_string)";
            if ($conn->exec($delete_sql)) {
                echo json_encode(['success' => true, 'message' => 'Selected candidates deleted successfully!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error deleting candidates']);
            }
            exit;
        }
    } else if (isset($_POST['action']) && $_POST['action'] === 'create') {
        $name = $_POST['name'];
        $position_id = $_POST['position_id'];
        $description = $_POST['description'];
        $photo = isset($_FILES['photo']) ? $_FILES['photo'] : null;

        // Handle photo upload
        $photo_path = '';
        if ($photo && $photo['error'] === UPLOAD_ERR_OK) {
            $upload_dir = "../uploads/candidates/";
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $file_extension = strtolower(pathinfo($photo['name'], PATHINFO_EXTENSION));
            $new_filename = uniqid() . '.' . $file_extension;
            $target_path = $upload_dir . $new_filename;

            if (move_uploaded_file($photo['tmp_name'], $target_path)) {
                $photo_path = 'uploads/candidates/' . $new_filename;
            }
        }

        $sql = "INSERT INTO candidates (name, position_id, description, photo) 
                VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        if ($stmt->execute([$name, $position_id, $description, $photo_path])) {
            $candidate_id = $conn->lastInsertId();
            
            // Get the newly added candidate with position and election info
            $new_candidate_sql = "SELECT c.*, p.title as position_title, e.title as election_title 
                                FROM candidates c 
                                LEFT JOIN positions p ON c.position_id = p.id 
                                LEFT JOIN elections e ON p.election_id = e.id 
                                WHERE c.id = ?";
            $new_candidate_stmt = $conn->prepare($new_candidate_sql);
            $new_candidate_stmt->execute([$candidate_id]);
            $new_candidate = $new_candidate_stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'message' => 'Candidate created successfully!',
                'candidate' => $new_candidate
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Error creating candidate'
            ]);
        }
        exit;
    }
}
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <?php if ($selected_position !== 'all'): ?>
                <?php 
                    $position_title = '';
                    foreach ($positions as $position) {
                        if ($position['id'] == $selected_position) {
                            $position_title = $position['title'];
                            break;
                        }
                    }
                    echo "Candidates for " . htmlspecialchars($position_title);
                ?>
            <?php else: ?>
                All Candidates
            <?php endif; ?>
        </h1>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newCandidateModal">
            <i class="bi bi-plus-circle"></i> New Candidate
        </button>
    </div>

    <?php if (isset($success)): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2 stats-card">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
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
            <div class="card border-left-success shadow h-100 py-2 stats-card">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Total Positions</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['total_positions']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-briefcase fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2 stats-card">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Total Votes</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['total_votes']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-bar-chart fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="mb-4">
        <div class="d-flex align-items-center gap-3">
            <div>
                <label class="me-2 mb-0">Election:</label>
                <select class="form-select" style="width: auto;" onchange="window.location.href='?election_id=' + this.value + '&position_id=<?php echo $selected_position; ?>'">
                    <option value="all" <?php echo $selected_election === 'all' ? 'selected' : ''; ?>>All Elections</option>
                    <?php foreach ($elections as $election): ?>
                        <option value="<?php echo $election['id']; ?>" 
                                <?php echo $selected_election == $election['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($election['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="me-2 mb-0">Position:</label>
                <select class="form-select" style="width: auto;" onchange="window.location.href='?position_id=' + this.value + '&election_id=<?php echo $selected_election; ?>'">
                    <option value="all" <?php echo $selected_position === 'all' ? 'selected' : ''; ?>>All Positions</option>
                    <?php foreach ($positions as $position): ?>
                        <option value="<?php echo $position['id']; ?>" 
                                <?php echo $selected_position == $position['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($position['title']); ?>
                            (<?php echo htmlspecialchars($position['election_title']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="post">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="form-check-input select-all"></th>
                                <th>Photo</th>
                                <th>Name</th>
                                <th>Position</th>
                                <th>Election</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($candidates as $candidate): ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_candidates[]" value="<?php echo $candidate['id']; ?>" class="form-check-input candidate-checkbox"></td>
                                    <td>
                                        <?php if ($candidate['photo']): ?>
                                            <img src="../<?php echo htmlspecialchars($candidate['photo']); ?>" 
                                                 alt="<?php echo htmlspecialchars($candidate['name']); ?>"
                                                 class="rounded-circle"
                                                 style="width: 40px; height: 40px; object-fit: cover;">
                                        <?php else: ?>
                                            <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center"
                                                 style="width: 40px; height: 40px;">
                                                <i class="bi bi-person"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($candidate['name']); ?></td>
                                    <td><?php echo htmlspecialchars($candidate['position_title']); ?></td>
                                    <td><?php echo htmlspecialchars($candidate['election_title']); ?></td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <button type="button" class="btn btn-sm btn-info" onclick="editCandidate(<?php echo $candidate['id']; ?>)">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <a href="candidates.php?position_id=<?php echo $candidate['position_id']; ?>" class="btn btn-sm btn-success">
                                                <i class="bi bi-people"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-danger" onclick="deleteCandidate(<?php echo $candidate['id']; ?>)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="submit" name="delete_candidates" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete the selected candidates?')">
                    <i class="bi bi-trash"></i> Delete Selected
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Add Candidate Modal -->
<div class="modal fade" id="newCandidateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Candidate</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addCandidateForm" method="post" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    
                    <?php if ($selected_election === 'all'): ?>
                    <div class="mb-3">
                        <label class="form-label">Election</label>
                        <select class="form-select" id="electionSelect" required>
                            <option value="">Select Election</option>
                            <?php foreach ($elections as $election): ?>
                                <option value="<?php echo $election['id']; ?>">
                                    <?php echo htmlspecialchars($election['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Position</label>
                        <select class="form-select" name="position_id" id="positionSelect" required>
                            <option value="">Select Position</option>
                            <?php foreach ($positions as $position): ?>
                                <option value="<?php echo $position['id']; ?>" 
                                        data-election="<?php echo $position['election_id']; ?>">
                                    <?php echo htmlspecialchars($position['title'] . ' (' . $position['election_title'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Photo</label>
                        <input type="file" class="form-control" name="photo" accept="image/*">
                        <div class="form-text">Upload a photo for the candidate (optional)</div>
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

<!-- Edit Candidate Modal -->
<div class="modal fade" id="editCandidateModal" tabindex="-1" aria-labelledby="editCandidateModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editCandidateModalLabel">Edit Candidate</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editCandidateForm">
                    <input type="hidden" id="edit_candidate_id" name="candidate_id">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Name</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="edit_position_id" class="form-label">Position</label>
                        <select class="form-select" id="edit_position_id" name="position_id" required>
                            <?php foreach ($positions as $position): ?>
                                <option value="<?php echo $position['id']; ?>">
                                    <?php echo htmlspecialchars($position['title'] . ' (' . $position['election_title'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_photo" class="form-label">Photo</label>
                        <input type="file" class="form-control" id="edit_photo" name="photo" accept="image/*">
                        <div class="form-text">Leave empty to keep current photo</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="updateCandidate()">Update Candidate</button>
            </div>
        </div>
    </div>
</div>

<style>
.border-left-primary {
    border-left: 4px solid #4e73df !important;
}
.border-left-success {
    border-left: 4px solid #1cc88a !important;
}
.border-left-warning {
    border-left: 4px solid #f6c23e !important;
}
.text-gray-300 {
    color: #dddfeb !important;
}
.text-gray-800 {
    color: #5a5c69 !important;
}

/* Hover effects for stats cards */
.stats-card {
    transition: all 0.3s ease-in-out;
    cursor: pointer;
}

.stats-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
}

.stats-card:hover .text-gray-300 {
    transform: scale(1.1);
    transition: transform 0.3s ease-in-out;
}

.stats-card:hover .text-primary {
    color: #2e59d9 !important;
}

.stats-card:hover .text-success {
    color: #169b6b !important;
}

.stats-card:hover .text-warning {
    color: #d4a106 !important;
}

.card {
    transition: all 0.3s ease;
}
.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}
.card .icon {
    transition: all 0.3s ease;
}
.card:hover .icon {
    transform: scale(1.1);
}
.toast-container {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    z-index: 9999;
}
.toast {
    background: white;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    padding: 1rem 1.5rem;
    margin-bottom: 1rem;
    min-width: 300px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.toast.success {
    border-left: 4px solid #28a745;
}
.toast.error {
    border-left: 4px solid #dc3545;
}
.toast.warning {
    border-left: 4px solid #ffc107;
}
.toast .toast-body {
    margin: 0;
    padding: 0;
}
.toast .close {
    margin-left: 1rem;
    font-size: 1.25rem;
    cursor: pointer;
    opacity: 0.7;
}
.toast .close:hover {
    opacity: 1;
}

/* Add these styles at the top of your existing styles */
.notification-container {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    z-index: 1050;
    width: 90%;
    max-width: 400px;
    text-align: center;
}

.notification {
    padding: 1rem;
    margin-bottom: 1rem;
    border-radius: 8px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    animation: slideIn 0.3s ease-out;
}

.notification.success {
    background-color: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

.notification.error {
    background-color: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

@keyframes slideIn {
    from {
        transform: translateY(-20px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

/* Add a semi-transparent overlay behind the notification */
.notification-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 1040;
    display: none;
}

.notification-overlay.show {
    display: block;
}

.confirmation-dialog {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 1060;
    align-items: center;
    justify-content: center;
}

.confirmation-dialog.show {
    display: flex;
}

.confirmation-content {
    background: white;
    border-radius: 8px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    width: 90%;
    max-width: 400px;
    animation: slideIn 0.3s ease-out;
}

.confirmation-header {
    padding: 1rem;
    border-bottom: 1px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.confirmation-header h5 {
    margin: 0;
    color: #dc3545;
}

.confirmation-body {
    padding: 1.5rem;
    text-align: center;
}

.confirmation-footer {
    padding: 1rem;
    border-top: 1px solid #dee2e6;
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
}

.confirmation-footer button {
    min-width: 80px;
}
</style>

<!-- Add this right after the opening body tag -->
<div class="notification-overlay" id="notificationOverlay"></div>
<div class="notification-container" id="notificationContainer"></div>

<!-- Update the PHP notification section -->
<?php if (isset($_SESSION['success'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            showNotification('<?php echo $_SESSION['success']; ?>', 'success');
            <?php unset($_SESSION['success']); ?>
        });
    </script>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            showNotification('<?php echo $_SESSION['error']; ?>', 'error');
            <?php unset($_SESSION['error']); ?>
        });
    </script>
<?php endif; ?>

<!-- Add this JavaScript at the bottom of the file, before the closing body tag -->
<script>
// Function to show notifications
function showNotification(message, type = 'success') {
    const overlay = document.getElementById('notificationOverlay');
    const container = document.getElementById('notificationContainer');
    
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.textContent = message;
    
    overlay.classList.add('show');
    container.appendChild(notification);
    
    setTimeout(() => {
        notification.remove();
        if (container.children.length === 0) {
            overlay.classList.remove('show');
        }
    }, 3000);
}

// Function to get candidate data and populate edit modal
function editCandidate(id) {
    fetch(`get_candidate.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const candidate = data.candidate;
                document.getElementById('edit_candidate_id').value = candidate.id;
                document.getElementById('edit_name').value = candidate.name;
                document.getElementById('edit_description').value = candidate.description || '';
                document.getElementById('edit_position_id').value = candidate.position_id;
                
                // Show the modal
                const modal = new bootstrap.Modal(document.getElementById('editCandidateModal'));
                modal.show();
            } else {
                showNotification(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Error fetching candidate data', 'error');
        });
}

// Function to update candidate
function updateCandidate() {
    const form = document.getElementById('editCandidateForm');
    const formData = new FormData(form);
    
    // Show loading state
    const submitButton = document.querySelector('#editCandidateModal .btn-primary');
    const originalText = submitButton.innerHTML;
    submitButton.disabled = true;
    submitButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Updating...';
    
    fetch('edit_candidate.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message
            showNotification(data.message, 'success');
            
            // Update the table row
            const candidate = data.candidate;
            const row = document.querySelector(`tr[data-id="${candidate.id}"]`);
            if (row) {
                row.querySelector('td:nth-child(3)').textContent = candidate.name;
                row.querySelector('td:nth-child(4)').textContent = candidate.position_title;
                row.querySelector('td:nth-child(5)').textContent = candidate.election_title;
                
                if (candidate.photo) {
                    const img = row.querySelector('td:nth-child(2) img');
                    if (img) {
                        img.src = '../' + candidate.photo;
                    }
                }
            }
            
            // Close the modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('editCandidateModal'));
            modal.hide();
            
            // Reload the page after a short delay
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showNotification(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error updating candidate', 'error');
    })
    .finally(() => {
        // Reset button state
        submitButton.disabled = false;
        submitButton.innerHTML = originalText;
    });
}

// Add event listeners for select all checkbox
document.querySelector('.select-all').addEventListener('change', function() {
    document.querySelectorAll('.candidate-checkbox').forEach(checkbox => {
        checkbox.checked = this.checked;
    });
});

<?php if ($selected_election === 'all'): ?>
// Filter positions based on selected election
document.getElementById('electionSelect').addEventListener('change', function() {
    const selectedElection = this.value;
    const positionSelect = document.getElementById('positionSelect');
    const options = positionSelect.getElementsByTagName('option');
    
    // Show/hide options based on selected election
    for (let option of options) {
        if (option.value === '') continue; // Skip the default "Select Position" option
        
        if (option.getAttribute('data-election') === selectedElection) {
            option.style.display = '';
        } else {
            option.style.display = 'none';
        }
    }
    
    // Reset position selection
    positionSelect.value = '';
});
<?php endif; ?>

// Handle form submission with AJAX
document.getElementById('addCandidateForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Show loading state
    const submitButton = this.querySelector('button[type="submit"]');
    const originalText = submitButton.innerHTML;
    submitButton.disabled = true;
    submitButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Adding...';
    
    const formData = new FormData(this);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message
            showNotification(data.message, 'success');
            
            // Close the modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('newCandidateModal'));
            modal.hide();
            
            // Reset the form
            this.reset();
            
            // Reload the page after a short delay
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showNotification(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error adding candidate', 'error');
    })
    .finally(() => {
        // Reset button state
        submitButton.disabled = false;
        submitButton.innerHTML = originalText;
    });
});

// Function to delete candidate
function deleteCandidate(id) {
    if (confirm('Are you sure you want to delete this candidate?')) {
        fetch('delete_candidate.php?id=' + id)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error deleting candidate', 'error');
            });
    }
}
</script>

<!-- Add this HTML right after the notification container -->
<div class="confirmation-dialog" id="confirmationDialog">
    <div class="confirmation-content">
        <div class="confirmation-header">
            <h5>Confirm Delete</h5>
            <button type="button" class="btn-close" onclick="closeConfirmationDialog()"></button>
        </div>
        <div class="confirmation-body">
            <p>Are you sure you want to delete this candidate?</p>
            <p class="text-muted">This action cannot be undone.</p>
        </div>
        <div class="confirmation-footer">
            <button type="button" class="btn btn-secondary" onclick="closeConfirmationDialog()">Cancel</button>
            <button type="button" class="btn btn-danger" onclick="confirmDelete()">Delete</button>
        </div>
    </div>
</div>

<?php require_once "includes/footer.php"; ?> 