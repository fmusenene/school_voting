<?php
session_start();
require_once "../config/database.php";
// NOTE: Includes header.php *after* data fetching and PHP logic
// require_once "includes/header.php"; // Moved down

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    // Redirect before any output
    header("Location: /school_voting/admin/login.php");
    exit();
}

// Function to generate unique voting codes
function generateVotingCode()
{
    global $conn;
    $attempts = 0;
    $max_attempts = 100; // Prevent infinite loop

    do {
        // Generate a random 6-digit number
        $code = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);

        // Check if code already exists
        $check_sql = "SELECT code FROM voting_codes WHERE code = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param('s', $code);
        $check_stmt->execute();
        $check_stmt->store_result();
        $exists = $check_stmt->num_rows > 0;
        $check_stmt->close();

        $attempts++;
    } while ($exists && $attempts < $max_attempts);

    if ($attempts >= $max_attempts) {
        // Log error instead of throwing exception that might break page load
        error_log("Unable to generate unique code after $max_attempts attempts");
        return false; // Indicate failure
    }

    return $code;
}

$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']); // Clear flash messages

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['delete_codes'])) {
        if (isset($_POST['selected_codes']) && is_array($_POST['selected_codes'])) {
            $selected_codes = array_map('intval', $_POST['selected_codes']);

            if (empty($selected_codes)) {
                $error = "No codes selected for deletion.";
            } else {
                // Check if any of the selected codes have been used
                $placeholders_check = implode(',', array_fill(0, count($selected_codes), '?'));
                $check_used_sql = "SELECT COUNT(*) FROM voting_codes vc
                                   WHERE vc.id IN ($placeholders_check) AND vc.is_used = 1";
                $check_stmt = $conn->prepare($check_used_sql);
                // Dynamically bind params
                $types = str_repeat('i', count($selected_codes));
                $check_stmt->bind_param($types, ...$selected_codes);
                $check_stmt->execute();
                $result = $check_stmt->get_result();
                $used_count = $result->fetch_row()[0];
                $check_stmt->close();

                if ($used_count > 0) {
                    $error = "Cannot delete used voting codes. Please unselect used codes and try again.";
                } else {
                    $placeholders_delete = implode(',', array_fill(0, count($selected_codes), '?'));
                    // Delete the selected unused codes
                    $delete_sql = "DELETE FROM voting_codes WHERE id IN ($placeholders_delete)";
                    $delete_stmt = $conn->prepare($delete_sql);
                    // Dynamically bind params
                    $types_delete = str_repeat('i', count($selected_codes));
                    $delete_stmt->bind_param($types_delete, ...$selected_codes);
                    
                    if ($delete_stmt->execute()) {
                        $_SESSION['success'] = count($selected_codes) . " selected voting code(s) deleted successfully!";
                    } else {
                        error_log("Error deleting voting codes: " . $delete_stmt->error);
                        $_SESSION['error'] = "Error deleting voting codes. Please check logs.";
                    }
                    $delete_stmt->close();
                    // Redirect to prevent form resubmission
                    header("Location: " . $_SERVER['PHP_SELF'] . "?" . http_build_query($_GET));
                    exit();
                }
            }
        } else {
            $error = "No codes selected for deletion.";
        }
    } else if (isset($_POST['action']) && $_POST['action'] === 'create') {
        $election_id = filter_input(INPUT_POST, 'election_id', FILTER_VALIDATE_INT);
        $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 1000]]); // Increased max slightly

        if (!$election_id) {
            $error = "Please select a valid election.";
        } elseif (!$quantity) {
            $error = "Please enter a valid quantity (1-1000).";
        } else {
            try {
                $conn->begin_transaction();

                // Generate unique voting codes
                $insert_sql = "INSERT INTO voting_codes (code, election_id, is_used) VALUES (?, ?, 0)"; // Explicitly set is_used
                $insert_stmt = $conn->prepare($insert_sql);

                $generated_codes = [];
                $success_count = 0;
                $failed_count = 0;

                for ($i = 0; $i < $quantity; $i++) {
                    $code = generateVotingCode();
                    if ($code === false) { // Check for generation failure
                        $failed_count++;
                        continue; // Skip this iteration
                    }
                    try {
                        // Ensure code is unique before attempting insert (though generateVotingCode should handle this)
                        $check_unique_sql = "SELECT code FROM voting_codes WHERE code = ?";
                        $check_unique_stmt = $conn->prepare($check_unique_sql);
                        $check_unique_stmt->bind_param('s', $code);
                        $check_unique_stmt->execute();
                        $check_unique_stmt->store_result();
                        
                        if ($check_unique_stmt->num_rows == 0) {
                            $check_unique_stmt->close();
                            $insert_stmt->bind_param('si', $code, $election_id);
                            if ($insert_stmt->execute()) {
                                $generated_codes[] = $code;
                                $success_count++;
                            } else {
                                error_log("Failed to insert code: " . $code . " Error: " . $insert_stmt->error);
                                $failed_count++;
                            }
                        } else {
                            $check_unique_stmt->close();
                            error_log("Generated code collision (should be rare): " . $code);
                            $failed_count++; // Treat collision as failure for this attempt
                            $i--; // Retry generating a code for this iteration
                        }

                    } catch (mysqli_sql_exception $e) {
                        // Catch potential unique constraint violation during insert (fallback)
                        error_log("MySQLi Exception during code insert: " . $e->getMessage());
                        $failed_count++;
                        // Optionally retry: $i--;
                    }
                }
                $insert_stmt->close();

                $conn->commit();

                if ($success_count > 0) {
                    $_SESSION['success'] = "$success_count unique voting codes generated successfully!";
                    if ($failed_count > 0) {
                        $_SESSION['warning'] = "Could not generate $failed_count codes (possibly due to generation limits or collisions).";
                    }
                } else {
                    $_SESSION['error'] = "Failed to generate any unique codes. Please try again.";
                }
                // Redirect to prevent form resubmission
                header("Location: " . $_SERVER['PHP_SELF'] . "?" . http_build_query($_GET));
                exit();

            } catch (Exception $e) {
                $conn->rollback();
                error_log("Error generating voting codes: " . $e->getMessage());
                $error = "Error generating voting codes. Please check logs.";
            }
        }
    }
}

// --- Data Fetching & View Setup ---
$fetch_error = null;
$elections = [];
$voting_codes = [];
$stats = ['total_codes' => 0, 'used_codes' => 0, 'unused_codes' => 0];
$total_records = 0;
$total_pages = 0;
$start_record = 0;
$end_record = 0;

// Get view mode (grid or list), default to grid. Store in session for persistence.
if (isset($_GET['view'])) {
    $_SESSION['voting_codes_view'] = in_array($_GET['view'], ['grid', 'list']) ? $_GET['view'] : 'grid';
}
$view = $_SESSION['voting_codes_view'] ?? 'grid'; // Default to grid view


try {
    // Get all elections for the dropdown
    $elections_sql = "SELECT id, title FROM elections ORDER BY title ASC";
    $elections_result = $conn->query($elections_sql);
    $elections = $elections_result ? $elections_result->fetch_all(MYSQLI_ASSOC) : [];

    // Get selected election ID from URL or default to null (All)
    $selected_election_id = filter_input(INPUT_GET, 'election_id', FILTER_VALIDATE_INT);
    if ($selected_election_id === false || $selected_election_id === 0) { // Treat invalid/0 ID as 'All'
        $selected_election_id = null;
    }

    // Get search term
    $search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

    // Pagination settings
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $items_per_page = isset($_GET['items_per_page']) ? max(5, (int)$_GET['items_per_page']) : 30; // Default 30 for grid view
    $offset = ($page - 1) * $items_per_page;

    // Base SQL and params for counting and fetching
    $base_sql_select = "SELECT vc.*, e.title as election_title, e.status as election_status,
                        (SELECT v.created_at FROM votes v WHERE v.voting_code_id = vc.id ORDER BY v.created_at DESC LIMIT 1) as used_at";
    $base_sql_from = " FROM voting_codes vc LEFT JOIN elections e ON vc.election_id = e.id";
    $base_sql_where = " WHERE 1=1";
    $params = [];
    $types = "";

    // Add election filter
    if ($selected_election_id) {
        $base_sql_where .= " AND vc.election_id = ?";
        $params[] = $selected_election_id;
        $types .= "i";
    }

    // Add search filter
    if (!empty($search_term)) {
        $base_sql_where .= " AND vc.code LIKE ?";
        $params[] = "%" . $search_term . "%";
        $types .= "s";
    }

    // Get total records for pagination
    $count_sql = "SELECT COUNT(vc.id)" . $base_sql_from . $base_sql_where;
    $count_stmt = $conn->prepare($count_sql);
    if (!empty($types)) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $count_stmt->bind_result($total_records);
    $count_stmt->fetch();
    $count_stmt->close();
    $total_records = (int)$total_records;

    // Calculate pagination values
    $total_pages = $total_records > 0 ? ceil($total_records / $items_per_page) : 1;
    $page = max(1, min($page, $total_pages)); // Recalculate page based on total pages
    $offset = ($page - 1) * $items_per_page; // Recalculate offset
    $start_record = $total_records > 0 ? $offset + 1 : 0;
    $end_record = min($offset + $items_per_page, $total_records);

    // Get voting codes for the current page
    $voting_codes_sql = $base_sql_select . $base_sql_from . $base_sql_where
                      . " ORDER BY vc.created_at DESC"
                      . " LIMIT ? OFFSET ?";
    
    $page_params = $params;
    $page_types = $types;
    $page_params[] = $items_per_page;
    $page_types .= "i";
    $page_params[] = $offset;
    $page_types .= "i";

    $stmt = $conn->prepare($voting_codes_sql);
    if (!empty($page_types)) {
        $stmt->bind_param($page_types, ...$page_params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $voting_codes = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get statistics (scoped by election if selected)
    $stats_where = " WHERE 1=1";
    $stats_params = [];
    $stats_types = "";
    if ($selected_election_id) { 
        $stats_where .= " AND vc.election_id = ?";
        $stats_params[] = $selected_election_id;
        $stats_types .= "i";
    }
    
    $stats_sql = "SELECT COUNT(vc.id) as total_codes, COUNT(CASE WHEN vc.is_used = 1 THEN vc.id END) as used_codes
                  FROM voting_codes vc" . $stats_where; 
    
    $stats_stmt = $conn->prepare($stats_sql);
    if(!empty($stats_types)) {
        $stats_stmt->bind_param($stats_types, ...$stats_params);
    }
    $stats_stmt->execute();
    $stats_result_set = $stats_stmt->get_result();
    $stats_result = $stats_result_set->fetch_assoc();
    $stats_stmt->close();
    
    $stats['total_codes'] = (int)($stats_result['total_codes'] ?? 0);
    $stats['used_codes'] = (int)($stats_result['used_codes'] ?? 0);
    $stats['unused_codes'] = $stats['total_codes'] - $stats['used_codes'];


} catch (Exception $e) {
    error_log("Voting Codes Page Error: " . $e->getMessage());
    $fetch_error = "Could not load voting codes data due to a database issue.";
    // Reset arrays on error
    $elections = []; $voting_codes = [];
    $stats = ['total_codes' => 0, 'used_codes' => 0, 'unused_codes' => 0];
    $total_records = 0; $total_pages = 0; $start_record = 0; $end_record = 0;
}

// Include Header AFTER all PHP logic and data fetching
require_once "includes/header.php";

// Get nonce from session for inline styles/scripts
$nonce = $_SESSION['csp_nonce'] ?? '';
?>

<style nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
    :root { /* Inherit variables from header */
        --primary-hue: 226; --primary-color: hsl(var(--primary-hue), 76%, 58%); --primary-light: hsl(var(--primary-hue), 76%, 95%); --primary-dark: hsl(var(--primary-hue), 70%, 48%);
        --secondary-color: #858796; --success-color: #1cc88a; --info-color: #36b9cc; --warning-color: #f6c23e; --danger-color: #e74a3b;
        --light-color: #f8f9fc; --white-color: #fff; --dark-color: #5a5c69; --gray-100: #f8f9fc; --gray-200: #eaecf4; --gray-300: #dddfeb; --gray-600: #858796; --border-color: #e3e6f0;
        --font-family-primary: "Poppins", sans-serif; --font-family-secondary: "Nunito", sans-serif;
        --shadow-sm: 0 .125rem .25rem rgba(0,0,0,.075); --shadow: 0 .15rem 1.75rem 0 rgba(58,59,69,.15); --border-radius: .35rem; --border-radius-lg: .5rem;
    }

    /* General page layout */
    .container-fluid { padding: 1.5rem 2rem; }

    /* Page Header */
    .page-header { margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border-color); }
    .page-header h2 { color: var(--primary-dark); font-family: var(--font-family-primary); font-weight: 700; margin-bottom: .25rem;}
    .page-header p { font-size: 0.95rem; color: var(--secondary-color); margin-bottom: 0; }
    .page-header .btn i { vertical-align: -2px; }

    /* Stats Cards */
    .stat-card { transition: all 0.25s ease-out; border: none; border-radius: var(--border-radius); background-color: var(--white-color); box-shadow: var(--shadow-sm); height: 100%; }
    .stat-card .card-body { padding: 1rem 1.25rem; display: flex; align-items: center; gap: 1rem; }
    .stat-card:hover { transform: translateY(-3px) scale(1.01); box-shadow: var(--shadow); }
    .stat-icon { flex-shrink: 0; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; border-radius: 50%; }
    .stat-card .stat-label { font-size: .75rem; font-weight: 700; text-transform: uppercase; margin-bottom: .1rem; color: var(--secondary-color); letter-spacing: .5px;}
    .stat-card .stat-value { font-size: 1.8rem; font-weight: 700; line-height: 1.1; color: var(--dark-color); margin: 0;}

    /* Controls Card */
    .controls-card { border: none; border-radius: var(--border-radius); box-shadow: var(--shadow-sm); }
    .controls-card .card-header { background-color: var(--gray-100); border-bottom: 1px solid var(--border-color); padding: .75rem 1.25rem; }

    /* Data Display card (common for table and grid) */
    .data-display-card { border: none; border-radius: var(--border-radius-lg); box-shadow: var(--shadow); overflow: hidden; }
    .data-display-card .card-header { background-color: var(--white-color); border-bottom: 1px solid var(--border-color); font-weight: 700; color: var(--primary-dark); padding: 1rem 1.25rem; }
    .data-display-card .card-body { padding: 0; }

    /* List View (Table) */
    .voting-codes-table { margin-bottom: 0; }
    .voting-codes-table thead th { background-color: var(--gray-100); border-bottom: 2px solid var(--border-color); border-top: none; font-size: .8rem; font-weight: 700; text-transform: uppercase; color: var(--gray-600); padding: .75rem 1rem; white-space: nowrap;}
    .voting-codes-table tbody td { padding: .8rem 1rem; vertical-align: middle; border-top: 1px solid var(--border-color); font-size: 0.9rem; }
    .voting-codes-table tbody tr:first-child td { border-top: none; }
    .voting-codes-table tbody tr:hover { background-color: var(--primary-light); }
    .voting-codes-table code, .code-card-code { font-family: 'Courier New', Courier, monospace; letter-spacing: 1px; }
    .voting-codes-table .form-check-input { cursor: pointer; }
    .voting-codes-table .action-buttons .btn { padding: 0.2rem 0.5rem; font-size: 0.8rem; }
    .badge { font-size: 0.8rem; padding: .4em .6em; font-weight: 600; }
    
    /* Grid View */
    .codes-grid-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 1rem; padding: 1.25rem; }
    .code-card { border: 1px solid var(--border-color); border-radius: var(--border-radius); padding: 1rem; text-align: center; background-color: var(--white-color); transition: all 0.2s ease-in-out; box-shadow: var(--shadow-sm); position: relative; display: flex; flex-direction: column; justify-content: center; align-items: center; min-height: 100px; }
    .code-card:hover { transform: translateY(-3px); box-shadow: var(--shadow); }
    .code-card-code { font-size: 1.75rem; font-weight: 700; color: var(--primary-dark); display: block; margin-bottom: 0.5rem; }
    .code-card .delete-code-btn { position: absolute; top: 4px; right: 4px; opacity: 0; transition: opacity 0.2s ease; line-height: 1; padding: 0.2rem 0.4rem; }
    .code-card:hover .delete-code-btn { opacity: 0.6; }
    .code-card .delete-code-btn:hover { opacity: 1; }

    /* Common for Empty State */
    .no-codes-placeholder { text-align: center; padding: 3rem 1rem; }
    .no-codes-placeholder i { font-size: 3rem; margin-bottom: .75rem; display: block; color: var(--gray-300); }
    .no-codes-placeholder p { color: var(--secondary-color); font-size: 1.1rem;}

    /* Pagination */
    .pagination-info { color: var(--secondary-color); font-size: 0.85rem; }
    .pagination .page-link { font-size: 0.85rem; }
    .pagination .page-item.disabled .page-link { color: var(--secondary-color); }

    /* Modals */
    .modal-header { background-color: var(--primary-light); border-bottom: 1px solid var(--border-color); }
    .modal-title { font-family: var(--font-family-primary); color: var(--primary-dark); }
    .modal-footer { background-color: var(--gray-100); border-top: 1px solid var(--border-color); }

      /* PDF/Excel specific icons */
      .bi-file-pdf { color: var(--danger-color); }
      .bi-file-excel { color: var(--success-color); }

      /* Print Styles */
      @media print {
        body { background-color: #fff !important; }
        .sidebar, .navbar, .page-header .btn, .controls-card, .table-actions, .action-buttons .btn, .pagination-nav, #sidebarToggle, .data-display-card .card-header .btn-group { display: none !important; }
        .main-content, .main-content.expanded { margin-left: 0 !important; width: 100% !important; padding: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .page-header { border-bottom: none; margin-bottom: 1rem; text-align: center;}
        .page-header h2 { font-size: 16pt; } .page-header p { display: none; }
        .card, .stat-card, .data-display-card { box-shadow: none !important; border: 1px solid #ddd !important; margin-bottom: .5rem; page-break-inside: avoid; }
        .stat-card .card-body { padding: .5rem !important;} .stat-card .stat-label { font-size: 8pt; } .stat-card .stat-value { font-size: 12pt;} .stat-icon { display: none; }
        .pagination-info { display: none; }
        a { text-decoration: none; color: #000; }
        .no-codes i { display: none; }

        /* List Print */
        .table { font-size: 9pt; } .table th, .table td { padding: .4rem !important; } .table code { background: none; padding: 0; font-size: inherit; }
        .table .badge { border: 1px solid #ccc; background-color: #eee !important; color: #000 !important; font-size: 8pt; }
        
        /* Grid Print */
        .codes-grid-container { display: grid !important; grid-template-columns: repeat(6, 1fr) !important; gap: 0.5rem !important; padding: 0 !important; }
        .data-display-card .card-body { padding: 0 !important; }
        .code-card { page-break-inside: avoid; padding: 0.5rem !important; border: 1px solid #666 !important; box-shadow: none !important; }
        .code-card-code { font-size: 11pt !important; margin-bottom: 0.25rem !important; }
        .code-card .badge { font-size: 7pt !important; }
        .code-card .delete-code-btn { display: none !important; }
      }

</style>

<div class="container-fluid">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 page-header">
        <div>
            <h2 class="mb-1">Manage Voting Codes</h2>
            <p>Generate, view, export, and manage voting codes for elections.</p>
        </div>
        <button type="button" class="btn btn-primary btn-sm shadow-sm mt-2 mt-md-0" data-bs-toggle="modal" data-bs-target="#generateCodesModal">
            <i class="bi bi-plus-circle-fill me-1"></i> Generate New Codes
        </button>
    </div>

    <div id="alertPlaceholder">
        <?php if (isset($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
         <?php endif; ?>
         <?php if (isset($_SESSION['warning'])): // Display warning from session ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($_SESSION['warning']); unset($_SESSION['warning']); ?>
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
        <div class="col-xl-4 col-md-6">
            <div class="stat-card">
                <div class="card-body">
                    <div class="stat-icon bg-primary bg-opacity-10">
                        <i class="bi bi-key-fill text-primary fs-4"></i>
                    </div>
                    <div>
                        <div class="stat-label">Total Codes</div>
                        <p class="stat-value"><?php echo number_format($stats['total_codes']); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="stat-card">
                <div class="card-body">
                    <div class="stat-icon bg-success bg-opacity-10">
                        <i class="bi bi-check-circle-fill text-success fs-4"></i>
                    </div>
                    <div>
                        <div class="stat-label">Used Codes</div>
                        <p class="stat-value"><?php echo number_format($stats['used_codes']); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-12"> 
            <div class="stat-card">
                <div class="card-body">
                    <div class="stat-icon bg-info bg-opacity-10">
                        <i class="bi bi-box-fill text-info fs-4"></i>
                    </div>
                    <div>
                        <div class="stat-label">Codes Available</div>
                        <p class="stat-value"><?php echo number_format($stats['unused_codes']); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card controls-card mb-4">
        <div class="card-header">
            <div class="row g-2 align-items-center">
                <div class="col-lg-4 col-md-6">
                    <select class="form-select form-select-sm" id="electionFilter" aria-label="Filter by election">
                        <option value="">Filter by Election (All)</option>
                        <?php foreach ($elections as $election): ?>
                            <option value="<?php echo $election['id']; ?>" <?php echo $selected_election_id == $election['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($election['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-5 col-md-6">
                     <div class="input-group input-group-sm">
                        <span class="input-group-text" id="search-addon"><i class="bi bi-search"></i></span>
                        <input type="search" class="form-control" id="searchInput" placeholder="Search by code..." aria-label="Search codes" aria-describedby="search-addon" value="<?php echo htmlspecialchars($search_term); ?>">
                     </div>
                </div>
                 <div class="col-lg-3 d-flex justify-content-lg-end align-items-center gap-2 mt-2 mt-lg-0 flex-wrap">
                    <div class="btn-group btn-group-sm" role="group" aria-label="View toggle">
                        <a href="<?php echo buildQueryString(['view' => 'grid']); ?>" class="btn <?php echo $view === 'grid' ? 'btn-primary' : 'btn-outline-secondary'; ?>" title="Grid View">
                            <i class="bi bi-grid-fill"></i>
                        </a>
                        <a href="<?php echo buildQueryString(['view' => 'list']); ?>" class="btn <?php echo $view === 'list' ? 'btn-primary' : 'btn-outline-secondary'; ?>" title="List View">
                            <i class="bi bi-list-task"></i>
                        </a>
                    </div>
                 </div>
            </div>
        </div>
    </div>

    <div class="card data-display-card">
         <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
            <h5 class="mb-0 fs-6"><i class="bi <?php echo $view === 'grid' ? 'bi-grid' : 'bi-table'; ?> me-2"></i>Voting Codes</h5>
            <div class="d-flex align-items-center gap-3 mt-2 mt-md-0">
                <div class="d-flex align-items-center gap-2">
                     <select class="form-select form-select-sm" id="itemsPerPageSelect" style="width: auto;" aria-label="Items per page">
                         <option value="12" <?php if($items_per_page == 12) echo 'selected'; ?>>12</option>
                         <option value="30" <?php if($items_per_page == 30) echo 'selected'; ?>>30</option>
                         <option value="60" <?php if($items_per_page == 60) echo 'selected'; ?>>60</option>
                         <option value="102" <?php if($items_per_page == 102) echo 'selected'; ?>>102</option>
                     </select>
                     <span class="text-muted text-nowrap d-none d-sm-inline">per page</span>
                </div>
                <div class="btn-group btn-group-sm">
                     <button type="button" class="btn btn-sm btn-outline-secondary" onclick="printCodes()" title="Print">
                         <i class="bi bi-printer"></i>
                     </button>
                     <div class="dropdown">
                         <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="exportDropdown" data-bs-toggle="dropdown" aria-expanded="false" title="Export">
                             <i class="bi bi-download"></i>
                         </button>
                         <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="exportDropdown">
                             <li><button class="dropdown-item" type="button" onclick="exportToPDF()"><i class="bi bi-file-pdf me-2"></i>PDF</button></li>
                             <li><button class="dropdown-item" type="button" onclick="exportToExcel()"><i class="bi bi-file-excel me-2"></i>Excel</button></li>
                         </ul>
                     </div>
                </div>
            </div>
         </div>
        <div class="card-body"> 
            <?php if (empty($voting_codes)): ?>
                <div class="no-codes-placeholder">
                    <i class="bi bi-inbox"></i>
                    <p class="mb-0">
                        <?php echo !empty($search_term) ? 'No codes found matching your search.' : ($selected_election_id ? 'No codes found for this election.' : 'No voting codes generated yet.'); ?>
                    </p>
                </div>
            <?php else: ?>
                <?php if ($view === 'grid'): ?>
                    <div class="codes-grid-container">
                        <?php foreach ($voting_codes as $code): ?>
                            <div class="code-card">
                                <?php if (!$code['is_used']): ?>
                                <button type="button" class="btn btn-outline-danger btn-sm delete-code-btn"
                                        data-code-id="<?php echo $code['id']; ?>"
                                        data-code-value="<?php echo htmlspecialchars($code['code']); ?>"
                                        title="Delete Code">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                                <?php endif; ?>
                                <span class="code-card-code"><?php echo htmlspecialchars($code['code']); ?></span>
                                <?php if ($code['is_used']): ?>
                                    <span class="badge bg-danger">Used</span>
                                <?php else: ?>
                                    <span class="badge bg-info">Available</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: // list view ?>
                    <form method="post" id="codesForm">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle voting-codes-table">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 1%;" class="text-center">
                                            <input type="checkbox" class="form-check-input select-all" title="Select All">
                                        </th>
                                        <th>Code</th>
                                        <th>Election</th>
                                        <th>Status</th>
                                        <th class="text-nowrap">Used At</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($voting_codes as $code): ?>
                                        <tr id="code-row-<?php echo $code['id']; ?>">
                                            <td class="text-center">
                                                <input type="checkbox" name="selected_codes[]" value="<?php echo $code['id']; ?>" class="form-check-input code-checkbox" <?php echo $code['is_used'] ? 'disabled' : ''; ?> aria-label="Select code <?php echo htmlspecialchars($code['code']); ?>">
                                            </td>
                                            <td>
                                                <code><?php echo htmlspecialchars($code['code']); ?></code>
                                            </td>
                                            <td>
                                                 <?php if($code['election_title']): ?>
                                                     <span class="badge bg-<?php echo $code['election_status'] === 'active' ? 'success' : ($code['election_status'] === 'pending' ? 'warning' : 'secondary'); ?> me-1"><?php echo ucfirst(htmlspecialchars($code['election_status'])); ?></span>
                                                     <?php echo htmlspecialchars($code['election_title']); ?>
                                                 <?php else: ?>
                                                     <span class="text-muted fst-italic">Unassigned</span>
                                                 <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($code['is_used']): ?>
                                                    <span class="badge bg-danger">Used</span>
                                                <?php else: ?>
                                                    <span class="badge bg-info">Available</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-nowrap">
                                                 <?php if ($code['is_used'] && $code['used_at']): ?>
                                                     <span class="text-danger" title="<?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($code['used_at']))); ?>">
                                                         <?php echo htmlspecialchars(date('M d, Y H:i', strtotime($code['used_at']))); ?>
                                                     </span>
                                                 <?php else: ?>
                                                     <span class="text-muted">--</span>
                                                 <?php endif; ?>
                                            </td>
                                            <td class="text-center action-buttons">
                                                <button type="button" class="btn btn-outline-danger btn-sm delete-code-btn"
                                                        data-code-id="<?php echo $code['id']; ?>"
                                                        data-code-value="<?php echo htmlspecialchars($code['code']); ?>"
                                                        title="Delete Code"
                                                        <?php echo $code['is_used'] ? 'disabled' : ''; ?>>
                                                    <i class="bi bi-trash3-fill"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer bg-light py-2 px-3 d-flex flex-wrap justify-content-between align-items-center table-actions">
                              <div>
                                  <button type="submit" name="delete_codes" class="btn btn-danger btn-sm" id="deleteSelectedBtn" disabled onclick="return confirmDeleteSelected()">
                                      <i class="bi bi-trash-fill me-1"></i> Delete Selected (<span id="selectedCount">0</span>)
                                  </button>
                              </div>
                        </div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php if ($total_pages > 1 && !empty($voting_codes)): ?>
            <div class="card-footer bg-light d-flex flex-wrap justify-content-between align-items-center">
                 <div class="pagination-info">
                      Showing <?php echo $start_record; ?> to <?php echo $end_record; ?> of <?php echo number_format($total_records); ?> entries
                 </div>
                <nav aria-label="Voting codes pagination" class="pagination-nav">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo buildQueryString(['page' => 1]); ?>" aria-label="First">&laquo;&laquo;</a>
                        </li>
                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo buildQueryString(['page' => $page - 1]); ?>" aria-label="Previous">&laquo;</a>
                        </li>
                        <?php
                            $max_links = 5;
                            $start_page = max(1, $page - floor($max_links / 2));
                            $end_page = min($total_pages, $start_page + $max_links - 1);
                            $start_page = max(1, $end_page - $max_links + 1);

                            if ($start_page > 1) { echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; }
                            for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo buildQueryString(['page' => $i]); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php
                            endfor;
                            if ($end_page < $total_pages) { echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; }
                        ?>
                        <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo buildQueryString(['page' => $page + 1]); ?>" aria-label="Next">&raquo;</a>
                        </li>
                         <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo buildQueryString(['page' => $total_pages]); ?>" aria-label="Last">&raquo;&raquo;</a>
                        </li>
                    </ul>
                </nav>
            </div>
         <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="generateCodesModal" tabindex="-1" aria-labelledby="generateCodesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="generateCodesModalLabel"><i class="bi bi-plus-circle-fill me-2"></i>Generate Voting Codes</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" id="generateCodesForm" novalidate> 
                <div class="modal-body">
                    <div id="generateModalAlertPlaceholder"></div>
                    <input type="hidden" name="action" value="create">

                    <div class="mb-3">
                        <label for="generate_election_id" class="form-label">Election*</label>
                        <select class="form-select" id="generate_election_id" name="election_id" required>
                            <option value="" selected disabled>-- Select Election --</option>
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
                        <label for="generate_quantity" class="form-label">Number of Codes*</label>
                        <input type="number" class="form-control" id="generate_quantity" name="quantity" min="1" max="1000" value="25" required>
                         <div class="invalid-feedback">Please enter a number between 1 and 1000.</div>
                         <small class="form-text text-muted">Maximum 1000 codes can be generated at once.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="generateCodesSubmitBtn" <?php echo empty($elections) ? 'disabled' : ''; ?>>
                        <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
                        <i class="bi bi-check-lg me-1"></i>Generate Codes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteSingleConfirmModal" tabindex="-1" aria-labelledby="deleteSingleConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white border-0">
                 <h6 class="modal-title" id="deleteSingleConfirmModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Deletion</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center py-4">
                <p class="mb-2">Delete code <code id="codeToDeleteValue" class="bg-light px-1"></code>?</p>
                <p class="text-muted small mb-0">This action cannot be undone.</p>
                <form id="deleteSingleForm" method="post" class="d-none">
                    <input type="hidden" name="delete_codes" value="1">
                    <input type="hidden" id="deleteCodeIdSingle" name="selected_codes[]">
                </form>
            </div>
             <div class="modal-footer justify-content-center border-0 pt-0">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                 <button type="button" class="btn btn-danger btn-sm px-3" id="confirmDeleteSingleBtn">
                     <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
                     <i class="bi bi-trash3-fill me-1"></i>Delete
                 </button>
            </div>
        </div>
    </div>
</div>


<?php
// Helper function to build query string for pagination links
function buildQueryString($params) {
    // Start with existing GET parameters
    $query = $_GET;
    // Merge/overwrite with new params
    $query = array_merge($query, $params);
    // Remove 'search' if it's empty to keep URL clean
    if (isset($query['search']) && empty($query['search'])) {
        unset($query['search']);
    }
     // Remove 'election_id' if it's empty
     if (isset($query['election_id']) && empty($query['election_id'])) {
         unset($query['election_id']);
     }
    // Build and return the query string
    return '?' . http_build_query($query);
}

require_once "includes/footer.php"; // Include footer which contains closing body/html and JS includes
?>

<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" integrity="sha512-qZvrmS2ekKPF2mSFdfFPz5uYnRjzmg8JvhBqt/nqozRDBaYRf/CcEAU+qnUyWUJ4MDufSkMpkHXtITpNcViiLQ==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" integrity="sha512-BNaRQnYJYiPSqHHDb58B0yaPfCu+Wgds8Gp/gU33kqBtgNS4tSPHuGibyoeqMV/TJlSKda6FXzoEyYGjTe+vXA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
document.addEventListener('DOMContentLoaded', function () {
    const currentView = '<?php echo $view; ?>';

    // --- Modal & Form References ---
    const generateModalEl = document.getElementById('generateCodesModal');
    const generateModal = generateModalEl ? bootstrap.Modal.getOrCreateInstance(generateModalEl) : null;
    const generateForm = document.getElementById('generateCodesForm');
    const generateSubmitBtn = document.getElementById('generateCodesSubmitBtn');
    const generateAlertPlaceholder = document.getElementById('generateModalAlertPlaceholder');

    const deleteSingleModalEl = document.getElementById('deleteSingleConfirmModal');
    const deleteSingleModal = deleteSingleModalEl ? bootstrap.Modal.getOrCreateInstance(deleteSingleModalEl) : null;
    const codeToDeleteValueEl = document.getElementById('codeToDeleteValue');
    const deleteCodeIdSingleInput = document.getElementById('deleteCodeIdSingle');
    const confirmDeleteSingleBtn = document.getElementById('confirmDeleteSingleBtn');
    const deleteSingleForm = document.getElementById('deleteSingleForm');

    const selectAllCheckbox = document.querySelector('.select-all');
    const codeCheckboxes = document.querySelectorAll('.code-checkbox:not(:disabled)'); // Select only enabled checkboxes
    const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
    const selectedCountSpan = document.getElementById('selectedCount');

    // --- Filter & Search Elements ---
    const electionFilter = document.getElementById('electionFilter');
    const searchInput = document.getElementById('searchInput');
    const itemsPerPageSelect = document.getElementById('itemsPerPageSelect');

    // --- Helper Functions ---
    function showModalAlert(placeholder, message, type = 'danger') {
         if(placeholder) {
              placeholder.innerHTML = `
                   <div class="alert alert-${type} alert-dismissible fade show d-flex align-items-center small p-2 mb-3" role="alert">
                        <i class="bi ${type === 'danger' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill'} me-2"></i>
                        <div>${message}</div>
                        <button type="button" class="btn-close p-2" data-bs-dismiss="alert" aria-label="Close"></button>
                   </div>`;
         }
    }

    function setButtonLoading(button, isLoading, loadingText = "Processing...") {
        if (!button) return;
        const originalHTML = button.dataset.originalHTML || button.innerHTML;
        if (!button.dataset.originalHTML) button.dataset.originalHTML = originalHTML;

        const spinner = button.querySelector('.spinner-border');
        const icon = button.querySelector('.bi');

        if (isLoading) {
            button.disabled = true;
            if (spinner) spinner.classList.remove('d-none');
            if (icon) icon.style.display = 'none';
            const textNode = Array.from(button.childNodes).find(node => node.nodeType === Node.TEXT_NODE && node.textContent.trim().length > 0);
            if (textNode) textNode.textContent = ` ${loadingText}`;
        } else {
            button.disabled = false;
            button.innerHTML = originalHTML;
        }
    }

    // --- List View Specific Functions ---
    if (currentView === 'list') {
        function updateSelectedCount() {
            const selectedCheckboxes = document.querySelectorAll('.code-checkbox:checked:not(:disabled)');
            const count = selectedCheckboxes.length;
            if(selectedCountSpan) selectedCountSpan.textContent = count;
            if(deleteSelectedBtn) deleteSelectedBtn.disabled = count === 0;
        }

        function updateSelectAllState() {
            const enabledCheckboxes = document.querySelectorAll('.code-checkbox:not(:disabled)');
            const checkedEnabledCount = document.querySelectorAll('.code-checkbox:checked:not(:disabled)').length;
            if (selectAllCheckbox) {
                if (enabledCheckboxes.length === 0) {
                    selectAllCheckbox.checked = false;
                    selectAllCheckbox.indeterminate = false;
                    selectAllCheckbox.disabled = true;
                } else {
                    selectAllCheckbox.disabled = false;
                    selectAllCheckbox.checked = checkedEnabledCount === enabledCheckboxes.length;
                    selectAllCheckbox.indeterminate = checkedEnabledCount > 0 && checkedEnabledCount < enabledCheckboxes.length;
                }
            }
        }
        
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                codeCheckboxes.forEach(checkbox => checkbox.checked = this.checked);
                updateSelectedCount();
            });
        }

        codeCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                updateSelectedCount();
                updateSelectAllState();
            });
        });

        updateSelectedCount();
        updateSelectAllState();
    }
    
    // --- Generic Functions ---
    function buildCurrentQuery(extraParams = {}) {
        const params = new URLSearchParams(window.location.search);
        
        for (const [key, value] of Object.entries(extraParams)) {
            params.set(key, value);
        }
        
        return '?' + params.toString();
    }

    function reloadWithFilters(extraParams = {}) {
        const params = new URLSearchParams();
        const electionId = electionFilter ? electionFilter.value : '';
        const items = itemsPerPageSelect ? itemsPerPageSelect.value : '30';
        const search = searchInput ? searchInput.value : '';

        if (electionId) params.set('election_id', electionId);
        if (items) params.set('items_per_page', items);
        if (search) params.set('search', search);

        // Preserve view, but override with extraParams if present
        params.set('view', extraParams.view || currentView);
        if(extraParams.page) params.set('page', extraParams.page);

        window.location.href = 'voting_codes.php?' + params.toString();
    }

    // --- Event Listeners ---
    if(electionFilter) electionFilter.addEventListener('change', () => reloadWithFilters({ page: 1 }));
    if(itemsPerPageSelect) itemsPerPageSelect.addEventListener('change', () => reloadWithFilters({ page: 1 }));
    if(searchInput) searchInput.addEventListener('keypress', (e) => { if (e.key === 'Enter') reloadWithFilters({ page: 1 }); });

    // Generate Codes Form Submission
    if (generateForm && generateSubmitBtn) {
        generateForm.addEventListener('submit', function(e) {
            e.preventDefault();
            generateAlertPlaceholder.innerHTML = '';
            generateForm.classList.remove('was-validated');

            if (!generateForm.checkValidity()) {
                e.stopPropagation();
                generateForm.classList.add('was-validated');
                showModalAlert(generateAlertPlaceholder, 'Please select an election and enter a valid quantity.', 'warning');
                return;
            }

            setButtonLoading(generateSubmitBtn, true, 'Generating...');
            setTimeout(() => { generateForm.submit(); }, 100);
        });
    }

    // Delete Single Code Button (works for both views)
    document.querySelectorAll('.delete-code-btn').forEach(button => {
         button.addEventListener('click', function() {
             const codeId = this.getAttribute('data-code-id');
             const codeValue = this.getAttribute('data-code-value');
             if(deleteCodeIdSingleInput) deleteCodeIdSingleInput.value = codeId;
             if(codeToDeleteValueEl) codeToDeleteValueEl.textContent = codeValue || 'this code';
             if(deleteSingleModal) deleteSingleModal.show();
         });
      });

      // Confirm Single Delete
      if(confirmDeleteSingleBtn && deleteSingleForm) {
          confirmDeleteSingleBtn.addEventListener('click', function() {
              setButtonLoading(this, true, 'Deleting...');
              setTimeout(() => { deleteSingleForm.submit(); }, 100);
          });
      }

    // Reset modals on close
    [generateModalEl, deleteSingleModalEl].forEach(modalEl => {
        if(modalEl) {
            modalEl.addEventListener('hidden.bs.modal', function () {
                const form = this.querySelector('form');
                if (form) { form.classList.remove('was-validated'); }
                const alertPlaceholder = this.querySelector('#generateModalAlertPlaceholder');
                if (alertPlaceholder) alertPlaceholder.innerHTML = '';
                const genBtn = this.querySelector('#generateCodesSubmitBtn'); if(genBtn) setButtonLoading(genBtn, false);
                const delBtn = this.querySelector('#confirmDeleteSingleBtn'); if(delBtn) setButtonLoading(delBtn, false);
            });
        }
    });

      // Tooltip initialization
      const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"], [title]'));
      tooltipTriggerList.map(function (tooltipTriggerEl) {
          return new bootstrap.Tooltip(tooltipTriggerEl);
      });
}); // End DOMContentLoaded

// --- Global Functions ---
function confirmDeleteSelected() {
    const selectedCheckboxes = document.querySelectorAll('.code-checkbox:checked:not(:disabled)');
    if (selectedCheckboxes.length === 0) {
        alert('Please select at least one available code to delete.');
        return false;
    }
    return confirm(`Are you sure you want to delete the ${selectedCheckboxes.length} selected voting code(s)? This action cannot be undone.`);
}

function printCodes() {
    const currentView = '<?php echo $view; ?>';
    const electionTitle = `<?php echo $selected_election_id ? htmlspecialchars(current(array_filter($elections, fn($e) => $e['id'] == $selected_election_id))['title'] ?? 'Selected Election', ENT_QUOTES) : 'All Elections'; ?>`;
    let contentToPrint;

    if (currentView === 'grid') {
        const grid = document.querySelector('.codes-grid-container');
        if (!grid) { alert("Grid not found for printing."); return; }
        contentToPrint = grid.outerHTML;
    } else {
        const table = document.querySelector('.voting-codes-table');
        if (!table) { alert("Table not found for printing."); return; }
        const tableClone = table.cloneNode(true);
        // Remove checkbox and action columns from the clone for printing
        tableClone.querySelectorAll('tr').forEach(tr => {
            tr.cells[0]?.remove(); // first cell (checkbox)
            tr.cells[tr.cells.length - 1]?.remove(); // last cell (action)
        });
        contentToPrint = tableClone.outerHTML;
    }

    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Voting Codes - ${electionTitle}</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
            <style>
                body { font-family: sans-serif; }
                h2 { font-size: 16pt; text-align: center; margin-bottom: 1rem; }
                ${document.querySelector('style[nonce]').innerHTML} /* Copy existing styles */
            </style>
        </head>
        <body>
            <h2>Voting Codes: ${electionTitle}</h2>
            ${contentToPrint}
            <script>
                setTimeout(() => {
                    window.print();
                    setTimeout(() => window.close(), 250);
                }, 250);
            <\/script>
        </body>
        </html>
    `);
    printWindow.document.close();
}

 // Export to PDF (Basic - relies on print styles)
 function exportToPDF() {
      alert('PDF export will use the browser\'s print function. Please choose "Save as PDF" in the print dialog.');
      printCodes();
 }

// Export to Excel (always uses table format for data integrity)
function exportToExcel() {
    if (typeof XLSX === 'undefined') {
        alert('Excel export library (SheetJS) not loaded.'); return;
     }
    const table = document.querySelector('.voting-codes-table');
    
    if (!table) {
        // If table doesn't exist (i.e., in grid view), create a temporary one from data.
        // This is a more robust approach but for now, we alert the user.
        alert('Please switch to List View to export to Excel.');
        return;
    }
    
    const tableClone = table.cloneNode(true);
    // Remove checkbox and action columns from the clone
     tableClone.querySelectorAll('tr').forEach(tr => {
         tr.cells[0]?.remove(); // checkbox
         tr.cells[tr.cells.length - 1]?.remove(); // action
     });

    const ws = XLSX.utils.table_to_sheet(tableClone);
      const cols = [];
      const range = XLSX.utils.decode_range(ws['!ref']);
      for(let C = range.s.c; C <= range.e.c; ++C) {
          let max_width = 0;
          for(let R = range.s.r; R <= range.e.r; ++R) {
              const cell_address = {c:C, r:R};
              const cell_ref = XLSX.utils.encode_cell(cell_address);
              if(!ws[cell_ref]) continue;
              const cell_text_width = ws[cell_ref].v ? String(ws[cell_ref].v).length : 0;
              if(cell_text_width > max_width) max_width = cell_text_width;
          }
          cols[C] = { wch: Math.max(15, max_width + 2) }; // Set a min width
      }
      ws['!cols'] = cols;

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Voting Codes');
    XLSX.writeFile(wb, 'voting_codes_export.xlsx');
}
</script>