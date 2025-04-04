<?php
require_once "includes/init.php";
require_once "includes/database.php";
require_once "includes/session.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: /school_voting/admin/login.php");
    exit();
}

require_once "includes/header.php";

// Get all elections
$elections_sql = "SELECT * FROM elections ORDER BY title";
$elections = $conn->query($elections_sql)->fetchAll(PDO::FETCH_ASSOC);

// Get selected election ID from URL
$selected_election_id = isset($_GET['election_id']) ? (int)$_GET['election_id'] : null;

// Get election results
$results_sql = "SELECT 
    e.id as election_id,
    e.title as election_title,
    p.id as position_id,
    p.title as position_title,
    c.id as candidate_id,
    c.name as candidate_name,
    c.photo as candidate_photo,
    COUNT(v.id) as vote_count
FROM elections e
LEFT JOIN positions p ON e.id = p.election_id
LEFT JOIN candidates c ON p.id = c.position_id
LEFT JOIN votes v ON c.id = v.candidate_id
WHERE 1=1";

$params = [];

if ($selected_election_id) {
    $results_sql .= " AND e.id = ?";
    $params[] = $selected_election_id;
}

$results_sql .= " GROUP BY e.id, e.title, p.id, p.title, c.id, c.name, c.photo
                 ORDER BY e.title, p.title, vote_count DESC";

$stmt = $conn->prepare($results_sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats_sql = "SELECT 
    (SELECT COUNT(*) FROM voting_codes WHERE 1=1 " . ($selected_election_id ? "AND election_id = ?" : "") . ") as total_codes,
    (SELECT COUNT(*) FROM votes v 
     INNER JOIN candidates c ON v.candidate_id = c.id 
     INNER JOIN positions p ON c.position_id = p.id 
     WHERE 1=1 " . ($selected_election_id ? "AND p.election_id = ?" : "") . ") as total_votes,
    (SELECT COUNT(*) FROM voting_codes 
     WHERE is_used = 1 " . ($selected_election_id ? "AND election_id = ?" : "") . ") as used_codes";

$stats_params = [];
if ($selected_election_id) {
    $stats_params = [$selected_election_id, $selected_election_id, $selected_election_id];
}

$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->execute($stats_params);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Calculate voter turnout
$stats['voter_turnout'] = $stats['total_codes'] > 0 ? 
    round(($stats['used_codes'] / $stats['total_codes']) * 100, 1) : 0;

// Add debug logging
error_log("Statistics Query: " . $stats_sql);
error_log("Statistics Params: " . print_r($stats_params, true));
error_log("Statistics Results: " . print_r($stats, true));

// Add animation to statistics cards
?>
<script nonce="<?php echo $_SESSION['csp_nonce']; ?>">
document.addEventListener('DOMContentLoaded', function() {
    const options = {
        duration: 2,
        separator: ',',
        decimal: '.',
        useEasing: true,
        useGrouping: true
    };

    // Create and start the counters
    new CountUp('totalCodes', <?php echo (int)$stats['total_codes']; ?>, options).start();
    new CountUp('totalVotes', <?php echo (int)$stats['total_votes']; ?>, options).start();
    new CountUp('usedCodes', <?php echo (int)$stats['used_codes']; ?>, options).start();
    new CountUp('voterTurnout', <?php echo (float)$stats['voter_turnout']; ?>, {
        ...options,
        decimals: 1,
        suffix: '%'
    }).start();
});
</script>

<style>
    /* Statistics Cards */
    .stats-card {
        transition: all 0.3s ease-in-out;
    }

    .stats-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
    }

    .stats-card .icon-container {
        transition: all 0.3s ease;
    }

    .stats-card:hover .icon-container {
        transform: scale(1.1) rotate(5deg);
    }

    .stats-card:hover .text-gray-300 {
        color: inherit !important;
    }

    .stats-card.border-left-primary:hover { background: linear-gradient(45deg, rgba(78,115,223,0.1) 0%, rgba(255,255,255,1) 100%); }
    .stats-card.border-left-success:hover { background: linear-gradient(45deg, rgba(28,200,138,0.1) 0%, rgba(255,255,255,1) 100%); }
    .stats-card.border-left-info:hover { background: linear-gradient(45deg, rgba(54,185,204,0.1) 0%, rgba(255,255,255,1) 100%); }
    .stats-card.border-left-warning:hover { background: linear-gradient(45deg, rgba(246,194,62,0.1) 0%, rgba(255,255,255,1) 100%); }

    .stats-card.border-left-primary:hover .text-gray-300 { color: #4e73df !important; }
    .stats-card.border-left-success:hover .text-gray-300 { color: #1cc88a !important; }
    .stats-card.border-left-info:hover .text-gray-300 { color: #36b9cc !important; }
    .stats-card.border-left-warning:hover .text-gray-300 { color: #f6c23e !important; }

    .stats-card .h5 {
        transition: all 0.3s ease;
    }

    .stats-card:hover .h5 {
        transform: scale(1.1);
    }

    /* Regular cards and other styles */
    .card {
        position: relative;
        background: linear-gradient(45deg, var(--bs-white) 0%, var(--bs-light) 100%);
    }

    .border-left-primary { border-left: 4px solid #4e73df !important; }
    .border-left-success { border-left: 4px solid #1cc88a !important; }
    .border-left-info { border-left: 4px solid #36b9cc !important; }
    .border-left-warning { border-left: 4px solid #f6c23e !important; }

    .text-gray-300 { color: #dddfeb !important; }
    .text-gray-800 { color: #5a5c69 !important; }

    /* Progress bar */
    .progress {
        height: 10px;
        border-radius: 5px;
        background-color: #f8f9fc;
    }

    .progress-bar {
        transition: width 1.5s ease-in-out;
        background-color: #4e73df;
    }

    /* Candidate results */
    .candidate-info {
        display: flex;
        align-items: center;
    }

    .candidate-photo {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        object-fit: cover;
    }

    /* Icons */
    .pulse-icon {
        color: #dddfeb;
    }

    .card.border-left-primary .pulse-icon { color: #4e73df; }
    .card.border-left-success .pulse-icon { color: #1cc88a; }
    .card.border-left-info .pulse-icon { color: #36b9cc; }
    .card.border-left-warning .pulse-icon { color: #f6c23e; }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0 text-gray-800">Election Results</h1>
        <div class="d-flex align-items-center gap-2">
            <div class="input-group input-group-sm" style="width: 250px;">
                <select class="form-select form-select-sm" id="electionSelect" onchange="filterResults(this.value)">
                    <option value="">All Elections</option>
                    <?php foreach ($elections as $election): ?>
                        <option value="<?php echo $election['id']; ?>" <?php echo $selected_election_id == $election['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($election['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2 stats-card">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                <?php echo $selected_election_id ? 'Election Voting Codes' : 'Total Voting Codes'; ?></div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="totalCodes">0</div>
                        </div>
                        <div class="col-auto">
                            <div class="icon-container">
                                <i class="bi bi-key fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2 stats-card">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                <?php echo $selected_election_id ? 'Election Votes Cast' : 'Total Votes Cast'; ?></div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="totalVotes">0</div>
                        </div>
                        <div class="col-auto">
                            <div class="icon-container">
                                <i class="bi bi-check-circle fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2 stats-card">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                <?php echo $selected_election_id ? 'Election Used Codes' : 'Total Used Codes'; ?></div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="usedCodes">0</div>
                        </div>
                        <div class="col-auto">
                            <div class="icon-container">
                                <i class="bi bi-person-check fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2 stats-card">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                <?php echo $selected_election_id ? 'Election Voter Turnout' : 'Overall Voter Turnout'; ?></div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <span id="voterTurnout">0</span>%
                            </div>
                        </div>
                        <div class="col-auto">
                            <div class="icon-container">
                                <i class="bi bi-graph-up fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Search and Controls Card -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex gap-2 align-items-center">
                    <div class="input-group input-group-sm" style="width: 250px;">
                        <input type="text" class="form-control" id="searchInput" placeholder="Search results...">
                        <button class="btn btn-outline-secondary" type="button" onclick="searchResults()">
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
                    <button type="button" class="btn btn-sm btn-secondary" onclick="printResults()">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-primary dropdown-toggle" type="button" id="exportDropdownInline" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-download"></i> Export
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="exportDropdownInline">
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

    <!-- Results Table -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <?php 
                if ($selected_election_id) {
                    $selected_election = array_filter($elections, function($e) use ($selected_election_id) {
                        return $e['id'] == $selected_election_id;
                    });
                    $selected_election = reset($selected_election);
                    echo $selected_election ? htmlspecialchars($selected_election['title']) . ' Results' : 'Election Results';
                } else {
                    echo 'All Elections Results';
                }
                ?>
            </h6>
        </div>
        <div class="card-body">
            <?php if (empty($results)): ?>
                <div class="text-center py-4">
                    <p class="text-muted mb-0">No election results found</p>
                </div>
            <?php else: ?>
                <?php
                $current_election = null;
                $current_position = null;
                
                foreach ($results as $result):
                    // Only show election header if showing all elections
                    if (!$selected_election_id && $current_election !== $result['election_id']):
                        if ($current_election !== null):
                            echo '</div></div>'; // Close previous election
                        endif;
                        $current_election = $result['election_id'];
                        echo '<div class="mb-4">';
                        echo '<h4 class="mb-3">' . htmlspecialchars($result['election_title'] ?? '') . '</h4>';
                    endif;
                    
                    if ($current_position !== $result['position_id']):
                        if ($current_position !== null):
                            echo '</div>'; // Close previous position
                        endif;
                        $current_position = $result['position_id'];
                        echo '<div class="mb-4">';
                        echo '<h5 class="mb-3">' . htmlspecialchars($result['position_title'] ?? '') . '</h5>';
                    endif;
                    
                    // Calculate percentage
                    $position_votes = array_filter($results, function($r) use ($result) {
                        return $r['position_id'] === $result['position_id'];
                    });
                    $total_votes = array_sum(array_column($position_votes, 'vote_count'));
                    $percentage = $total_votes > 0 ? round(($result['vote_count'] / $total_votes) * 100, 1) : 0;
                    ?>
                    
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="d-flex align-items-center">
                                <?php if (!empty($result['candidate_photo'])): 
                                    $image_path = "../" . htmlspecialchars($result['candidate_photo']);
                                ?>
                                    <img src="<?php echo $image_path; ?>" 
                                         alt="<?php echo htmlspecialchars($result['candidate_name'] ?? ''); ?>"
                                         class="rounded-circle me-2" width="40" height="40"
                                         onerror="this.src='../assets/img/default-avatar.svg'">
                                <?php else: ?>
                                    <img src="../assets/img/default-avatar.svg" 
                                         alt="Default Avatar"
                                         class="rounded-circle me-2" width="40" height="40">
                                <?php endif; ?>
                                <div>
                                    <h6 class="mb-0"><?php echo htmlspecialchars($result['candidate_name'] ?? 'Unnamed Candidate'); ?></h6>
                                    <small class="text-muted"><?php echo $result['vote_count']; ?> votes</small>
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="h5 mb-0"><?php echo $percentage; ?>%</div>
                                <small class="text-muted">of total votes</small>
                            </div>
                        </div>
                        <div class="progress" style="height: 10px;">
                            <div class="progress-bar" role="progressbar" 
                                 style="width: <?php echo $percentage; ?>%"
                                 aria-valuenow="<?php echo $percentage; ?>" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <?php if ($current_position !== null): ?>
                    </div> <!-- Close last position -->
                <?php endif; ?>
                
                <?php if ($current_election !== null && !$selected_election_id): ?>
                    </div> <!-- Close last election -->
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add this JavaScript at the bottom of the file, before the closing body tag -->
<script nonce="<?php echo $_SESSION['csp_nonce']; ?>" src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script nonce="<?php echo $_SESSION['csp_nonce']; ?>" src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/1.5.3/jspdf.min.js"></script>
<script nonce="<?php echo $_SESSION['csp_nonce']; ?>" src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script nonce="<?php echo $_SESSION['csp_nonce']; ?>" src="/school_voting/admin/assets/js/results.js"></script>
<script nonce="<?php echo $_SESSION['csp_nonce']; ?>" src="https://cdnjs.cloudflare.com/ajax/libs/countup.js/2.0.8/countUp.min.js"></script>
<script nonce="<?php echo $_SESSION['csp_nonce']; ?>">
// Wait for the DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function() {
    // Get the sidebar toggle button
    const sidebarToggle = document.getElementById('sidebarToggle');
    
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Toggle classes for sidebar collapse/expand
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const navbar = document.querySelector('.navbar');
            
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            navbar.classList.toggle('expanded');
            
            // Store the state
            localStorage.setItem('sidebarState', sidebar.classList.contains('collapsed'));
        });
        
        // Check and apply stored sidebar state
        const storedState = localStorage.getItem('sidebarState');
        if (storedState === 'true') {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const navbar = document.querySelector('.navbar');
            
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
            navbar.classList.add('expanded');
        }
    }

    // Initialize table functionality
    let currentPage = 1;
    let itemsPerPage = 10;
    let searchQuery = '';
    
    // Search functionality
    document.getElementById('searchInput').addEventListener('input', function() {
        searchQuery = this.value;
        currentPage = 1;
    });
    
    // Items per page functionality
    document.getElementById('itemsPerPage').addEventListener('change', function() {
        itemsPerPage = parseInt(this.value);
        currentPage = 1;
    });
    
    // Print functionality
    window.printResults = function() {
        const printWindow = window.open('', '_blank');
        
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Election Results</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
                <style>
                    @media print {
                        .card { margin-bottom: 1rem; }
                        .progress { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
                    }
                </style>
            </head>
            <body class="p-4">
                <h2 class="mb-4">Election Results</h2>
                ${document.querySelector('.card-body').outerHTML}
            </body>
            </html>
        `);
        
        printWindow.document.close();
        printWindow.focus();
        
        printWindow.onload = function() {
            printWindow.print();
            printWindow.close();
        };
    };

    // Export to PDF functionality
    window.exportToPDF = function() {
        const element = document.querySelector('.card-body');
        html2canvas(element).then(canvas => {
            const imgData = canvas.toDataURL('image/png');
            const pdf = new jsPDF('l', 'mm', 'a4');
            const imgProps = pdf.getImageProperties(imgData);
            const pdfWidth = pdf.internal.pageSize.getWidth();
            const pdfHeight = (imgProps.height * pdfWidth) / imgProps.width;
            
            pdf.addImage(imgData, 'PNG', 0, 0, pdfWidth, pdfHeight);
            pdf.save('election_results.pdf');
        });
    };

    // Export to Excel functionality
    window.exportToExcel = function() {
        const results = <?php echo json_encode($results); ?>;
        let csv = [
            ['Election', 'Position', 'Candidate', 'Votes', 'Percentage']
        ];
        
        results.forEach(result => {
            const total_votes = results
                .filter(r => r.position_id === result.position_id)
                .reduce((sum, r) => sum + parseInt(r.vote_count), 0);
            const percentage = total_votes > 0 ? ((result.vote_count / total_votes) * 100).toFixed(1) : '0.0';
            
            csv.push([
                result.election_title,
                result.position_title,
                result.candidate_name,
                result.vote_count,
                percentage + '%'
            ]);
        });
        
        const csvContent = csv.map(row => {
            return row.map(cell => {
                cell = cell.toString();
                if (cell.includes(',') || cell.includes('"')) {
                    cell = '"' + cell.replace(/"/g, '""') + '"';
                }
                return cell;
            }).join(',');
        }).join('\n');
        
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        
        link.setAttribute('href', url);
        link.setAttribute('download', 'election_results.csv');
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };
});

// Function to filter results by election
function filterResults(electionId) {
    window.location.href = 'results.php' + (electionId ? '?election_id=' + electionId : '');
}
</script>
</body>
</html> 