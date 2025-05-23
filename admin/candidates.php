<?php
// Start session and include necessary files
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate CSP nonce if not exists
if (empty($_SESSION['csp_nonce'])) {
    $_SESSION['csp_nonce'] = base64_encode(random_bytes(16));
}
$nonce = htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8');

require_once "../config/database.php"; // Relative path from admin folder
require_once "includes/session.php"; // Includes isAdminLoggedIn()

// --- Security Check ---
if (!isAdminLoggedIn()) {
    header("Location: login.php");
    exit();
}

// --- Configuration ---
date_default_timezone_set('Asia/Manila'); // Adjust timezone
// error_reporting(E_ALL); // Dev for debugging
// ini_set('display_errors', 1); // Dev for debugging
ini_set('display_errors', 0); // Prod
error_reporting(0); // Prod


// --- Handle Bulk Delete Action ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'delete_bulk') {
    header('Content-Type: application/json'); // Set header for JSON response
    $response = ['success' => false, 'message' => 'Invalid request or no candidates selected.'];
    $deleted_count = 0;
    $error_details = [];

    if (isset($_POST['selected_ids']) && is_array($_POST['selected_ids']) && !empty($_POST['selected_ids'])) {
        $selected_ids = array_map('intval', $_POST['selected_ids']); // Sanitize IDs

        if (!empty($selected_ids)) {
            // Use the global $conn variable established in database.php
            global $conn;

            try {
                $conn->beginTransaction();

                // Prepare statements outside the loop
                $get_photo_sql = "SELECT photo FROM candidates WHERE id = :id";
                $get_photo_stmt = $conn->prepare($get_photo_sql);

                $delete_sql = "DELETE FROM candidates WHERE id = :id";
                $delete_stmt = $conn->prepare($delete_sql);

                foreach ($selected_ids as $candidate_id) {
                    if ($candidate_id > 0) {
                        // 1. Get photo path before deleting record
                        $get_photo_stmt->execute([':id' => $candidate_id]);
                        $photo_path = $get_photo_stmt->fetchColumn();

                        // 2. Delete candidate record
                        if ($delete_stmt->execute([':id' => $candidate_id])) {
                            if ($delete_stmt->rowCount() > 0) {
                                $deleted_count++;
                                // 3. Delete photo file if it exists
                                if ($photo_path) {
                                     // Construct absolute path from script location
                                     $absolute_photo_path = realpath(__DIR__ . "/../" . ltrim($photo_path, '/'));
                                     if ($absolute_photo_path && file_exists($absolute_photo_path)) {
                                          if (!@unlink($absolute_photo_path)) { // Suppress warning, log error
                                               error_log("Bulk Delete: Failed to delete photo file for candidate ID {$candidate_id}: {$absolute_photo_path}");
                                                // Log error but continue transaction
                                          }
                                     } else if ($absolute_photo_path) {
                                           error_log("Bulk Delete: Photo file record existed but file not found at: {$absolute_photo_path}");
                                     }
                                }
                            }
                        } else {
                            // Log error for specific ID if delete failed
                            $errorInfo = $delete_stmt->errorInfo();
                            error_log("Bulk Delete: Failed to delete candidate ID {$candidate_id}: " . ($errorInfo[2] ?? 'Unknown PDO error'));
                            $error_details[] = "Could not delete candidate ID {$candidate_id}.";
                        }
                    }
                } // End foreach loop

                if (empty($error_details)) {
                    $conn->commit();
                    $response['success'] = true;
                    $response['message'] = "Successfully deleted {$deleted_count} candidate(s).";
                } else {
                    // Rollback if any individual deletion failed
                    $conn->rollBack();
                    $response['message'] = "Operation failed. Deleted {$deleted_count} candidates successfully before encountering errors: " . implode(" ", $error_details);
                     http_response_code(500); // Indicate partial failure/server error
                }

            } catch (PDOException $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                error_log("Bulk Delete PDOException: " . $e->getMessage());
                $response['message'] = 'A database error occurred during bulk deletion.';
                http_response_code(500);
            } catch (Exception $e) {
                 if ($conn->inTransaction()) {
                    $conn->rollBack();
                 }
                 error_log("Bulk Delete Exception: " . $e->getMessage());
                 $response['message'] = 'An unexpected error occurred: ' . $e->getMessage();
                 http_response_code(500);
            }
        } else {
             $response['message'] = 'No valid candidate IDs were selected.';
             http_response_code(400);
        }
    } else {
         $response['message'] = 'No candidates selected for deletion.';
         http_response_code(400);
    }

    echo json_encode($response);
    exit; // Crucial: Stop script execution after handling AJAX request
}
// --- End Bulk Delete Action ---


// --- Initial Fetch for Filters & Basic Info ---
$elections = [];
$positions_for_filter = []; // Positions just for the filter dropdown
$candidates = [];
// Initialize stats array
$stats = ['total_candidates' => 0, 'total_positions' => 0, 'total_votes' => 0];
$fetch_error = null;
$selected_election = 'all';
$selected_position = 'all';

try {
    // Get all elections for filter
    $elections = $conn->query("SELECT id, title FROM elections ORDER BY start_date DESC, title ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Get selected filters from URL
    $selected_election = isset($_GET['election_id']) && $_GET['election_id'] !== 'all' ? filter_input(INPUT_GET, 'election_id', FILTER_VALIDATE_INT) : 'all';
    $selected_position = isset($_GET['position_id']) && $_GET['position_id'] !== 'all' ? filter_input(INPUT_GET, 'position_id', FILTER_VALIDATE_INT) : 'all';


    // Get positions for filter dropdown (optionally pre-filter based on selected election)
    $positions_filter_sql = "SELECT p.id, p.title, p.election_id, e.title as election_title
                           FROM positions p LEFT JOIN elections e ON p.election_id = e.id";
    $pos_filter_params = [];
    if ($selected_election !== 'all' && $selected_election) {
        $positions_filter_sql .= " WHERE p.election_id = :election_id";
        $pos_filter_params[':election_id'] = $selected_election;
    }
     $positions_filter_sql .= " ORDER BY e.start_date DESC, p.id ASC, p.title ASC";
     $stmt_pos_filter = $conn->prepare($positions_filter_sql);
     $stmt_pos_filter->execute($pos_filter_params);
     $positions_for_filter = $stmt_pos_filter->fetchAll(PDO::FETCH_ASSOC);


    // --- Fetch Main Candidate List based on filters ---
    $sql_candidates = "SELECT c.id, c.name, c.bio, c.photo, c.position_id,
                        p.title as position_title, p.election_id, e.title as election_title
                       FROM candidates c
                       LEFT JOIN positions p ON c.position_id = p.id
                       LEFT JOIN elections e ON p.election_id = e.id
                       WHERE 1=1"; // Start WHERE clause always true

    $params_candidates = [];
    if ($selected_election !== 'all' && $selected_election) {
        $sql_candidates .= " AND p.election_id = :election_id";
        $params_candidates[':election_id'] = $selected_election;
    }
    if ($selected_position !== 'all' && $selected_position) {
        $sql_candidates .= " AND c.position_id = :position_id";
        $params_candidates[':position_id'] = $selected_position;
    }
    $sql_candidates .= " ORDER BY e.start_date DESC, p.title ASC, c.name ASC";

    $stmt_candidates = $conn->prepare($sql_candidates);
    $stmt_candidates->execute($params_candidates);
    $candidates = $stmt_candidates->fetchAll(PDO::FETCH_ASSOC);

    // --- DYNAMIC STATS FETCHING ---
    // Fetch Overall Stats (these are site-wide totals, not filtered by selection)
    $stats['total_candidates'] = (int)$conn->query("SELECT COUNT(*) FROM candidates")->fetchColumn();
    $stats['total_positions'] = (int)$conn->query("SELECT COUNT(*) FROM positions")->fetchColumn();
    $stats['total_votes'] = (int)$conn->query("SELECT COUNT(*) FROM votes")->fetchColumn();
    // --- END DYNAMIC STATS FETCHING ---


} catch (PDOException $e) {
    error_log("Candidates Page Error: " . $e->getMessage());
    $fetch_error = "Could not load candidates data due to a database issue.";
    $elections = []; $positions_for_filter = []; $candidates = [];
    $stats = ['total_candidates' => 0, 'total_positions' => 0, 'total_votes' => 0]; // Reset on error
}


// --- Include Header (AFTER all PHP logic) ---
require_once "includes/header.php";
?>

<style nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
    /* Inherit styles from header.php or add specific overrides */
    :root {
        --primary-hue: 226; --primary-color: hsl(var(--primary-hue), 76%, 58%); --primary-light: hsl(var(--primary-hue), 76%, 95%); --primary-dark: hsl(var(--primary-hue), 70%, 48%);
        --secondary-color: #858796; --success-color: #1cc88a; --info-color: #36b9cc; --warning-color: #f6c23e; --danger-color: #e74a3b;
        --light-color: #f8f9fc; --white-color: #fff; --dark-color: #5a5c69; --gray-100: #f8f9fc; --gray-200: #eaecf4; --gray-300: #dddfeb; --gray-600: #858796; --border-color: #e3e6f0;
        --font-family-primary: "Poppins", sans-serif; --font-family-secondary: "Nunito", sans-serif;
        --shadow-sm: 0 .125rem .25rem rgba(0,0,0,.075); --shadow: 0 .15rem 1.75rem 0 rgba(58,59,69,.15); --border-radius: .35rem; --border-radius-lg: .5rem;
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

    /* Filter Bar */
    .filter-bar { background-color: var(--white-color); padding: .75rem 1.25rem; border-radius: var(--border-radius); box-shadow: var(--shadow-sm); border: 1px solid var(--border-color); }
    .filter-bar label { font-weight: 600; font-size: 0.9rem; color: var(--primary-dark); }
    .filter-bar .form-select { max-width: 280px; font-size: 0.9rem; }

    /* Candidates Table Card */
    .candidates-card { border: none; border-radius: var(--border-radius-lg); box-shadow: var(--shadow); }
    .candidates-card .card-header { background-color: var(--white-color); border-bottom: 1px solid var(--border-color); font-weight: 700; color: var(--primary-dark); padding: 1rem 1.25rem; }
    .candidates-table { margin-bottom: 0; }
    .candidates-table thead th { background-color: var(--gray-100); border-bottom: 1px solid var(--gray-300); border-top: none; font-size: .8rem; font-weight: 700; text-transform: uppercase; color: var(--gray-600); padding: .75rem 1rem; white-space: nowrap; letter-spacing: .5px;}
    .candidates-table tbody td { padding: .7rem 1rem; vertical-align: middle; border-top: 1px solid var(--border-color); font-size: 0.9rem; }
    .candidates-table tbody tr:first-child td { border-top: none; }
    .candidates-table tbody tr:hover { background-color: var(--primary-light); }
    .candidates-table .candidate-name { font-weight: 600; color: var(--dark-color); font-size: 0.95rem;}
    .candidates-table .position-details { font-size: 0.85rem; color: var(--secondary-color); display: block; max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;}
    .candidates-table .candidate-photo { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-color); background-color: var(--gray-200); }
    .candidates-table .action-buttons .btn { padding: 0.25rem 0.5rem; font-size: 0.8rem; margin-left: 0.25rem; box-shadow: var(--shadow-sm); }
    .candidates-table .action-buttons .btn:hover { transform: scale(1.1); }
    .candidates-table .form-check-input { cursor: pointer; }
    .candidates-table .no-candidates td { text-align: center; padding: 2.5rem; }
    .candidates-table .no-candidates i { font-size: 2.5rem; margin-bottom: .75rem; display: block; color: var(--gray-300); }
    .candidates-table .no-candidates p { color: var(--secondary-color); font-size: 1rem;}

    /* Table Footer Actions */
    .table-actions { padding: .75rem 1.25rem; background-color: var(--gray-100); border-top: 1px solid var(--border-color); }
    .table-actions .btn { font-size: .8rem; }

    /* Modals */
    .modal-header { background-color: var(--primary-light); border-bottom: 1px solid var(--border-color); }
    .modal-title { font-family: var(--font-family-primary); color: var(--primary-dark); }
    .modal-footer { background-color: var(--gray-100); border-top: 1px solid var(--border-color); }
    .was-validated .form-control:invalid, .form-control.is-invalid { border-color: var(--danger-color); background-image: none;}
    .was-validated .form-control:invalid:focus, .form-control.is-invalid:focus { box-shadow: 0 0 0 .25rem rgba(231, 74, 59, .25); }
    .was-validated .form-select:invalid, .form-select.is-invalid { border-color: var(--danger-color); }
    .was-validated .form-select:invalid:focus, .form-select.is-invalid:focus { border-color: var(--danger-color); box-shadow: 0 0 0 .25rem rgba(231, 74, 59, .25); }
    .invalid-feedback { font-size: .8rem; }
    #deleteSingleConfirmModal .modal-header, #deleteBulkConfirmModal .modal-header { background-color: var(--danger-color); color: white; }
    #deleteSingleConfirmModal .btn-close, #deleteBulkConfirmModal .btn-close { filter: brightness(0) invert(1);}
    #add_photoPreview, #edit_photoPreview { height: 150px; width: 150px; object-fit: cover; border: 1px solid var(--border-color); background-color: var(--gray-100);} /* Image preview */
    .img-thumbnail { padding: .25rem; background-color: #fff; border: 1px solid var(--border-color); border-radius: .25rem; max-width: 100%; height: auto; }

    /* Notification Toast */
    .toast-container { z-index: 1090; }
    .notification-toast { min-width: 300px; border-radius: var(--border-radius); box-shadow: var(--shadow-lg); padding: 0; border: none; }
    .notification-toast .notification-content { display: flex; align-items: center; padding: .75rem 1rem;}
    .notification-toast .toast-body { padding: 0; color: white; font-weight: 500; flex-grow: 1;}
    .notification-toast .notification-icon { font-size: 1.3rem; margin-right: .75rem; line-height: 1;}
    .notification-toast .btn-close-white { filter: brightness(0) invert(1); opacity: 0.8;}
    .notification-toast .btn-close-white:hover { opacity: 1;}

</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center page-header">
        <h1 class="h2 mb-0 text-gray-800">Manage Candidates</h1>
        <button type="button" class="btn btn-primary btn-sm shadow-sm" data-bs-toggle="modal" data-bs-target="#newCandidateModal">
            <i class="bi bi-person-plus-fill me-1"></i> Add New Candidate
        </button>
    </div>

     <div id="alertPlaceholder">
         <?php if ($fetch_error): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($fetch_error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
         </div>


    <div class="row g-3 mb-4">
        <div class="col-lg-4 col-md-6 mb-3">
            <div class="stats-card border-left-primary h-100">
                <div class="card-body">
                    <div>
                        <div class="stat-label text-primary">Total Candidates</div>
                        <div class="stat-value" id="stat-total-candidates"><?php echo number_format($stats['total_candidates'] ?? 0); ?></div>
                    </div>
                    <div class="stat-detail">Across all elections</div>
                    <i class="bi bi-people-fill stats-card-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6 mb-3">
            <div class="stats-card border-left-success h-100">
                <div class="card-body">
                    <div>
                        <div class="stat-label text-success">Total Positions</div>
                        <div class="stat-value" id="stat-total-positions"><?php echo number_format($stats['total_positions'] ?? 0); ?></div>
                    </div>
                     <div class="stat-detail">Across all elections</div>
                    <i class="bi bi-tag-fill stats-card-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-12">
             <div class="stats-card border-left-warning h-100">
                <div class="card-body">
                    <div>
                        <div class="stat-label text-warning">Total Votes Recorded</div>
                        <div class="stat-value" id="stat-total-votes"><?php echo number_format($stats['total_votes'] ?? 0); ?></div>
                    </div>
                     <div class="stat-detail">Overall system votes</div>
                     <i class="bi bi-check2-square stats-card-icon"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="filter-bar mb-4">
        <div class="row g-2 align-items-center">
            <div class="col-md-auto">
                <label class="col-form-label col-form-label-sm fw-bold">Filter:</label>
            </div>
            <div class="col-md">
                <select class="form-select form-select-sm" id="electionFilter" aria-label="Filter by election">
                    <option value="all" <?php echo $selected_election === 'all' ? 'selected' : ''; ?>>All Elections</option>
                    <?php foreach ($elections as $election): ?>
                        <option value="<?php echo $election['id']; ?>" <?php echo $selected_election == $election['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($election['title']); ?>
                        </option>
                    <?php endforeach; ?>
                     <?php if (empty($elections)): ?> <option disabled>No elections found</option> <?php endif; ?>
                </select>
            </div>
            <div class="col-md">
                 <select class="form-select form-select-sm" id="positionFilter" aria-label="Filter by position" <?php echo empty($positions_for_filter) ? 'disabled' : ''; ?>>
                    <option value="all" <?php echo $selected_position === 'all' ? 'selected' : ''; ?>>All Positions</option>
                     <?php foreach ($positions_for_filter as $position): ?>
                        <option value="<?php echo $position['id']; ?>"
                                data-election-id="<?php echo $position['election_id']; ?>"
                                <?php echo $selected_position == $position['id'] ? 'selected' : ''; ?>
                                <?php // Hide initially if election filter is set and doesn't match
                                if ($selected_election !== 'all' && $selected_election && $position['election_id'] != $selected_election) { echo ' style="display:none;"'; } ?>
                                >
                            <?php echo htmlspecialchars($position['title']); ?>
                             (<?php echo htmlspecialchars($position['election_title'] ?? 'N/A'); ?>)
                        </option>
                    <?php endforeach; ?>
                     <?php if (empty($positions_for_filter)): ?> <option disabled>No positions found</option> <?php endif; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="card candidates-card">
        <div class="card-header">
             <i class="bi bi-person-lines-fill me-2"></i>Candidates List
        </div>
        <div class="card-body p-0">
             <form id="candidatesTableForm" method="POST" action="candidates.php">
                <input type="hidden" name="action" value="delete_bulk">
                <div class="table-responsive">
                    <table class="table table-hover align-middle candidates-table">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" class="text-center" style="width: 1%;">
                                    <input class="form-check-input" type="checkbox" id="selectAllCheckbox" title="Select All">
                                </th>
                                <th scope="col" style="width: 1%;">Photo</th>
                                <th scope="col">Name & Description</th>
                                <th scope="col">Position</th>
                                <th scope="col">Election</th>
                                <th scope="col" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($candidates)): ?>
                                <tr class="no-candidates">
                                    <td colspan="6">
                                        <i class="bi bi-person-x-fill"></i>
                                        <p class="mb-0">
                                             <?php echo ($selected_election !== 'all' || $selected_position !== 'all') ? 'No candidates match the current filter.' : 'No candidates added yet.'; ?>
                                        </p>
                                    </td>
                                </tr>
                            <?php else: ?>
                            <?php foreach ($candidates as $candidate): ?>
                                    <tr id="candidate-row-<?php echo $candidate['id']; ?>">
                                        <td class="text-center">
                                            <input class="form-check-input candidate-checkbox" type="checkbox" name="selected_ids[]" value="<?php echo $candidate['id']; ?>" aria-label="Select candidate <?php echo htmlspecialchars($candidate['name']); ?>">
                                        </td>
                                        <td>
                                             <?php
                                                 // Determine photo path relative to *this* file (admin/candidates.php)
                                                 $default_avatar = 'assets/images/default-avatar.png'; // Default relative to assets folder
                                                 $photo_display_path = $default_avatar;
                                                 if (!empty($candidate['photo'])) {
                                                      // Path stored in DB is relative to project root (e.g., 'uploads/candidates/...')
                                                      $potential_path_from_root = "../" . ltrim($candidate['photo'], '/');
                                                     if (file_exists($potential_path_from_root)) {
                                                         $photo_display_path = htmlspecialchars($potential_path_from_root);
                                                     } else {
                                                         // error_log("Candidate photo file not found at expected path: " . $potential_path_from_root);
                                                     }
                                                 }
                                             ?>
                                            <img src="<?php echo $photo_display_path; ?>" class="candidate-photo" alt="<?php echo htmlspecialchars($candidate['name']); ?>">
                                    </td>
                                        <td>
                                            <span class="candidate-name"><?php echo htmlspecialchars($candidate['name']); ?></span>
                                            <small class="position-details" title="<?php echo htmlspecialchars($candidate['bio'] ?? ''); ?>"><?php echo htmlspecialchars($candidate['bio'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <a href="?position_id=<?php echo $candidate['position_id']; ?>&election_id=<?php echo $candidate['election_id']; ?>" class="link-secondary text-decoration-none">
                                                <?php echo htmlspecialchars($candidate['position_title'] ?? '[N/A]'); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <a href="?election_id=<?php echo $candidate['election_id']; ?>" class="link-secondary text-decoration-none">
                                                 <?php echo htmlspecialchars($candidate['election_title'] ?? '[N/A]'); ?>
                                            </a>
                                        </td>
                                        <td class="text-center text-nowrap action-buttons">
                                            <button type="button" class="btn btn-outline-primary btn-sm edit-candidate-btn"
                                                    data-candidate-id="<?php echo $candidate['id']; ?>"
                                                    data-bs-toggle="tooltip" data-bs-placement="top" title="Edit Candidate">
                                                <i class="bi bi-pencil-fill"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-danger btn-sm delete-candidate-btn"
                                                    data-candidate-id="<?php echo $candidate['id']; ?>"
                                                    data-candidate-name="<?php echo htmlspecialchars($candidate['name']); ?>"
                                                    data-bs-toggle="tooltip" data-bs-placement="top" title="Delete Candidate">
                                                <i class="bi bi-trash3-fill"></i>
                                            </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                 <?php if (!empty($candidates)): ?>
                 <div class="table-actions px-3 py-2 border-top d-flex align-items-center">
                     <small class="text-muted me-3"><span id="selectedCount">0</span> selected</small>
                     <button type="button" class="btn btn-sm btn-danger" id="deleteSelectedBtnBulk" disabled>
                          <i class="bi bi-trash-fill me-1"></i> Delete Selected
                     </button>
                 </div>
                 <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="newCandidateModal" tabindex="-1" aria-labelledby="newCandidateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="newCandidateModalLabel"><i class="bi bi-person-plus-fill me-2"></i>Add New Candidate</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addCandidateForm" method="POST" enctype="multipart/form-data" novalidate>
                <div class="modal-body p-4">
                    <div id="addModalAlertPlaceholder"></div>
                    <input type="hidden" name="action" value="create">
                    <div class="row g-3">
                         <div class="col-md-8">
                            <div class="mb-3">
                                <label for="add_name" class="form-label">Candidate Name*</label>
                                <input type="text" class="form-control" id="add_name" name="name" required>
                                <div class="invalid-feedback">Please enter the candidate's name.</div>
                            </div>
                            <div class="mb-3">
                                <label for="add_position_id" class="form-label">Position*</label>
                                <select class="form-select" id="add_position_id" name="position_id" required <?php echo empty($positions_for_filter) ? 'disabled' : ''; ?>>
                                    <option value="" selected disabled>-- Select Position --</option>
                                    <?php foreach ($positions_for_filter as $position): ?>
                                        <option value="<?php echo $position['id']; ?>"
                                                <?php // Pre-select if a specific position was filtered on the main page
                                                     echo ($selected_position !== 'all' && $selected_position == $position['id']) ? 'selected' : '';
                                                ?>
                                                >
                                            <?php echo htmlspecialchars($position['title'] . ' (' . ($position['election_title'] ?? 'N/A') . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                     <?php if(empty($positions_for_filter)): ?>
                                         <option value="" disabled>No positions found. Add positions first.</option>
                                     <?php endif; ?>
                                </select>
                                 <div class="invalid-feedback">Please select a position.</div>
                            </div>
                            <div class="mb-3">
                                <label for="add_bio" class="form-label">Short Description/Slogan <span class="text-muted small">(Optional)</span></label>
                                <textarea class="form-control" id="add_bio" name="bio" rows="2"></textarea>
                            </div>
                        </div>
                         <div class="col-md-4 text-center">
                            <label for="add_photo" class="form-label">Photo <span class="text-muted small">(Optional)</span></label>
                             <img id="add_photoPreview" src="assets/images/default-avatar.png" alt="Photo Preview" class="img-thumbnail mb-2">
                            <input type="file" class="form-control form-control-sm" id="add_photo" name="photo" accept="image/jpeg, image/png, image/gif, image/webp">
                            <small class="form-text text-muted d-block mt-1">Max 5MB. JPG, PNG, GIF, WEBP.</small>
                            <div class="invalid-feedback" id="add_photo_feedback"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="addCandidateSubmitBtn" <?php echo empty($positions_for_filter) ? 'disabled' : ''; ?>>
                        <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
                        <i class="bi bi-plus-lg"></i> Add Candidate
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editCandidateModal" tabindex="-1" aria-labelledby="editCandidateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editCandidateModalLabel"><i class="bi bi-pencil-square me-2"></i>Edit Candidate</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editCandidateForm" method="POST" enctype="multipart/form-data" novalidate>
                <input type="hidden" id="edit_candidate_id" name="candidate_id">
                <div class="modal-body p-4">
                     <div id="editModalAlertPlaceholder"></div>
                    <div class="row g-3">
                         <div class="col-md-8">
                            <div class="mb-3">
                                <label for="edit_name" class="form-label">Candidate Name*</label>
                                <input type="text" class="form-control" id="edit_name" name="name" required>
                                <div class="invalid-feedback">Name is required.</div>
                            </div>
                            <div class="mb-3">
                                <label for="edit_position_id" class="form-label">Position*</label>
                                <select class="form-select" id="edit_position_id" name="position_id" required <?php echo empty($positions_for_filter) ? 'disabled' : ''; ?>>
                                    <option value="">-- Select Position --</option>
                                    <?php foreach ($positions_for_filter as $position): ?>
                                        <option value="<?php echo $position['id']; ?>">
                                            <?php echo htmlspecialchars($position['title'] . ' (' . ($position['election_title'] ?? 'N/A') . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                     <?php if(empty($positions_for_filter)): ?>
                                         <option value="" disabled>No positions found</option>
                                     <?php endif; ?>
                                </select>
                                <div class="invalid-feedback">Please select a position.</div>
                            </div>
                            <div class="mb-3">
                                <label for="edit_bio" class="form-label">Description <span class="text-muted small">(Optional)</span></label>
                                <textarea class="form-control" id="edit_bio" name="bio" rows="3"></textarea>
                            </div>
                        </div>
                        <div class="col-md-4 text-center">
                             <label class="form-label d-block">Current Photo</label>
                             <img id="edit_photoPreview" src="assets/images/default-avatar.png" alt="Current Photo" class="img-thumbnail mb-2">
                             <label for="edit_photo" class="form-label">Change Photo <span class="text-muted small">(Optional)</span></label>
                            <input type="file" class="form-control form-control-sm" id="edit_photo" name="photo" accept="image/jpeg, image/png, image/gif, image/webp">
                             <small class="form-text text-muted d-block mt-1">Leave empty to keep current photo.</small>
                             <div class="invalid-feedback" id="edit_photo_feedback"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="editCandidateSubmitBtn">
                        <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
                        <i class="bi bi-save-fill me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteSingleConfirmModal" tabindex="-1" aria-labelledby="deleteSingleConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white border-0">
                 <h5 class="modal-title" id="deleteSingleConfirmModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Deletion</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center py-4">
                <p class="lead mb-2">Delete candidate "<strong id="candidateToDeleteName"></strong>"?</p>
                <p class="text-danger small"><i class="bi bi-exclamation-circle me-1"></i>This action cannot be undone.</p>
                <input type="hidden" id="deleteCandidateIdSingle">
            </div>
             <div class="modal-footer justify-content-center border-0 pt-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                 <button type="button" class="btn btn-danger px-4" id="confirmDeleteSingleBtn">
                      <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
                      <i class="bi bi-trash3-fill me-1"></i>Delete
                 </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteBulkConfirmModal" tabindex="-1" aria-labelledby="deleteBulkConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white border-0">
                 <h5 class="modal-title" id="deleteBulkConfirmModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Bulk Deletion</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center py-4">
                <p class="lead mb-2">Are you sure you want to delete the <strong id="bulkDeleteCount">0</strong> selected candidate(s)?</p>
                <p class="text-danger small"><i class="bi bi-exclamation-circle me-1"></i>This action cannot be undone.</p>
            </div>
             <div class="modal-footer justify-content-center border-0 pt-0">
                 <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                 <button type="button" class="btn btn-danger px-4" id="confirmDeleteBulkBtn">
                      <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
                      <i class="bi bi-trash3-fill me-1"></i>Delete Selected
                 </button>
            </div>
        </div>
    </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3">
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


<?php require_once "includes/footer.php"; // Includes closing body/html, Bootstrap JS ?>

<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="https://unpkg.com/countup.js@2.8.0/dist/countUp.umd.js"></script>
<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
document.addEventListener('DOMContentLoaded', function () {
    // --- Initialize Bootstrap Components ---
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (el) { return new bootstrap.Tooltip(el) });

    const newCandidateModalEl = document.getElementById('newCandidateModal');
    const editCandidateModalEl = document.getElementById('editCandidateModal');
    const deleteSingleModalEl = document.getElementById('deleteSingleConfirmModal');
    const deleteBulkModalEl = document.getElementById('deleteBulkConfirmModal');
    const newCandidateModal = newCandidateModalEl ? bootstrap.Modal.getOrCreateInstance(newCandidateModalEl) : null;
    const editCandidateModal = editCandidateModalEl ? bootstrap.Modal.getOrCreateInstance(editCandidateModalEl) : null;
    const deleteSingleModal = deleteSingleModalEl ? bootstrap.Modal.getOrCreateInstance(deleteSingleModalEl) : null;
    const deleteBulkModal = deleteBulkModalEl ? bootstrap.Modal.getOrCreateInstance(deleteBulkModalEl) : null;

    const notificationToastEl = document.getElementById('notificationToast');
    const notificationToast = notificationToastEl ? bootstrap.Toast.getOrCreateInstance(notificationToastEl) : null;

    // --- DOM References ---
    const addForm = document.getElementById('addCandidateForm');
    const editForm = document.getElementById('editCandidateForm');
    const mainTableForm = document.getElementById('candidatesTableForm'); // Form wrapping the table
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    const deleteSelectedBtnBulk = document.getElementById('deleteSelectedBtnBulk');
    const selectedCountSpan = document.getElementById('selectedCount');
    const electionFilter = document.getElementById('electionFilter');
    const positionFilter = document.getElementById('positionFilter');
    const addModalAlertPlaceholder = document.getElementById('addModalAlertPlaceholder');
    const editModalAlertPlaceholder = document.getElementById('editModalAlertPlaceholder');
    const addPhotoInput = document.getElementById('add_photo');
    const addPhotoPreview = document.getElementById('add_photoPreview');
    const editPhotoInput = document.getElementById('edit_photo');
    const editPhotoPreview = document.getElementById('edit_photoPreview');
    const addCandidateSubmitBtn = document.getElementById('addCandidateSubmitBtn');
    const editCandidateSubmitBtn = document.getElementById('editCandidateSubmitBtn');
    const deleteCandidateIdSingleInput = document.getElementById('deleteCandidateIdSingle');
    const candidateToDeleteNameEl = document.getElementById('candidateToDeleteName');
    const confirmDeleteSingleBtn = document.getElementById('confirmDeleteSingleBtn');
    const confirmDeleteBulkBtn = document.getElementById('confirmDeleteBulkBtn');
    const bulkDeleteCountEl = document.getElementById('bulkDeleteCount');
    const defaultAvatarPath = 'assets/images/default-avatar.png'; // Define default path

    // --- Utility: LTrim ---
    function ltrim(str, chars) { chars = chars || "\\s"; return str.replace(new RegExp("^[" + chars + "]+", "g"), ""); }


    // --- DYNAMIC STATS DISPLAY (CountUp.js) ---
    if (typeof CountUp === 'function') {
        const countUpOptions = { duration: 2, enableScrollSpy: true, scrollSpyDelay: 50, scrollSpyOnce: true };
        try {
            const statsData = <?php echo json_encode($stats); ?>;
            const elCandTotal = document.getElementById('stat-total-candidates');
            const elPosTotal = document.getElementById('stat-total-positions');
            const elVotesTotal = document.getElementById('stat-total-votes');
            if(elCandTotal) new CountUp(elCandTotal, statsData.total_candidates || 0, countUpOptions).start();
            if(elPosTotal) new CountUp(elPosTotal, statsData.total_positions || 0, countUpOptions).start();
            if(elVotesTotal) new CountUp(elVotesTotal, statsData.total_votes || 0, countUpOptions).start();
        } catch (e) { console.error("CountUp init error:", e); }
    } else {
         console.warn("CountUp library not available. Displaying static stats.");
         const statsData = <?php echo json_encode($stats); ?>;
         document.getElementById('stat-total-candidates').textContent = statsData.total_candidates || 0;
         document.getElementById('stat-total-positions').textContent = statsData.total_positions || 0;
         document.getElementById('stat-total-votes').textContent = statsData.total_votes || 0;
    }


    // --- Helper: Show Notification Toast ---
    function showNotification(message, type = 'success') {
        const msgEl = document.getElementById('notificationMessage');
        const iconEl = notificationToastEl?.querySelector('.notification-icon');
        if (!notificationToast || !msgEl || !iconEl) return;
        notificationToastEl.className = 'toast align-items-center border-0'; // Reset classes
        iconEl.innerHTML = '';
        message = String(message || 'Action completed.').substring(0, 200);
        msgEl.textContent = message;
        let iconClass = 'bi-check-circle-fill', bgClass = 'bg-success', textClass = 'text-white';
        switch (type) {
            case 'danger': iconClass = 'bi-x-octagon-fill'; bgClass = 'bg-danger'; break;
            case 'warning': iconClass = 'bi-exclamation-triangle-fill'; bgClass = 'bg-warning'; textClass = 'text-dark'; break;
            case 'info': iconClass = 'bi-info-circle-fill'; bgClass = 'bg-info'; break;
        }
        if (bgClass) notificationToastEl.classList.add(bgClass);
        if (textClass) notificationToastEl.classList.add(textClass);
        iconEl.innerHTML = `<i class="bi ${iconClass}"></i>`;
        notificationToast.show();
    }

    // --- Helper: Show Modal Alert ---
     function showModalAlert(placeholder, message, type = 'danger') {
          if(placeholder) {
               const icon = type === 'danger' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill';
               placeholder.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show d-flex align-items-center small p-2 mb-3" role="alert"><i class="bi ${icon} me-2"></i><div>${message}</div><button type="button" class="btn-close p-2" data-bs-dismiss="alert" aria-label="Close"></button></div>`;
          }
     }

     // --- Helper: Set/Reset Button Loading State ---
     function setButtonLoading(button, isLoading, loadingText = "Processing...") {
         if (!button) return;
         const originalHTML = button.dataset.originalHTML || button.innerHTML;
         if (!button.dataset.originalHTML) button.dataset.originalHTML = originalHTML;
         const spinner = button.querySelector('.spinner-border');
         const icon = button.querySelector('.bi');
         if (isLoading) {
             button.disabled = true;
             if (!spinner) { const newSpinner = document.createElement('span'); newSpinner.className = 'spinner-border spinner-border-sm me-1'; newSpinner.setAttribute('role', 'status'); newSpinner.setAttribute('aria-hidden', 'true'); button.prepend(newSpinner); } else { spinner.classList.remove('d-none'); }
             if (icon) icon.style.display = 'none';
             const textNode = Array.from(button.childNodes).find(node => node.nodeType === Node.TEXT_NODE && node.textContent.trim().length > 0); if (textNode) textNode.textContent = ` ${loadingText}`;
         } else {
             button.disabled = false;
             if (spinner) spinner.classList.add('d-none');
             if (icon) icon.style.display = '';
             button.innerHTML = originalHTML; // Restore original
             // Clear stored original HTML after restoring to allow future updates if needed
             delete button.dataset.originalHTML;
         }
     }


    // --- Filtering Logic ---
    function applyFilters() {
        const electionId = electionFilter.value;
        const positionId = positionFilter.value;
        const url = new URL(window.location.pathname, window.location.origin);
        if (electionId !== 'all') url.searchParams.set('election_id', electionId); else url.searchParams.delete('election_id');
        if (positionId !== 'all') url.searchParams.set('position_id', positionId); else url.searchParams.delete('position_id');
        window.location.href = url.toString();
    }

    if(electionFilter) {
        electionFilter.addEventListener('change', function() {
            const selectedElection = this.value;
            if (positionFilter) {
                 let currentPositionVal = positionFilter.value;
                 let foundCurrent = false;
                 Array.from(positionFilter.options).forEach(option => {
                     if (option.value === 'all') { option.style.display = ''; return; }
                     const electionMatch = selectedElection === 'all' || option.dataset.electionId == selectedElection;
                     option.style.display = electionMatch ? '' : 'none';
                     if (electionMatch && option.value == currentPositionVal) foundCurrent = true;
                 });
                 if (!foundCurrent) positionFilter.value = 'all'; // Reset position if not valid for new election
                 positionFilter.disabled = (selectedElection === 'all' && <?php echo empty($positions_for_filter) ? 'true' : 'false'; ?>); // Simplified check
            }
            applyFilters();
        });
    }
    if(positionFilter) positionFilter.addEventListener('change', applyFilters);
    // Initial position filter setup based on selected election on page load
     if (electionFilter && positionFilter) {
         const initialElection = electionFilter.value;
         Array.from(positionFilter.options).forEach(option => {
              if (option.value === 'all') return;
              option.style.display = (initialElection === 'all' || option.dataset.electionId == initialElection) ? '' : 'none';
         });
         positionFilter.disabled = (initialElection === 'all' && <?php echo empty($positions_for_filter) ? 'true' : 'false'; ?>);
     }


    // --- Photo Preview Logic ---
    function setupPhotoPreview(inputEl, previewEl, defaultSrc, feedbackEl) {
        if (!inputEl || !previewEl) return;
        inputEl.addEventListener('change', function(event) {
            const file = event.target.files[0];
            if (feedbackEl) feedbackEl.textContent = ''; // Clear feedback on change
            inputEl.classList.remove('is-invalid');

            if (file) {
                if (file.size > 5 * 1024 * 1024) { // Size check
                    previewEl.src = defaultSrc; inputEl.value = ''; inputEl.classList.add('is-invalid');
                    if (feedbackEl) feedbackEl.textContent = 'File too large (Max 5MB).'; return;
                }
                if (!['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(file.type)) { // Type check
                    previewEl.src = defaultSrc; inputEl.value = ''; inputEl.classList.add('is-invalid');
                    if (feedbackEl) feedbackEl.textContent = 'Invalid file type (JPG, PNG, GIF, WEBP).'; return;
                }
                const reader = new FileReader(); reader.onload = (e) => { previewEl.src = e.target.result; }; reader.readAsDataURL(file);
            } else {
                 previewEl.src = defaultSrc; // Reset to default if no file selected
            }
        });
    }
    setupPhotoPreview(addPhotoInput, addPhotoPreview, defaultAvatarPath, document.getElementById('add_photo_feedback'));
    setupPhotoPreview(editPhotoInput, editPhotoPreview, editPhotoPreview?.dataset.default || defaultAvatarPath, document.getElementById('edit_photo_feedback'));


    // --- Add Candidate Form Handling ---
    if (addForm && addCandidateSubmitBtn) {
        addForm.addEventListener('submit', function(e) {
            e.preventDefault();
            addModalAlertPlaceholder.innerHTML = ''; addForm.classList.remove('was-validated');
            if (!addForm.checkValidity()) { e.stopPropagation(); addForm.classList.add('was-validated'); showModalAlert(addModalAlertPlaceholder, 'Please fill required fields.', 'warning'); return; }

            setButtonLoading(addCandidateSubmitBtn, true, 'Adding...');
            const formData = new FormData(addForm);

            fetch('add_candidate.php', { method: 'POST', body: formData, headers: {'Accept': 'application/json'}})
                .then(async response => { // Use async to await json parsing
                    const data = await response.json();
                    if (!response.ok) throw new Error(data.message || `HTTP error! status: ${response.status}`);
                    return data;
                })
                .then(data => {
                    showNotification(data.message || 'Candidate added!', 'success');
                    if(newCandidateModal) newCandidateModal.hide();
                    setTimeout(() => window.location.reload(), 1000);
                })
                .catch(error => { showModalAlert(addModalAlertPlaceholder, error.message, 'danger'); })
                .finally(() => { setButtonLoading(addCandidateSubmitBtn, false); });
        });
    }

    // --- Edit Candidate Handling ---
    // 1. Populate Modal
    document.querySelectorAll('.edit-candidate-btn').forEach(button => {
        button.addEventListener('click', function() {
            const candidateId = this.getAttribute('data-candidate-id');
            if (!candidateId) return;
            editModalAlertPlaceholder.innerHTML = ''; editForm?.classList.remove('was-validated'); editForm.reset();
            if (editPhotoInput) { editPhotoInput.value = ''; editPhotoInput.classList.remove('is-invalid'); } // Reset file input state
            const editPhotoFeedback = document.getElementById('edit_photo_feedback'); if (editPhotoFeedback) editPhotoFeedback.textContent = '';

            setButtonLoading(editCandidateSubmitBtn, true, 'Loading...');

            fetch(`get_candidate.php?id=${candidateId}`, { headers: {'Accept': 'application/json'}})
                .then(response => {
                     if (!response.ok) throw new Error(`HTTP error ${response.status}`);
                     return response.json();
                })
                .then(data => {
                    if (data.success && data.candidate) {
                        const cand = data.candidate;
                        document.getElementById('edit_candidate_id').value = cand.id;
                        document.getElementById('edit_name').value = cand.name;
                        document.getElementById('edit_position_id').value = cand.position_id;
                        document.getElementById('edit_bio').value = cand.bio || '';
                        const preview = document.getElementById('edit_photoPreview');
                        // Construct path relative to this script's dir (admin/) to reach project root uploads
                        const photoPathFromRoot = cand.photo ? `../${ltrim(cand.photo, '/')}` : defaultAvatarPath;
                        preview.src = photoPathFromRoot;
                        preview.onerror = () => { preview.src = defaultAvatarPath; }; // Fallback
                        preview.dataset.default = photoPathFromRoot; // Store for reset

                        if(editCandidateModal) editCandidateModal.show();
                    } else { throw new Error(data.message || 'Could not load candidate data.'); }
                })
                .catch(error => { console.error('Fetch Edit Error:', error); showNotification('Error loading candidate details.', 'danger'); })
                .finally(() => { setButtonLoading(editCandidateSubmitBtn, false); });
        });
    });

    // 2. Submit Edit Form
    if(editForm && editCandidateSubmitBtn) {
        editForm.addEventListener('submit', function(e) {
             e.preventDefault();
            editModalAlertPlaceholder.innerHTML = ''; editForm.classList.remove('was-validated');
             if (!editForm.checkValidity()) { e.stopPropagation(); editForm.classList.add('was-validated'); showModalAlert(editModalAlertPlaceholder, 'Please fill required fields.', 'warning'); return; }

             setButtonLoading(editCandidateSubmitBtn, true, 'Saving...');
             const formData = new FormData(editForm);

             fetch('edit_candidate.php', { method: 'POST', body: formData, headers: {'Accept': 'application/json'}})
                .then(async response => {
                    const data = await response.json();
                    if (!response.ok) throw new Error(data.message || `HTTP error! status: ${response.status}`);
                    return data;
                 })
                .then(data => {
                    showNotification(data.message || 'Candidate updated!', 'success');
                    if(editCandidateModal) editCandidateModal.hide();
                    setTimeout(() => window.location.reload(), 1000);
                })
                .catch(error => { showModalAlert(editModalAlertPlaceholder, error.message, 'danger'); })
                .finally(() => { setButtonLoading(editCandidateSubmitBtn, false); });
        });
    }

     // --- Delete Candidate Handling (Single) ---
     document.querySelectorAll('.delete-candidate-btn').forEach(button => {
         button.addEventListener('click', function() {
             const candidateId = this.getAttribute('data-candidate-id');
             const candidateName = this.getAttribute('data-candidate-name');
             if(deleteCandidateIdSingleInput) deleteCandidateIdSingleInput.value = candidateId;
             if(candidateToDeleteNameEl) candidateToDeleteNameEl.textContent = candidateName || 'this candidate';
             if(deleteSingleModal) deleteSingleModal.show();
         });
     });

     if(confirmDeleteSingleBtn) {
         confirmDeleteSingleBtn.addEventListener('click', function() {
             const candidateId = deleteCandidateIdSingleInput ? deleteCandidateIdSingleInput.value : null;
             if (!candidateId) return;

             setButtonLoading(this, true, 'Deleting...');
             const formData = new FormData();
             formData.append('candidate_id', candidateId); // Parameter name expected by delete_candidate.php

             fetch('delete_candidate.php', { method: 'POST', body: formData, headers: {'Accept': 'application/json'}})
                 .then(async response => {
                    const data = await response.json();
                    if (!response.ok) throw new Error(data.message || `HTTP error! status: ${response.status}`);
                    return data;
                 })
                 .then(data => {
                    showNotification(data.message || 'Candidate deleted!', 'success');
                    if(deleteSingleModal) deleteSingleModal.hide();
                    const rowToRemove = document.getElementById(`candidate-row-${candidateId}`);
                    if(rowToRemove) { rowToRemove.style.transition = 'opacity 0.3s ease-out'; rowToRemove.style.opacity = '0'; setTimeout(() => { rowToRemove.remove(); updateSelectAllState(); updateSelectedCount(); }, 300); }
                    else { setTimeout(() => window.location.reload(), 500); }
                 })
                 .catch(error => { showNotification(error.message, 'danger'); if(deleteSingleModal) deleteSingleModal.hide(); })
                 .finally(() => { setButtonLoading(this, false); });
         });
     }

     // --- Bulk Delete Handling ---
     function updateSelectedCount() {
         const selectedCheckboxes = document.querySelectorAll('.candidate-checkbox:checked');
         const count = selectedCheckboxes.length;
         if(selectedCountSpan) selectedCountSpan.textContent = count;
         if(deleteSelectedBtnBulk) deleteSelectedBtnBulk.disabled = count === 0;
         if(bulkDeleteCountEl) bulkDeleteCountEl.textContent = count;
     }

     function updateSelectAllState() {
        const totalCheckboxes = document.querySelectorAll('.candidate-checkbox').length;
        const checkedCount = document.querySelectorAll('.candidate-checkbox:checked').length;
        if (selectAllCheckbox) {
             if(totalCheckboxes === 0) { selectAllCheckbox.checked = false; selectAllCheckbox.indeterminate = false; selectAllCheckbox.disabled = true; }
             else { selectAllCheckbox.disabled = false; selectAllCheckbox.checked = checkedCount === totalCheckboxes; selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < totalCheckboxes; }
        }
    }

     if(selectAllCheckbox) {
         selectAllCheckbox.addEventListener('change', function() {
             document.querySelectorAll('.candidate-checkbox').forEach(checkbox => checkbox.checked = this.checked);
             updateSelectedCount();
         });
     }
     document.querySelectorAll('.candidate-checkbox').forEach(checkbox => {
         checkbox.addEventListener('change', () => { updateSelectedCount(); updateSelectAllState(); });
     });

     if(deleteSelectedBtnBulk) {
         deleteSelectedBtnBulk.addEventListener('click', function() {
             if (document.querySelectorAll('.candidate-checkbox:checked').length > 0) {
                 if(deleteBulkModal) deleteBulkModal.show();
             } else { showNotification("Please select at least one candidate to delete.", "warning"); }
         });
     }

     if(confirmDeleteBulkBtn) {
         confirmDeleteBulkBtn.addEventListener('click', function() {
             if (!mainTableForm) { console.error("Could not find form #candidatesTableForm"); return; }
             const selectedCheckboxes = mainTableForm.querySelectorAll('.candidate-checkbox:checked');
             if (selectedCheckboxes.length === 0) return;

             setButtonLoading(this, true, 'Deleting...');
             const formData = new FormData(mainTableForm); // Submit the main form which contains checkboxes

             fetch('candidates.php', { method: 'POST', body: formData, headers: {'Accept': 'application/json'} }) // POST to self (where bulk delete logic is)
                 .then(async response => {
                    const data = await response.json();
                    if (!response.ok) throw new Error(data.message || `HTTP error! status: ${response.status}`);
                    return data;
                 })
                 .then(data => {
                    showNotification(data.message || 'Selected candidates deleted!', 'success');
                    if(deleteBulkModal) deleteBulkModal.hide();
                    const selectedIds = Array.from(selectedCheckboxes).map(cb => cb.value);
                    selectedIds.forEach(id => { const row = document.getElementById(`candidate-row-${id}`); if(row) { row.style.transition = 'opacity 0.3s ease-out'; row.style.opacity = '0'; setTimeout(() => row.remove(), 300); } });
                    // Update counts/states after rows are removed
                    setTimeout(() => { updateSelectedCount(); updateSelectAllState(); }, 350);
                 })
                 .catch(error => { showNotification(error.message, 'danger'); if(deleteBulkModal) deleteBulkModal.hide(); })
                 .finally(() => { setButtonLoading(this, false); });
         });
     }
     updateSelectedCount(); // Initial count
     updateSelectAllState(); // Initial select all state


    // --- Reset Modals on Close ---
     [newCandidateModalEl, editCandidateModalEl, deleteSingleModalEl, deleteBulkModalEl].forEach(modalEl => {
        if(modalEl) {
            modalEl.addEventListener('hidden.bs.modal', function () {
                const form = this.querySelector('form');
                if (form) { form.classList.remove('was-validated'); form.reset(); }
                const alertPlaceholder = this.querySelector('#addModalAlertPlaceholder, #editModalAlertPlaceholder');
                if (alertPlaceholder) alertPlaceholder.innerHTML = '';
                // Reset image previews
                if(addPhotoPreview) addPhotoPreview.src = defaultAvatarPath;
                if(editPhotoPreview) editPhotoPreview.src = editPhotoPreview.dataset.default || defaultAvatarPath;
                 // Reset file input visual state
                 modalEl.querySelectorAll('input[type="file"]').forEach(input => { input.value = ''; input.classList.remove('is-invalid'); });
                 modalEl.querySelectorAll('.invalid-feedback').forEach(el => el.textContent = ''); // Clear feedback text

                 setButtonLoading(document.getElementById('addCandidateSubmitBtn'), false);
                 setButtonLoading(document.getElementById('editCandidateSubmitBtn'), false);
                 setButtonLoading(document.getElementById('confirmDeleteSingleBtn'), false);
                 setButtonLoading(document.getElementById('confirmDeleteBulkBtn'), false);
            });
        }
    });

    // Sidebar Toggle Logic (ensure it's loaded, maybe move to common.js)
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