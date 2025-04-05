<?php
session_start();
require_once "../config/database.php";
require_once "includes/header.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: /school_voting/admin/login.php");
    exit();
}

// --- Initial Fetch for Filters & Basic Info ---
$elections = [];
$positions_for_filter = []; // Positions for the filter dropdown
$candidates = [];
$stats = ['total_candidates' => 0, 'total_positions' => 0, 'total_votes' => 0];
$fetch_error = null;
$selected_election_id = isset($_GET['election_id']) ? (int)$_GET['election_id'] : null;

// Get all elections for the dropdown
$elections_sql = "SELECT * FROM elections ORDER BY title";
$result = mysqli_query($conn, $elections_sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $elections[] = $row;
    }
} else {
    error_log("Error fetching elections: " . mysqli_error($conn));
}

// Pagination settings
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$items_per_page = isset($_GET['items_per_page']) ? (int)$_GET['items_per_page'] : 10;
$offset = ($page - 1) * $items_per_page;

// Get total records for pagination
$count_sql = "SELECT COUNT(*) as total FROM voting_codes vc WHERE 1=1";
if ($selected_election_id) {
    $count_sql .= " AND vc.election_id = " . intval($selected_election_id);
}
$count_result = mysqli_query($conn, $count_sql);
if ($count_result) {
    $row = mysqli_fetch_assoc($count_result);
    $total_records = (int)$row['total'];
} else {
    error_log("Count query error: " . mysqli_error($conn));
    $total_records = 0;
}
$total_pages = ceil($total_records / $items_per_page);
$page = max(1, min($page, $total_pages));
$start_record = (($page - 1) * $items_per_page) + 1;
$end_record = min($start_record + $items_per_page - 1, $total_records);
error_log("Pagination Info: Page=$page, Items per page=$items_per_page, Total records=$total_records");

// Build main query for voting codes with pagination
$voting_codes_sql = "SELECT 
    vc.*, 
    e.title as election_title,
    e.status as election_status,
    v.created_at as used_at,
    (SELECT COUNT(*) FROM votes WHERE voting_code_id = vc.id) as vote_count,
    CASE 
        WHEN (SELECT COUNT(*) FROM votes WHERE voting_code_id = vc.id) > 0 THEN 'Used'
        ELSE 'Available'
    END as status
FROM voting_codes vc
LEFT JOIN elections e ON vc.election_id = e.id
LEFT JOIN votes v ON vc.id = v.voting_code_id";

if ($selected_election_id) {
    $voting_codes_sql .= " WHERE vc.election_id = " . intval($selected_election_id);
}

$voting_codes_sql .= " GROUP BY vc.id
ORDER BY vc.created_at DESC
LIMIT " . intval($items_per_page) . " OFFSET " . intval($offset);

$voting_codes = [];
$result = mysqli_query($conn, $voting_codes_sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $voting_codes[] = $row;
    }
    error_log("Query executed successfully. Number of records returned: " . count($voting_codes));
} else {
    error_log("Query execution error: " . mysqli_error($conn));
    $error = "Error loading voting codes: " . mysqli_error($conn);
    $voting_codes = [];
}

// Get statistics for voting codes
$stats_sql = "SELECT 
    COUNT(DISTINCT vc.id) as total_codes,
    COUNT(DISTINCT CASE WHEN v.id IS NOT NULL THEN vc.id END) as used_codes
FROM voting_codes vc
LEFT JOIN votes v ON vc.id = v.voting_code_id";
if ($selected_election_id) {
    $stats_sql .= " WHERE vc.election_id = " . intval($selected_election_id);
}
$result_stats = mysqli_query($conn, $stats_sql);
if ($result_stats) {
    $stats = mysqli_fetch_assoc($result_stats);
    $stats['unused_codes'] = $stats['total_codes'] - ($stats['used_codes'] ?? 0);
    error_log("Statistics Query: " . $stats_sql);
    error_log("Statistics Results: " . print_r($stats, true));
} else {
    error_log("Statistics error: " . mysqli_error($conn));
    $stats = [
        'total_codes' => 0,
        'used_codes' => 0,
        'unused_codes' => 0
    ];
}

// Calculate percentages
$used_percentage = $stats['total_codes'] > 0 ? round(($stats['used_codes'] / $stats['total_codes']) * 100, 1) : 0;
$unused_percentage = $stats['total_codes'] > 0 ? round(($stats['unused_codes'] / $stats['total_codes']) * 100, 1) : 0;
?>


<div class="container-fluid">
    <!-- Header Section -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">Manage Voting Codes</h2>
            <p class="text-muted mb-0">Generate and manage voting codes for elections</p>
        </div>
        <div class="d-flex gap-2">
            <select class="form-select form-select-sm" style="width: 200px;" id="electionSelect" onchange="filterByElection(this.value)">
                <option value="">All Elections</option>
                <?php foreach ($elections as $election): ?>
                    <option value="<?php echo $election['id']; ?>" <?php echo $selected_election_id == $election['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($election['title']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#generateCodesModal">
                <i class="bi bi-plus-circle me-1"></i> Generate New Codes
            </button>
        </div>
    </div>

    <?php if (isset($success)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo $success; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card stat-card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-primary bg-opacity-10 rounded-circle p-3 me-3">
                            <i class="bi bi-key-fill text-primary fs-4"></i>
                        </div>
                        <div>
                            <h6 class="card-title text-muted mb-1">Total Voting Codes</h6>
                            <p class="card-text display-6 mb-0"><?php echo number_format($stats['total_codes']); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-success bg-opacity-10 rounded-circle p-3 me-3">
                            <i class="bi bi-check-circle-fill text-success fs-4"></i>
                        </div>
                        <div>
                            <h6 class="card-title text-muted mb-1">Used Codes</h6>
                            <p class="card-text display-6 mb-0"><?php echo number_format($stats['used_codes']); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-warning bg-opacity-10 rounded-circle p-3 me-3">
                            <i class="bi bi-hourglass-split text-warning fs-4"></i>
                        </div>
                        <div>
                            <h6 class="card-title text-muted mb-1">Codes Remaining</h6>
                            <p class="card-text display-6 mb-0"><?php echo number_format($stats['unused_codes']); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Controls Card -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex gap-2 align-items-center">
                    <div class="input-group input-group-sm" style="width: 250px;">
                        <input type="text" class="form-control" id="searchInput" placeholder="Search codes...">
                        <button class="btn btn-outline-secondary" type="button" onclick="searchCodes()">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                    <select class="form-select form-select-sm" id="itemsPerPage" style="width: auto;" onchange="changeItemsPerPage(this.value)">
                        <option value="10">10 per page</option>
                        <option value="25">25 per page</option>
                        <option value="50">50 per page</option>
                        <option value="100">100 per page</option>
                    </select>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-secondary" onclick="printCodes()">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-primary dropdown-toggle" type="button" id="exportDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-download"></i> Export
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="exportDropdown">
                            <li><button class="dropdown-item" type="button" onclick="exportToPDF()">
                                <i class="bi bi-file-pdf"></i> PDF
                            </button></li>
                            <li><button class="dropdown-item" type="button" onclick="exportToExcel()">
                                <i class="bi bi-file-excel"></i> Excel
                            </button></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Voting Codes Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Voting Codes List</h5>
            </div>
        </div>
        <div class="card-body">
            <form method="post" id="deleteForm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th width="40">
                                    <input type="checkbox" class="form-check-input select-all">
                                </th>
                                <th>Code</th>
                                <th>Election</th>
                                <th>Status</th>
                                <th>Used At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($voting_codes as $code): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="selected_codes[]" value="<?php echo $code['id']; ?>" class="form-check-input code-checkbox">
                                    </td>
                                    <td>
                                        <code class="bg-light px-2 py-1 rounded"><?php echo htmlspecialchars($code['code']); ?></code>
                                    </td>
                                    <td>
                                        <span class="badge bg-success"><?php echo htmlspecialchars($code['election_status']); ?></span>
                                        <?php echo htmlspecialchars($code['election_title'] ?? 'Unassigned'); ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$code['vote_count'] > 0): ?>
                                            <span class="badge bg-danger">Used</span>
                                        <?php else: ?>
                                            <span class="badge bg-info">Available</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$code['vote_count'] > 0): ?>
                                            <span class="text-danger">
                                                <?php echo date('M d, Y H:i', strtotime($code['used_at'])); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteCode(<?php echo $code['id']; ?>)" <?php echo (int)$code['vote_count'] > 0 ? 'disabled' : ''; ?>>
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="text-end mt-3">
                    <button type="submit" name="delete_codes" class="btn btn-danger btn-sm" onclick="return confirmDelete()">
                        <i class="bi bi-trash"></i> Delete Selected
                    </button>
                </div>
            </form>
            <!-- Pagination -->
            <div class="d-flex justify-content-between align-items-center mt-4">
                <div class="text-muted">
                    Showing <?php echo $start_record; ?> to <?php echo $end_record; ?> of <?php echo $total_records; ?> entries
                </div>
                <nav aria-label="Page navigation">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo ($page - 1); ?>&items_per_page=<?php echo $items_per_page; ?><?php echo $selected_election_id ? '&election_id=' . $selected_election_id : ''; ?>">Previous</a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&items_per_page=<?php echo $items_per_page; ?><?php echo $selected_election_id ? '&election_id=' . $selected_election_id : ''; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo ($page + 1); ?>&items_per_page=<?php echo $items_per_page; ?><?php echo $selected_election_id ? '&election_id=' . $selected_election_id : ''; ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>
    </div>
</div>

<!-- Generate Codes Modal -->
<div class="modal fade" id="generateCodesModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title">Generate Voting Codes</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    
                    <div class="mb-4">
                        <label class="form-label">Election</label>
                        <select class="form-select form-select-sm" name="election_id" required>
                            <option value="">Select Election</option>
                            <?php foreach ($elections as $election): ?>
                                <option value="<?php echo $election['id']; ?>">
                                    <?php echo htmlspecialchars($election['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label">Number of Codes</label>
                        <input type="number" class="form-control" name="quantity" min="1" max="100" value="10" required>
                        <div class="form-text">Maximum 100 codes can be generated at a time</div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-lg"></i> Generate Codes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.stat-card {
    transition: transform 0.2s;
}
.stat-card:hover {
    transform: translateY(-5px);
}
.stat-icon {
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.table > :not(caption) > * > * {
    padding: 1rem;
}
code {
    font-size: 0.9rem;
    color: #666;
}
</style>

<script nonce="<?php echo $_SESSION['csp_nonce']; ?>">
document.querySelector('.select-all').addEventListener('change', function() {
    document.querySelectorAll('.code-checkbox').forEach(checkbox => {
        checkbox.checked = this.checked;
    });
});

// Add these variables for pagination and search
let currentPage = 1;
let itemsPerPage = 10;
let searchQuery = '';

// Function to load codes with pagination and search
function loadCodes() {
    const data = new FormData();
    data.append('action', 'fetch_codes');
    data.append('page', currentPage);
    data.append('items_per_page', itemsPerPage);
    data.append('search', searchQuery);

    fetch('voting_codes_ajax.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: data
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateTable(data.data.codes);
            updatePagination(data.data.total_pages, data.data.total_records);
        }
    })
    .catch(error => console.error('Error:', error));
}

// Function to update table content
function updateTable(codes) {
    const tbody = document.querySelector('tbody');
    tbody.innerHTML = '';

    codes.forEach(code => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>
                <input type="checkbox" name="selected_codes[]" value="${code.id}" class="form-check-input code-checkbox">
            </td>
            <td>
                <code class="bg-light px-2 py-1 rounded">${code.code}</code>
            </td>
            <td>
                <span class="badge bg-success">${code.election_status || 'Active'}</span>
                ${code.election_title || 'Unassigned'}
            </td>
            <td>
                <span class="badge bg-${code.vote_count > 0 ? 'danger' : 'info'}">
                    ${code.vote_count > 0 ? 'Used' : 'Available'}
                </span>
            </td>
            <td>
                ${code.vote_count > 0 ? 
                    `<span class="text-danger">${new Date(code.used_at).toLocaleString()}</span>` : 
                    '<span class="text-muted">-</span>'}
            </td>
            <td>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteCode(${code.id})">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

// Function to update pagination
function updatePagination(totalPages, totalRecords) {
    const paginationContainer = document.createElement('div');
    paginationContainer.className = 'd-flex justify-content-between align-items-center mt-3';
    
    // Showing entries text
    const showingText = document.createElement('div');
    showingText.className = 'text-muted';
    const start = (currentPage - 1) * itemsPerPage + 1;
    const end = Math.min(currentPage * itemsPerPage, totalRecords);
    showingText.textContent = `Showing ${start} to ${end} of ${totalRecords} entries`;
    
    // Pagination controls
    const pagination = document.createElement('nav');
    pagination.innerHTML = `
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="changePage(${currentPage - 1})">&laquo;</a>
            </li>
    `;
    
    for (let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
            pagination.querySelector('ul').innerHTML += `
                <li class="page-item ${currentPage === i ? 'active' : ''}">
                    <a class="page-link" href="#" onclick="changePage(${i})">${i}</a>
                </li>
            `;
        } else if (i === currentPage - 3 || i === currentPage + 3) {
            pagination.querySelector('ul').innerHTML += `
                <li class="page-item disabled">
                    <a class="page-link" href="#">...</a>
                </li>
            `;
        }
    }
    
    pagination.querySelector('ul').innerHTML += `
        <li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
            <a class="page-link" href="#" onclick="changePage(${currentPage + 1})">&raquo;</a>
        </li>
    `;
    
    paginationContainer.appendChild(showingText);
    paginationContainer.appendChild(pagination);
    
    // Replace or append pagination
    const existingPagination = document.querySelector('.card-body > .d-flex.justify-content-between');
    if (existingPagination) {
        existingPagination.replaceWith(paginationContainer);
    } else {
        document.querySelector('.card-body').appendChild(paginationContainer);
    }
}

// Function to change page
function changePage(page) {
    currentPage = page;
    loadCodes();
}

// Function to change items per page
function changeItemsPerPage(value) {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('items_per_page', value);
    urlParams.set('page', '1'); // Reset to first page when changing items per page
    
    // Preserve election_id if it exists
    const electionId = urlParams.get('election_id');
    if (electionId) {
        urlParams.set('election_id', electionId);
    }
    
    window.location.href = 'voting_codes.php?' + urlParams.toString();
}

// Function to handle search
function searchCodes() {
    searchQuery = document.getElementById('searchInput').value.trim();
    currentPage = 1;
    loadCodes();
}

// Add event listener for search input
document.getElementById('searchInput').addEventListener('keyup', function(event) {
    if (event.key === 'Enter') {
        searchCodes();
    }
});

// Print functionality
function printCodes() {
    const printWindow = window.open('', '_blank');
    const table = document.querySelector('table').cloneNode(true);
    
    // Remove action column and checkboxes
    table.querySelectorAll('tr').forEach(tr => {
        tr.deleteCell(-1); // Remove last cell (Actions)
        tr.deleteCell(0);  // Remove first cell (Checkbox)
    });
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Voting Codes</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
            <style>
                @media print {
                    .table { width: 100%; margin-bottom: 1rem; }
                    .badge { border: 1px solid #000; }
                }
            </style>
        </head>
        <body class="p-4">
            <h2 class="mb-4">Voting Codes List</h2>
            ${table.outerHTML}
        </body>
        </html>
    `);
    
    printWindow.document.close();
    printWindow.focus();
    
    printWindow.onload = function() {
        printWindow.print();
        printWindow.close();
    };
}

// Export to PDF functionality
function exportToPDF() {
    const element = document.querySelector('table');
    html2canvas(element).then(canvas => {
        const imgData = canvas.toDataURL('image/png');
        const pdf = new jsPDF('l', 'mm', 'a4');
        const imgProps = pdf.getImageProperties(imgData);
        const pdfWidth = pdf.internal.pageSize.getWidth();
        const pdfHeight = (imgProps.height * pdfWidth) / imgProps.width;
        
        pdf.addImage(imgData, 'PNG', 0, 0, pdfWidth, pdfHeight);
        pdf.save('voting_codes.pdf');
    });
}

// Export to Excel functionality
function exportToExcel() {
    const table = document.querySelector('table');
    const rows = Array.from(table.querySelectorAll('tr'));
    
    const data = rows.map(row => {
        const cells = Array.from(row.querySelectorAll('th, td'));
        return cells.map(cell => cell.textContent.trim());
    });
    
    // Remove checkbox and actions columns
    data.forEach(row => {
        row.pop(); // Remove actions column
        row.shift(); // Remove checkbox column
    });
    
    const ws = XLSX.utils.aoa_to_sheet(data);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Voting Codes');
    XLSX.writeFile(wb, 'voting_codes.xlsx');
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    loadCodes();
});

function filterByElection(electionId) {
    const urlParams = new URLSearchParams(window.location.search);
    
    // Preserve items_per_page if it exists
    const itemsPerPage = urlParams.get('items_per_page') || '10';
    urlParams.set('items_per_page', itemsPerPage);
    
    if (electionId) {
        urlParams.set('election_id', electionId);
    } else {
        urlParams.delete('election_id');
    }
    
    urlParams.set('page', '1'); // Reset to first page when filtering
    window.location.href = 'voting_codes.php?' + urlParams.toString();
}

function confirmDelete() {
    const selectedCodes = document.querySelectorAll('.code-checkbox:checked');
    if (selectedCodes.length === 0) {
        alert('Please select at least one voting code to delete.');
        return false;
    }
    return confirm('Are you sure you want to delete the selected voting codes?');
}
</script>

<!-- Add required libraries -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<?php require_once "includes/footer.php"; ?> 