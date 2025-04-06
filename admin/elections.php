<?php
// Session MUST be started before any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/database.php";
require_once "includes/session.php"; // Includes isAdminLoggedIn()

// --- Security Check ---
if (!isAdminLoggedIn()) {
    header("Location: login.php"); // Use relative path within admin
    exit();
}

// --- Configuration ---
date_default_timezone_set('Asia/Manila'); // Adjust timezone if needed
// error_reporting(E_ALL); // Dev
// ini_set('display_errors', 1); // Dev
ini_set('display_errors', 0); // Prod
error_reporting(0); // Prod

// --- Flash Message Handling ---
$success_message = $_SESSION['success'] ?? null;
$error_message = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']); // Clear after retrieving

// --- Handle Create Election Form Submission (POST to self) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $status = $_POST['status'] ?? '';

    // Basic Validation
    if (empty($title) || empty($start_date) || empty($end_date) || empty($status)) {
        $_SESSION['error'] = "Title, Start Date, End Date, and Status are required.";
    } elseif (strtotime($end_date) <= strtotime($start_date)) {
         $_SESSION['error'] = "End Date must be after Start Date.";
    } else {
        try {
            $sql = "INSERT INTO elections (title, description, start_date, end_date, status)
                    VALUES (:title, :description, :start_date, :end_date, :status)";
            $stmt = $conn->prepare($sql);

            if ($stmt->execute([
                ':title' => $title,
                ':description' => $description,
                ':start_date' => $start_date,
                ':end_date' => $end_date,
                ':status' => $status
            ])) {
                $_SESSION['success'] = "Election created successfully!";
            } else {
                $_SESSION['error'] = "Error creating election. Database error occurred.";
                error_log("Election Creation Error: " . implode(", ", $stmt->errorInfo()));
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
            error_log("Election Creation PDOException: " . $e->getMessage());
        }
    }

    // Redirect to GET to prevent form resubmission
    header("Location: elections.php");
    exit();
}

// --- Data Fetching ---
$fetch_error = null;
$stats = ['total' => 0, 'active' => 0, 'pending' => 0, 'completed' => 0];
$elections = [];

try {
    // Get elections statistics
    $stats_sql = "SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
    FROM elections";
    $stats_result = $conn->query($stats_sql)->fetch(PDO::FETCH_ASSOC);
    if($stats_result) {
        $stats = $stats_result; // Assign fetched stats
    }

    // Get all elections with their positions and votes count
    $elections_sql = "SELECT
        e.*,
        (SELECT COUNT(*) FROM positions p WHERE p.election_id = e.id) as position_count,
        (SELECT COUNT(*) FROM votes v WHERE v.election_id = e.id) as vote_count
    FROM elections e
    ORDER BY e.created_at DESC"; // Simpler query if detailed vote counts aren't essential on this page
    $elections = $conn->query($elections_sql)->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $fetch_error = "Database error loading election data: " . $e->getMessage();
    error_log("Elections Page Fetch Error: " . $e->getMessage());
    // Reset data on error
    $stats = ['total' => 0, 'active' => 0, 'pending' => 0, 'completed' => 0];
    $elections = [];
}

// --- Include Header (AFTER PHP logic) ---
require_once "includes/header.php";
// Retrieve nonce set in header.php or generate if needed
$nonce = $_SESSION['csp_nonce'] ?? base64_encode(random_bytes(16));
?>

<style nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
    /* Inherit root variables from header.php */
    /* Page Header */
    .page-header { margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border-color); }
    .page-header h1 { color: var(--primary-dark); font-family: var(--font-family-primary); font-weight: 700; }
    .page-header .btn { box-shadow: var(--shadow-sm); font-weight: 600; font-size: 0.875rem; }

    /* Stats Cards (using styles similar to candidates.php) */
    .stats-card { transition: all 0.25s ease-out; border: none; border-left: 4px solid var(--primary-color); border-radius: var(--border-radius); background-color: var(--white-color); box-shadow: var(--shadow-sm); height: 100%; }
    .stats-card .card-body { padding: 1.1rem 1.25rem; position: relative;}
    .stats-card:hover { transform: translateY(-3px) scale(1.01); box-shadow: var(--shadow); }
    .stats-card-icon { font-size: 1.8rem; opacity: 0.15; position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); transition: all .3s ease; }
    .stats-card:hover .stats-card-icon { opacity: 0.25; transform: translateY(-50%) scale(1.1); }
    .stats-card .stat-label { font-size: .7rem; font-weight: 700; text-transform: uppercase; margin-bottom: .1rem; color: var(--secondary-color); letter-spacing: .5px;}
    .stats-card .stat-value { font-size: 1.9rem; font-weight: 700; line-height: 1.1; color: var(--dark-color); }
    /* Specific Colors */
    .stats-card.border-left-primary { border-left-color: var(--primary-color); } .stats-card.border-left-primary .stats-card-icon { color: var(--primary-color); }
    .stats-card.border-left-success { border-left-color: var(--success-color); } .stats-card.border-left-success .stats-card-icon { color: var(--success-color); }
    .stats-card.border-left-warning { border-left-color: var(--warning-color); } .stats-card.border-left-warning .stats-card-icon { color: var(--warning-color); }
    .stats-card.border-left-secondary { border-left-color: var(--secondary-color); } .stats-card.border-left-secondary .stats-card-icon { color: var(--secondary-color); }

    /* Elections Table Card */
    .elections-card { border: none; border-radius: var(--border-radius-lg); box-shadow: var(--shadow); }
    .elections-card .card-header { background-color: var(--white-color); border-bottom: 1px solid var(--border-color); font-weight: 700; color: var(--primary-dark); padding: 1rem 1.25rem; }
    .elections-table { margin-bottom: 0; }
    .elections-table thead th { background-color: var(--gray-100); border-bottom: 2px solid var(--border-color); border-top: none; font-size: .8rem; font-weight: 700; text-transform: uppercase; color: var(--gray-600); padding: .75rem 1rem; white-space: nowrap;}
    .elections-table tbody td { padding: .8rem 1rem; vertical-align: middle; border-top: 1px solid var(--border-color); font-size: 0.9rem; }
    .elections-table tbody tr:first-child td { border-top: none; }
    .elections-table tbody tr:hover { background-color: var(--primary-light); }
    .elections-table .election-title { font-weight: 600; color: var(--dark-color); }
    .elections-table .election-description { font-size: 0.85rem; color: var(--secondary-color); display: block; max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;}
    .elections-table .action-buttons .btn { padding: 0.2rem 0.5rem; font-size: 0.8rem; margin-left: 0.25rem; }
    .elections-table .badge { font-size: 0.8rem; padding: .4em .6em;}
    .elections-table .no-elections td { text-align: center; padding: 2.5rem; }
    .elections-table .no-elections i { font-size: 2.5rem; margin-bottom: .75rem; display: block; color: var(--gray-300); }
    .elections-table .no-elections p { color: var(--secondary-color); font-size: 1rem;}
    .elections-table .text-center span { display: block; font-weight: 600; } /* Center numbers */

    /* Modals */
    .modal-header { background-color: var(--primary-light); border-bottom: 1px solid var(--border-color); }
    .modal-title { font-family: var(--font-family-primary); color: var(--primary-dark); }
    .modal-footer { background-color: var(--gray-100); border-top: 1px solid var(--border-color); }
    .was-validated .form-control:invalid, .form-control.is-invalid { border-color: var(--danger-color); background-image: none;} /* Bootstrap 5 validation */
    .was-validated .form-control:invalid:focus, .form-control.is-invalid:focus { box-shadow: 0 0 0 .25rem rgba(231, 74, 59, .25); }
    .was-validated .form-select:invalid, .form-select.is-invalid { border-color: var(--danger-color); }
    .was-validated .form-select:invalid:focus, .form-select.is-invalid:focus { border-color: var(--danger-color); box-shadow: 0 0 0 .25rem rgba(231, 74, 59, .25); }
    .invalid-feedback { font-size: .8rem; }

    /* Delete Modal */
    #deleteConfirmationModal .modal-header { background-color: var(--danger-color); color: white; }
    #deleteConfirmationModal .btn-close { filter: brightness(0) invert(1);}

</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center page-header">
        <h1 class="h2 mb-0 text-gray-800">Elections Management</h1>
        <button type="button" class="btn btn-primary btn-sm shadow-sm" data-bs-toggle="modal" data-bs-target="#createElectionModal">
            <i class="bi bi-plus-circle-fill me-1"></i> Create New Election
        </button>
    </div>

    <div id="alertPlaceholder">
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> <?php echo htmlspecialchars($success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
         <?php if ($fetch_error): ?>
            <div class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($fetch_error); ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card border-left-primary h-100">
                <div class="card-body">
                    <div>
                        <div class="stat-label text-primary">Total Elections</div>
                        <div class="stat-value" id="stat-total"><?php echo number_format($stats['total'] ?? 0); ?></div>
                    </div>
                    <i class="bi bi-calendar2-week-fill stats-card-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card border-left-success h-100">
                <div class="card-body">
                    <div>
                        <div class="stat-label text-success">Active Elections</div>
                        <div class="stat-value" id="stat-active"><?php echo number_format($stats['active'] ?? 0); ?></div>
                    </div>
                    <i class="bi bi-play-circle-fill stats-card-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card border-left-warning h-100">
                <div class="card-body">
                    <div>
                        <div class="stat-label text-warning">Pending Elections</div>
                        <div class="stat-value" id="stat-pending"><?php echo number_format($stats['pending'] ?? 0); ?></div>
                    </div>
                    <i class="bi bi-clock-history stats-card-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card border-left-secondary h-100"> 
                <div class="card-body">
                    <div>
                        <div class="stat-label text-secondary">Completed Elections</div>
                        <div class="stat-value" id="stat-completed"><?php echo number_format($stats['completed'] ?? 0); ?></div>
                    </div>
                    <i class="bi bi-check-circle-fill stats-card-icon"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="card elections-card">
        <div class="card-header">
            <i class="bi bi-list-ul me-2"></i>Elections List
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle elections-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th class="text-center">Status</th>
                            <th>Duration</th>
                            <th class="text-center">Positions</th>
                            <th class="text-center">Votes</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($elections)): ?>
                            <tr class="no-elections">
                                <td colspan="6">
                                    <i class="bi bi-info-circle"></i>
                                    <p class="mb-0 text-muted">No elections found.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($elections as $election):
                                // Determine status badge class
                                $status_class = 'secondary';
                                if ($election['status'] === 'active') $status_class = 'success';
                                elseif ($election['status'] === 'pending') $status_class = 'warning text-dark'; // Dark text on yellow
                            ?>
                                <tr id="election-row-<?php echo $election['id']; ?>">
                                    <td>
                                        <span class="election-title"><?php echo htmlspecialchars($election['title']); ?></span>
                                        <span class="election-description" title="<?php echo htmlspecialchars($election['description'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($election['description'] ?? 'No description'); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?php echo $status_class; ?>"><?php echo ucfirst($election['status']); ?></span>
                                    </td>
                                    <td class="text-nowrap">
                                        <?php echo date('M d, Y', strtotime($election['start_date'])); ?> -
                                        <?php echo date('M d, Y', strtotime($election['end_date'])); ?>
                                    </td>
                                    <td class="text-center">
                                        <span><?php echo number_format($election['position_count'] ?? 0); ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span><?php echo number_format($election['vote_count'] ?? 0); ?></span>
                                    </td>
                                    <td class="text-center text-nowrap action-buttons">
                                        <button type="button" class="btn btn-outline-primary btn-sm edit-election-btn"
                                               data-id="<?php echo $election['id']; ?>"
                                               data-bs-toggle="tooltip" data-bs-placement="top" title="Edit Election">
                                            <i class="bi bi-pencil-fill"></i>
                                        </button>
                                        <a href="results.php?election_id=<?php echo $election['id']; ?>" class="btn btn-outline-info btn-sm"
                                           data-bs-toggle="tooltip" data-bs-placement="top" title="View Results">
                                            <i class="bi bi-bar-chart-line-fill"></i>
                                        </a>
                                        <button type="button" class="btn btn-outline-danger btn-sm delete-election-btn"
                                                data-id="<?php echo $election['id']; ?>"
                                                data-title="<?php echo htmlspecialchars($election['title']); ?>"
                                                data-bs-toggle="tooltip" data-bs-placement="top" title="Delete Election">
                                            <i class="bi bi-trash3-fill"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="createElectionModal" tabindex="-1" aria-labelledby="createElectionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered"> 
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createElectionModalLabel"><i class="bi bi-plus-square-fill me-2"></i>Create New Election</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="elections.php" id="createElectionForm" novalidate> 
                <input type="hidden" name="action" value="create"> 
                <div class="modal-body">
                     <div id="createModalAlertPlaceholder"></div>
                    <div class="mb-3">
                        <label for="create_title" class="form-label">Election Title*</label>
                        <input type="text" class="form-control" id="create_title" name="title" required>
                         <div class="invalid-feedback">Please provide an election title.</div>
                    </div>

                    <div class="mb-3">
                        <label for="create_description" class="form-label">Description</label>
                        <textarea class="form-control" id="create_description" name="description" rows="3"></textarea>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6 mb-3">
                            <label for="create_start_date" class="form-label">Start Date*</label>
                            <input type="datetime-local" class="form-control" id="create_start_date" name="start_date" required>
                            <div class="invalid-feedback">Please select a start date and time.</div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="create_end_date" class="form-label">End Date*</label>
                            <input type="datetime-local" class="form-control" id="create_end_date" name="end_date" required>
                             <div class="invalid-feedback" id="create_end_date_feedback">Please select an end date and time after the start date.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="create_status" class="form-label">Status*</label>
                        <select class="form-select" id="create_status" name="status" required>
                            <option value="pending" selected>Pending</option>
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                        </select>
                         <div class="invalid-feedback">Please select a status.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="createElectionSubmitBtn">
                         <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
                         <i class="bi bi-check-lg me-1"></i>Save Election
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editElectionModal" tabindex="-1" aria-labelledby="editElectionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered"> 
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editElectionModalLabel"><i class="bi bi-pencil-square me-2"></i>Edit Election</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
             <form id="editElectionForm" novalidate> 
                <div class="modal-body">
                    <div id="editModalAlertPlaceholder"></div>
                    <input type="hidden" id="editElectionId" name="id">
                    <div class="mb-3">
                        <label for="editTitle" class="form-label">Election Title*</label>
                        <input type="text" class="form-control" id="editTitle" name="title" required>
                         <div class="invalid-feedback">Please provide an election title.</div>
                    </div>
                    <div class="mb-3">
                        <label for="editDescription" class="form-label">Description</label>
                        <textarea class="form-control" id="editDescription" name="description" rows="3"></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6 mb-3">
                            <label for="editStartDate" class="form-label">Start Date*</label>
                            <input type="datetime-local" class="form-control" id="editStartDate" name="start_date" required>
                             <div class="invalid-feedback">Please select a start date and time.</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="editEndDate" class="form-label">End Date*</label>
                            <input type="datetime-local" class="form-control" id="editEndDate" name="end_date" required>
                            <div class="invalid-feedback" id="edit_end_date_feedback">Please select an end date and time after the start date.</div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="editStatus" class="form-label">Status*</label>
                        <select class="form-select" id="editStatus" name="status" required>
                            <option value="pending">Pending</option>
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                        </select>
                        <div class="invalid-feedback">Please select a status.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveElectionChangesBtn"> 
                        <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
                         <i class="bi bi-save-fill me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteConfirmationModal" tabindex="-1" aria-labelledby="deleteConfirmationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white border-0">
                <h5 class="modal-title" id="deleteConfirmationModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Deletion</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center py-4">
                <h4 class="mb-2">Delete Election?</h4>
                <p>Are you sure you want to delete "<strong id="electionToDeleteName"></strong>"?</p>
                <p class="text-danger small"><i class="bi bi-exclamation-circle me-1"></i>This action cannot be undone. All associated positions, candidates, and votes will be permanently deleted.</p>
            </div>
            <div class="modal-footer justify-content-center border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" class="btn btn-danger" id="confirmDeleteLink"> 
                    <i class="bi bi-trash3-fill me-1"></i>Delete Election
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once "includes/footer.php"; // Includes closing tags, Bootstrap JS ?>

<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="https://unpkg.com/countup.js@2.8.0/dist/countUp.umd.js"></script>


<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
document.addEventListener('DOMContentLoaded', function() {

    // --- Initialize Bootstrap Components ---
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (el) { return new bootstrap.Tooltip(el) });

    const createElectionModalEl = document.getElementById('createElectionModal');
    const editElectionModalEl = document.getElementById('editElectionModal');
    const deleteConfirmModalEl = document.getElementById('deleteConfirmationModal');
    const createElectionModal = createElectionModalEl ? bootstrap.Modal.getOrCreateInstance(createElectionModalEl) : null;
    const editElectionModal = editElectionModalEl ? bootstrap.Modal.getOrCreateInstance(editElectionModalEl) : null;
    const deleteConfirmModal = deleteConfirmModalEl ? bootstrap.Modal.getOrCreateInstance(deleteConfirmModalEl) : null;

    // --- CountUp Animations ---
    if (typeof CountUp === 'function') {
        const countUpOptions = { duration: 2, enableScrollSpy: true, scrollSpyDelay: 50, scrollSpyOnce: true };
        try {
             const statsData = <?php echo json_encode($stats); ?>;
             new CountUp('stat-total', statsData.total || 0, countUpOptions).start();
             new CountUp('stat-active', statsData.active || 0, countUpOptions).start();
             new CountUp('stat-pending', statsData.pending || 0, countUpOptions).start();
             new CountUp('stat-completed', statsData.completed || 0, countUpOptions).start();
        } catch (e) { console.error("CountUp init error:", e); }
    }

    // --- Modal Alert Helper ---
    function showModalAlert(placeholderId, message, type = 'danger') {
        const placeholder = document.getElementById(placeholderId);
        if (placeholder) {
            const icon = type === 'danger' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill';
            placeholder.innerHTML = `
                <div class="alert alert-${type} alert-dismissible fade show d-flex align-items-center small p-2" role="alert">
                    <i class="bi ${icon} me-2"></i>
                    <div>${message}</div>
                    <button type="button" class="btn-close p-2" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>`;
        }
    }

    // Helper function to manage button loading states
    function setButtonLoading(button, isLoading, loadingText = 'Loading...') {
        if (!button) return;
        
        if (isLoading) {
            button.setAttribute('data-original-text', button.innerHTML);
            button.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ${loadingText}`;
            button.disabled = true;
        } else {
            const originalText = button.getAttribute('data-original-text');
            if (originalText) {
                button.innerHTML = originalText;
                button.removeAttribute('data-original-text');
            }
            button.disabled = false;
        }
    }

    // --- Date Validation Helper ---
    function validateDates(startInput, endInput, feedbackElement) {
        const startDate = startInput.value ? new Date(startInput.value) : null;
        const endDate = endInput.value ? new Date(endInput.value) : null;
        let isValid = true;

        // Clear previous states
        endInput.classList.remove('is-invalid');
        if (feedbackElement) feedbackElement.textContent = 'Please select an end date and time after the start date.'; // Reset message

        if (startDate && endDate && endDate <= startDate) {
            endInput.classList.add('is-invalid');
            if (feedbackElement) feedbackElement.textContent = 'End date/time must be after start date/time.';
            isValid = false;
        } else if (!endDate) {
            // Don't mark as invalid just because it's empty unless required validation fails
        }
        return isValid;
    }

    // Attach date validation listeners for CREATE modal
    const createStartDate = document.getElementById('create_start_date');
    const createEndDate = document.getElementById('create_end_date');
    const createEndDateFeedback = document.getElementById('create_end_date_feedback');
    if (createStartDate && createEndDate) {
        createStartDate.addEventListener('change', () => validateDates(createStartDate, createEndDate, createEndDateFeedback));
        createEndDate.addEventListener('change', () => validateDates(createStartDate, createEndDate, createEndDateFeedback));
    }

    // Attach date validation listeners for EDIT modal
    const editStartDate = document.getElementById('editStartDate');
    const editEndDate = document.getElementById('editEndDate');
    const editEndDateFeedback = document.getElementById('edit_end_date_feedback');
    if (editStartDate && editEndDate) {
        editStartDate.addEventListener('change', () => validateDates(editStartDate, editEndDate, editEndDateFeedback));
        editEndDate.addEventListener('change', () => validateDates(editStartDate, editEndDate, editEndDateFeedback));
    }


    // --- Create Election Form Submission (Client-side Validation) ---
     const createForm = document.getElementById('createElectionForm');
     const createSubmitBtn = document.getElementById('createElectionSubmitBtn');
     if (createForm && createSubmitBtn) {
         createForm.addEventListener('submit', function(e) {
             // Clear previous alerts
             document.getElementById('createModalAlertPlaceholder').innerHTML = '';
             createForm.classList.remove('was-validated'); // Reset validation state

              // Perform date validation before checking overall form validity
              const datesValid = validateDates(createStartDate, createEndDate, createEndDateFeedback);

             if (!createForm.checkValidity() || !datesValid) {
                 e.preventDefault(); // Prevent submission
                 e.stopPropagation();
                 createForm.classList.add('was-validated'); // Show Bootstrap validation styles
                 if (!datesValid) {
                      showModalAlert('createModalAlertPlaceholder', 'Please correct the date errors.', 'warning');
                 } else {
                      showModalAlert('createModalAlertPlaceholder', 'Please fill all required fields correctly.', 'warning');
                 }
             } else {
                  // If valid, show loading state (form will submit via standard POST)
                  setButtonLoading(createSubmitBtn, true, 'Saving...');
                  // Standard form submission will proceed
             }
         });
     }


    // --- Edit Election: Populate Modal ---
    document.querySelectorAll('.edit-election-btn').forEach(button => {
        button.addEventListener('click', function() {
            const electionId = this.getAttribute('data-id');
            if (!electionId) return;

            // Clear previous validation states/alerts
            document.getElementById('editModalAlertPlaceholder').innerHTML = '';
            document.getElementById('editElectionForm').classList.remove('was-validated');
            document.getElementById('editEndDate').classList.remove('is-invalid');

            // Show loading state
            setButtonLoading(document.getElementById('saveElectionChangesBtn'), true, 'Loading...');

            // Fetch election data
            fetch(`get_election.php?id=${electionId}`, { headers: {'Accept': 'application/json'}})
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.election) {
                        const election = data.election;
                        
                        // Populate form fields
                        document.getElementById('editElectionId').value = election.id;
                        document.getElementById('editTitle').value = election.title;
                        document.getElementById('editDescription').value = election.description || '';
                        document.getElementById('editStartDate').value = election.start_date;
                        document.getElementById('editEndDate').value = election.end_date;
                        document.getElementById('editStatus').value = election.status;

                        // Show modal
                        if(editElectionModal) editElectionModal.show();
                    } else {
                        throw new Error(data.message || 'Could not load election data.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification(error.message || 'Error loading election data', 'danger');
                })
                .finally(() => {
                    setButtonLoading(document.getElementById('saveElectionChangesBtn'), false);
                });
        });
    });

    // --- Edit Election: Handle AJAX Submission ---
    const editForm = document.getElementById('editElectionForm');
    const saveChangesBtn = document.getElementById('saveElectionChangesBtn');
    if(editForm && saveChangesBtn) {
        editForm.addEventListener('submit', function(event) {
            event.preventDefault(); // Prevent default form submission
            document.getElementById('editModalAlertPlaceholder').innerHTML = '';
            editForm.classList.remove('was-validated');

            const datesValid = validateDates(editStartDate, editEndDate, editEndDateFeedback);

            if (!editForm.checkValidity() || !datesValid) {
                event.stopPropagation();
                editForm.classList.add('was-validated');
                 if (!datesValid) {
                      showModalAlert('editModalAlertPlaceholder', 'Please correct the date errors.', 'warning');
                 } else {
                      showModalAlert('editModalAlertPlaceholder', 'Please fill all required fields correctly.', 'warning');
                 }
                return; // Stop submission if invalid
            }

            setButtonLoading(saveChangesBtn, true, 'Saving...');

            const formData = new FormData(editForm);
            const data = Object.fromEntries(formData.entries());

            fetch('edit_election.php', { // Point to your AJAX endpoint
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    // Show success message (consider using a toast outside modal)
                    // For now, show in modal and then reload
                    showModalAlert('editModalAlertPlaceholder', result.message || 'Election updated successfully!', 'success');
                    if(editElectionModal) {
                        // Hide modal after a delay, then reload page
                        setTimeout(() => {
                            editElectionModal.hide();
                            window.location.reload();
                        }, 1500);
                    } else {
                         window.location.reload(); // Fallback reload
                    }
                } else {
                    throw new Error(result.message || 'Error updating election.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showModalAlert('editModalAlertPlaceholder', error.message, 'danger');
            })
            .finally(() => {
                 // Only reset button if staying on modal (e.g., error occurred)
                 // Success case handles page reload.
                 if (!document.getElementById('editModalAlertPlaceholder').querySelector('.alert-success')) {
                     setButtonLoading(saveChangesBtn, false);
                 }
            });
        });
    }

    // --- Delete Election: Populate Modal & Set Link ---
    document.querySelectorAll('.delete-election-btn').forEach(button => {
        button.addEventListener('click', function() {
            const electionId = this.getAttribute('data-id');
            const electionTitle = this.getAttribute('data-title');
            const confirmLink = document.getElementById('confirmDeleteLink');
            const nameSpan = document.getElementById('electionToDeleteName');

            if(nameSpan) nameSpan.textContent = electionTitle || 'this election';
            if(confirmLink) confirmLink.href = `delete_election.php?id=${electionId}`; // Set the correct URL

            if(deleteConfirmModal) deleteConfirmModal.show();
        });
    });

    // Reset modals on close
    [createElectionModalEl, editElectionModalEl].forEach(modalEl => {
         if (modalEl) {
             modalEl.addEventListener('hidden.bs.modal', function () {
                 const form = this.querySelector('form');
                 if (form) {
                     form.reset(); // Reset form fields
                     form.classList.remove('was-validated'); // Remove validation classes
                 }
                 const alertPlaceholder = this.querySelector('#createModalAlertPlaceholder') || this.querySelector('#editModalAlertPlaceholder');
                 if (alertPlaceholder) alertPlaceholder.innerHTML = ''; // Clear alerts

                  // Reset buttons if needed (might already be reset by logic)
                  const createBtn = this.querySelector('#createElectionSubmitBtn');
                  if(createBtn) setButtonLoading(createBtn, false);
                  const editBtn = this.querySelector('#saveElectionChangesBtn');
                  if(editBtn) setButtonLoading(editBtn, false);
             });
         }
     });


}); // End DOMContentLoaded
</script>