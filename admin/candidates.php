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
// Production settings:
// ini_set('display_errors', 0);
// error_reporting(0);

// Generate CSP nonce
if (empty($_SESSION['csp_nonce'])) {
    $_SESSION['csp_nonce'] = base64_encode(random_bytes(16));
}
$nonce = htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8');

// --- Initial Fetch for Filters & Basic Info ---
$elections = [];
$positions_for_filter = []; // Positions just for the filter dropdown
$candidates = [];
$stats = ['total_candidates' => 0, 'total_positions' => 0, 'total_votes' => 0];
$fetch_error = null;
$selected_election = 'all';
$selected_position = 'all';

try {
    // Get all elections for filter
    $elections = $conn->query("SELECT id, title FROM elections ORDER BY start_date DESC, title ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Get selected filters
    $selected_election = isset($_GET['election_id']) && $_GET['election_id'] !== 'all' ? filter_input(INPUT_GET, 'election_id', FILTER_VALIDATE_INT) : 'all';
    $selected_position = isset($_GET['position_id']) && $_GET['position_id'] !== 'all' ? filter_input(INPUT_GET, 'position_id', FILTER_VALIDATE_INT) : 'all';

    // Get positions for filter dropdown (optionally filter based on selected election)
    $positions_filter_sql = "SELECT p.id, p.title, p.election_id, e.title as election_title
                           FROM positions p LEFT JOIN elections e ON p.election_id = e.id";
    $pos_filter_params = [];
    if ($selected_election !== 'all' && $selected_election) {
        $positions_filter_sql .= " WHERE p.election_id = :election_id";
        $pos_filter_params[':election_id'] = $selected_election;
    }
     $positions_filter_sql .= " ORDER BY e.start_date DESC, p.title ASC";
     $stmt_pos_filter = $conn->prepare($positions_filter_sql);
     $stmt_pos_filter->execute($pos_filter_params);
     $positions_for_filter = $stmt_pos_filter->fetchAll(PDO::FETCH_ASSOC);


    // --- Fetch Main Candidate List ---
    $sql_candidates = "SELECT c.id, c.name, c.description, c.photo, c.position_id,
                        p.title as position_title, p.election_id, e.title as election_title
        FROM candidates c
        LEFT JOIN positions p ON c.position_id = p.id
        LEFT JOIN elections e ON p.election_id = e.id
                       WHERE 1=1"; // Start WHERE clause

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

    // --- Fetch Overall Stats ---
    // Use conditions similar to candidate fetching for accuracy if needed, or keep simple totals
    $stats['total_candidates'] = (int)$conn->query("SELECT COUNT(*) FROM candidates")->fetchColumn(); // Overall total
    $stats['total_positions'] = (int)$conn->query("SELECT COUNT(*) FROM positions")->fetchColumn(); // Overall total
    $stats['total_votes'] = (int)$conn->query("SELECT COUNT(*) FROM votes")->fetchColumn(); // Overall total

} catch (PDOException $e) {
    error_log("Candidates Page Error: " . $e->getMessage());
    $fetch_error = "Could not load candidates data due to a database issue.";
    $elections = []; $positions_for_filter = []; $candidates = [];
    $stats = ['total_candidates' => 0, 'total_positions' => 0, 'total_votes' => 0];
}

// --- Handle POST Actions (Refined for AJAX) ---
// This section will now primarily handle AJAX requests from the JS below.
// We assume specific backend scripts (add_candidate.php, edit_candidate.php, delete_candidate.php)
// are preferred, but if POSTing to *this* file, the logic needs routing.
// Example: Handling ADD action if posted here (less ideal than separate file)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'create') {
    header('Content-Type: application/json'); // Expect AJAX, respond JSON
    $response = ['success' => false, 'message' => 'Invalid request.']; // Default error

    try {
        // --- Validation ---
        $name = trim($_POST['name'] ?? '');
        $position_id = filter_input(INPUT_POST, 'position_id', FILTER_VALIDATE_INT);
        $description = trim($_POST['description'] ?? '');

        if (empty($name) || !$position_id) {
            throw new Exception("Name and Position are required.");
        }

        // --- Photo Upload Handling ---
        $photo_path_db = null; // Path to store in DB
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
             $upload_dir = "../uploads/candidates/"; // Path relative to *this* script
             if (!is_dir($upload_dir)) { if (!mkdir($upload_dir, 0775, true)) throw new Exception("Failed to create upload directory."); }

             $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
             $file_info = pathinfo($_FILES['photo']['name']);
             $file_extension = strtolower($file_info['extension'] ?? '');
             $file_size = $_FILES['photo']['size'];
             $max_size = 5 * 1024 * 1024; // 5MB limit

             if (!in_array($file_extension, $allowed_types)) throw new Exception("Invalid file type. Only JPG, PNG, GIF, WEBP allowed.");
             if ($file_size > $max_size) throw new Exception("File size exceeds the 5MB limit.");

             $new_filename = uniqid('cand_', true) . '.' . $file_extension;
             $target_path_absolute = $upload_dir . $new_filename;

             if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_path_absolute)) {
                // Store path relative to web root (or consistent base) for DB
                 $photo_path_db = 'uploads/candidates/' . $new_filename;
            } else {
                 throw new Exception("Failed to move uploaded file. Check permissions.");
             }
        }

        // --- Database Insertion ---
         $sql = "INSERT INTO candidates (name, position_id, description, photo) VALUES (:name, :position_id, :description, :photo)";
         $stmt = $conn->prepare($sql);
         $stmt->bindParam(':name', $name);
         $stmt->bindParam(':position_id', $position_id, PDO::PARAM_INT);
         $stmt->bindParam(':description', $description);
         $stmt->bindParam(':photo', $photo_path_db); // Bind the potentially null path

         if ($stmt->execute()) {
             $response = ['success' => true, 'message' => 'Candidate added successfully!'];
         } else {
             throw new Exception("Database error while adding candidate.");
         }

    } catch (PDOException $e) {
         error_log("Add Candidate PDO Error: " . $e->getMessage());
         $response['message'] = "Database error processing request.";
    } catch (Exception $e) {
         error_log("Add Candidate Error: " . $e->getMessage());
         $response['message'] = $e->getMessage(); // Use specific error message
    }

    echo json_encode($response);
    exit; // Important to stop script after JSON response
}

// Handle Bulk Delete (Example if POSTing here)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'delete_bulk') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'No candidates selected or invalid request.'];

    if (!empty($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
        $ids_to_delete = array_map('intval', $_POST['selected_ids']); // Sanitize to integers
        $placeholders = implode(',', array_fill(0, count($ids_to_delete), '?'));

        if (!empty($placeholders)) {
            try {
                // Optional: Fetch photos to delete files first (more robust)
                 $sql_photos = "SELECT photo FROM candidates WHERE id IN ($placeholders)";
                 $stmt_photos = $conn->prepare($sql_photos);
                 $stmt_photos->execute($ids_to_delete);
                 $photos_to_delete = $stmt_photos->fetchAll(PDO::FETCH_COLUMN);

                 // Delete from database
                $delete_sql = "DELETE FROM candidates WHERE id IN ($placeholders)";
                $stmt_delete = $conn->prepare($delete_sql);

                if ($stmt_delete->execute($ids_to_delete)) {
                    // Attempt to delete photo files
                    foreach ($photos_to_delete as $photo_file) {
                        if (!empty($photo_file)) {
                             $file_path_absolute = realpath(__DIR__ . '/../' . ltrim($photo_file, '/')); // Correct path from admin folder
                            if ($file_path_absolute && file_exists($file_path_absolute)) {
                                if (!unlink($file_path_absolute)) {
                                     error_log("Failed to delete candidate photo file: " . $file_path_absolute);
                                }
                            }
                        }
                    }
                    $response = ['success' => true, 'message' => 'Selected candidates deleted successfully!'];
        } else {
                    throw new Exception("Database error during bulk delete.");
                }
            } catch (PDOException $e) {
                 error_log("Bulk Delete PDO Error: " . $e->getMessage());
                 $response['message'] = "Database error during deletion.";
            } catch (Exception $e) {
                 error_log("Bulk Delete Error: " . $e->getMessage());
                 $response['message'] = $e->getMessage();
            }
        }
    }
    echo json_encode($response);
    exit;
}


// --- Include Header ---
require_once "includes/header.php";
?>

<style nonce="<?php echo $nonce; ?>">
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
    .filter-bar label { font-weight: 600; font-size: 0.9rem; color: var(--primary-dark); }
    .filter-bar .form-select { max-width: 280px; font-size: 0.9rem; }

    /* Candidates Table Card */
    .candidates-card { border: none; border-radius: var(--border-radius-lg); box-shadow: var(--shadow); }
    .candidates-card .card-header { background-color: var(--white-color); border-bottom: 1px solid var(--border-color); font-weight: 700; color: var(--primary-dark); padding: 1rem 1.25rem; border-top-left-radius: var(--border-radius-lg); border-top-right-radius: var(--border-radius-lg); }
    .candidates-table { margin-bottom: 0; }
    .candidates-table thead th { background-color: var(--gray-100); border-bottom: 1px solid var(--gray-300); border-top: none; font-size: .8rem; font-weight: 700; text-transform: uppercase; color: var(--gray-600); padding: .75rem 1rem; white-space: nowrap; letter-spacing: .5px;}
    .candidates-table tbody td { padding: .7rem 1rem; vertical-align: middle; border-top: 1px solid var(--border-color); font-size: 0.9rem; }
    .candidates-table tbody tr:first-child td { border-top: none; }
    .candidates-table tbody tr:hover { background-color: var(--primary-light); }
    .candidates-table .candidate-name { font-weight: 600; color: var(--dark-color); font-size: 0.95rem;}
    .candidates-table .position-details { font-size: 0.85rem; color: var(--secondary-color); display: block; }
    .candidates-table .candidate-photo { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-color); }
    .candidates-table .action-buttons .btn { padding: 0.25rem 0.5rem; font-size: 0.8rem; margin-left: 0.25rem; box-shadow: var(--shadow-sm); }
    .candidates-table .action-buttons .btn:hover { transform: scale(1.1); }
    .candidates-table .form-check-input { cursor: pointer; }
    .candidates-table .no-candidates td { text-align: center; padding: 2.5rem; }
    .candidates-table .no-candidates i { font-size: 2.5rem; margin-bottom: .75rem; display: block; color: var(--gray-400); }
    .candidates-table .no-candidates p { color: var(--gray-500); font-size: 1rem;}

    /* Table Header Actions */
    .table-actions { padding: .5rem 1rem; background-color: var(--gray-100); border-top: 1px solid var(--border-color); }
    .table-actions .btn { font-size: .8rem; }

    /* Modals */
    .modal-header { background-color: var(--primary-light); border-bottom: 1px solid var(--border-color); }
    .modal-title { font-family: var(--font-family-primary); color: var(--primary-dark); }
    .modal-footer { background-color: var(--gray-100); border-top: 1px solid var(--border-color); }
    .was-validated .form-control:invalid, .form-control.is-invalid { border-color: var(--danger-color); background-image: none;}
    .was-validated .form-control:invalid:focus, .form-control.is-invalid:focus { box-shadow: 0 0 0 .25rem rgba(231, 74, 59, .25); }
    #deleteConfirmModal .modal-header { background-color: var(--danger-color); color: white; }
    #deleteConfirmModal .btn-close { filter: brightness(0) invert(1);}
    #photoPreview { max-width: 150px; max-height: 150px; margin-top: .5rem; border-radius: var(--border-radius); border: 1px solid var(--border-color); display: none; } /* Image preview */

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
                        <div class="stat-label text-primary">Total Candidates</div>
                        <div class="stat-value" id="stat-total-candidates">0</div>
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
                        <div class="stat-value" id="stat-total-positions">0</div>
                        </div>
                     <div class="stat-detail">With active candidates</div>
                    <i class="bi bi-tag-fill stats-card-icon"></i>
                        </div>
                    </div>
                </div>
        <div class="col-lg-4 col-md-6 mb-3">
             <div class="stats-card border-left-warning h-100">
                <div class="card-body">
                    <div>
                        <div class="stat-label text-warning">Total Votes Recorded</div>
                        <div class="stat-value" id="stat-total-votes">0</div>
                        </div>
                     <div class="stat-detail">For all candidates</div>
                     <i class="bi bi-check2-square stats-card-icon"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="filter-bar mb-4">
        <div class="row g-3 align-items-center">
            <div class="col-md-auto">
                <label for="electionFilter" class="col-form-label">Filter:</label>
            </div>
            <div class="col-md-4">
                <select class="form-select form-select-sm" id="electionFilter">
                    <option value="all" <?php echo $selected_election === 'all' ? 'selected' : ''; ?>>All Elections</option>
                    <?php foreach ($elections as $election): ?>
                        <option value="<?php echo $election['id']; ?>" <?php echo $selected_election == $election['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($election['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                 <select class="form-select form-select-sm" id="positionFilter" <?php echo empty($positions_for_filter) ? 'disabled' : ''; ?>>
                    <option value="all" <?php echo $selected_position === 'all' ? 'selected' : ''; ?>>All Positions</option>
                    <?php foreach ($positions_for_filter as $position): ?>
                         {/* Filtered by JS or pre-filtered by PHP */}
                        <option value="<?php echo $position['id']; ?>" 
                                data-election-id="<?php echo $position['election_id']; ?>"
                                <?php echo $selected_position == $position['id'] ? 'selected' : ''; ?>
                                <?php // Hide if election filter is set and doesn't match
                                if ($selected_election !== 'all' && $selected_election && $position['election_id'] != $selected_election) { echo ' style="display:none;"'; } ?>
                                >
                            <?php echo htmlspecialchars($position['title']); ?>
                             (<?php echo htmlspecialchars($position['election_title'] ?? 'N/A'); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-auto ms-md-auto"> 
                 <button type="button" class="btn btn-sm btn-outline-danger" id="deleteSelectedBtn" style="display: none;">
                      <i class="bi bi-trash-fill me-1"></i> Delete Selected
                 </button>
            </div>
        </div>
    </div>

    <div class="card candidates-card border-0 shadow mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 text-primary font-weight-bold"><i class="bi bi-person-lines-fill me-2"></i>Candidates List</h6>
        </div>
        <div class="card-body p-0">
             <form id="candidatesTableForm"> 
                <div class="table-responsive">
                    <table class="table table-hover align-middle candidates-table">
                        <thead>
                            <tr>
                                <th scope="col" class="text-center" style="width: 1%;">
                                    <input class="form-check-input" type="checkbox" id="selectAllCheckbox" title="Select All">
                                </th>
                                <th scope="col" style="width: 1%;">Photo</th>
                                <th scope="col">Name</th>
                                <th scope="col">Position</th>
                                <th scope="col">Election</th>
                                <th scope="col" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($candidates)): ?>
                                <tr class="no-candidates">
                                    <td colspan="6">
                                        <i class="bi bi-person-x"></i>
                                        <p class="mb-0 text-muted">
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
                                                 $photo_path = 'assets/images/default-avatar.png';
                                                 if (!empty($candidate['photo'])) {
                                                     $relative_path = "../" . ltrim($candidate['photo'], '/');
                                                     if (strpos($relative_path, '../uploads/candidates/') === 0 && file_exists($relative_path)) { $photo_path = htmlspecialchars($relative_path); }
                                                 }
                                             ?>
                                            <img src="<?php echo $photo_path; ?>" class="candidate-photo" alt="" onerror="this.src='assets/images/default-avatar.png'; this.onerror=null;">
                                    </td>
                                        <td>
                                            <span class="candidate-name"><?php echo htmlspecialchars($candidate['name']); ?></span>
                                            <small class="position-details d-block text-muted"><?php echo htmlspecialchars($candidate['description'] ?? ''); ?></small>
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
            
            <form id="addCandidateForm" method="POST" action="candidates.php" enctype="multipart/form-data" novalidate>
                <div class="modal-body p-4">
                    <div id="addModalAlertPlaceholder"></div>
                    <input type="hidden" name="action" value="create"> 
                    <div class="row">
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
                                            <?php // Pre-select if filtered
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
                                <label for="add_description" class="form-label">Short Description/Slogan (Optional)</label>
                                <textarea class="form-control" id="add_description" name="description" rows="2"></textarea>
                    </div>
                        </div>
                         <div class="col-md-4 text-center">
                            <label for="add_photo" class="form-label">Photo</label>
                             <img id="add_photoPreview" src="assets/images/default-avatar.png" alt="Photo Preview" class="img-thumbnail mb-2" style="height: 150px; width: 150px; object-fit: cover;">
                            <input type="file" class="form-control form-control-sm" id="add_photo" name="photo" accept="image/jpeg, image/png, image/gif, image/webp">
                            <small class="form-text text-muted d-block mt-1">Max 5MB. JPG, PNG, GIF, WEBP.</small>
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
            
            <form id="editCandidateForm" method="POST" action="edit_candidate.php" enctype="multipart/form-data" novalidate>
                    <input type="hidden" id="edit_candidate_id" name="candidate_id">
                <div class="modal-body p-4">
                     <div id="editModalAlertPlaceholder"></div>
                    <div class="row">
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
                        </select>
                                <div class="invalid-feedback">Please select a position.</div>
                    </div>
                    <div class="mb-3">
                                <label for="edit_description" class="form-label">Description (Optional)</label>
                                <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                        </div>
                        <div class="col-md-4 text-center">
                             <label class="form-label d-block">Current Photo</label>
                             <img id="edit_photoPreview" src="assets/images/default-avatar.png" alt="Current Photo" class="img-thumbnail mb-2" style="height: 150px; width: 150px; object-fit: cover;">
                             <label for="edit_photo" class="form-label">Change Photo (Optional)</label>
                            <input type="file" class="form-control form-control-sm" id="edit_photo" name="photo" accept="image/jpeg, image/png, image/gif, image/webp">
                             <small class="form-text text-muted d-block mt-1">Leave empty to keep current photo.</small>
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
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white">
                 <h5 class="modal-title" id="deleteSingleConfirmModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Deletion</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center py-4">
                <p class="lead mb-2">Delete candidate "<strong id="candidateToDeleteName"></strong>"?</p>
                <p class="text-danger"><small>This action cannot be undone.</small></p>
                <input type="hidden" id="deleteCandidateIdSingle">
            </div>
             <div class="modal-footer justify-content-center border-0">
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
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white">
                 <h5 class="modal-title" id="deleteBulkConfirmModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Bulk Deletion</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center py-4">
                <p class="lead mb-2">Are you sure you want to delete the <strong id="bulkDeleteCount">0</strong> selected candidate(s)?</p>
                <p class="text-danger"><small>This action cannot be undone.</small></p>
            </div>
             <div class="modal-footer justify-content-center border-0">
                 <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                 <button type="button" class="btn btn-danger px-4" id="confirmDeleteBulkBtn">
                      <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
                      <i class="bi bi-trash3-fill me-1"></i>Delete Selected
                 </button>
            </div>
        </div>
    </div>
</div>


<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1090">
    <div id="notificationToast" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="3500"> {/* Increased delay */}
        <div class="d-flex notification-content">
            <div class="toast-body d-flex align-items-center">
                <span class="notification-icon me-2 fs-4"></span>
                <span id="notificationMessage" class="fw-medium"></span>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>


<?php require_once "includes/footer.php"; ?>

<script nonce="<?php echo $nonce; ?>" src="https://unpkg.com/countup.js@2.8.0/dist/countUp.umd.js"></script>

<script nonce="<?php echo $nonce; ?>">
document.addEventListener('DOMContentLoaded', function () {
    // --- Initialize Bootstrap Components ---
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (el) { return new bootstrap.Tooltip(el) });

    const newCandidateModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('newCandidateModal'));
    const editCandidateModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('editCandidateModal'));
    const deleteSingleModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteSingleConfirmModal'));
    const deleteBulkModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteBulkConfirmModal'));
    const notificationToastEl = document.getElementById('notificationToast');
    const notificationToast = notificationToastEl ? bootstrap.Toast.getOrCreateInstance(notificationToastEl) : null;

    // --- DOM References ---
    const addForm = document.getElementById('addCandidateForm');
    const editForm = document.getElementById('editCandidateForm');
    const tableForm = document.getElementById('candidatesTableForm');
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    const candidateCheckboxes = document.querySelectorAll('.candidate-checkbox');
    const deleteSelectedBtn = document.getElementById('deleteSelectedBtnBulk'); // Renamed button ID
    const selectedCountSpan = document.getElementById('selectedCount');
    const electionFilter = document.getElementById('electionFilter');
    const positionFilter = document.getElementById('positionFilter');
    const addModalAlertPlaceholder = document.getElementById('addModalAlertPlaceholder');
    const editModalAlertPlaceholder = document.getElementById('editModalAlertPlaceholder');
    const addPhotoInput = document.getElementById('add_photo');
    const addPhotoPreview = document.getElementById('add_photoPreview');
    const editPhotoInput = document.getElementById('edit_photo');
    const editPhotoPreview = document.getElementById('edit_photoPreview');


    // --- CountUp Animations ---
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
    } else { console.warn("CountUp library not available."); }


    // --- Helper: Show Notification Toast ---
    function showNotification(message, type = 'success') {
        const msgEl = document.getElementById('notificationMessage');
        const iconEl = notificationToastEl?.querySelector('.notification-icon');
        if (!notificationToast || !msgEl || !iconEl) return;
        notificationToastEl.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-info', 'text-dark');
        iconEl.innerHTML = ''; message = String(message || 'Action completed.').substring(0, 200); msgEl.textContent = message;
        let iconClass = 'bi-check-circle-fill'; let bgClass = 'bg-success'; let textClass = '';
        if (type === 'danger') { iconClass = 'bi-x-octagon-fill'; bgClass = 'bg-danger'; }
        else if (type === 'warning') { iconClass = 'bi-exclamation-triangle-fill'; bgClass = 'bg-warning'; textClass = 'text-dark'; }
        else if (type === 'info') { iconClass = 'bi-info-circle-fill'; bgClass = 'bg-info'; }
        notificationToastEl.classList.add(bgClass, textClass); iconEl.innerHTML = `<i class="bi ${iconClass}"></i>`; notificationToast.show();
    }

    // --- Helper: Show Modal Alert ---
     function showModalAlert(placeholder, message, type = 'danger') {
          if(placeholder) {
               const alertClass = `alert-${type}`; const iconClass = type === 'danger' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill';
               placeholder.innerHTML = `<div class="alert ${alertClass} alert-dismissible fade show d-flex align-items-center" role="alert" style="font-size: 0.9rem; padding: 0.75rem 1rem;"><i class="bi ${iconClass} me-2"></i><div>${message}</div><button type="button" class="btn-close" style="padding: 0.75rem;" data-bs-dismiss="alert" aria-label="Close"></button></div>`;
          }
     }

     // --- Helper: Reset Button State ---
     function resetButton(button, originalHTML) {
         if(button) {
             button.disabled = false;
             button.innerHTML = originalHTML;
         }
     }
     // --- Helper: Set Button Loading State ---
      function setButtonLoading(button, loadingText = 'Processing...') {
         if (button) {
             button.disabled = true;
             const icon = button.querySelector('i');
             const spinner = button.querySelector('.spinner-border');
             if (icon) icon.classList.add('d-none'); // Hide icon
             if (spinner) spinner.classList.remove('d-none'); // Show spinner
             // Find text node to update, avoid replacing spinner/icon
              const textNode = Array.from(button.childNodes).find(node => node.nodeType === Node.TEXT_NODE && node.textContent.trim().length > 0);
              if (textNode) textNode.textContent = ` ${loadingText}`;
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
            // Update position filter options based on selected election
            if (positionFilter) {
                 let currentPositionVal = positionFilter.value; // Remember current selection
                 let foundCurrent = false;
                 Array.from(positionFilter.options).forEach(option => {
                     if (option.value === '') return; // Skip placeholder
                     const electionMatch = selectedElection === 'all' || option.dataset.electionId == selectedElection;
                     option.style.display = electionMatch ? '' : 'none';
                     if (electionMatch && option.value == currentPositionVal) {
                         foundCurrent = true; // Current selection is still valid
                     }
                 });
                 // If previous selection is no longer valid in the filtered list, reset to "All Positions"
                 if (!foundCurrent) {
                     positionFilter.value = 'all';
                 }
            }
            applyFilters(); // Apply both filters together
        });
    }
    if(positionFilter) {
        positionFilter.addEventListener('change', applyFilters);
    }


    // --- Photo Preview Logic ---
    function setupPhotoPreview(inputEl, previewEl) {
        if(inputEl && previewEl) {
            inputEl.addEventListener('change', function(event) {
                const file = event.target.files[0];
                if (file && file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        previewEl.src = e.target.result;
                        previewEl.style.display = 'block';
                    }
                    reader.readAsDataURL(file);
        } else {
                     previewEl.src = 'assets/images/default-avatar.png'; // Reset or keep old? Resetting might be better.
                     previewEl.style.display = 'block'; // Show default even if invalid file selected
                }
            });
        }
    }
    setupPhotoPreview(addPhotoInput, add_photoPreview);
    setupPhotoPreview(editPhotoInput, edit_photoPreview);

    // --- Add Candidate Form Handling ---
    const addCandidateSubmitBtn = document.getElementById('addCandidateSubmitBtn');
    if (addForm && addCandidateSubmitBtn) {
        addForm.addEventListener('submit', function(e) {
    e.preventDefault();
            addModalAlertPlaceholder.innerHTML = ''; addForm.classList.remove('was-validated');
            if (!addForm.checkValidity()) { e.stopPropagation(); addForm.classList.add('was-validated'); showModalAlert(addModalAlertPlaceholder, 'Please fill required fields.', 'warning'); return; }

            const originalBtnHTML = addCandidateSubmitBtn.innerHTML;
            setButtonLoading(addCandidateSubmitBtn, 'Adding...');
            const formData = new FormData(addForm); // Automatically includes file

            fetch('candidates.php', { method: 'POST', body: formData, headers: {'Accept': 'application/json'}}) // POST to self, relies on action='create' check
                .then(response => response.json())
    .then(data => {
        if (data.success) {
                        showNotification(data.message || 'Candidate added!', 'success');
                        if(newCandidateModal) newCandidateModal.hide();
                        setTimeout(() => window.location.reload(), 1500);
                    } else { throw new Error(data.message || 'Failed to add candidate.'); }
                })
                .catch(error => { showModalAlert(addModalAlertPlaceholder, error.message || 'An error occurred.', 'danger'); })
                .finally(() => { resetButton(addCandidateSubmitBtn, originalBtnHTML); });
        });
    }

    // --- Edit Candidate Handling ---
    const editCandidateSubmitBtn = document.getElementById('editCandidateSubmitBtn');
    const editPositionIdSelect = document.getElementById('edit_position_id');
    document.querySelectorAll('.edit-candidate-btn').forEach(button => {
        button.addEventListener('click', function() {
            const candidateId = this.getAttribute('data-candidate-id');
            if (!candidateId) return;
            editModalAlertPlaceholder.innerHTML = ''; editForm?.classList.remove('was-validated');

            // Fetch existing data - **Requires get_candidate.php script**
            fetch(`get_candidate.php?id=${candidateId}`, { headers: {'Accept': 'application/json'}})
                .then(response => response.ok ? response.json() : Promise.reject(`Error ${response.status}`))
                .then(data => {
                    if (data.success && data.candidate) {
                        const cand = data.candidate;
                        document.getElementById('edit_candidate_id').value = cand.id;
                        document.getElementById('edit_name').value = cand.name;
                        document.getElementById('edit_position_id').value = cand.position_id;
                        document.getElementById('edit_description').value = cand.description || '';
                        // Update photo preview
                        const preview = document.getElementById('edit_photoPreview');
                        if(preview) {
                             preview.src = cand.photo_url || 'assets/images/default-avatar.png'; // Assuming backend provides full URL or default
                             preview.style.display = 'block';
                        }
                        if(editCandidateModal) editCandidateModal.show();
                    } else { throw new Error(data.message || 'Could not load candidate data.'); }
                })
                .catch(error => { console.error('Fetch Edit Error:', error); showNotification('Error loading candidate details.', 'danger'); });
    });
});

    // Edit Form Submit - **Requires edit_candidate.php script**
    if(editForm && editCandidateSubmitBtn) {
        editForm.addEventListener('submit', function(e) {
             e.preventDefault();
            editModalAlertPlaceholder.innerHTML = ''; editForm.classList.remove('was-validated');
             if (!editForm.checkValidity()) { e.stopPropagation(); editForm.classList.add('was-validated'); showModalAlert(editModalAlertPlaceholder, 'Please fill required fields.', 'warning'); return; }

             const originalBtnHTML = editCandidateSubmitBtn.innerHTML;
             setButtonLoading(editCandidateSubmitBtn, 'Saving...');
             const formData = new FormData(editForm); // Includes file if selected

             fetch('edit_candidate.php', { method: 'POST', body: formData, headers: {'Accept': 'application/json'}})
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                        showNotification(data.message || 'Candidate updated!', 'success');
                        if(editCandidateModal) editCandidateModal.hide();
                        setTimeout(() => window.location.reload(), 1500);
                    } else { throw new Error(data.message || 'Failed to update candidate.'); }
                })
                .catch(error => { showModalAlert(editModalAlertPlaceholder, error.message || 'An error occurred.', 'danger'); })
                .finally(() => { resetButton(editCandidateSubmitBtn, originalBtnHTML); });
        });
    }

     // --- Delete Candidate Handling (Single) ---
     const deleteCandidateIdSingleInput = document.getElementById('deleteCandidateIdSingle');
     const candidateToDeleteNameEl = document.getElementById('candidateToDeleteName');
     const confirmDeleteSingleBtn = document.getElementById('confirmDeleteSingleBtn');
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

             const originalBtnText = this.innerHTML;
             setButtonLoading(this, 'Deleting...');

             // **Requires delete_candidate.php script**
             fetch(`delete_candidate.php?id=${candidateId}`, { headers: {'Accept': 'application/json'}})
    .then(response => response.json())
    .then(data => {
        if (data.success) {
                        showNotification(data.message || 'Candidate deleted!', 'success');
                        if(deleteSingleModal) deleteSingleModal.hide();
                         const rowToRemove = document.getElementById(`candidate-row-${candidateId}`);
                         if(rowToRemove) { rowToRemove.style.opacity = '0'; setTimeout(() => rowToRemove.remove(), 300); }
                         else { setTimeout(() => window.location.reload(), 1200); } // Fallback reload
                         updateSelectedCount(); // Update count display
                    } else { throw new Error(data.message || 'Failed to delete candidate.'); }
                })
                .catch(error => { showNotification(error.message || 'Error deleting candidate.', 'danger'); if(deleteSingleModal) deleteSingleModal.hide(); })
                .finally(() => { resetButton(confirmDeleteSingleBtn, originalBtnText); }); // Reset button
         });
     }

     // --- Bulk Delete Handling ---
     const deleteBulkConfirmModalEl = document.getElementById('deleteBulkConfirmModal');
     const confirmDeleteBulkBtn = document.getElementById('confirmDeleteBulkBtn');
     const bulkDeleteCountEl = document.getElementById('bulkDeleteCount');

     function updateSelectedCount() {
         const selectedCheckboxes = document.querySelectorAll('.candidate-checkbox:checked');
         const count = selectedCheckboxes.length;
         if(selectedCountSpan) selectedCountSpan.textContent = count;
         if(deleteSelectedBtn) deleteSelectedBtn.style.display = count > 0 ? 'inline-block' : 'none'; // Show/hide bulk delete button
         if(confirmDeleteBulkBtn) confirmDeleteBulkBtn.disabled = count === 0; // Enable/disable modal confirm button
         if(bulkDeleteCountEl) bulkDeleteCountEl.textContent = count;
         // Update select all checkbox state
         if(selectAllCheckbox) selectAllCheckbox.checked = count > 0 && count === candidateCheckboxes.length;
     }

     if(selectAllCheckbox) {
         selectAllCheckbox.addEventListener('change', function() {
             candidateCheckboxes.forEach(checkbox => checkbox.checked = this.checked);
             updateSelectedCount();
         });
     }

     candidateCheckboxes.forEach(checkbox => {
         checkbox.addEventListener('change', updateSelectedCount);
     });

     // Trigger bulk delete modal
     if(deleteSelectedBtn) {
         deleteSelectedBtn.addEventListener('click', function() {
             if (document.querySelectorAll('.candidate-checkbox:checked').length > 0) {
                 if(deleteBulkModal) deleteBulkModal.show();
             } else {
                 showNotification("Please select at least one candidate to delete.", "warning");
             }
         });
     }

     // Confirm bulk delete
     if(confirmDeleteBulkBtn) {
         confirmDeleteBulkBtn.addEventListener('click', function() {
             const selectedCheckboxes = document.querySelectorAll('.candidate-checkbox:checked');
             const selectedIds = Array.from(selectedCheckboxes).map(cb => cb.value);
             if (selectedIds.length === 0) return;

             const originalBtnText = this.innerHTML;
             setButtonLoading(this, 'Deleting...');

             const formData = new FormData();
             formData.append('action', 'delete_bulk');
             selectedIds.forEach(id => formData.append('selected_ids[]', id));

             // POST to self (candidates.php) to handle bulk delete action
             fetch('candidates.php', { method: 'POST', body: formData, headers: {'Accept': 'application/json'} })
                 .then(response => response.json())
    .then(data => {
        if (data.success) {
                         showNotification(data.message || 'Selected candidates deleted!', 'success');
                         if(deleteBulkModal) deleteBulkModal.hide();
                         // Remove rows visually
                         selectedIds.forEach(id => {
                              const row = document.getElementById(`candidate-row-${id}`);
                              if(row) { row.style.opacity = '0'; setTimeout(() => row.remove(), 300); }
                         });
                         updateSelectedCount(); // Reset count and button state
        } else {
                          throw new Error(data.message || 'Failed to delete candidates.');
        }
    })
    .catch(error => {
                      showNotification(error.message || 'Error during bulk delete.', 'danger');
                      if(deleteBulkModal) deleteBulkModal.hide();
                 })
                 .finally(() => { resetButton(confirmDeleteBulkBtn, originalBtnText); });
         });
     }

    // --- Reset Modals on Close ---
     [newCandidateModalEl, editCandidateModalEl, deleteSingleModalEl, deleteBulkModalEl].forEach(modalEl => {
        if(modalEl) {
            modalEl.addEventListener('hidden.bs.modal', function () {
                const form = this.querySelector('form');
                if (form) { form.classList.remove('was-validated'); form.reset(); }
                 const alertPlaceholder = this.querySelector('#addModalAlertPlaceholder') || this.querySelector('#editModalAlertPlaceholder');
                 if (alertPlaceholder) alertPlaceholder.innerHTML = '';
                 // Reset specific buttons
                 const addBtn = this.querySelector('#addCandidateSubmitBtn'); if(addBtn) resetButton(addBtn, '<i class="bi bi-plus-lg"></i> Add Candidate');
                 const editBtn = this.querySelector('#editCandidateSubmitBtn'); if(editBtn) resetButton(editBtn, '<i class="bi bi-save-fill me-1"></i>Save Changes');
                 const confirmSingleBtn = this.querySelector('#confirmDeleteSingleBtn'); if(confirmSingleBtn) resetButton(confirmSingleBtn, '<i class="bi bi-trash3-fill me-1"></i>Delete');
                 const confirmBulkBtn = this.querySelector('#confirmDeleteBulkBtn'); if(confirmBulkBtn) resetButton(confirmBulkBtn, '<i class="bi bi-trash3-fill me-1"></i>Delete Selected');
                 // Reset image previews
                 [add_photoPreview, edit_photoPreview].forEach(preview => { if(preview) preview.src = 'assets/images/default-avatar.png'; });
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