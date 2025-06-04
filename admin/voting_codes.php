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
function generateVotingCode() {
    global $conn;
    $attempts = 0;
    $max_attempts = 100; // Prevent infinite loop

    do {
        // Generate a random 6-digit number
        $code = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);

        // Check if code already exists
        $check_sql = "SELECT COUNT(*) FROM voting_codes WHERE code = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->execute([$code]);
        $exists = $check_stmt->fetchColumn() > 0;

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
                $check_stmt->execute($selected_codes);
                $used_count = $check_stmt->fetchColumn();

                if ($used_count > 0) {
                    $error = "Cannot delete used voting codes. Please unselect used codes and try again.";
                } else {
                    $placeholders_delete = implode(',', array_fill(0, count($selected_codes), '?'));
                    // Delete the selected unused codes
                    $delete_sql = "DELETE FROM voting_codes WHERE id IN ($placeholders_delete)";
                    $delete_stmt = $conn->prepare($delete_sql);
                    if ($delete_stmt->execute($selected_codes)) {
                        $_SESSION['success'] = count($selected_codes) . " selected voting code(s) deleted successfully!";
                    } else {
                        error_log("Error deleting voting codes: " . implode(", ", $delete_stmt->errorInfo()));
                        $_SESSION['error'] = "Error deleting voting codes. Please check logs.";
                    }
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
                $conn->beginTransaction();

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
                         $check_unique_sql = "SELECT COUNT(*) FROM voting_codes WHERE code = ?";
                         $check_unique_stmt = $conn->prepare($check_unique_sql);
                         $check_unique_stmt->execute([$code]);
                         if ($check_unique_stmt->fetchColumn() == 0) {
                             if ($insert_stmt->execute([$code, $election_id])) {
                                 $generated_codes[] = $code;
                                 $success_count++;
                             } else {
                                 error_log("Failed to insert code: " . $code . " Error: " . implode(", ", $insert_stmt->errorInfo()));
                                 $failed_count++;
                             }
                         } else {
                              error_log("Generated code collision (should be rare): " . $code);
                              $failed_count++; // Treat collision as failure for this attempt
                              $i--; // Retry generating a code for this iteration
                         }

                    } catch (PDOException $e) {
                        // Catch potential unique constraint violation during insert (fallback)
                        error_log("PDOException during code insert: " . $e->getMessage());
                        $failed_count++;
                         // Optionally retry: $i--;
                    }
                }

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

            } catch (PDOException $e) {
                $conn->rollBack();
                error_log("Error generating voting codes (PDO): " . $e->getMessage());
                $error = "Error generating voting codes. Please check logs.";
            } catch (Exception $e) {
                 // Catch generateVotingCode failure if it throws Exception
                 $conn->rollBack(); // Rollback if transaction started
                 error_log("Error generating voting codes (General): " . $e->getMessage());
                 $error = "Error generating voting codes: " . $e->getMessage();
            }
        }
    }
}

// --- Data Fetching ---
$fetch_error = null;
$elections = [];
$voting_codes = [];
$stats = [
    'total_codes' => 0,
    'used_codes' => 0,
    'unused_codes' => 0
];
$total_records = 0;
$total_pages = 0;
$start_record = 0;
$end_record = 0;

try {
    // Get all elections for the dropdown
    $elections_sql = "SELECT id, title FROM elections ORDER BY title ASC";
    $elections = $conn->query($elections_sql)->fetchAll(PDO::FETCH_ASSOC);

    // Get selected election ID from URL or default to null (All)
    $selected_election_id = filter_input(INPUT_GET, 'election_id', FILTER_VALIDATE_INT);
    if ($selected_election_id === false || $selected_election_id === 0) { // Treat invalid/0 ID as 'All'
        $selected_election_id = null;
    }

    // Get search term
    $search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

    // Pagination settings
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    // Get items_per_page from GET or default. Use -1 for 'View All'.
    $items_per_page = isset($_GET['items_per_page']) ? (int)$_GET['items_per_page'] : 10; // Default to 10
    
    // Validate items_per_page - allow -1 and specific values
    $allowed_items_per_page = [-1, 10, 50, 100, 200];
    if (!in_array($items_per_page, $allowed_items_per_page)) {
        $items_per_page = 10; // Reset to default if invalid
    }

    $offset = ($page - 1) * $items_per_page;

    // Base SQL and params for counting and fetching
    $base_sql_select = "SELECT vc.*, e.title as election_title, e.status as election_status,
                           (SELECT v.created_at FROM votes v WHERE v.voting_code_id = vc.id ORDER BY v.created_at DESC LIMIT 1) as used_at";
    $base_sql_from = " FROM voting_codes vc LEFT JOIN elections e ON vc.election_id = e.id";
    $base_sql_where = " WHERE 1=1";
    $params = [];

    // Add election filter
    if ($selected_election_id) {
        $base_sql_where .= " AND vc.election_id = :election_id";
        $params[':election_id'] = $selected_election_id;
    }

    // Add search filter
    if (!empty($search_term)) {
        $base_sql_where .= " AND vc.code LIKE :search";
        $params[':search'] = "%" . $search_term . "%";
    }

    // Get total records for pagination
    $count_sql = "SELECT COUNT(vc.id)" . $base_sql_from . $base_sql_where;
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = (int)$count_stmt->fetchColumn();

    // Calculate pagination values
    if ($items_per_page === -1) {
        $total_pages = 1; // If viewing all, there's only one page
        $page = 1;
        $offset = 0;
        $start_record = $total_records > 0 ? 1 : 0;
        $end_record = $total_records; // End record is total records when viewing all
    } else {
        $total_pages = $total_records > 0 ? ceil($total_records / $items_per_page) : 1;
        $page = max(1, min($page, $total_pages)); // Recalculate page based on total pages
        $offset = ($page - 1) * $items_per_page; // Recalculate offset
        $start_record = $total_records > 0 ? $offset + 1 : 0;
        $end_record = min($offset + $items_per_page, $total_records);
    }

    // Get voting codes for the current page
    $voting_codes_sql = $base_sql_select . $base_sql_from . $base_sql_where
                      . " ORDER BY vc.created_at DESC";
                      
    // Add LIMIT and OFFSET only if not viewing all
    if ($items_per_page !== -1) {
        $voting_codes_sql .= " LIMIT :limit OFFSET :offset";
    }

    $stmt = $conn->prepare($voting_codes_sql);
    // Bind base params
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    // Bind pagination params only if not viewing all
    if ($items_per_page !== -1) {
        $stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    }

    $stmt->execute();
    $voting_codes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get statistics (scoped by election if selected)
    $stats_sql = "SELECT COUNT(vc.id) as total_codes, COUNT(CASE WHEN vc.is_used = 1 THEN vc.id END) as used_codes
                  FROM voting_codes vc" . $base_sql_where; // Re-use WHERE clause but without search
    $stats_params = [];
    if ($selected_election_id) { $stats_params[':election_id'] = $selected_election_id; }
    // Do NOT include search in stats params
    $stats_stmt = $conn->prepare($stats_sql);
    $stats_stmt->execute($stats_params);
    $stats_result = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_codes'] = (int)($stats_result['total_codes'] ?? 0);
    $stats['used_codes'] = (int)($stats_result['used_codes'] ?? 0);
    $stats['unused_codes'] = $stats['total_codes'] - $stats['used_codes'];


} catch (PDOException $e) {
    error_log("Voting Codes Page Error: " . $e->getMessage());
    $fetch_error = "Could not load voting codes data due to a database issue.";
    // Reset arrays on error
    $elections = []; 
    $voting_codes = [];
    $stats = [
        'total_codes' => 0,
        'used_codes' => 0,
        'unused_codes' => 0
    ];
    $total_records = 0;
    $total_pages = 0;
    $start_record = 0;
    $end_record = 0;
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

    /* Grid Card */
    .grid-card { border: none; border-radius: var(--border-radius-lg); box-shadow: var(--shadow); overflow: hidden; }
    .grid-card .card-header { background-color: var(--white-color); border-bottom: 1px solid var(--border-color); font-weight: 700; color: var(--primary-dark); padding: 1rem 1.25rem; }

    /* Pagination */
    .pagination-info { color: var(--secondary-color); font-size: 0.85rem; }
    .pagination .page-link { font-size: 0.85rem; }
    .pagination .page-item.disabled .page-link { color: var(--secondary-color); }

    /* Modals */
    .modal-header { background-color: var(--primary-light); border-bottom: 1px solid var(--border-color); }
    .modal-title { font-family: var(--font-family-primary); color: var(--primary-dark); }
    .modal-footer { background-color: var(--gray-100); border-top: 1px solid var(--border-color); }


    /* --- NEW VOTING CODE GRID STYLES --- */
    .voting-code-card {
        transition: all 0.25s ease-in-out;
        border: 1px solid var(--border-color);
        box-shadow: var(--shadow-sm);
        background-color: var(--white-color);
        border-radius: var(--border-radius-lg);
        display: flex;
        flex-direction: column;
    }

    .voting-code-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow);
        border-color: var(--primary-color);
    }

    .voting-code-card.used {
        background-color: var(--danger-light); /* Light red background */
        opacity: 1; /* Full opacity for used cards */
        border-color: var(--danger-color); /* Red border */
        color: var(--dark-color); /* Ensure text color is readable */
    }
    .voting-code-card.used:hover {
        border-color: var(--danger-dark); /* Darker red on hover */
        box-shadow: var(--shadow); /* Keep shadow on hover */
    }

    .voting-code-card .card-body {
        padding: 1rem;
        display: flex;
        flex-direction: column;
        flex-grow: 1; /* Ensures footer is pushed down */
    }

    .voting-code-card .card-header-actions {
        margin-bottom: 0.75rem;
    }
    
    .voting-code-card .form-check-input {
        cursor: pointer;
    }
    
    .voting-code-card .dropdown .btn-link {
        color: var(--secondary-color);
        padding: 0.1rem 0.25rem;
        line-height: 1;
    }
    .voting-code-card .dropdown .btn-link:hover {
        color: var(--dark-color);
    }

    .voting-code-card .code-display {
        font-family: 'Courier New', Courier, monospace;
        font-size: 1.75rem;
        font-weight: 700;
        color: var(--primary-dark);
        background-color: var(--primary-light);
        padding: 0.75rem;
        border-radius: var(--border-radius);
        text-align: center;
        letter-spacing: 2px;
        margin-bottom: 1rem;
        flex-grow: 1; /* Allow this to grow */
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .voting-code-card.used .code-display {
        background-color: var(--gray-200);
        color: var(--secondary-color);
        text-decoration: line-through;
    }
    
    .voting-code-card .card-footer {
        background-color: transparent;
        border-top: 1px solid var(--border-color);
        padding: 0.75rem 1rem;
        font-size: 0.8rem;
        color: var(--secondary-color);
    }

    .voting-code-card .election-title {
        font-weight: 600;
        color: var(--dark-color);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        display: block; /* Make it a block element */
        max-width: 100%;
    }
    
    .voting-code-card .code-status .badge {
        font-size: 0.75rem;
        padding: .4em .6em;
        font-weight: 600;
    }

    @media print {
        body { background-color: #fff !important; }
        .sidebar, .navbar, .page-header .btn, .controls-card, .table-actions, .action-buttons .btn, .pagination-nav, #sidebarToggle { display: none !important; }
        .main-content, .main-content.expanded { margin-left: 0 !important; width: 100% !important; padding: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .page-header { border-bottom: none; margin-bottom: 1rem; text-align: center;}
        .page-header h2 { font-size: 16pt; } .page-header p { display: none; }
        .card, .stat-card, .grid-card, .voting-code-card { box-shadow: none !important; border: 1px solid #ddd !important; margin-bottom: .5rem; page-break-inside: avoid; }
        .stat-card .card-body { padding: .5rem !important;} .stat-card .stat-label { font-size: 8pt; } .stat-card .stat-value { font-size: 12pt;} .stat-icon { display: none; }
        .code-display { font-size: 14pt; background-color: #f0f0f0 !important; color: #333 !important; }
        .card-footer { font-size: 8pt; }
        .badge { border: 1px solid #ccc; background-color: #eee !important; color: #000 !important; font-size: 8pt; }
        .pagination-info, .card-header-actions { display: none; }
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
                <div class="col-lg-4 col-md-6">
                     <div class="input-group input-group-sm">
                         <span class="input-group-text" id="search-addon"><i class="bi bi-search"></i></span>
                         <input type="search" class="form-control" id="searchInput" placeholder="Search by code..." aria-label="Search codes" aria-describedby="search-addon" value="<?php echo htmlspecialchars($search_term); ?>">
                     </div>
                </div>
                 <div class="col-lg-4 d-flex justify-content-lg-end align-items-center gap-2 mt-2 mt-lg-0 flex-wrap">
                      <select class="form-select form-select-sm" id="itemsPerPageSelect" style="width: auto;" aria-label="Items per page">
                          <option value="-1" <?php echo $items_per_page == -1 ? 'selected' : ''; ?>>All</option>
                          <option value="10" <?php echo $items_per_page == 10 ? 'selected' : ''; ?>>10</option>
                          <option value="50" <?php echo $items_per_page == 50 ? 'selected' : ''; ?>>50</option>
                          <option value="100" <?php echo $items_per_page == 100 ? 'selected' : ''; ?>>100</option>
                          <option value="200" <?php echo $items_per_page == 200 ? 'selected' : ''; ?>>200</option>
                      </select>
                      <span class="text-muted text-nowrap">per page</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card grid-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-grid-3x3-gap me-2"></i>Voting Codes</span>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-danger btn-sm" id="deleteSelectedBtn" style="display: none;" data-bs-toggle="modal" data-bs-target="#confirmDeleteModal">
                    <i class="bi bi-trash-fill me-1"></i> Delete Selected
                </button>
                <div class="form-check form-check-inline me-0">
                    <input class="form-check-input" type="checkbox" id="selectAllCheckbox" title="Select all on page">
                    <label class="form-check-label small" for="selectAllCheckbox">Select All</label>
                </div>
            </div>
        </div>
        <div class="card-body p-3"> 
            <form method="post" id="codesForm">
                <div class="row g-3">
                    <?php if (empty($voting_codes)): ?>
                        <div class="col-12">
                            <div class="text-center py-5">
                                <i class="bi bi-inbox display-4 text-muted mb-3"></i>
                                <p class="mb-0 text-muted">
                                    <?php echo !empty($search_term) ? 'No codes found matching your search.' : ($selected_election_id ? 'No codes for this election.' : 'No voting codes generated yet.'); ?>
                                </p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($voting_codes as $code): ?>
                            <div class="col-3 col-xl-3 col-lg-4 col-md-6">
                                <div class="card voting-code-card <?php echo $code['is_used'] ? 'used' : 'available'; ?>">
                                    <div class="card-body p-0">
                                        <div class="d-flex justify-content-between align-items-start card-header-actions">
                                            <div class="form-check">
                                                <input type="checkbox" name="selected_codes[]" value="<?php echo $code['id']; ?>" 
                                                       class="form-check-input code-checkbox" 
                                                       <?php echo $code['is_used'] ? 'disabled' : ''; ?> 
                                                       aria-label="Select code <?php echo htmlspecialchars($code['code']); ?>">
                                            </div>
                                            <div class="code-status">
                                                <?php if ($code['is_used']): ?>
                                                    <span class="badge text-bg-danger">Used</span>
                                                <?php else: ?>
                                                    <span class="badge text-bg-success">Available</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="code-display p-0" style="font-family: 'Courier New', Courier, monospace; font-size: 1.2rem; text-align: center; letter-spacing: 2px;">
                                            <?php echo htmlspecialchars($code['code']); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="card-footer">
                                        <span class="election-title" title="<?php echo htmlspecialchars($code['election_title']); ?>">
                                            <i class="bi bi-archive-fill me-1 text-muted"></i>
                                            <?php echo htmlspecialchars($code['election_title'] ?: 'N/A'); ?>
                                        </span>
                                        <div class="d-flex justify-content-between mt-2">
                                            <span>
                                                <i class="bi bi-clock-history me-1 text-muted"></i>
                                                <?php echo date("M j, Y", strtotime($code['created_at'])); ?>
                                            </span>
                                             <?php if ($code['is_used'] && $code['used_at']): ?>
                                                <span class="text-dark" title="Used on <?php echo date("M j, Y, g:i a", strtotime($code['used_at'])); ?>">
                                                    <i class="bi bi-check-all me-1 text-muted"></i>
                                                    <?php echo date("M j, Y", strtotime($code['used_at'])); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                 <input type="hidden" name="delete_codes" value="1">
            </form>
        </div>

        <?php if ($total_records > 0): ?>
        <div class="card-footer bg-light d-flex flex-column flex-md-row justify-content-between align-items-center">
             <div class="pagination-info mb-2 mb-md-0">
                 Showing <strong><?php echo $start_record; ?></strong> to <strong><?php echo $end_record; ?></strong> of <strong><?php echo number_format($total_records); ?></strong> codes
             </div>
             <nav aria-label="Page navigation" class="pagination-nav">
                 <ul class="pagination pagination-sm mb-0">
                     <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                         <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Previous</a>
                     </li>
                     <?php
                         $num_links = 4;
                         $start = max(1, $page - floor($num_links / 2));
                         $end = min($total_pages, $start + $num_links - 1);
                         if ($end - $start < $num_links - 1) {
                             $start = max(1, $end - $num_links + 1);
                         }
                         if ($start > 1) {
                             echo '<li class="page-item"><a class="page-link" href="?'.http_build_query(array_merge($_GET, ['page' => 1])).'">1</a></li>';
                             if ($start > 2) {
                                 echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                             }
                         }
                         for ($i = $start; $i <= $end; $i++) {
                             echo '<li class="page-item '.($page == $i ? 'active' : '').'"><a class="page-link" href="?'.http_build_query(array_merge($_GET, ['page' => $i])).'">'.$i.'</a></li>';
                         }
                         if ($end < $total_pages) {
                             if ($end < $total_pages - 1) {
                                 echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                             }
                             echo '<li class="page-item"><a class="page-link" href="?'.http_build_query(array_merge($_GET, ['page' => $total_pages])).'">'.$total_pages.'</a></li>';
                         }
                     ?>
                     <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                         <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                     </li>
                 </ul>
             </nav>
        </div>
        <?php endif; ?>
    </div>
    </div>

<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
    function buildCurrentQuery(extraParams = {}) {
        const params = { ...extraParams };
        const electionId = electionFilter ? electionFilter.value : '';
        const items = itemsPerPageSelect ? itemsPerPageSelect.value : '10'; // Default to 10 now
        const search = searchInput ? searchInput.value : '';
        const currentPage = new URLSearchParams(window.location.search).get('page') || '1'; // Keep current page if exists

        if (electionId) params.election_id = electionId;
        // Always include items_per_page in the parameters
        params.items_per_page = items;

        if (search) params.search = search;
        // Decide the page number:
        // 1. If 'page' is in extraParams, use that.
        // 2. If items_per_page, election filter, or search changed, reset to page 1 (unless 'View All').
        // 3. Otherwise, keep the current page.

        const urlParams = new URLSearchParams(window.location.search);
        const currentItemsPerPage = urlParams.get('items_per_page') || '10';
        const currentElectionId = urlParams.get('election_id') || '';
        const currentSearch = urlParams.get('search') || '';

        if (Object.keys(extraParams).includes('page')) {
            params.page = extraParams.page;
        } else if (items !== currentItemsPerPage || electionId !== currentElectionId || search !== currentSearch) {
             // If items is -1 (View All), always go to page 1 (which will show all)
             if (items === '-1') {
                  params.page = 1;
             } else {
                  // For other changes, reset to page 1
                 params.page = 1;
             }
        } else {
            // If nothing relevant changed, stay on the current page
            params.page = currentPage;
        }

        return new URLSearchParams(params).toString();
    }

    function reloadWithFilters(extraParams = {}) {
        // Remove delete_codes parameter before reloading
        const currentParams = new URLSearchParams(window.location.search);
        if (currentParams.has('delete_codes')) {
            currentParams.delete('delete_codes');
        }
        // Pass extraParams directly to buildCurrentQuery
        const queryString = buildCurrentQuery(extraParams);
        window.location.href = 'voting_codes.php?' + queryString;
    }

    // --- Event Listeners ---

    // Filter change listeners
    if(electionFilter) electionFilter.addEventListener('change', () => reloadWithFilters({ page: 1 })); // Reset to page 1 on filter change
    if(itemsPerPageSelect) itemsPerPageSelect.addEventListener('change', () => {
         // Simply call reloadWithFilters. buildCurrentQuery will handle page reset logic.
         reloadWithFilters();
    });
    if(searchInput) searchInput.addEventListener('keypress', (e) => { if (e.key === 'Enter') reloadWithFilters({ page: 1 }); });
    // Optionally add a search button listener if needed: document.getElementById('searchButton').addEventListener('click', () => reloadWithFilters({ page: 1 }));


    // Generate Codes Form Submission
    // ... existing code ...
</script>

<script nonce="<?php echo $nonce; ?>">
document.addEventListener('DOMContentLoaded', function() {
    // Initialize sidebar toggle functionality
    // ... existing code ...


    // Tooltip initialization (if not done in footer.php)
    // ... existing code ...


    // Add event listener for the print button if onclick is not sufficient
    const printButton = document.querySelector('.btn-sm.btn-outline-secondary i.bi-printer').closest('button');
    if (printButton && !printButton.onclick) { // Check if onclick is not already set
        printButton.addEventListener('click', function() {
            console.log('Print button clicked via event listener');
            printCodes();
        });
    }
    console.log('DOMContentLoaded - Print button setup complete.');

}); // End DOMContentLoaded

// --- Global Functions ---
function confirmDeleteSelected() {
    // ... existing code ...
}

function printCodes() {
    console.log('printCodes function called');
    const gridContainer = document.querySelector('.row.g-3');
    if (!gridContainer) { alert("Grid not found for printing."); return; }

    // Clone the grid to modify for printing
    const gridClone = gridContainer.cloneNode(true);

    // Select and process each card element
    const cardsToProcess = gridClone.querySelectorAll('.voting-code-card');
    cardsToProcess.forEach(card => {
        // Remove checkbox and dropdown
        card.querySelectorAll('.form-check, .dropdown').forEach(el => el.remove());
        
        // Simplify card body layout for print
        const cardBody = card.querySelector('.card-body');
        if(cardBody) {
            cardBody.style.padding = '0.3cm'; // Consistent padding
            cardBody.style.display = 'block'; 
        }
        
        // Simplify card footer layout for print
        const cardFooter = card.querySelector('.card-footer');
         if(cardFooter) {
             cardFooter.style.padding = '0.3cm'; // Consistent padding
             cardFooter.style.marginTop = '0.2cm'; // Reduced top margin
             cardFooter.style.borderTop = '1px solid #eee'; // Lighter border
             cardFooter.style.fontSize = '7pt'; // Smaller font
         }
    });

    // Get all voting code cards
    const cardsToPrint = Array.from(cardsToProcess); // Use the processed cards
    const totalCardsToPrint = cardsToPrint.length;
    // Determine columns based on estimated card width and page width
    const estimatedCardWidth = 100; // px
    const pagePadding = 30; // px (estimate for total side padding)
    const estimatedPageWidth = 750; // px (slightly reduced estimate) - this is just an estimate
    const estimatedColumns = Math.max(1, Math.floor((estimatedPageWidth - pagePadding) / estimatedCardWidth));
    const cardsPerPageEstimate = estimatedColumns * Math.ceil(24 / estimatedColumns); // Aim for a multiple of columns near 24

    const totalPages = totalCardsToPrint > 0 ? Math.ceil(totalCardsToPrint / cardsPerPageEstimate) : 1;

    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Voting Codes</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
            <style>
                @media print {
                    body { 
                        font-size: 8pt; /* Even smaller base font for density */
                        margin: 0.5in; /* Use inches for print consistency */
                        padding: 0; /* Reset padding */
                    }
                    .page {
                        page-break-after: always;
                        padding: 0; 
                        height: auto; /* Let height be determined by content */
                        box-sizing: border-box;
                        margin-bottom: 0.5in; /* Space between pages */
                     }
                     .page:last-child {
                         page-break-after: avoid;
                     }
                     .grid-container {
                         display: grid;
                         grid-template-columns: repeat(4, 1fr); /* Explicitly set 4 columns for consistency */
                         gap: 0.4cm; /* Reduced gap */
                         width: 100%;
                     }
                     .voting-code-card {
                         border: 1px solid #ccc; /* Lighter border for print */
                         padding: 0.2cm; /* Reduced padding */
                         display: block; /* Simple block display */
                         height: auto; /* Natural height */
                     }
                     .voting-code-card code {
                         font-size: 9pt; /* Slightly larger code font for readability */
                           font-weight: bold;
                           text-align: center;
                           display: block;
                           margin: 0.1cm 0; /* Further reduced margin */
                           font-family: 'Courier New', monospace;
                           background-color: #eee; /* Light background for code */
                           padding: 0.15cm; /* Further adjusted padding */
                       }
                     .voting-code-details {
                         font-size: 7pt; /* Keep detail font size */
                         color: #333; /* Darker color for readability */
                     }
                      .voting-code-details > div {
                          word-break: break-word; /* Prevent long titles from overflowing */
                          margin-bottom: 0.1cm; /* Reduced space between details */
                          line-height: 1.2; /* Tighter line height */
                          /* Optional: Hide icons in print details if they take too much space */
                          /* i { display: none; } */
                      }
                     .page-header {
                          text-align: center;
                          margin-bottom: 0.75cm; /* Adjusted space above grid */
                       }
                     .page-header h2 {
                           font-size: 12pt; /* Adjusted header size */
                            margin: 0;
                        }
                     .election-title {
                         font-weight: bold;
                         /* Ensure ellipsis works in print */
                         white-space: nowrap;
                         overflow: hidden;
                         text-overflow: ellipsis;
                         display: block;
                         max-width: 100%;
                     }
                     .code-status .badge {
                         font-size: 7pt;
                         font-weight: bold;
                         padding: 0.1em 0.3em;
                         /* Ensure print colors are high contrast */
                         background-color: #ccc !important; /* Default badge background */
                         color: #000 !important; /* Default badge text color */
                         border: 1px solid #000 !important;
                     }
                     .code-status .badge.text-bg-danger {
                          background-color: #f00 !important; /* Red for used */
                          color: #fff !important;
                     }
                      .code-status .badge.text-bg-success {
                          background-color: #0f0 !important; /* Green for available */
                          color: #000 !important;
                     }
                     .card-footer {
                         font-size: 7pt; /* Smaller footer font */
                         color: #555; /* Darker color */
                         border-top: 1px solid #eee; /* Lighter border */
                         padding-top: 0.3cm; /* Reduced padding */
                         margin-top: 0.3cm; /* Space above footer */
                     }
                     .card-footer span {
                          display: inline-block;
                          margin-right: 0.5cm; /* Space between footer elements */
                     }
                     .card-footer span:last-child {
                          margin-right: 0;
                     }
                     .page-number {
                         position: absolute;
                         bottom: 0.3cm;
                         right: 0.5cm;
                         font-size: 7pt;
                     }
                     /* Ensure no extra margin/padding on elements that could cause breaks */
                     p, div, span { margin: 0; padding: 0; }
                     code { margin: 0; padding: 0; }
                     .voting-code-card .card-body { padding: 0.3cm; } /* Ensure consistent padding */
                }
            </style>
        </head>
        <body>
            ${Array.from({ length: totalPages }, (_, pageIndex) => {
                const start = pageIndex * cardsPerPageEstimate;
                const end = Math.min(start + cardsPerPageEstimate, cardsToPrint.length);
                const pageCards = cardsToPrint.slice(start, end);
                
                return `