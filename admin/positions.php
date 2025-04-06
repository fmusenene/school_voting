<?php
// Start session and include necessary files
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate CSP nonce if not exists - Moved nonce generation here for clarity
if (empty($_SESSION['csp_nonce'])) {
    $_SESSION['csp_nonce'] = base64_encode(random_bytes(16));
}
$nonce = htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8');

// Set strict CSP header - Can be set here or in init.php/header.php
// Ensure all necessary sources (CDNs, etc.) are included if set here
/* Example:
header("Content-Security-Policy: ".
    "default-src 'self'; ".
    "script-src 'self' 'nonce-".$nonce."' https://cdn.jsdelivr.net https://unpkg.com; ". // Added unpkg for countup
    "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; ". // Allow inline styles if needed, plus CDNs
    "font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; ".
    "img-src 'self' data: blob:; ".
    "connect-src 'self'; ". // Needed for AJAX calls (edit/delete)
    "frame-src 'none'; ".
    "object-src 'none'; ".
    "base-uri 'self';"
);
*/

require_once "../config/database.php"; // Relative path from admin folder
require_once "includes/session.php"; // Includes isAdminLoggedIn()

// --- Security Check ---
if (!isAdminLoggedIn()) {
    header("Location: login.php");
    exit();
}

// --- Configuration ---
date_default_timezone_set('Asia/Manila'); // Adjust timezone
// error_reporting(E_ALL); // Dev
// ini_set('display_errors', 1); // Dev
ini_set('display_errors', 0); // Prod
error_reporting(0); // Prod


// --- Flash Message Handling ---
$success_message = $_SESSION['success'] ?? null;
$error_message = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']); // Clear after retrieving


// --- Data Fetching ---
$elections = [];
$positions = [];
// Initialize stats array
$stats = ['total_positions' => 0, 'total_candidates' => 0, 'total_votes' => 0];
$fetch_error = null;
// Get selected election filter, default to 'all' if not set or invalid
$selected_election_filter = filter_input(INPUT_GET, 'election', FILTER_VALIDATE_INT);
if ($selected_election_filter === false || $selected_election_filter === null) {
     $selected_election = 'all';
} else {
     $selected_election = $selected_election_filter;
}


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
    // Apply election filter if selected
    if ($selected_election !== 'all') {
        $sql_positions .= " WHERE p.election_id = :election_id";
        $params[':election_id'] = $selected_election;
    }
    $sql_positions .= " ORDER BY e.start_date DESC, p.id ASC, p.title ASC"; // Changed to ORDER BY id ASC

    $stmt_positions = $conn->prepare($sql_positions);
    $stmt_positions->execute($params);
    $positions = $stmt_positions->fetchAll(PDO::FETCH_ASSOC);

    // --- DYNAMIC STATS CALCULATION ---
    // Calculate stats based on the *fetched* (potentially filtered) positions
    $stats['total_positions'] = count($positions);
    $calculated_candidates = 0;
    foreach ($positions as $pos) {
        // Ensure candidate_count is numeric before adding
        $calculated_candidates += (int)($pos['candidate_count'] ?? 0);
    }
    $stats['total_candidates'] = $calculated_candidates;

    // Total votes stat usually represents the *overall* total, regardless of position filter
    // If you want votes specific to the filtered positions, this query needs adjustment
    $stats['total_votes'] = (int)$conn->query("SELECT COUNT(*) FROM votes")->fetchColumn();
    // --- END DYNAMIC STATS CALCULATION ---


} catch (PDOException $e) {
    error_log("Positions Page Error: " . $e->getMessage());
    $fetch_error = "Could not load position data due to a database issue.";
    // Reset arrays on error
    $elections = [];
    $positions = [];
    $stats = ['total_positions' => 0, 'total_candidates' => 0, 'total_votes' => 0];
}

// --- Include Header (AFTER PHP logic) ---
require_once "includes/header.php";
?>

<style nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
    :root { /* Ensure variables are available */
        --primary-hue: 226; --primary-color: hsl(var(--primary-hue), 76%, 58%); --primary-light: hsl(var(--primary-hue), 76%, 95%); --primary-dark: hsl(var(--primary-hue), 70%, 48%);
        --secondary-color: #858796; --success-color: #1cc88a; --info-color: #36b9cc; --warning-color: #f6c23e; --danger-color: #e74a3b;
        --light-color: #f8f9fc; --white-color: #fff; --dark-color: #5a5c69; --gray-100: #f8f9fc; --gray-200: #eaecf4; --gray-300: #dddfeb; --gray-400: #d1d3e2; --gray-500: #b7b9cc; --gray-600: #858796; --gray-700: #6e707e; --gray-800: #5a5c69; --gray-900: #3a3b45;
        --font-family-primary: "Poppins", sans-serif; --font-family-secondary: "Nunito", sans-serif;
        --shadow-sm: 0 .125rem .25rem rgba(0,0,0,.075); --shadow: 0 .15rem 1.75rem 0 rgba(58,59,69,.15); --border-radius: .35rem; --border-radius-lg: .5rem; --border-color: #e3e6f0;
    }
    body { font-family: var(--font-family-secondary); background-color: var(--light-color); font-size: 0.95rem; }
    .container-fluid { padding: 1.5rem 2rem; }

    /* Page Header */
    .page-header { margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border-color); }
    .page-header h1 { color: var(--primary-dark); font-family: var(--font-family-primary); font-weight: 700; }
    .page-header .btn { box-shadow: var(--shadow-sm); font-weight: 600; font-size: 0.875rem; }
    .page-header .btn i { vertical-align: -2px; }

    /* Stats Cards */
    .stats-card { transition: all 0.25s ease-out; border: none; border-left: 4px solid var(--primary-color); border-radius: var(--border-radius); background-color: var(--white-color); box-shadow: var(--shadow-sm); height: 100%; }
    .stats-card .card-body { padding: 1.1rem 1.25rem; position: relative;}
    .stats-card:hover { transform: translateY(-3px) scale(1.01); box-shadow: var(--shadow); }
    .stats-card-icon { font-size: 1.8rem; opacity: 0.15; position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); transition: all .3s ease; }
    .stats-card:hover .stats-card-icon { opacity: 0.25; transform: translateY(-50%) scale(1.1); }
    .stats-card .stat-label { font-size: .7rem; font-weight: 700; text-transform: uppercase; margin-bottom: .1rem; color: var(--secondary-color); letter-spacing: .5px;}
    .stats-card .stat-value { font-size: 1.9rem; font-weight: 700; line-height: 1.1; color: var(--dark-color); }
    .stats-card .stat-detail { font-size: 0.8rem; color: var(--secondary-color); }
    /* Colors */
    .stats-card.border-left-primary { border-left-color: var(--primary-color); } .stats-card.border-left-primary .stats-card-icon { color: var(--primary-color); }
    .stats-card.border-left-success { border-left-color: var(--success-color); } .stats-card.border-left-success .stats-card-icon { color: var(--success-color); }
    .stats-card.border-left-warning { border-left-color: var(--warning-color); } .stats-card.border-left-warning .stats-card-icon { color: var(--warning-color); }

    /* Positions Table Card */
    .positions-card { border: none; border-radius: var(--border-radius-lg); box-shadow: var(--shadow); }
    .positions-card .card-header { background-color: var(--white-color); border-bottom: 1px solid var(--border-color); font-weight: 700; color: var(--primary-dark); padding: 1rem 1.25rem; }
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
    .positions-table .no-positions i { font-size: 2.5rem; margin-bottom: .75rem; display: block; color: var(--gray-300); }
    .positions-table .no-positions p { color: var(--secondary-color); font-size: 1rem;}

    /* Modals */
    .modal-header { background-color: var(--primary-light); border-bottom: 1px solid var(--border-color); }
    .modal-title { font-family: var(--font-family-primary); color: var(--primary-dark); }
    .modal-footer { background-color: var(--gray-100); border-top: 1px solid var(--border-color); }
    .was-validated .form-control:invalid, .form-control.is-invalid { border-color: var(--danger-color); background-image: none;}
    .was-validated .form-control:invalid:focus, .form-control.is-invalid:focus { box-shadow: 0 0 0 .25rem rgba(231, 74, 59, .25); }
    .was-validated .form-select:invalid, .form-select.is-invalid { border-color: var(--danger-color); }
    .was-validated .form-select:invalid:focus, .form-select.is-invalid:focus { border-color: var(--danger-color); box-shadow: 0 0 0 .25rem rgba(231, 74, 59, .25); }
    .invalid-feedback { font-size: .8rem; }

    /* Delete Modal Specific */
    #deleteConfirmModal .modal-header { background-color: var(--danger-color); color: white; }
    #deleteConfirmModal .btn-close { filter: brightness(0) invert(1);}

    /* Notification Toast */
    .toast-container { z-index: 1090; } /* Ensure toast is on top */
    .notification-toast { min-width: 300px; border-radius: var(--border-radius); box-shadow: var(--shadow-lg); padding: 0; border: none;}
    .notification-toast .notification-content { display: flex; align-items: center; padding: .75rem 1rem;}
    .notification-toast .toast-body { padding: 0; color: white; font-weight: 500; flex-grow: 1;}
    .notification-toast .notification-icon { font-size: 1.3rem; margin-right: .75rem; line-height: 1;}
    .notification-toast .btn-close-white { filter: brightness(0) invert(1); opacity: 0.8;}
    .notification-toast .btn-close-white:hover { opacity: 1;}

</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center page-header">
        <h1 class="h2 mb-0 text-gray-800">Manage Posts</h1>
        <button type="button" class="btn btn-primary btn-sm shadow-sm" data-bs-toggle="modal" data-bs-target="#newPositionModal">
            <i class="bi bi-plus-circle-fill me-1"></i> Add New Post
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
        <div class="col-lg-4 col-md-6 mb-3">
            <div class="stats-card border-left-primary h-100">
                <div class="card-body">
                    <div>
                        <div class="stat-label text-primary">Total Posts</div>
                        <div class="stat-value" id="stat-total-positions">0</div> 
                    </div>
                    <div class="stat-detail">
                         <?php echo $selected_election !== 'all' ? 'In selected election' : 'Across all elections'; ?>
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
                    <div class="stat-detail">In listed posts</div>
                     <i class="bi bi-people-fill stats-card-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-12"> 
             <div class="stats-card border-left-warning h-100">
                 <div class="card-body">
                    <div>
                        <div class="stat-label text-warning">Total Votes Cast</div>
                        <div class="stat-value" id="stat-total-votes">0</div> 
                    </div>
                     <div class="stat-detail">Overall system votes</div>
                     <i class="bi bi-check2-square stats-card-icon"></i>
                 </div>
             </div>
        </div>
    </div>

    <div class="card positions-card">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between">
            <h6 class="mb-0 me-3"><i class="bi bi-list-ul me-2"></i>Post List</h6>
            <div class="d-flex align-items-center">
                <label for="electionFilter" class="form-label mb-0 me-2 text-nowrap small fw-bold text-secondary">Filter:</label>
                <select class="form-select form-select-sm" id="electionFilter" style="width: auto; min-width: 200px;">
                    <option value="all" <?php echo $selected_election === 'all' ? 'selected' : ''; ?>>All Elections</option>
                    <?php foreach ($elections as $election): ?>
                        <option value="<?php echo $election['id']; ?>" <?php echo $selected_election == $election['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($election['title']); ?>
                        </option>
                    <?php endforeach; ?>
                     <?php if (empty($elections)): ?>
                         <option value="" disabled>No elections available</option>
                     <?php endif; ?>
                </select>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle positions-table">
                    <thead>
                        <tr>
                            <th>Post Title</th>
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
                                        <?php echo ($selected_election !== 'all') ? 'No posts found for the selected election.' : 'No posts created yet.'; ?>
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
                                        <span class="badge bg-info"><?php echo number_format((int)($position['candidate_count'] ?? 0)); ?></span>
                                    </td>
                                    <td class="text-center text-nowrap action-buttons">
                                        <button type="button" class="btn btn-outline-primary btn-sm edit-position-btn"
                                                data-position-id="<?php echo $position['id']; ?>"
                                                data-bs-toggle="tooltip" data-bs-placement="top" title="Edit Post">
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
                                                data-bs-toggle="tooltip" data-bs-placement="top" title="Delete Post">
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
                <h5 class="modal-title" id="newPositionModalLabel"><i class="bi bi-plus-square-fill me-2"></i>Add New Post</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addPositionForm" method="POST" action="add_position.php" novalidate> 
                <div class="modal-body">
                     <div id="addModalAlertPlaceholder"></div>
                    <div class="mb-3">
                        <label for="add_title" class="form-label">Post Title*</label>
                        <input type="text" class="form-control" id="add_title" name="title" required>
                        <div class="invalid-feedback">Please enter a title for the post.</div>
                    </div>
                    <div class="mb-3">
                        <label for="add_election_id" class="form-label">Election*</label>
                        <select class="form-select" id="add_election_id" name="election_id" required>
                            <option value="" disabled <?php echo $selected_election === 'all' ? 'selected' : ''; ?>>-- Select Election --</option>
                            <?php foreach ($elections as $election): ?>
                                <option value="<?php echo $election['id']; ?>" <?php echo $selected_election == $election['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($election['title']); ?>
                                </option>
                            <?php endforeach; ?>
                             <?php if(empty($elections)): ?>
                                 <option value="" disabled>No elections found. Create one first.</option>
                             <?php endif; ?>
                        </select>
                         <div class="invalid-feedback">Please select the election this post belongs to.</div>
                    </div>
                    <div class="mb-3">
                        <label for="add_description" class="form-label">Description <span class="text-muted small">(Optional)</span></label>
                        <textarea class="form-control" id="add_description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="addPositionSubmitBtn" <?php echo empty($elections) ? 'disabled' : ''; ?>>
                         <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
                         <i class="bi bi-check-lg me-1"></i>Add Post
                     </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editPositionModal" tabindex="-1" aria-labelledby="editPositionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editPositionModalLabel"><i class="bi bi-pencil-square me-2"></i>Edit Post</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editPositionForm" method="POST" action="update_position.php" novalidate> 
                <input type="hidden" id="edit_position_id" name="position_id">
                 <div class="modal-body">
                     <div id="editModalAlertPlaceholder"></div>
                    <div class="mb-3">
                        <label for="edit_title" class="form-label">Post Title*</label>
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
                             <?php if(empty($elections)): ?>
                                 <option value="" disabled>No elections available</option>
                             <?php endif; ?>
                        </select>
                         <div class="invalid-feedback">Please select an election.</div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description <span class="text-muted small">(Optional)</span></label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="editPositionSubmitBtn">
                        <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
                         <i class="bi bi-save-fill me-1"></i>Save Changes
                     </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white border-0">
                 <h5 class="modal-title" id="deleteConfirmModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Deletion</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center py-4">
                 <h4 class="mb-2">Delete Post?</h4>
                <p>Are you sure you want to delete the Post "<strong id="positionToDeleteName"></strong>"?</p>
                <p class="text-danger small"><i class="bi bi-exclamation-circle me-1"></i>This action cannot be undone. Associated candidates and votes may also be affected.</p>
                <input type="hidden" id="deletePositionId">
            </div>
             <div class="modal-footer justify-content-center border-0">
                 <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                 <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                      <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
                      <i class="bi bi-trash3-fill me-1"></i>Delete Post
                 </button>
            </div>
        </div>
    </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1090">
    <div id="notificationToast" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4000">
        <div class="d-flex notification-content">
            <div class="toast-body d-flex align-items-center">
                <span class="notification-icon me-2 fs-4"></span>
                <span id="notificationMessage" class="fw-medium"></span>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>


<?php require_once "includes/footer.php"; // Includes closing tags, Bootstrap JS ?>

<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="https://unpkg.com/countup.js@2.8.0/dist/countUp.umd.js"></script>
<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
document.addEventListener('DOMContentLoaded', function () {

    // --- Initialize Bootstrap Tooltips ---
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });

    // --- DYNAMIC STATS DISPLAY (using CountUp.js) ---
    if (typeof CountUp === 'function') {
        const countUpOptions = { duration: 2, enableScrollSpy: true, scrollSpyDelay: 50, scrollSpyOnce: true };
        try {
            // Get the stats data passed from PHP
            const statsData = <?php echo json_encode($stats); ?>;

            // Target elements by ID and start the count-up animation
            const elPosTotal = document.getElementById('stat-total-positions');
            const elCandTotal = document.getElementById('stat-total-candidates');
            const elVotesTotal = document.getElementById('stat-total-votes');

            if(elPosTotal) new CountUp(elPosTotal, statsData.total_positions || 0, countUpOptions).start();
            if(elCandTotal) new CountUp(elCandTotal, statsData.total_candidates || 0, countUpOptions).start();
            if(elVotesTotal) new CountUp(elVotesTotal, statsData.total_votes || 0, countUpOptions).start();

        } catch (e) { console.error("CountUp init error:", e); }
    } else {
        console.warn("CountUp library not available. Stats will be displayed statically.");
        // Fallback to static display if CountUp fails
        const statsData = <?php echo json_encode($stats); ?>;
        const elPosTotal = document.getElementById('stat-total-positions');
        const elCandTotal = document.getElementById('stat-total-candidates');
        const elVotesTotal = document.getElementById('stat-total-votes');
        if(elPosTotal) elPosTotal.textContent = statsData.total_positions || 0;
        if(elCandTotal) elCandTotal.textContent = statsData.total_candidates || 0;
        if(elVotesTotal) elVotesTotal.textContent = statsData.total_votes || 0;
    }
    // --- END DYNAMIC STATS DISPLAY ---


    // --- Notification Toast Functionality ---
    const notificationToastEl = document.getElementById('notificationToast');
    const notificationToast = notificationToastEl ? bootstrap.Toast.getOrCreateInstance(notificationToastEl) : null;
    const notificationMessageEl = document.getElementById('notificationMessage');
    const notificationIconEl = notificationToastEl ? notificationToastEl.querySelector('.notification-icon') : null;

    function showNotification(message, type = 'success') {
        if (!notificationToast || !notificationMessageEl || !notificationIconEl) {
             console.warn('Notification elements not found.'); return;
        }
        // Reset classes
        notificationToastEl.className = 'toast align-items-center border-0'; // Reset all BGs
        notificationIconEl.innerHTML = '';
        // Set message and icon based on type
        message = String(message || 'Operation completed.').substring(0, 200);
        notificationMessageEl.textContent = message;
        let iconClass = 'bi-check-circle-fill'; let bgClass = 'bg-success'; let textClass = 'text-white';
        if (type === 'danger') { iconClass = 'bi-x-octagon-fill'; bgClass = 'bg-danger'; }
        else if (type === 'warning') { iconClass = 'bi-exclamation-triangle-fill'; bgClass = 'bg-warning'; textClass = 'text-dark';} // Dark text on yellow
        else if (type === 'info') { iconClass = 'bi-info-circle-fill'; bgClass = 'bg-info'; }

        notificationToastEl.classList.add(bgClass, textClass);
        notificationIconEl.innerHTML = `<i class="bi ${iconClass}"></i>`;
        notificationToast.show();
    }

     // --- Display Session Flash Messages ---
     <?php if ($success_message): ?>
         showNotification(<?php echo json_encode($success_message); ?>, 'success');
     <?php endif; ?>
     <?php if ($error_message): ?>
         showNotification(<?php echo json_encode($error_message); ?>, 'danger');
     <?php endif; ?>


    // --- Filter Handling ---
    const electionFilter = document.getElementById('electionFilter');
    if(electionFilter) {
        electionFilter.addEventListener('change', function() {
            const selectedValue = this.value;
            const currentUrl = new URL(window.location.href);
            if (selectedValue === 'all' || selectedValue === '') {
                 currentUrl.searchParams.delete('election');
            } else {
                 currentUrl.searchParams.set('election', selectedValue);
            }
            // Reset page param when filter changes
            currentUrl.searchParams.delete('page');
            window.location.href = currentUrl.toString();
        });
    }

    // --- Modal & Form References ---
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

    // --- Helper Functions for Modals ---
     function showModalAlert(placeholder, message, type = 'danger') {
          if(placeholder) {
               const icon = type === 'danger' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill';
               placeholder.innerHTML = `
                    <div class="alert alert-${type} alert-dismissible fade show d-flex align-items-center small p-2 mb-3" role="alert">
                         <i class="bi ${icon} me-2"></i>
                         <div>${message}</div>
                         <button type="button" class="btn-close p-2" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>`;
          }
     }
     function setButtonLoading(button, isLoading, loadingText = "Processing...") {
        // (Using the same helper function as in previous examples)
         if (!button) return;
         const originalHTML = button.dataset.originalHTML || button.innerHTML;
         if (!button.dataset.originalHTML) button.dataset.originalHTML = originalHTML;
         const spinner = button.querySelector('.spinner-border');
         const icon = button.querySelector('.bi');
         if (isLoading) {
             button.disabled = true;
             if (!spinner) { /* Add spinner */
                  const newSpinner = document.createElement('span'); newSpinner.className = 'spinner-border spinner-border-sm me-1'; newSpinner.setAttribute('role', 'status'); newSpinner.setAttribute('aria-hidden', 'true'); button.prepend(newSpinner);
             } else { spinner.classList.remove('d-none'); }
             if (icon) icon.style.display = 'none';
             const textNode = Array.from(button.childNodes).find(node => node.nodeType === Node.TEXT_NODE && node.textContent.trim().length > 0); if (textNode) textNode.textContent = ` ${loadingText}`;
         } else {
             button.disabled = false;
             if (spinner) spinner.classList.add('d-none');
             if (icon) icon.style.display = '';
             button.innerHTML = originalHTML;
         }
     }

    // --- Add Position Form Handling (AJAX) ---
    if (addPositionForm && addPositionSubmitBtn) {
        addPositionForm.addEventListener('submit', function(e) {
            e.preventDefault();
            addModalAlertPlaceholder.innerHTML = '';
            addPositionForm.classList.remove('was-validated');

            if (!addPositionForm.checkValidity()) {
                e.stopPropagation(); addPositionForm.classList.add('was-validated');
                showModalAlert(addModalAlertPlaceholder, 'Please fill all required fields.', 'warning'); return;
            }

            setButtonLoading(addPositionSubmitBtn, true, 'Adding...');
            const formData = new FormData(addPositionForm);

            fetch('add_position.php', { method: 'POST', body: formData, headers: {'Accept': 'application/json'}})
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message || 'Post added successfully!', 'success');
                        if(newPositionModal) newPositionModal.hide();
                        // Optional: Add row dynamically instead of reloading
                        setTimeout(() => window.location.reload(), 500); // Simple reload for now
                    } else { throw new Error(data.message || 'Failed to add post.'); }
                })
                .catch(error => { showModalAlert(addModalAlertPlaceholder, error.message, 'danger'); })
                .finally(() => { setButtonLoading(addPositionSubmitBtn, false); });
        });
    }

    // --- Edit Position Handling ---
    // 1. Populate Modal
    document.querySelectorAll('.edit-position-btn').forEach(button => {
        button.addEventListener('click', function() {
            const positionId = this.getAttribute('data-position-id');
            if (!positionId) return;
            editModalAlertPlaceholder.innerHTML = '';
            editPositionForm.classList.remove('was-validated');
            editPositionForm.reset(); // Reset form before fetching

            setButtonLoading(editPositionSubmitBtn, true, 'Loading...'); // Indicate loading

            // Fetch existing data via AJAX (using get_position.php)
            fetch(`get_position.php?id=${positionId}`, { headers: {'Accept': 'application/json'}})
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.position) {
                        document.getElementById('edit_position_id').value = data.position.id;
                        document.getElementById('edit_title').value = data.position.title;
                        document.getElementById('edit_election_id').value = data.position.election_id;
                        document.getElementById('edit_description').value = data.position.description || '';
                        if(editPositionModal) editPositionModal.show();
                    } else { throw new Error(data.message || 'Could not load post data.'); }
                })
                .catch(error => { showNotification(error.message, 'danger'); })
                .finally(() => { setButtonLoading(editPositionSubmitBtn, false); }); // Reset loading state
        });
    });

    // 2. Submit Edit Form (AJAX)
    if(editPositionForm && editPositionSubmitBtn) {
        editPositionForm.addEventListener('submit', function(e) {
             e.preventDefault();
             editModalAlertPlaceholder.innerHTML = '';
             editPositionForm.classList.remove('was-validated');

             if (!editPositionForm.checkValidity()) {
                 e.stopPropagation(); editPositionForm.classList.add('was-validated');
                 showModalAlert(editModalAlertPlaceholder, 'Please fill all required fields.', 'warning'); return;
             }

             setButtonLoading(editPositionSubmitBtn, true, 'Saving...');
             const formData = new FormData(editPositionForm);

             fetch('update_position.php', { method: 'POST', body: formData, headers: {'Accept': 'application/json'}})
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message || 'Post updated successfully!', 'success');
                        if(editPositionModal) editPositionModal.hide();
                        // Optional: Update table row dynamically
                        setTimeout(() => window.location.reload(), 500); // Simple reload
                    } else { throw new Error(data.message || 'Failed to update post.'); }
                })
                .catch(error => { showModalAlert(editModalAlertPlaceholder, error.message, 'danger'); })
                .finally(() => { setButtonLoading(editPositionSubmitBtn, false); });
        });
    }

     // --- Delete Position Handling ---
     // 1. Show Confirmation Modal
     document.querySelectorAll('.delete-position-btn').forEach(button => {
         button.addEventListener('click', function() {
             const positionId = this.getAttribute('data-position-id');
             const positionTitle = this.getAttribute('data-position-title');
             if(deletePositionIdInput) deletePositionIdInput.value = positionId;
             if(positionToDeleteNameEl) positionToDeleteNameEl.textContent = positionTitle || 'this item';
             if(deleteConfirmModal) deleteConfirmModal.show();
         });
     });

     // 2. Confirm Deletion (AJAX)
     if(confirmDeleteBtn) {
         confirmDeleteBtn.addEventListener('click', function() {
             const positionId = deletePositionIdInput ? deletePositionIdInput.value : null;
             if (!positionId) return;

             setButtonLoading(this, true, 'Deleting...');

             fetch(`delete_position.php?id=${positionId}`, { headers: {'Accept': 'application/json'}}) // Using GET as per delete_position.php
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message || 'Post deleted successfully!', 'success');
                        if(deleteConfirmModal) deleteConfirmModal.hide();
                         const rowToRemove = document.getElementById(`position-row-${positionId}`);
                         if(rowToRemove) { rowToRemove.style.opacity = '0'; setTimeout(() => rowToRemove.remove(), 300); }
                         else { setTimeout(() => window.location.reload(), 500); } // Fallback reload
                    } else { throw new Error(data.message || 'Failed to delete post.'); }
                })
                .catch(error => {
                     showNotification(error.message, 'danger');
                     if(deleteConfirmModal) deleteConfirmModal.hide();
                })
                .finally(() => { setButtonLoading(this, false); }); // Reset button
         });
     }

    // Reset modals on close
    [newPositionModalEl, editPositionModalEl, deleteConfirmModalEl].forEach(modalEl => {
        if(modalEl) {
            modalEl.addEventListener('hidden.bs.modal', function () {
                const form = this.querySelector('form');
                if (form) { form.classList.remove('was-validated'); form.reset(); }
                const alertPlaceholder = this.querySelector('#addModalAlertPlaceholder, #editModalAlertPlaceholder');
                if (alertPlaceholder) alertPlaceholder.innerHTML = '';
                const addBtn = this.querySelector('#addPositionSubmitBtn'); if(addBtn) setButtonLoading(addBtn, false);
                const editBtn = this.querySelector('#editPositionSubmitBtn'); if(editBtn) setButtonLoading(editBtn, false);
                 const confirmDelBtn = this.querySelector('#confirmDeleteBtn'); if(confirmDelBtn) setButtonLoading(confirmDelBtn, false);
            });
        }
    });

}); // End DOMContentLoaded
</script>