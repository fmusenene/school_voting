<?php declare(strict_types=0); // Enforce strict types

/**
 * Voting Codes Management Page (Admin - Front-end)
 *
 * Displays the interface for managing voting codes.
 * Fetches initial data for the first page load.
 * Contains JavaScript to handle interactions and make AJAX calls to voting_codes_ajax.php.
 */

// --- Initialization & Config ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
error_reporting(E_ALL); // Dev
ini_set('display_errors', '1'); // Dev
date_default_timezone_set('Africa/Nairobi'); // EAT

// --- Includes ---
require_once "../config/database.php"; // Provides $conn (mysqli object)
require_once "includes/session.php"; // Provides isAdminLoggedIn() function

// --- Admin Authentication Check (for Page Access) ---
if (!isAdminLoggedIn()) {
    header("Location: /admin/login.php"); // Redirect to login if not admin
    exit();
}

// --- Pre-Check DB Connection for Page Load ---
if (!$conn || $conn->connect_error) {
    // Display a user-friendly error page or message for page load failure
    die("Database connection failed. Please check configuration or contact support. Error: " . ($conn ? $conn->connect_error : 'N/A'));
}

// --- Generate CSP nonce --- (Needs to be done before any output)
if (empty($_SESSION['csp_nonce'])) {
    $_SESSION['csp_nonce'] = base64_encode(random_bytes(16));
}
$nonce = htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8');


// --- Initial Page Load Data Fetching ---
$elections = [];
$initial_voting_codes = [];
$stats = ['total_codes' => 0, 'used_codes' => 0, 'unused_codes' => 0];
$page_error = null; // Store page load errors
$total_records = 0;
$total_pages = 0;

// Get filters/pagination params from GET for initial load, with defaults and validation
$selected_election_id = filter_input(INPUT_GET, 'election_id', FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]) ?: 1;
$items_per_page = filter_input(INPUT_GET, 'items_per_page', FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 100]]) ?: 10;
$search_query = trim($_GET['search'] ?? '');

try {
    // 1. Fetch elections for dropdown
    $elections_sql = "SELECT id, title FROM elections ORDER BY start_date DESC, title ASC";
    $result_elections = $conn->query($elections_sql);
    if ($result_elections === false) throw new mysqli_sql_exception("Error fetching elections: " . $conn->error, $conn->errno);
    while ($row = $result_elections->fetch_assoc()) { $elections[] = $row; }
    $result_elections->free_result();

    // 2. Fetch Initial Page Data (Codes for page 1 or specified page)
    $offset = ($page - 1) * $items_per_page;

    // Build Query Parts
    $select_clause = "SELECT vc.id, vc.code, vc.election_id, vc.created_at,
                             e.title as election_title, e.status as election_status,
                             MAX(v.created_at) as used_at, COUNT(DISTINCT v.id) as vote_count";
    $from_clause = " FROM voting_codes vc
                     LEFT JOIN elections e ON vc.election_id = e.id
                     LEFT JOIN votes v ON vc.id = v.voting_code_id";
    $where_clause = " WHERE 1=1";
    $group_by_clause = " GROUP BY vc.id";
    $order_by_clause = " ORDER BY vc.created_at DESC";

    // Apply Filters from GET params
    if ($selected_election_id) { $where_clause .= " AND vc.election_id = $selected_election_id"; }
    if (!empty($search_query)) { $search_escaped_init = $conn->real_escape_string($search_query); $where_clause .= " AND (vc.code LIKE '%$search_escaped_init%' OR e.title LIKE '%$search_escaped_init%')"; }

    // Get Total Records Count (with filters) for pagination calculation
    $count_sql = "SELECT COUNT(DISTINCT vc.id) as total_count" . $from_clause . $where_clause;
    $count_result = $conn->query($count_sql);
    if ($count_result === false) throw new mysqli_sql_exception("Error counting records: " . $conn->error, $conn->errno);
    $total_records_row = $count_result->fetch_assoc();
    $total_records = (int)($total_records_row['total_count'] ?? 0);
    $count_result->free_result();

    // Calculate Total Pages & Adjust Current Page (essential for correct offset)
    $total_pages = ($items_per_page > 0) ? ceil($total_records / $items_per_page) : 0;
    if ($page > $total_pages && $total_pages > 0) { $page = $total_pages; }
    if ($page < 1) { $page = 1; }
    $offset = ($page - 1) * $items_per_page; // Recalculate offset
    $limit_clause = " LIMIT $items_per_page OFFSET $offset";

    // Build and Execute Main Fetch Query for initial page load
    $voting_codes_sql = $select_clause . $from_clause . $where_clause . $group_by_clause . $order_by_clause . $limit_clause;
    $result_codes = $conn->query($voting_codes_sql);
    if ($result_codes === false) throw new mysqli_sql_exception("Error fetching voting codes: " . $conn->error, $conn->errno);

    // Fetch and process initial codes for display
    while ($row = $result_codes->fetch_assoc()) {
        $row['vote_count'] = (int)$row['vote_count'];
        $row['status'] = ($row['vote_count'] > 0) ? 'Used' : 'Available';
        $row['created_at_formatted'] = date('M d, Y H:i', strtotime($row['created_at']));
        $row['used_at_formatted'] = !empty($row['used_at']) ? date('M d, Y H:i', strtotime($row['used_at'])) : 'N/A';
        $row['is_deletable'] = ($row['status'] === 'Available');
        $initial_voting_codes[] = $row;
    }
    $result_codes->free_result();

    // 3. Fetch Stats (apply election filter if selected)
    $stats_sql = "SELECT COUNT(DISTINCT vc.id) as total_codes,
                         COUNT(DISTINCT CASE WHEN v.id IS NOT NULL THEN vc.id END) as used_codes
                  FROM voting_codes vc
                  LEFT JOIN votes v ON vc.id = v.voting_code_id";
    $stats_where = "";
    if ($selected_election_id) { $stats_where = " WHERE vc.election_id = $selected_election_id"; }
    $stats_sql .= $stats_where;

    $result_stats = $conn->query($stats_sql);
    if ($result_stats === false) throw new mysqli_sql_exception("Statistics query error: " . $conn->error, $conn->errno);
    $stats_row = $result_stats->fetch_assoc();
    $stats['total_codes'] = (int)($stats_row['total_codes'] ?? 0);
    $stats['used_codes'] = (int)($stats_row['used_codes'] ?? 0);
    $stats['unused_codes'] = $stats['total_codes'] - $stats['used_codes'];
    $result_stats->free_result();

} catch (mysqli_sql_exception $db_ex) { // Catch specific DB errors
    error_log("Voting Codes Page Load DB Error: " . $db_ex->getMessage() . " (Code: " . $db_ex->getCode() . ")");
    $page_error = "Could not load page data due to a database error.";
    // Reset data arrays
    $elections = []; $initial_voting_codes = [];
    $stats = ['total_codes' => 'N/A', 'used_codes' => 'N/A', 'unused_codes' => 'N/A'];
    $total_records = 0; $total_pages = 0; $page = 1;
} catch (Exception $e) { // Catch other general errors
    error_log("Voting Codes Page Load General Error: " . $e->getMessage());
    $page_error = "An unexpected error occurred while loading the page.";
    // Reset data arrays
    $elections = []; $initial_voting_codes = [];
    $stats = ['total_codes' => 'N/A', 'used_codes' => 'N/A', 'unused_codes' => 'N/A'];
    $total_records = 0; $total_pages = 0; $page = 1;
}

// Calculate display range AFTER potentially adjusting $page and getting $total_records
$start_record = ($total_records > 0) ? (($page - 1) * $items_per_page) + 1 : 0;
$end_record = ($total_records > 0) ? min($start_record + $items_per_page - 1, $total_records) : 0;

// --- Include HTML Header ---
require_once "includes/header.php"; // Outputs <!DOCTYPE html>, <head>, opening <body>, navbar, sidebar etc.
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<style nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
    /* --- Styles (Copied from previous response, unchanged) --- */
    :root { /* Define colors */
        --bs-primary-rgb: 13, 110, 253; --bs-success-rgb: 25, 135, 84; --bs-warning-rgb: 255, 193, 7; --bs-info-rgb: 13, 202, 240; --bs-danger-rgb: 220, 53, 69;
        --bs-light-rgb: 248, 249, 250; --bs-dark-rgb: 33, 37, 41; --bs-secondary-rgb: 108, 117, 125;
        --bs-border-color: #dee2e6; --bs-border-radius: .375rem; --bs-box-shadow-sm: 0 .125rem .25rem rgba(0,0,0,.075);
        --bs-font-sans-serif: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", "Liberation Sans", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
    }
    body { background-color: #f8f9fa; font-size: 0.95rem; color: #212529; font-family: var(--bs-font-sans-serif); }
    .container-fluid { padding: 1.5rem 2rem; max-width: 1600px;}
    .card { box-shadow: var(--bs-box-shadow-sm); border-color: var(--bs-border-color); border-radius: var(--bs-border-radius); }
    .card-header { background-color: #fff; font-weight: 600;} /* Lighter header */
    .card-footer { background-color: #f8f9fa; } /* Lighter footer */

    .stat-card { transition: transform 0.2s ease-out; border-left-width: 4px; border-radius: var(--bs-border-radius); }
    .stat-card:hover { transform: translateY(-4px); }
    .stat-icon { width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; border-radius: 50%; }
    .stat-card.border-left-primary { border-left-color: var(--bs-primary) !important; } .stat-card.border-left-primary .stat-icon { background-color: rgba(var(--bs-primary-rgb), 0.1); color: var(--bs-primary); }
    .stat-card.border-left-success { border-left-color: var(--bs-success) !important; } .stat-card.border-left-success .stat-icon { background-color: rgba(var(--bs-success-rgb), 0.1); color: var(--bs-success); }
    .stat-card.border-left-warning { border-left-color: var(--bs-warning) !important; } .stat-card.border-left-warning .stat-icon { background-color: rgba(var(--bs-warning-rgb), 0.1); color: var(--bs-warning); }
    .stat-card .display-6 { font-weight: 600; font-size: 1.75rem; }

    .controls-card .card-header { background-color: var(--bs-white); }
    .table > :not(caption) > * > * { padding: .75rem; vertical-align: middle; font-size: 0.9rem; }
    .table code { font-size: 0.95rem; color: var(--bs-dark); background-color: rgba(var(--bs-secondary-rgb), 0.1) !important; border: 1px solid rgba(var(--bs-secondary-rgb), 0.2); padding: .2em .4em; border-radius: .2rem;}
    .table .badge { font-size: 0.78rem; padding: .4em .65em; font-weight: 500;}
    .table .btn-sm { padding: 0.2rem 0.45rem; font-size: 0.75rem; }
    .table th .form-check-input { margin-top: 0; cursor: pointer; }
    .table td .form-check-input { cursor: pointer; }

    .pagination-sm .page-link { padding: 0.3rem 0.65rem; font-size: 0.8rem; }
    .pagination-sm .page-item.active .page-link { z-index: 1; background-color: #0d6efd; border-color: #0d6efd; }
    .pagination-sm .page-item.disabled .page-link { color: #6c757d; }

    .modal-header { border-bottom: none;}
    .modal-footer { border-top: 1px solid var(--bs-border-color); background-color: #f8f9fa; }
    .toast-container { z-index: 1100; }
    .notification-toast { min-width: 300px; border-radius: var(--bs-border-radius); box-shadow: 0 .5rem 1rem rgba(0,0,0,.15); padding: 0; border: none; background-clip: padding-box; }
    .notification-toast .notification-content { display: flex; align-items: center; padding: .75rem 1rem;}
    .notification-toast .toast-body { padding: 0; color: white; font-weight: 500; flex-grow: 1;}
    .notification-toast .notification-icon { font-size: 1.3rem; margin-right: .75rem; line-height: 1;}
    .notification-toast .btn-close { background: transparent; border: 0; padding: 0; opacity: 0.8; margin-left: auto; font-size: 1.2rem; line-height: 1; }
    .notification-toast .btn-close:hover { opacity: 1;}
    .notification-toast .btn-close-white { color: white; text-shadow: none; }
    .btn .spinner-border-sm { width: 1em; height: 1em; border-width: .2em; vertical-align: -0.125em;}
    .table .spinner-border { width: 3rem; height: 3rem;}
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2 class="mb-1">Manage Voting Codes</h2>
            <p class="text-muted mb-0">Generate and manage voting codes for elections</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
             <select class="form-select form-select-sm" style="width: auto; min-width: 180px;" id="electionFilter" aria-label="Filter by election">
                <option value="">Filter by Election</option> <option value="" <?php echo ($selected_election_id === null) ? 'selected' : ''; ?>>All Elections</option>
                <?php foreach ($elections as $election): ?>
                    <option value="<?php echo $election['id']; ?>" <?php echo ($selected_election_id == $election['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($election['title']); ?>
                    </option>
                <?php endforeach; ?>
                <?php if (empty($elections)): ?>
                    <option value="" disabled>No elections found</option>
                <?php endif; ?>
            </select>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#generateCodesModal" <?php echo empty($elections) ? 'disabled' : ''; ?>>
                <i class="bi bi-plus-circle me-1"></i> Generate Codes
            </button>
        </div>
    </div>

     <div id="feedbackPlaceholder" class="mb-3"></div>

     <?php if ($page_error): ?>
         <div class="alert alert-danger alert-dismissible fade show" role="alert">
             <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($page_error); ?>
             <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
         </div>
     <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-lg-4 col-md-6">
            <div class="card stat-card border-left-primary h-100 shadow-sm">
                <div class="card-body"> <div class="d-flex align-items-center">
                    <div class="stat-icon"> <i class="bi bi-key-fill fs-4"></i> </div>
                    <div class="ms-3"> <h6 class="card-title text-muted mb-1">Total Codes</h6> <p class="card-text display-6 mb-0"><?php echo ($stats['total_codes'] === 'N/A' ? 'N/A' : number_format($stats['total_codes'])); ?></p> </div>
                </div></div>
            </div>
        </div>
         <div class="col-lg-4 col-md-6">
            <div class="card stat-card border-left-success h-100 shadow-sm">
                <div class="card-body"> <div class="d-flex align-items-center">
                     <div class="stat-icon"> <i class="bi bi-check-circle-fill fs-4"></i> </div>
                    <div class="ms-3"> <h6 class="card-title text-muted mb-1">Used Codes</h6> <p class="card-text display-6 mb-0"><?php echo ($stats['used_codes'] === 'N/A' ? 'N/A' : number_format($stats['used_codes'])); ?></p> </div>
                 </div></div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="card stat-card border-left-warning h-100 shadow-sm">
                 <div class="card-body"> <div class="d-flex align-items-center">
                     <div class="stat-icon"> <i class="bi bi-hourglass-split fs-4"></i> </div>
                     <div class="ms-3"> <h6 class="card-title text-muted mb-1">Available Codes</h6> <p class="card-text display-6 mb-0"><?php echo ($stats['unused_codes'] === 'N/A' ? 'N/A' : number_format($stats['unused_codes'])); ?></p> </div>
                 </div></div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4 controls-card">
        <div class="card-header bg-white py-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex gap-2 align-items-center flex-wrap">
                     <div class="input-group input-group-sm" style="width: 250px;">
                         <input type="text" class="form-control" id="searchInput" placeholder="Search codes or elections..." value="<?php echo htmlspecialchars($search_query); ?>" aria-label="Search codes">
                         <button class="btn btn-outline-secondary" type="button" id="searchButton" title="Search"> <i class="bi bi-search"></i> </button>
                     </div>
                     <select class="form-select form-select-sm" id="itemsPerPageSelect" style="width: auto;" aria-label="Items per page">
                         <option value="10" <?php if($items_per_page == 10) echo 'selected'; ?>>10 per page</option>
                         <option value="25" <?php if($items_per_page == 25) echo 'selected'; ?>>25 per page</option>
                         <option value="50" <?php if($items_per_page == 50) echo 'selected'; ?>>50 per page</option>
                         <option value="100" <?php if($items_per_page == 100) echo 'selected'; ?>>100 per page</option>
                     </select>
                 </div>
                 <div class="d-flex gap-2 flex-wrap">
                     <button type="button" class="btn btn-sm btn-outline-secondary" id="printButton" title="Print current table view"> <i class="bi bi-printer"></i> Print </button>
                     <div class="dropdown">
                         <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="exportDropdown" data-bs-toggle="dropdown" aria-expanded="false"> <i class="bi bi-download"></i> Export </button>
                         <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="exportDropdown">
                             <li><button class="dropdown-item" type="button" id="exportPdfButton"><i class="bi bi-file-pdf me-2"></i>PDF</button></li>
                             <li><button class="dropdown-item" type="button" id="exportExcelButton"><i class="bi bi-file-excel me-2"></i>Excel</button></li>
                         </ul>
                     </div>
                     <button type="button" class="btn btn-sm btn-danger" id="deleteSelectedBtn" disabled title="Delete selected available codes">
                         <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
                         <i class="bi bi-trash-fill me-1"></i> Delete Selected (<span id="selectedCount">0</span>)
                    </button>
                 </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light py-3 border-bottom-0">
             <h5 class="mb-0 text-primary"><i class="bi bi-list-check me-2"></i>Voting Codes List</h5>
        </div>
        <div class="card-body p-0">
            <form id="codesListForm"> <input type="hidden" name="action" value="delete_bulk"> <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="votingCodesTable">
                        <thead class="table-light">
                            <tr>
                                <th width="40" class="text-center"> <input type="checkbox" class="form-check-input select-all" id="selectAllCheckbox" title="Select/Deselect All Deletable" aria-label="Select all deletable codes"> </th>
                                <th>Code</th>
                                <th>Election</th>
                                <th>Status</th>
                                <th>Used At</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                             <?php /* Initial data is rendered here by PHP */ ?>
                             <?php if (empty($initial_voting_codes) && !$page_error): ?>
                                 <tr><td colspan="6" class="text-center text-muted p-4"><i>No voting codes found matching the criteria.</i></td></tr>
                             <?php elseif ($page_error): ?>
                                  <tr><td colspan="6" class="text-center text-danger p-4"><i>Error loading data. <?php echo htmlspecialchars($page_error);?></i></td></tr>
                             <?php else: ?>
                                 <?php foreach ($initial_voting_codes as $code): ?>
                                      <tr id="code-row-<?php echo $code['id']; ?>">
                                          <td class="text-center"> <input type="checkbox" name="selected_codes[]" value="<?php echo $code['id']; ?>" class="form-check-input code-checkbox" <?php echo !$code['is_deletable'] ? 'disabled' : ''; ?> aria-label="Select code <?php echo htmlspecialchars($code['code']); ?>"> </td>
                                          <td><code class="bg-light px-2 py-1 rounded border"><?php echo htmlspecialchars($code['code']); ?></code></td>
                                          <td><?php echo htmlspecialchars($code['election_title'] ?? 'N/A'); ?> <?php $e_status = $code['election_status'] ?? ''; $e_badge = 'secondary'; if ($e_status === 'active') $e_badge = 'success'; elseif ($e_status === 'pending') $e_badge = 'warning text-dark'; ?> <?php if($e_status): ?> <span class="badge bg-<?php echo $e_badge; ?> ms-1"><?php echo htmlspecialchars(ucfirst($e_status)); ?></span> <?php endif; ?> </td>
                                          <td> <span class="badge bg-<?php echo $code['status'] === 'Used' ? 'danger' : 'info'; ?>"><?php echo $code['status']; ?></span> </td>
                                          <td><?php echo $code['status'] === 'Used' ? "<span class='text-danger small'>" . htmlspecialchars($code['used_at_formatted']) . "</span>" : "<span class='text-muted'>-</span>"; ?></td>
                                          <td class="text-center"> <button type="button" class="btn btn-sm btn-outline-danger delete-code-btn" data-code-id="<?php echo $code['id']; ?>" data-code-value="<?php echo htmlspecialchars($code['code']); ?>" title="Delete Code" <?php echo !$code['is_deletable'] ? 'disabled' : ''; ?>> <i class="bi bi-trash"></i> </button> </td>
                                      </tr>
                                 <?php endforeach; ?>
                             <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form> </div> <div class="card-footer bg-light py-2 px-3 border-top">
               <div id="paginationContainer">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                         <div class="text-muted small">
                              <?php if($total_records > 0): ?> Showing <?php echo $start_record; ?> to <?php echo $end_record; ?> of <?php echo number_format($total_records); ?> entries <?php else: ?> No entries found <?php endif; ?>
                         </div>
                         <nav aria-label="Voting code pagination">
                              <ul class="pagination pagination-sm mb-0">
                                   <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>"> <a class="page-link" href="#" data-page="<?php echo ($page - 1); ?>" aria-label="Previous">&laquo;</a> </li>
                                   <?php $max_links_display = 5; $start_page_loop = max(1, $page - floor($max_links_display / 2)); $end_page_loop = min($total_pages, $start_page_loop + $max_links_display - 1); if($end_page_loop - $start_page_loop + 1 < $max_links_display) { $start_page_loop = max(1, $end_page_loop - $max_links_display + 1); }
                                        if ($start_page_loop > 1) { echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; }
                                        for ($i = $start_page_loop; $i <= $end_page_loop; $i++): ?> <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>"> <a class="page-link" href="#" data-page="<?php echo $i; ?>"><?php echo $i; ?></a> </li> <?php endfor;
                                        if ($end_page_loop < $total_pages) { echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; } ?>
                                    <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>"> <a class="page-link" href="#" data-page="<?php echo ($page + 1); ?>" aria-label="Next">&raquo;</a> </li>
                              </ul>
                         </nav>
                    </div>
               </div> </div> </div> </div> <div class="modal fade" id="generateCodesModal" tabindex="-1" aria-labelledby="generateCodesModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered"> <div class="modal-content">
            <div class="modal-header border-0"> <h5 class="modal-title" id="generateCodesModalLabel"><i class="bi bi-plus-circle me-2"></i>Generate Voting Codes</h5> <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button> </div>
            <form id="generateCodesForm" novalidate> <div class="modal-body"> <div id="generateModalAlertPlaceholder"></div> <input type="hidden" name="action" value="create"> <div class="mb-3"> <label for="generate_election_id" class="form-label">Select Election*</label> <select class="form-select" name="election_id" id="generate_election_id" required> <option value="" selected disabled>-- Choose Election --</option> <?php foreach ($elections as $election): ?> <option value="<?php echo $election['id']; ?>" <?php echo ($selected_election_id == $election['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($election['title']); ?></option> <?php endforeach; ?> <?php if(empty($elections)): ?> <option value="" disabled>No elections available</option> <?php endif; ?> </select> <div class="invalid-feedback">Please select an election.</div> </div> <div class="mb-3"> <label for="generate_quantity" class="form-label">Number of Codes to Generate*</label> <input type="number" class="form-control" name="quantity" id="generate_quantity" min="1" max="1000" value="50" required> <div class="form-text">Enter a number between 1 and 1000.</div> <div class="invalid-feedback">Please enter a valid number (1-1000).</div> </div> </div> <div class="modal-footer border-0"> <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button> <button type="submit" class="btn btn-primary btn-sm" id="generateCodesBtn"><span class="spinner-border spinner-border-sm d-none me-1"></span><i class="bi bi-gear-fill me-1"></i>Generate Codes</button> </div> </form>
    </div></div>
</div>
<div class="modal fade" id="deleteSingleCodeConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered"> <div class="modal-content"> <div class="modal-header bg-danger text-white"><h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Delete</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div> <div class="modal-body text-center">Delete code <strong id="codeToDeleteValue"></strong>?<br><small class="text-danger">Action cannot be undone.</small><input type="hidden" id="deleteCodeIdSingle"></div> <div class="modal-footer justify-content-center border-0"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger btn-sm" id="confirmDeleteSingleCodeBtn"><span class="spinner-border spinner-border-sm d-none me-1"></span>Delete</button></div> </div></div>
</div>
<div class="modal fade" id="deleteBulkCodeConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"> <div class="modal-content"> <div class="modal-header bg-danger text-white"><h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Bulk Delete</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div> <div class="modal-body text-center">Delete the selected <strong id="bulkDeleteCodeCount">0</strong> code(s)?<br><small class="text-danger">Only 'Available' codes will be deleted.<br>Action cannot be undone.</small></div> <div class="modal-footer justify-content-center border-0"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger btn-sm" id="confirmDeleteBulkCodeBtn"><span class="spinner-border spinner-border-sm d-none me-1"></span>Delete Selected</button></div> </div></div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1100">
    <div id="notificationToast" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4500"> <div class="d-flex notification-content"> <div class="toast-body d-flex align-items-center"><span class="notification-icon me-2 fs-4"></span><span id="notificationMessage" class="fw-medium"></span></div> <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button> </div> </div>
</div>


<?php require_once "includes/footer.php"; // Assumes includes Bootstrap JS ?>

<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" integrity="sha512-qZvrmS2ekKPF2mSznTQsxqPgnpkI4DNTlrdUmTzrDgektczlKNRRhy5X5AAOnx5S09ydFYWWNSfcEqDTTHgtNA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" integrity="sha512-BNaRQnYJYiPSqHHDb58B0yaPfCu+Wgds8Gp/gU33kqBtgNS4tSPHuGibyoeqMV/TJlSKda6FXzoEyYGjTe+vXA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>


<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
document.addEventListener('DOMContentLoaded', function () {

    // --- Globals & State ---
    // Use values rendered by PHP for initial state
    let currentPage = <?php echo $page; ?>;
    let currentItemsPerPage = <?php echo $items_per_page; ?>;
    let currentSearchQuery = <?php echo json_encode($search_query); ?>;
    let currentElectionId = <?php echo json_encode($selected_election_id); ?>;
    let isLoading = false;
    // These will be updated by AJAX response
    let totalPages = <?php echo $total_pages; ?>;
    let totalRecords = <?php echo $total_records; ?>;

    // --- API Endpoint ---
    const AJAX_ENDPOINT = 'voting_codes_ajax.php'; // Point to the dedicated AJAX handler file

    // --- DOM References ---
    const tableBody = document.querySelector('#votingCodesTable tbody');
    const paginationContainer = document.getElementById('paginationContainer');
    const electionFilter = document.getElementById('electionFilter');
    const itemsPerPageSelect = document.getElementById('itemsPerPageSelect');
    const searchInput = document.getElementById('searchInput');
    const searchButton = document.getElementById('searchButton');
    const generateCodesForm = document.getElementById('generateCodesForm');
    const generateCodesBtn = document.getElementById('generateCodesBtn');
    const generateModalAlertPlaceholder = document.getElementById('generateModalAlertPlaceholder');
    const codesListForm = document.getElementById('codesListForm');
    const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
    const selectedCountSpan = document.getElementById('selectedCount');
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    const feedbackPlaceholder = document.getElementById('feedbackPlaceholder');

    // Modals & Confirmation Buttons
    const generateModal = getModalInstance('generateCodesModal');
    const deleteSingleModal = getModalInstance('deleteSingleCodeConfirmModal');
    const deleteBulkModal = getModalInstance('deleteBulkCodeConfirmModal');
    const confirmDeleteSingleCodeBtn = document.getElementById('confirmDeleteSingleCodeBtn');
    const confirmDeleteBulkCodeBtn = document.getElementById('confirmDeleteBulkCodeBtn');
    const codeToDeleteValueEl = document.getElementById('codeToDeleteValue');
    const deleteCodeIdSingleInput = document.getElementById('deleteCodeIdSingle');
    const bulkDeleteCodeCountEl = document.getElementById('bulkDeleteCodeCount');

     // --- Helper Functions (Toast, Button State, Modals, Escaping) ---
     const notificationToastEl = document.getElementById('notificationToast');
     const notificationToast = notificationToastEl ? bootstrap.Toast.getOrCreateInstance(notificationToastEl) : null;
     function showNotification(message, type = 'success') { /* ... implementation ... */
         const msgEl = document.getElementById('notificationMessage'); const iconEl = notificationToastEl?.querySelector('.notification-icon'); if (!notificationToast || !msgEl || !iconEl || !notificationToastEl) { console.warn('Toast elements not found'); return; } notificationToastEl.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-info', 'text-dark'); const bodyEl = notificationToastEl.querySelector('.toast-body'); const closeBtn = notificationToastEl.querySelector('.btn-close'); bodyEl?.classList.remove('text-dark'); closeBtn?.classList.remove('text-dark', 'btn-close-white'); closeBtn?.classList.add('btn-close-white'); iconEl.innerHTML = ''; message = String(message || 'Action completed.').substring(0, 200); msgEl.textContent = message; let iconClass = 'bi-check-circle-fill'; let bgClass = 'bg-success'; let isDarkText = false; if (type === 'danger') { iconClass = 'bi-x-octagon-fill'; bgClass = 'bg-danger'; } else if (type === 'warning') { iconClass = 'bi-exclamation-triangle-fill'; bgClass = 'bg-warning'; isDarkText = true; } else if (type === 'info') { iconClass = 'bi-info-circle-fill'; bgClass = 'bg-info'; } notificationToastEl.classList.add(bgClass); if (isDarkText) { bodyEl?.classList.add('text-dark'); closeBtn?.classList.remove('btn-close-white'); closeBtn?.classList.add('text-dark'); } iconEl.innerHTML = `<i class="bi ${iconClass}"></i>`; notificationToast.show();
     }
     function showModalAlert(placeholder, message, type = 'danger') { /* ... implementation ... */
           if(!placeholder) return; const alertClass = `alert-${type}`; const iconClass = type === 'danger' ? 'bi-exclamation-triangle-fill' : (type === 'warning' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill'); placeholder.innerHTML = `<div class="alert ${alertClass} alert-dismissible fade show d-flex align-items-center" role="alert" style="font-size: 0.9rem; padding: 0.75rem 1rem;"><i class="bi ${iconClass} me-2 fs-5"></i><div>${escapeHtml(message)}</div><button type="button" class="btn-close" style="padding: 0.75rem;" data-bs-dismiss="alert" aria-label="Close"></button></div>`;
      }
    function updateButtonState(button, isLoading, loadingText = 'Processing...') { /* ... implementation ... */
         if (!button) return; if (isLoading) { button.dataset.originalHtml = button.innerHTML; button.disabled = true; const spinner = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`; const icon = button.querySelector('i'); button.innerHTML = spinner + (icon ? icon.outerHTML : '') + ` ${escapeHtml(loadingText)}`; } else { button.disabled = false; button.innerHTML = button.dataset.originalHtml || 'Submit'; delete button.dataset.originalHtml; }
    }
    function getModalInstance(id) { const el = document.getElementById(id); return el ? bootstrap.Modal.getOrCreateInstance(el) : null; };
    function escapeHtml(unsafe) { if (unsafe === null || typeof unsafe === 'undefined') return ''; return String(unsafe).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;"); }
    function ucfirst(string) { if (!string) return ''; return string.charAt(0).toUpperCase() + string.slice(1); }
    function numberFormat(number) { return new Intl.NumberFormat().format(number); }


    // --- Core Function: Load Voting Codes via AJAX ---
    function loadCodes() {
        if (isLoading) return; isLoading = true;
        if(tableBody) tableBody.innerHTML = `<tr><td colspan="6" class="text-center p-5"><div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;"><span class="visually-hidden">Loading...</span></div></td></tr>`;
        if (paginationContainer) paginationContainer.innerHTML = ''; feedbackPlaceholder.innerHTML = ''; // Clear feedback

        const formData = new FormData();
        formData.append('action', 'fetch_codes');
        formData.append('page', currentPage);
        formData.append('records_per_page', currentItemsPerPage);
        if (currentSearchQuery) formData.append('search', currentSearchQuery);
        if (currentElectionId) formData.append('election_id', currentElectionId);

        fetch(AJAX_ENDPOINT, { /* Use defined endpoint */
             method: 'POST', body: formData, headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(response => response.json().then(data => ({ ok: response.ok, status: response.status, body: data })))
        .then(({ ok, status, body }) => {
            if (ok && body.success && body.data) {
                updateTable(body.data.codes || []);
                updatePagination(body.data);
                currentPage = body.data.current_page || 1; totalPages = body.data.total_pages || 0; totalRecords = body.data.total_records || 0;
                 // Update URL query parameters without reloading page (optional)
                 // updateUrlQueryParams();
            } else { throw new Error(body.message || `Failed to load codes (HTTP ${status})`); }
        })
        .catch(error => {
            console.error('Error loading codes:', error);
            if(tableBody) tableBody.innerHTML = `<tr><td colspan="6" class="text-center text-danger p-4">Error loading data: ${escapeHtml(error.message)}</td></tr>`;
            showNotification('Could not load voting codes.', 'danger');
        })
        .finally(() => { isLoading = false; updateDeleteButtonState(); });
    }

    // --- Function: Update Table Body ---
    function updateTable(codes) { /* ... implementation unchanged ... */
         if (!tableBody) return; tableBody.innerHTML = ''; if (codes.length === 0) { tableBody.innerHTML = `<tr><td colspan="6" class="text-center text-muted p-4"><i>No voting codes found matching the criteria.</i></td></tr>`; return; } codes.forEach(code => { const tr = document.createElement('tr'); tr.id = `code-row-${code.id}`; const isDeletable = code.is_deletable; const electionStatus = code.election_status || ''; let electionBadgeClass = 'secondary'; if (electionStatus === 'active') electionBadgeClass = 'success'; else if (electionStatus === 'pending') electionBadgeClass = 'warning text-dark'; tr.innerHTML = ` <td class="text-center"> <input type="checkbox" name="selected_codes[]" value="${code.id}" class="form-check-input code-checkbox" ${!isDeletable ? 'disabled' : ''} aria-label="Select code ${escapeHtml(code.code)}"> </td> <td><code class="bg-light px-2 py-1 rounded border">${escapeHtml(code.code)}</code></td> <td> ${escapeHtml(code.election_title || 'N/A')} ${electionStatus ? `<span class="badge bg-${electionBadgeClass} ms-1">${escapeHtml(ucfirst(electionStatus))}</span>` : ''} </td> <td> <span class="badge bg-${code.status === 'Used' ? 'danger' : 'info'}">${escapeHtml(code.status)}</span> </td> <td> ${code.status === 'Used' ? `<span class="text-danger small">${escapeHtml(code.used_at_formatted)}</span>` : `<span class="text-muted">-</span>`} </td> <td class="text-center"> <button type="button" class="btn btn-sm btn-outline-danger delete-code-btn" data-code-id="${code.id}" data-code-value="${escapeHtml(code.code)}" title="Delete Code" ${!isDeletable ? 'disabled' : ''}> <i class="bi bi-trash"></i> </button> </td> `; tableBody.appendChild(tr); }); if(selectAllCheckbox) { selectAllCheckbox.checked = false; selectAllCheckbox.indeterminate = false; } updateDeleteButtonState();
    }

     // --- Function: Update Pagination Controls ---
     function updatePagination(data) { /* ... implementation unchanged ... */
          if (!paginationContainer) return; paginationContainer.innerHTML = ''; const { total_pages = 0, total_records = 0, current_page = 1, records_per_page = 10 } = data; if (total_records === 0) { paginationContainer.innerHTML = '<div class="text-muted small">No entries found</div>'; return; } const start = ((current_page - 1) * records_per_page) + 1; const end = Math.min(start + records_per_page - 1, total_records); const paginationWrapper = document.createElement('div'); paginationWrapper.className = 'd-flex justify-content-between align-items-center flex-wrap gap-2'; const showingText = document.createElement('div'); showingText.className = 'text-muted small'; showingText.textContent = `Showing ${start} to ${end} of ${numberFormat(total_records)} entries`; paginationWrapper.appendChild(showingText); if (total_pages > 1) { const nav = document.createElement('nav'); nav.setAttribute('aria-label', 'Voting code pagination'); const ul = document.createElement('ul'); ul.className = 'pagination pagination-sm mb-0'; ul.appendChild(createPageItem(current_page - 1, '&laquo;', current_page <= 1)); const max_links_display = 5; let start_loop = Math.max(1, current_page - Math.floor(max_links_display / 2)); let end_loop = Math.min(total_pages, start_loop + max_links_display - 1); if (end_loop - start_loop + 1 < max_links_display) start_loop = Math.max(1, end_loop - max_links_display + 1); if (start_loop > 1) ul.appendChild(createPageItem(0, '...', true)); for (let i = start_loop; i <= end_loop; i++) ul.appendChild(createPageItem(i, i, false, current_page === i)); if (end_loop < total_pages) ul.appendChild(createPageItem(0, '...', true)); ul.appendChild(createPageItem(current_page + 1, '&raquo;', current_page >= total_pages)); nav.appendChild(ul); paginationWrapper.appendChild(nav); ul.querySelectorAll('.page-link[data-page]').forEach(link => { link.addEventListener('click', function(e) { e.preventDefault(); if (isLoading) return; const pageNum = parseInt(this.dataset.page, 10); if (pageNum && pageNum !== currentPage && pageNum > 0 && pageNum <= total_pages && !this.closest('.page-item').classList.contains('disabled')) { currentPage = pageNum; loadCodes(); } }); }); } paginationContainer.appendChild(paginationWrapper);
     }
     // Helper for updatePagination
     function createPageItem(pageNumber, text, isDisabled = false, isActive = false) { /* ... implementation unchanged ... */
          const li = document.createElement('li'); li.className = `page-item${isDisabled ? ' disabled' : ''}${isActive ? ' active' : ''}`; const a = document.createElement('a'); a.className = 'page-link'; a.href = '#'; if(pageNumber > 0 && !isDisabled) a.dataset.page = pageNumber; a.innerHTML = text; if (isDisabled && pageNumber <= 0) a.setAttribute('aria-hidden', 'true'); if (isActive) a.setAttribute('aria-current', 'page'); li.appendChild(a); return li;
     }


    // --- Event Listeners ---

    // Filter/Control Changes trigger AJAX load (No page reload)
    electionFilter?.addEventListener('change', () => { currentPage = 1; currentElectionId = electionFilter.value ? parseInt(electionFilter.value, 10) : null; updateUrlQueryParams(); loadCodes(); });
    itemsPerPageSelect?.addEventListener('change', () => { currentPage = 1; currentItemsPerPage = parseInt(itemsPerPageSelect.value, 10); updateUrlQueryParams(); loadCodes(); });
    searchButton?.addEventListener('click', () => { currentPage = 1; currentSearchQuery = searchInput.value.trim(); updateUrlQueryParams(); loadCodes(); });
    searchInput?.addEventListener('keyup', (e) => { if (e.key === 'Enter') { currentPage = 1; currentSearchQuery = searchInput.value.trim(); updateUrlQueryParams(); loadCodes(); } });

    // Generate Codes Modal Form Submit (AJAX)
    generateCodesForm?.addEventListener('submit', function(e) { /* ... implementation unchanged, uses AJAX_ENDPOINT ... */
        e.preventDefault(); generateModalAlertPlaceholder.innerHTML = ''; this.classList.remove('was-validated'); if (!this.checkValidity()) { e.stopPropagation(); this.classList.add('was-validated'); showModalAlert(generateModalAlertPlaceholder, 'Check fields.', 'warning'); return; } updateButtonState(generateCodesBtn, true, null, 'Generating...'); fetch(AJAX_ENDPOINT, { method: 'POST', body: new FormData(this), headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'} }).then(response => response.json().then(data => ({ ok: response.ok, body: data }))).then(({ ok, body }) => { if (ok && body.success) { showNotification(body.message || 'Codes generated!', 'success'); generateModal.hide(); currentPage = 1; loadCodes(); } else { throw new Error(body.message || 'Failed.'); } }).catch(error => { showModalAlert(generateModalAlertPlaceholder, error.message, 'danger'); }).finally(() => { updateButtonState(generateCodesBtn, false, '<i class="bi bi-gear-fill me-1"></i> Generate Codes'); });
    });

    // Select All / Checkbox Handling
    function updateDeleteButtonState() { /* ... implementation unchanged ... */
         const selectedCheckboxes = document.querySelectorAll('.code-checkbox:checked'); const count = selectedCheckboxes.length; const allDeletableCheckboxes = document.querySelectorAll('.code-checkbox:not(:disabled)'); const allDeletableCount = allDeletableCheckboxes.length; if(selectedCountSpan) selectedCountSpan.textContent = count; if(deleteSelectedBtn) deleteSelectedBtn.disabled = count === 0; if(selectAllCheckbox) { selectAllCheckbox.checked = allDeletableCount > 0 && count === allDeletableCount; selectAllCheckbox.indeterminate = count > 0 && count < allDeletableCount; selectAllCheckbox.disabled = allDeletableCount === 0; }
    }
    selectAllCheckbox?.addEventListener('change', function() { document.querySelectorAll('.code-checkbox:not(:disabled)').forEach(cb => cb.checked = this.checked); updateDeleteButtonState(); });
    tableBody?.addEventListener('change', e => { if (e.target.classList.contains('code-checkbox')) updateDeleteButtonState(); });
    updateDeleteButtonState(); // Initial call

    // Trigger Bulk Delete Modal
    deleteSelectedBtn?.addEventListener('click', function() { /* ... implementation unchanged ... */
         const selectedCount = document.querySelectorAll('.code-checkbox:checked').length; if (selectedCount > 0) { if(bulkDeleteCodeCountEl) bulkDeleteCodeCountEl.textContent = selectedCount; if(deleteBulkModal) deleteBulkModal.show(); } else { showNotification("Select 'Available' codes to delete.", "warning"); }
    });

    // Confirm Bulk Delete (AJAX)
    confirmDeleteBulkCodeBtn?.addEventListener('click', function() { /* ... implementation unchanged, uses AJAX_ENDPOINT ... */
         const selectedCheckboxes = document.querySelectorAll('.code-checkbox:checked'); const selectedIds = Array.from(selectedCheckboxes).map(cb => cb.value); if (selectedIds.length === 0) return; updateButtonState(this, true, null, 'Deleting...'); updateButtonState(deleteSelectedBtn, true); const formData = new FormData(); formData.append('action', 'delete_bulk'); selectedIds.forEach(id => formData.append('selected_codes[]', id)); fetch(AJAX_ENDPOINT, { method: 'POST', body: formData, headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'} }).then(response => response.json().then(data => ({ ok: response.ok, body: data }))).then(({ ok, body }) => { if (ok && body.success) { showNotification(body.message || 'Bulk delete successful!', 'success'); loadCodes(); } else { throw new Error(body.message || 'Failed.'); } }).catch(error => { showNotification(error.message, 'danger'); }).finally(() => { if(deleteBulkModal) deleteBulkModal.hide(); updateButtonState(confirmDeleteBulkCodeBtn, false, '<i class="bi bi-trash3-fill me-1"></i>Delete Selected'); updateButtonState(deleteSelectedBtn, false); });
    });

     // Delete Single Code (AJAX) - Listener attached to tbody using delegation
     tableBody?.addEventListener('click', function(event) { /* ... implementation unchanged ... */
          const deleteButton = event.target.closest('.delete-code-btn'); if (!deleteButton || deleteButton.disabled) return; const codeId = deleteButton.dataset.codeId; const codeValue = deleteButton.dataset.codeValue; if(codeToDeleteValueEl) codeToDeleteValueEl.textContent = codeValue || 'this code'; if(deleteCodeIdSingleInput) deleteCodeIdSingleInput.value = codeId; if(deleteSingleModal) deleteSingleModal.show();
     });

     // Confirm Single Delete (AJAX)
     confirmDeleteSingleCodeBtn?.addEventListener('click', function() { /* ... implementation unchanged, uses AJAX_ENDPOINT ... */
          const codeId = deleteCodeIdSingleInput.value; if (!codeId) return; updateButtonState(this, true, null, 'Deleting...'); const formData = new FormData(); formData.append('action', 'delete'); formData.append('id', codeId); fetch(AJAX_ENDPOINT, { method: 'POST', body: formData, headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'} }).then(response => response.json().then(data => ({ ok: response.ok, body: data }))).then(({ ok, body }) => { if (ok && body.success) { showNotification(body.message || 'Code deleted!', 'success'); loadCodes(); } else { throw new Error(body.message || 'Failed.'); } }).catch(error => { showNotification(error.message, 'danger'); }).finally(() => { if(deleteSingleModal) deleteSingleModal.hide(); updateButtonState(this, false, '<i class="bi bi-trash3-fill me-1"></i>Delete'); });
     });


     // --- Print/Export Event Listeners ---
     document.getElementById('printButton')?.addEventListener('click', printCodes);
     document.getElementById('exportPdfButton')?.addEventListener('click', () => { if (typeof jsPDF !== 'undefined' && typeof html2canvas !== 'undefined') exportToPDF(); else showNotification('PDF library not loaded.', 'warning'); });
     document.getElementById('exportExcelButton')?.addEventListener('click', () => { if (typeof XLSX !== 'undefined') exportToExcel(); else showNotification('Excel library not loaded.', 'warning'); });

     // --- Print/Export Functions ---
     // Assume implementations for printCodes, exportToPDF, exportToExcel are correct from previous
     function printCodes() { /* ... implementation ... */ const table = document.querySelector('#votingCodesTable'); if(!table) return; const printWindow = window.open('', '_blank', 'height=800,width=1100'); const tableClone = table.cloneNode(true); tableClone.querySelectorAll('tr').forEach(tr => { tr.cells[tr.cells.length - 1]?.remove(); tr.cells[0]?.remove(); }); printWindow.document.write(`<!DOCTYPE html><html><head><title>Voting Codes</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"><style>body{margin:20px;}@media print{.table{font-size:9pt;}.badge{border:1px solid #ccc!important;background:#fff!important;color:#000!important;}}</style></head><body><h2>Voting Codes</h2>${tableClone.outerHTML}<script>window.onload=function(){window.print();window.close();}<\/script></body></html>`); printWindow.document.close(); }
     function exportToPDF() { /* ... implementation ... */ showNotification('Generating PDF...', 'info'); const { jsPDF } = jspdf; const table = document.querySelector('#votingCodesTable'); if (!table) return; const tableClone = table.cloneNode(true); tableClone.querySelectorAll('tr').forEach(tr => { tr.cells[tr.cells.length - 1]?.remove(); tr.cells[0]?.remove(); }); const tempContainer = document.createElement('div'); tempContainer.style.position = 'absolute'; tempContainer.style.left = '-9999px'; tempContainer.appendChild(tableClone); document.body.appendChild(tempContainer); html2canvas(tableClone, { scale: 2, useCORS: true }).then(canvas => { const imgData = canvas.toDataURL('image/png'); const pdf = new jsPDF('l', 'mm', 'a4'); const pdfWidth = pdf.internal.pageSize.getWidth() - 20; const pdfHeight = pdf.internal.pageSize.getHeight() - 20; const imgProps = pdf.getImageProperties(imgData); const ratio = imgProps.width / imgProps.height; let imgHeight = pdfWidth / ratio; let imgWidth = pdfWidth; if (imgHeight > pdfHeight) { imgHeight = pdfHeight; imgWidth = pdfHeight * ratio; } pdf.addImage(imgData, 'PNG', 10, 10, imgWidth, imgHeight); pdf.save('voting_codes.pdf'); document.body.removeChild(tempContainer); }).catch(err => { console.error("PDF Export error:", err); showNotification('Failed to generate PDF.', 'danger'); document.body.removeChild(tempContainer); }); }
     function exportToExcel() { /* ... implementation ... */ const table = document.querySelector('#votingCodesTable'); if (!table) return; const tableClone = table.cloneNode(true); tableClone.querySelectorAll('tr').forEach(tr => { tr.cells[tr.cells.length - 1]?.remove(); tr.cells[0]?.remove(); }); const ws = XLSX.utils.table_to_sheet(tableClone); const wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, ws, 'Voting Codes'); XLSX.writeFile(wb, 'voting_codes.xlsx'); }

     // --- Modal Cleanup ---
     [generateModal, deleteSingleModal, deleteBulkModal].forEach(modal => { /* ... implementation ... */ const modalEl = modal ? modal._element : null; if(!modalEl) return; modalEl.addEventListener('hidden.bs.modal', function () { const form = this.querySelector('form'); if (form) { form.classList.remove('was-validated'); form.reset(); } this.querySelectorAll('#generateModalAlertPlaceholder').forEach(p => p.innerHTML = ''); const buttonsToReset = [ { id: 'generateCodesBtn', defaultHtml: '<i class="bi bi-gear-fill me-1"></i> Generate Codes' }, { id: 'confirmDeleteSingleCodeBtn', defaultHtml: '<i class="bi bi-trash3-fill me-1"></i>Delete' }, { id: 'confirmDeleteBulkCodeBtn', defaultHtml: '<i class="bi bi-trash3-fill me-1"></i>Delete Selected' }]; buttonsToReset.forEach(btnInfo => { const button = this.querySelector(`#${btnInfo.id}`); if(button) { updateButtonState(button, false, button.dataset.originalHtml || btnInfo.defaultHtml); delete button.dataset.originalHtml; } }); }); });

     // --- URL Update Helper (Optional) ---
     function updateUrlQueryParams() {
         const urlParams = new URLSearchParams(window.location.search);
         urlParams.set('page', currentPage);
         urlParams.set('items_per_page', currentItemsPerPage);
         if (currentElectionId) { urlParams.set('election_id', currentElectionId); } else { urlParams.delete('election_id'); }
         if (currentSearchQuery) { urlParams.set('search', currentSearchQuery); } else { urlParams.delete('search'); }
         // Use history.pushState to update URL without reloading
         const newUrl = window.location.pathname + '?' + urlParams.toString();
         window.history.pushState({path: newUrl}, '', newUrl);
     }

    // --- Initial State Setup ---
    // Initial data is rendered by PHP. JS will handle updates from here.
    // Add listeners to pagination links rendered initially by PHP
    paginationContainer?.querySelectorAll('.page-link[data-page]').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            if (isLoading) return;
            const pageNum = parseInt(this.dataset.page, 10);
            if (pageNum && pageNum !== currentPage && !this.closest('.page-item').classList.contains('disabled')) {
                 currentPage = pageNum;
                 updateUrlQueryParams(); // Update URL when page changes
                 loadCodes();
            }
        });
    });


}); // End DOMContentLoaded
</script>

</body>
</html>