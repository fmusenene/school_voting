<?php
session_start();
require_once "../config/database.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: /school_voting/admin/login.php");
    exit();
}

require_once "includes/header.php";

// Get elections statistics
$elections_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
FROM elections";
$elections_result = $conn->query($elections_sql)->fetch(PDO::FETCH_ASSOC);

// Get total positions
$positions_sql = "SELECT COUNT(*) as total FROM positions";
$positions_total = $conn->query($positions_sql)->fetch(PDO::FETCH_ASSOC)['total'];

// Get total candidates
$candidates_sql = "SELECT COUNT(*) as total FROM candidates";
$candidates_total = $conn->query($candidates_sql)->fetch(PDO::FETCH_ASSOC)['total'];

// Get total voting codes used
$codes_sql = "SELECT COUNT(*) as total FROM voting_codes WHERE is_used = 1";
$codes_used = $conn->query($codes_sql)->fetch(PDO::FETCH_ASSOC)['total'];

// Get total voting codes
$total_codes_sql = "SELECT COUNT(*) as total FROM voting_codes";
$total_codes = $conn->query($total_codes_sql)->fetch(PDO::FETCH_ASSOC)['total'];

// Get recent voting activity with more details
$recent_votes_sql = "SELECT 
    v.id,
    v.created_at,
    v.candidate_id,
    v.election_id,
    v.voting_code_id,
    c.name as candidate_name,
    c.photo as candidate_photo,
    p.title as position_title,
    e.title as election_title,
    vc.code as voting_code
FROM votes v
LEFT JOIN candidates c ON v.candidate_id = c.id
LEFT JOIN positions p ON c.position_id = p.id
LEFT JOIN elections e ON p.election_id = e.id
LEFT JOIN voting_codes vc ON v.voting_code_id = vc.id
ORDER BY v.created_at DESC
LIMIT 5";

try {
    $stmt = $conn->prepare($recent_votes_sql);
    $stmt->execute();
    $recent_votes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug information
    echo "<!-- Debug: Number of recent votes found: " . count($recent_votes) . " -->";
    
    if (count($recent_votes) === 0) {
        // Check for any votes
        $check_votes = "SELECT COUNT(*) as count FROM votes";
        $votes_count = $conn->query($check_votes)->fetch(PDO::FETCH_ASSOC);
        echo "<!-- Debug: Total votes in database: " . $votes_count['count'] . " -->";
    }
} catch (PDOException $e) {
    error_log("Error fetching recent votes: " . $e->getMessage());
    echo "<!-- Debug: SQL Error: " . htmlspecialchars($e->getMessage()) . " -->";
    $recent_votes = [];
}
?>

<style>
.dashboard-title {
    font-size: 2rem;
    font-weight: 500;
    color: #2c3e50;
    margin-bottom: 2rem;
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

.stats-detail {
    font-size: 0.8rem;
    color: #95a5a6;
    line-height: 1.1;
}

.activity-table {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.activity-table th {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    color: #2c3e50;
    font-weight: 600;
    padding: 1rem;
}

.activity-table td {
    padding: 1rem;
    vertical-align: middle;
}

.activity-table tbody tr {
    transition: background-color 0.2s ease;
}

.activity-table tbody tr:hover {
    background-color: #f8f9fa;
}

.status-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 50px;
    font-size: 0.85rem;
    font-weight: 500;
}

.status-active {
    background-color: #e3fcef;
    color: #00a854;
}

.status-pending {
    background-color: #fff7e6;
    color: #fa8c16;
}

.status-completed {
    background-color: #f0f0f0;
    color: #595959;
}

.voting-code {
    font-family: monospace;
    background: #f8f9fa;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    color: #2c3e50;
    font-size: 0.9rem;
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

.bi {
    font-size: 1rem;
}

.badge {
    padding: 0.5rem 0.75rem;
    font-weight: 500;
}
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0 text-gray-800">Dashboard</h1>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newElectionModal">
            <i class="bi bi-plus-circle"></i> New Election
        </button>
    </div>

    <div class="row g-4">
        <!-- Elections Card -->
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: #e3f2fd; color: #1976d2;">
                    <i class="bi bi-calendar2-check"></i>
                </div>
                <h3 class="stats-number"><?php echo $elections_result['total']; ?></h3>
                <p class="stats-label">Elections</p>
                <div class="stats-detail">
                    <span class="text-success"><?php echo $elections_result['active']; ?> Active</span> •
                    <span class="text-warning"><?php echo $elections_result['pending']; ?> Pending</span> •
                    <span class="text-secondary"><?php echo $elections_result['completed']; ?> Completed</span>
                </div>
            </div>
        </div>

        <!-- Positions Card -->
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: #e8f5e9; color: #388e3c;">
                    <i class="bi bi-person-badge"></i>
                </div>
                <h3 class="stats-number"><?php echo $positions_total; ?></h3>
                <p class="stats-label">Positions</p>
                <div class="stats-detail">Total registered positions</div>
            </div>
        </div>

        <!-- Candidates Card -->
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: #fff3e0; color: #f57c00;">
                    <i class="bi bi-people"></i>
                </div>
                <h3 class="stats-number"><?php echo $candidates_total; ?></h3>
                <p class="stats-label">Candidates</p>
                <div class="stats-detail">Total registered candidates</div>
            </div>
        </div>

        <!-- Votes Cast Card -->
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: #fce4ec; color: #c2185b;">
                    <i class="bi bi-check2-square"></i>
                </div>
                <h3 class="stats-number"><?php echo $codes_used; ?></h3>
                <p class="stats-label">Votes Cast</p>
                <div class="stats-detail">Out of <?php echo $total_codes; ?> voting codes</div>
            </div>
        </div>
    </div>

    <!-- Recent Voting Activity -->
    <div class="activity-table table-responsive mt-4">
        <div class="d-flex justify-content-between align-items-center p-3">
            <h2 class="fs-4 mb-0">Recent Voting Activity</h2>
            <span class="badge bg-primary"><?php echo count($recent_votes); ?> recent votes</span>
        </div>
        <?php if (count($recent_votes) > 0): ?>
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Candidate</th>
                        <th>Position</th>
                        <th>Election</th>
                        <th>Voting Code</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_votes as $vote): ?>
                        <tr>
                            <td>
                                <div class="text-primary"><?php echo date('h:i A', strtotime($vote['created_at'])); ?></div>
                                <small class="text-muted"><?php echo date('M d, Y', strtotime($vote['created_at'])); ?></small>
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <?php if (!empty($vote['candidate_photo'])): ?>
                                        <img src="../<?php echo htmlspecialchars($vote['candidate_photo']); ?>" 
                                             alt="<?php echo htmlspecialchars($vote['candidate_name']); ?>"
                                             class="rounded-circle me-2"
                                             style="width: 32px; height: 32px; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2"
                                             style="width: 32px; height: 32px;">
                                            <i class="bi bi-person"></i>
                                        </div>
                                    <?php endif; ?>
                                    <span><?php echo htmlspecialchars($vote['candidate_name'] ?? 'N/A'); ?></span>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($vote['position_title'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($vote['election_title'] ?? 'N/A'); ?></td>
                            <td><span class="voting-code"><?php echo htmlspecialchars($vote['voting_code'] ?? 'N/A'); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="text-center p-4">
                <div class="text-muted">
                    <i class="bi bi-inbox fs-2"></i>
                    <p class="mt-2">No voting activity recorded yet</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add this before the closing body tag -->
<div class="modal fade" id="newElectionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New Election</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="newElectionForm" method="post" action="elections.php">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="title" class="form-label">Election Title</label>
                        <input type="text" class="form-control" id="title" name="title" required>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status" required>
                            <option value="pending">Pending</option>
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-lg"></i> Create Election
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('newElectionForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Show loading state
    const submitButton = this.querySelector('button[type="submit"]');
    const originalText = submitButton.innerHTML;
    submitButton.disabled = true;
    submitButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Creating...';
    
    const formData = new FormData(this);
    
    fetch('elections.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Election created successfully');
            // Close modal using Bootstrap's modal method
            const modalElement = document.getElementById('newElectionModal');
            const modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) {
                modal.hide();
            } else {
                // If modal instance doesn't exist, create one and hide it
                const newModal = new bootstrap.Modal(modalElement);
                newModal.hide();
            }
            // Redirect after a short delay
            setTimeout(() => {
                window.location.href = 'elections.php';
            }, 1000);
        } else {
            showNotification(data.message || 'Error creating election', 'error');
        }
    })
    .catch(error => {
        showNotification('Error creating election', 'error');
    })
    .finally(() => {
        // Reset button state
        submitButton.disabled = false;
        submitButton.innerHTML = originalText;
    });
});

// Add date validation
document.getElementById('end_date').addEventListener('change', function() {
    const startDate = document.getElementById('start_date').value;
    const endDate = this.value;
    
    if (startDate && endDate && endDate < startDate) {
        showNotification('End date cannot be earlier than start date', 'error');
        this.value = '';
    }
});

document.getElementById('start_date').addEventListener('change', function() {
    const startDate = this.value;
    const endDate = document.getElementById('end_date').value;
    
    if (startDate && endDate && endDate < startDate) {
        showNotification('End date cannot be earlier than start date', 'error');
        document.getElementById('end_date').value = '';
    }
});
</script>

<?php require_once "includes/footer.php"; ?> 