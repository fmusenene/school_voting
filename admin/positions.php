<?php
// Start session and include necessary files
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/database.php"; // Relative path from admin folder
require_once "includes/session.php"; // Includes isAdminLoggedIn()

// Check if admin is logged in
if (!isAdminLoggedIn()) {
    header("Location: login.php");
    exit();
}

// --- Configuration ---
date_default_timezone_set('Asia/Manila'); // Adjust timezone
error_reporting(E_ALL); // Dev
ini_set('display_errors', 1); // Dev
// Production settings (uncomment for deployment):
// ini_set('display_errors', 0);
// error_reporting(0);

// Generate CSP nonce
if (empty($_SESSION['csp_nonce'])) {
    $_SESSION['csp_nonce'] = base64_encode(random_bytes(16));
}
$nonce = htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8');

// --- Data Fetching ---
$elections = [];
$positions = [];
$stats = ['total_positions' => 0, 'total_candidates' => 0, 'total_votes' => 0]; // Simplified stats
$fetch_error = null;
$selected_election = isset($_GET['election']) && $_GET['election'] !== 'all' ? (int)$_GET['election'] : 'all'; // Get selected election filter

try {
    // Get all elections for the filter dropdown
    $elections_sql = "SELECT id, title FROM elections ORDER BY start_date DESC, title ASC";
    $elections = $conn->query($elections_sql)->fetchAll(PDO::FETCH_ASSOC);

    // Base SQL for positions
    $sql_positions = "SELECT p.id, p.title, p.description, p.election_id, e.title as election_title,
               (SELECT COUNT(*) FROM candidates c WHERE c.position_id = p.id) as candidate_count
               FROM positions p
               LEFT JOIN elections e ON p.election_id = e.id";

    $params = [];
    if ($selected_election !== 'all') {
        $sql_positions .= " WHERE p.election_id = :election_id";
        $params[':election_id'] = $selected_election;
    }
    $sql_positions .= " ORDER BY e.start_date DESC, p.title ASC";

    $stmt_positions = $conn->prepare($sql_positions);
    $stmt_positions->execute($params);
    $positions = $stmt_positions->fetchAll(PDO::FETCH_ASSOC);

    // Calculate overall stats based on fetched positions (or query separately if preferred)
    $stats['total_positions'] = count($positions);
    foreach ($positions as $pos) {
        $stats['total_candidates'] += $pos['candidate_count'];
        // Note: Total votes are not calculated here for performance; use Results page.
    }
    // If you still need total votes for the card, query it separately:
    $stats['total_votes'] = (int)$conn->query("SELECT COUNT(*) FROM votes")->fetchColumn();


} catch (PDOException $e) {
    error_log("Positions Page Error: " . $e->getMessage());
    $fetch_error = "Could not load position data due to a database issue.";
    // Reset arrays on error
    $elections = [];
    $positions = [];
    $stats = ['total_positions' => 0, 'total_candidates' => 0, 'total_votes' => 0];
}

// Include header AFTER data fetching
require_once "includes/header.php";
?>

<style nonce="<?php echo $nonce; ?>">
    :root {
        --primary-hue: 226; --primary-color: hsl(var(--primary-hue), 76%, 58%); --primary-light: hsl(var(--primary-hue), 76%, 95%); --primary-dark: hsl(var(--primary-hue), 70%, 48%);
        --secondary-color: #858796; --success-color: #1cc88a; --info-color: #36b9cc; --warning-color: #f6c23e; --danger-color: #e74a3b;
        --light-color: #f8f9fc; --white-color: #fff; --dark-color: #5a5c69; --gray-100: #f8f9fc; --gray-200: #eaecf4; --gray-300: #dddfeb; --gray-400: #d1d3e2; --gray-500: #b7b9cc; --gray-600: #858796; --gray-700: #6e707e; --gray-800: #5a5c69; --gray-900: #3a3b45;
        --font-family-primary: "Poppins", sans-serif; --font-family-secondary: "Nunito", sans-serif;
        --shadow-sm: 0 .125rem .25rem rgba(0,0,0,.075); --shadow: 0 .15rem 1.75rem 0 rgba(58,59,69,.15); --border-radius: .35rem; --border-radius-lg: .5rem;
    }
    body { font-family: var(--font-family-secondary); background-color: var(--light-color); font-size: 0.95rem; }
    .container-fluid { padding: 1.5rem 2rem; }

    /* Page Header */
    .page-header { margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border-color); }
    .page-header h1 { color: var(--primary-dark); font-family: var(--font-family-primary); font-weight: 700; }
    .page-header .btn { box-shadow: var(--shadow-sm); font-weight: 600; font-size: 0.875rem; }

    /* Stats Cards */
    .stats-card { transition: all 0.25s ease-out; border: none; border-left: 4px solid var(--primary-color); border-radius: var(--border-radius); background-color: var(--white-color); box-shadow: var(--shadow-sm); height: 100%; }
    .stats-card .card-body { padding: 1.1rem 1.25rem; position: relative;}
    .stats-card:hover { transform: translateY(-3px) scale(1.01); box-shadow: var(--shadow); }
    .stats-card-icon { font-size: 1.8rem; opacity: 0.15; position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); transition: all .3s ease; }
    .stats-card:hover .stats-card-icon { opacity: 0.25; transform: translateY(-50%) scale(1.1); }
    .stats-card .stat-label { font-size: .7rem; font-weight: 700; text-transform: uppercase; margin-bottom: .1rem; color: var(--secondary-color); letter-spacing: .5px;}
    .stats-card .stat-value { font-size: 1.9rem; font-weight: 700; line-height: 1.1; color: var(--dark-color); }
    /* Colors */
    .stats-card.border-left-primary { border-left-color: var(--primary-color); } .stats-card.border-left-primary .stats-card-icon { color: var(--primary-color); }
    .stats-card.border-left-success { border-left-color: var(--success-color); } .stats-card.border-left-success .stats-card-icon { color: var(--success-color); }
    .stats-card.border-left-warning { border-left-color: var(--warning-color); } .stats-card.border-left-warning .stats-card-icon { color: var(--warning-color); }

    /* Filter Bar */
    .filter-bar { background-color: var(--white-color); padding: .75rem 1.25rem; border-radius: var(--border-radius); box-shadow: var(--shadow-sm); border: 1px solid var(--border-color); }
    .filter-bar label { font-weight: 600; color: var(--primary-dark); }
    .filter-bar .form-select { max-width: 300px; font-size: 0.9rem; }

    /* Positions Table Card */
    .positions-card { border: none; border-radius: var(--border-radius-lg); box-shadow: var(--shadow); }
    .positions-card .card-header { background-color: var(--white-color); border-bottom: 1px solid var(--border-color); font-weight: 700; color: var(--primary-dark); padding: 1rem 1.25rem; border-top-left-radius: var(--border-radius-lg); border-top-right-radius: var(--border-radius-lg); }
    .positions-table { margin-bottom: 0; }
    .positions-table thead th { background-color: var(--gray-100); border-bottom: 2px solid var(--border-color); border-top: none; font-size: .8rem; font-weight: 700; text-transform: uppercase; color: var(--gray-600); padding: .75rem 1rem; white-space: nowrap;}
    .positions-table tbody td { padding: .8rem 1rem; vertical-align: middle; border-top: 1px solid var(--border-color); font-size: 0.9rem; }
    .positions-table tbody tr:first-child td { border-top: none; }
    .positions-table tbody tr:hover { background-color: var(--primary-light); }
    .positions-table .position-title { font-weight: 600; color: var(--dark-color); }
    .positions-table .election-title { font-size: 0.85rem; color: var(--secondary-color); }
    .positions-table .action-buttons .btn { padding: 0.2rem 0.5rem; font-size: 0.8rem; margin-left: 0.25rem; }
    .positions-table .badge { font-size: 0.8rem; padding: .4em .6em;}
    .positions-table .no-positions td { text-align: center; padding: 2.5rem; }
    .positions-table .no-positions i { font-size: 2.5rem; margin-bottom: .75rem; display: block; color: var(--gray-400); }
    .positions-table .no-positions p { color: var(--gray-500); font-size: 1rem;}

    /* Modals */
    .modal-header { background-color: var(--primary-light); border-bottom: 1px solid var(--border-color); }
    .modal-title { font-family: var(--font-family-primary); color: var(--primary-dark); }
    .modal-footer { background-color: var(--gray-100); border-top: 1px solid var(--border-color); }
    .was-validated .form-control:invalid, .form-control.is-invalid { border-color: var(--danger-color); background-image: none;}
    .was-validated .form-control:invalid:focus, .form-control.is-invalid:focus { box-shadow: 0 0 0 .25rem rgba(231, 74, 59, .25); }

    /* Notification Toast (Copied from previous) */
    .notification-toast { min-width: 300px; border-radius: var(--border-radius); box-shadow: var(--shadow-lg); padding: 0;}
    .notification-toast .notification-content { display: flex; align-items: center; padding: .75rem 1rem;}
    .notification-toast .toast-body { padding: 0; color: white; font-weight: 500; flex-grow: 1;}
    .notification-toast .notification-icon { font-size: 1.3rem; margin-right: .75rem; line-height: 1;}
    .notification-toast .btn-close-white { filter: brightness(0) invert(1); opacity: 0.8;}
    .notification-toast .btn-close-white:hover { opacity: 1;}

</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center page-header">
        <h1 class="h2 mb-0 text-gray-800">Manage Positions</h1>
        <button type="button" class="btn btn-primary btn-sm shadow-sm" data-bs-toggle="modal" data-bs-target="#newPositionModal">
            <i class="bi bi-plus-circle-fill me-1"></i> Add New Position
        </button>
    </div>

     <?php if ($fetch_error): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($fetch_error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-lg-4 col-md-6 mb-3">
            <div class="stats-card border-left-primary h-100">
                <div class="card-body">
                    <div>
                        <div class="stat-label text-primary">Total Positions</div>
                         
                        <div class="stat-value" id="stat-total-positions">0</div>
                    </div>
                     <i class="bi bi-tag-fill stats-card-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6 mb-3">
            <div class="stats-card border-left-success h-100">
                <div class="card-body">
                    <div>
                        <div class="stat-label text-success">Total Candidates</div>
                         
                        <div class="stat-value" id="stat-total-candidates">0</div>
                    </div>
                    <div class="stat-detail">Across all positions</div>
                     <i class="bi bi-people-fill stats-card-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6 mb-3">
             <div class="stats-card border-left-warning h-100">
                 <div class="card-body">
                    <div>
                        <div class="stat-label text-warning">Total Votes Cast</div>
                        
                        <div class="stat-value" id="stat-total-votes">0</div>
                    </div>
                     <div class="stat-detail">Across all positions</div>
                     <i class="bi bi-check2-square stats-card-icon"></i>
                 </div>
             </div>
        </div>
    </div>

    <div class="card positions-card border-0 shadow mb-4">
        <div class="card-header bg-white py-3 d-flex flex-wrap align-items-center justify-content-between">
            <h6 class="mb-0 me-3 text-primary font-weight-bold"><i class="bi bi-list-ul me-2"></i>Positions List</h6>
            <div class="d-flex align-items-center filter-bar p-0 shadow-none border-0">
                <label for="electionFilter" class="form-label mb-0 me-2 text-nowrap">Filter by Election:</label>
                <select class="form-select form-select-sm" id="electionFilter" style="width: auto; min-width: 200px;">
                    <option value="all" <?php echo $selected_election === 'all' ? 'selected' : ''; ?>>All Elections</option>
                    <?php foreach ($elections as $election): ?>
                        <option value="<?php echo $election['id']; ?>" <?php echo $selected_election == $election['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($election['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle positions-table">
                    <thead>
                        <tr>
                            <th>Position Title</th>
                            <th>Election</th>
                            <th>Description</th>
                            <th class="text-center">Candidates</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($positions)): ?>
                            <tr class="no-positions">
                                <td colspan="5">
                                    <i class="bi bi-info-circle"></i>
                                    <p class="mb-0 text-muted">
                                        <?php echo ($selected_election !== 'all') ? 'No positions found for the selected election.' : 'No positions created yet.'; ?>
                                    </p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($positions as $position): ?>
                                <tr id="position-row-<?php echo $position['id']; ?>">
                                    <td>
                                        <span class="position-title"><?php echo htmlspecialchars($position['title']); ?></span>
                                    </td>
                                    <td>
                                        <span class="election-title"><?php echo htmlspecialchars($position['election_title'] ?? 'N/A'); ?></span>
                                    </td>
                                    <td><?php echo nl2br(htmlspecialchars(substr($position['description'] ?? '', 0, 70))) . (strlen($position['description'] ?? '') > 70 ? '...' : ''); ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-info"><?php echo number_format($position['candidate_count']); ?></span>
                                    </td>
                                    <td class="text-center text-nowrap action-buttons">
                                        <button type="button" class="btn btn-outline-primary btn-sm edit-position-btn"
                                                data-position-id="<?php echo $position['id']; ?>"
                                                data-bs-toggle="tooltip" data-bs-placement="top" title="Edit Position">
                                            <i class="bi bi-pencil-fill"></i>
                                        </button>
                                        <a href="candidates.php?position_id=<?php echo $position['id']; ?>&election_id=<?php echo $position['election_id']; ?>"
                                           class="btn btn-outline-success btn-sm"
                                           data-bs-toggle="tooltip" data-bs-placement="top" title="Manage Candidates">
                                            <i class="bi bi-people-fill"></i>
                                        </a>
                                        <button type="button" class="btn btn-outline-danger btn-sm delete-position-btn"
                                                data-position-id="<?php echo $position['id']; ?>"
                                                data-position-title="<?php echo htmlspecialchars($position['title']); ?>"
                                                data-bs-toggle="tooltip" data-bs-placement="top" title="Delete Position">
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

<div class="modal fade" id="newPositionModal" tabindex="-1" aria-labelledby="newPositionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="newPositionModalLabel"><i class="bi bi-plus-square-fill me-2"></i>Add New Position</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addPositionForm" method="POST" action="add_position.php" novalidate> 
                <div class="modal-body">
                     <div id="addModalAlertPlaceholder"></div>
                    <div class="mb-3">
                        <label for="add_title" class="form-label">Position Title*</label>
                        <input type="text" class="form-control" id="add_title" name="title" required>
                        <div class="invalid-feedback">Please enter a title.</div>
                    </div>
                    <div class="mb-3">
                        <label for="add_election_id" class="form-label">Election*</label>
                        <select class="form-select" id="add_election_id" name="election_id" required>
                            <option value="" disabled selected>-- Select Election --</option>
                            <?php foreach ($elections as $election): ?>
                                <option value="<?php echo $election['id']; ?>">
                                    <?php echo htmlspecialchars($election['title']); ?>
                                </option>
                            <?php endforeach; ?>
                             <?php if(empty($elections)): ?>
                                 <option value="" disabled>No elections found. Create one first.</option>
                             <?php endif; ?>
                        </select>
                         <div class="invalid-feedback">Please select an election.</div>
                    </div>
                    <div class="mb-3">
                        <label for="add_description" class="form-label">Description</label>
                        <textarea class="form-control" id="add_description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="addPositionSubmitBtn"><i class="bi bi-check-lg me-1"></i>Add Position</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editPositionModal" tabindex="-1" aria-labelledby="editPositionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editPositionModalLabel"><i class="bi bi-pencil-square me-2"></i>Edit Position</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editPositionForm" method="POST" novalidate> 
                <input type="hidden" id="edit_position_id" name="position_id">
                 <div class="modal-body">
                     <div id="editModalAlertPlaceholder"></div>
                    <div class="mb-3">
                        <label for="edit_title" class="form-label">Position Title*</label>
                        <input type="text" class="form-control" id="edit_title" name="title" required>
                        <div class="invalid-feedback">Please enter a title.</div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_election_id" class="form-label">Election*</label>
                        <select class="form-select" id="edit_election_id" name="election_id" required>
                            <option value="">-- Select Election --</option>
                            <?php foreach ($elections as $election): ?>
                                <option value="<?php echo $election['id']; ?>">
                                    <?php echo htmlspecialchars($election['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                         <div class="invalid-feedback">Please select an election.</div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="editPositionSubmitBtn"><i class="bi bi-save-fill me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                 <h5 class="modal-title" id="deleteConfirmModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Deletion</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center pb-4 pt-4">
                <p class="lead">Are you sure you want to delete the position "<strong id="positionToDeleteName"></strong>"?</p>
                <p class="text-danger"><small>This action cannot be undone and may affect associated candidates and votes.</small></p>
                <input type="hidden" id="deletePositionId">
            </div>
             <div class="modal-footer justify-content-center">
                 <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                 <button type="button" class="btn btn-danger" id="confirmDeleteBtn"><i class="bi bi-trash3-fill me-1"></i>Delete Position</button>
            </div>
        </div>
    </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1090">
    <div id="notificationToast" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="3000">
        <div class="d-flex notification-content">
            <div class="toast-body d-flex align-items-center">
                <span class="notification-icon me-2"></span>
                <span id="notificationMessage"></span>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>


<?php require_once "includes/footer.php"; // Includes closing tags, Bootstrap JS ?>

<script nonce="<?php echo $nonce; ?>" src="https://unpkg.com/countup.js@2.8.0/dist/countUp.umd.js"></script>

<script nonce="<?php echo $nonce; ?>">
document.addEventListener('DOMContentLoaded', function () {

    // --- Initialize Bootstrap Tooltips ---
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });

    // --- CountUp Animations ---
    if (typeof CountUp === 'function') {
        const countUpOptions = { duration: 2, enableScrollSpy: true, scrollSpyDelay: 50, scrollSpyOnce: true };
        try {
            const statsData = <?php echo json_encode($stats); ?>;
            // Target elements by ID
            const elPosTotal = document.getElementById('stat-total-positions');
            const elCandTotal = document.getElementById('stat-total-candidates');
            const elVotesTotal = document.getElementById('stat-total-votes');

            if(elPosTotal) new CountUp(elPosTotal, statsData.total_positions || 0, countUpOptions).start();
            if(elCandTotal) new CountUp(elCandTotal, statsData.total_candidates || 0, countUpOptions).start();
            if(elVotesTotal) new CountUp(elVotesTotal, statsData.total_votes || 0, countUpOptions).start();

        } catch (e) { console.error("CountUp init error:", e); }
    } else { console.warn("CountUp library not available."); }

    // --- Notification Toast Function ---
    const notificationToastEl = document.getElementById('notificationToast');
    const notificationToast = notificationToastEl ? bootstrap.Toast.getOrCreateInstance(notificationToastEl) : null;
    const notificationMessageEl = document.getElementById('notificationMessage');
    const notificationIconEl = notificationToastEl ? notificationToastEl.querySelector('.notification-icon') : null;

    function showNotification(message, type = 'success') {
        if (!notificationToast || !notificationMessageEl || !notificationIconEl) return;

        // Reset classes
        notificationToastEl.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-info');
        notificationIconEl.innerHTML = ''; // Clear previous icon

        // Set message and icon based on type
        message = String(message || 'An operation completed.').substring(0, 200); // Limit length
        notificationMessageEl.textContent = message;
        let iconClass = 'bi-check-circle-fill';
        let bgClass = 'bg-success';

        if (type === 'danger') {
            iconClass = 'bi-x-octagon-fill'; bgClass = 'bg-danger';
        } else if (type === 'warning') {
            iconClass = 'bi-exclamation-triangle-fill'; bgClass = 'bg-warning text-dark'; // Dark text for warning
        } else if (type === 'info') {
             iconClass = 'bi-info-circle-fill'; bgClass = 'bg-info';
        }

        notificationToastEl.classList.add(bgClass);
        notificationIconEl.innerHTML = `<i class="bi ${iconClass}"></i>`;
        notificationToast.show();
    }

    // --- Filter Handling ---
    const electionFilter = document.getElementById('electionFilter');
    if(electionFilter) {
        electionFilter.addEventListener('change', function() {
            const selectedValue = this.value;
            // Construct URL preserving other parameters if needed in future
            const currentUrl = new URL(window.location.href);
            if (selectedValue === 'all') {
                 currentUrl.searchParams.delete('election');
            } else {
                 currentUrl.searchParams.set('election', selectedValue);
            }
            window.location.href = currentUrl.toString();
        });
    }

    // --- Modal Instances & Placeholders ---
    const newPositionModalEl = document.getElementById('newPositionModal');
    const newPositionModal = newPositionModalEl ? bootstrap.Modal.getOrCreateInstance(newPositionModalEl) : null;
    const addPositionForm = document.getElementById('addPositionForm');
    const addPositionSubmitBtn = document.getElementById('addPositionSubmitBtn');
    const addModalAlertPlaceholder = document.getElementById('addModalAlertPlaceholder');

    const editPositionModalEl = document.getElementById('editPositionModal');
    const editPositionModal = editPositionModalEl ? bootstrap.Modal.getOrCreateInstance(editPositionModalEl) : null;
    const editPositionForm = document.getElementById('editPositionForm');
    const editPositionSubmitBtn = document.getElementById('editPositionSubmitBtn');
    const editModalAlertPlaceholder = document.getElementById('editModalAlertPlaceholder');

    const deleteConfirmModalEl = document.getElementById('deleteConfirmModal');
    const deleteConfirmModal = deleteConfirmModalEl ? bootstrap.Modal.getOrCreateInstance(deleteConfirmModalEl) : null;
    const positionToDeleteNameEl = document.getElementById('positionToDeleteName');
    const deletePositionIdInput = document.getElementById('deletePositionId');
    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');


    // Function to show modal errors
     function showModalAlert(placeholder, message, type = 'danger') {
          if(placeholder) {
               placeholder.innerHTML = `
                    <div class="alert alert-${type} alert-dismissible fade show mb-0" role="alert">
                         <i class="bi ${type === 'danger' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill'} me-2"></i>
                         ${message}
                         <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>`;
          }
     }

    // --- Add Position Form Handling ---
    if (addPositionForm && addPositionSubmitBtn) {
        addPositionForm.addEventListener('submit', function(e) {
            e.preventDefault();
            addModalAlertPlaceholder.innerHTML = ''; // Clear previous alerts
            addPositionForm.classList.remove('was-validated');

            if (!addPositionForm.checkValidity()) {
                e.stopPropagation();
                addPositionForm.classList.add('was-validated');
                showModalAlert(addModalAlertPlaceholder, 'Please fill in all required fields.', 'warning');
                return;
            }

            const originalBtnHTML = addPositionSubmitBtn.innerHTML;
            addPositionSubmitBtn.disabled = true;
            addPositionSubmitBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Adding...`;
            const formData = new FormData(addPositionForm);

            fetch('add_position.php', { method: 'POST', body: formData, headers: {'Accept': 'application/json'}})
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message || 'Position added successfully!', 'success');
                        if(newPositionModal) newPositionModal.hide();
                        setTimeout(() => window.location.reload(), 1000); // Reload after delay
                    } else {
                        throw new Error(data.message || 'Failed to add position.');
                    }
                })
                .catch(error => {
                    console.error('Add Position Error:', error);
                    showModalAlert(addModalAlertPlaceholder, error.message || 'An error occurred.', 'danger');
                })
                .finally(() => {
                    addPositionSubmitBtn.disabled = false;
                    addPositionSubmitBtn.innerHTML = originalBtnHTML;
                });
        });
    }

    // --- Edit Position Handling ---
    document.querySelectorAll('.edit-position-btn').forEach(button => {
        button.addEventListener('click', function() {
            const positionId = this.getAttribute('data-position-id');
            if (!positionId) return;
            editModalAlertPlaceholder.innerHTML = ''; // Clear previous errors
            editPositionForm.classList.remove('was-validated');

            // Fetch existing data
            fetch(`get_position.php?id=${positionId}`, { headers: {'Accept': 'application/json'}})
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.position) {
                        // Populate the edit form
                        document.getElementById('edit_position_id').value = data.position.id;
                        document.getElementById('edit_title').value = data.position.title;
                        document.getElementById('edit_election_id').value = data.position.election_id;
                        document.getElementById('edit_description').value = data.position.description;
                        if(editPositionModal) editPositionModal.show();
                    } else {
                        showNotification(data.message || 'Error loading position data', 'danger');
                    }
                })
                .catch(error => {
                    console.error('Error fetching position:', error);
                    showNotification('Error loading position data', 'danger');
                });
        });
    });

    // Edit Form Submit
    if(editPositionForm && editPositionSubmitBtn) {
        editPositionForm.addEventListener('submit', function(e) {
             e.preventDefault();
            editModalAlertPlaceholder.innerHTML = '';
            editPositionForm.classList.remove('was-validated');

             if (!editPositionForm.checkValidity()) {
                 e.stopPropagation();
                 editPositionForm.classList.add('was-validated');
                 showModalAlert(editModalAlertPlaceholder, 'Please fill in all required fields.', 'warning');
                 return;
             }

             const originalBtnHTML = editPositionSubmitBtn.innerHTML;
             editPositionSubmitBtn.disabled = true;
             editPositionSubmitBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...`;
             const formData = new FormData(editPositionForm);

             fetch('update_position.php', { method: 'POST', body: formData, headers: {'Accept': 'application/json'}})
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message || 'Position updated successfully!', 'success');
                        if(editPositionModal) editPositionModal.hide();
                        setTimeout(() => window.location.reload(), 1000);
                    } else {
                         throw new Error(data.message || 'Failed to update position.');
                    }
                })
                .catch(error => {
                    console.error('Update Position Error:', error);
                     showModalAlert(editModalAlertPlaceholder, error.message || 'An error occurred.', 'danger');
                })
                .finally(() => {
                    editPositionSubmitBtn.disabled = false;
                    editPositionSubmitBtn.innerHTML = originalBtnHTML;
                });
        });
    }

     // --- Delete Position Handling ---
     document.querySelectorAll('.delete-position-btn').forEach(button => {
         button.addEventListener('click', function() {
             const positionId = this.getAttribute('data-position-id');
             const positionTitle = this.getAttribute('data-position-title');
             if(deletePositionIdInput) deletePositionIdInput.value = positionId;
             if(positionToDeleteNameEl) positionToDeleteNameEl.textContent = positionTitle || 'this item';
             if(deleteConfirmModal) deleteConfirmModal.show();
         });
     });

     if(confirmDeleteBtn) {
         confirmDeleteBtn.addEventListener('click', function() {
             const positionId = deletePositionIdInput ? deletePositionIdInput.value : null;
             if (!positionId) return;

             const originalBtnText = this.innerHTML;
             this.disabled = true;
             this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Deleting...`;

             fetch(`delete_position.php?id=${positionId}`, { headers: {'Accept': 'application/json'}})
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message || 'Position deleted successfully!', 'success');
                        if(deleteConfirmModal) deleteConfirmModal.hide();
                         // Remove row from table visually instead of full reload
                         const rowToRemove = document.getElementById(`position-row-${positionId}`);
                         if(rowToRemove) {
                              rowToRemove.style.opacity = '0';
                              setTimeout(() => rowToRemove.remove(), 300);
                         } else {
                             setTimeout(() => window.location.reload(), 1000); // Fallback reload
                         }
                    } else {
                         throw new Error(data.message || 'Failed to delete position.');
                    }
                })
                .catch(error => {
                     console.error('Delete Position Error:', error);
                     showNotification(error.message || 'Error deleting position', 'danger');
                     if(deleteConfirmModal) deleteConfirmModal.hide(); // Hide modal even on error
                })
                .finally(() => {
                    // Re-enable button happens when modal closes via 'hidden.bs.modal'
                });
         });
     }

     // Reset delete button state when modal closes
      if(deleteConfirmModalEl) {
           deleteConfirmModalEl.addEventListener('hidden.bs.modal', function () {
               if(confirmDeleteBtn) {
                   confirmDeleteBtn.disabled = false;
                   confirmDeleteBtn.innerHTML = '<i class="bi bi-trash3-fill me-1"></i>Delete Position';
               }
           });
      }

    // Reset forms validation state when modals close
    [newPositionModalEl, editPositionModalEl].forEach(modalEl => {
        if(modalEl) {
            modalEl.addEventListener('hidden.bs.modal', function () {
                const form = this.querySelector('form');
                if (form) {
                    form.classList.remove('was-validated');
                    form.reset();
                }
                 const alertPlaceholder = this.querySelector('#addModalAlertPlaceholder') || this.querySelector('#editModalAlertPlaceholder');
                 if (alertPlaceholder) alertPlaceholder.innerHTML = '';

                  // Also reset date validation visual state if applicable
                 const modalEndDate = this.querySelector('#end_date') || this.querySelector('#edit_end_date');
                 const modalStartDate = this.querySelector('#start_date') || this.querySelector('#edit_start_date');
                 const modalDateError = this.querySelector('#dateValidationError'); // Assuming only new election modal has dates
                 if(modalEndDate) modalEndDate.classList.remove('is-invalid');
                 if(modalStartDate) modalStartDate.classList.remove('is-invalid');
                 if(modalDateError) modalDateError.classList.add('d-none');

            });
        }
    });


    // --- Sidebar Toggle Logic ---
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const navbar = document.querySelector('.navbar');
    if (sidebarToggle && sidebar && mainContent && navbar) {
        const applySidebarState = (state) => { sidebar.classList.toggle('collapsed', state === 'collapsed'); mainContent.classList.toggle('expanded', state === 'collapsed'); navbar.classList.toggle('expanded', state === 'collapsed'); };
        sidebarToggle.addEventListener('click', (e) => { e.preventDefault(); const newState = sidebar.classList.contains('collapsed') ? 'expanded' : 'collapsed'; applySidebarState(newState); try { localStorage.setItem('sidebarState', newState); } catch (e) {} });
        try { const storedState = localStorage.getItem('sidebarState'); if (storedState) applySidebarState(storedState); } catch (e) {}
    }

}); // End DOMContentLoaded
</script>

</body>
</html>