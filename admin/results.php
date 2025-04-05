<?php
// Initialize session and load required files
require_once "includes/init.php"; // Sets up session, CSP nonce
require_once "includes/database.php"; // Database connection ($conn)
require_once "includes/session.php"; // Session handling functions

// Check if admin is logged in
if (!isAdminLoggedIn()) {
    header("Location: admin/login.php");
    exit();
}

// --- Configuration & Initial Setup ---
$results_error = null;
$elections = [];
$all_positions = [];
$grouped_results = [];
$stats = ['total_codes' => 0, 'total_votes' => 0, 'used_codes' => 0, 'voter_turnout' => 0];
$selected_election_id = null;
$selected_position_id = null;
$selected_election_title = "All Elections";
$selected_position_title = "All Positions";
$logo_path_relative = '../assets/images/gombe-ss-logo.png'; // Relative path from admin folder
$logo_base64 = null;

// --- Logo Processing ---
// Construct absolute path carefully
$base_dir = dirname(__DIR__); // Assumes results.php is in /admin/
$logo_path_absolute = realpath($base_dir . '/' . ltrim($logo_path_relative, '/'));

if ($logo_path_absolute && file_exists($logo_path_absolute)) {
    try {
        $image_type = pathinfo($logo_path_absolute, PATHINFO_EXTENSION);
        // Ensure it's a common web image type before proceeding
        if (in_array(strtolower($image_type), ['png', 'jpg', 'jpeg', 'gif', 'webp'])) {
            $logo_data = file_get_contents($logo_path_absolute);
            if ($logo_data !== false) {
                $logo_base64 = 'data:image/' . $image_type . ';base64,' . base64_encode($logo_data);
                error_log("Logo successfully processed into Base64.");
            } else {
                 error_log("Failed to read logo file content: " . $logo_path_absolute);
            }
        } else {
             error_log("Unsupported logo image type: " . $image_type);
        }
    } catch (Exception $e) {
         error_log("Error processing logo file: " . $e->getMessage());
    }
} else {
     error_log("Logo file not found or path is invalid: " . ($logo_path_absolute ?: $logo_path_relative) . " (resolved from " . $base_dir . ")");
}
if (!$logo_base64) {
    error_log("Logo Base64 could not be generated. Watermark/background will not appear.");
}


// --- Data Fetching (Elections & Positions) ---
try {
    $elections_sql = "SELECT id, title FROM elections ORDER BY created_at DESC, title ASC";
$elections = $conn->query($elections_sql)->fetchAll(PDO::FETCH_ASSOC);

    $positions_sql = "SELECT id, election_id, title FROM positions ORDER BY title ASC";
    $all_positions = $conn->query($positions_sql)->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error fetching elections/positions: " . $e->getMessage());
    $results_error = "Could not load election/position lists.";
}

// --- Process Filters ---
$selected_election_id_input = isset($_GET['election_id']) ? filter_input(INPUT_GET, 'election_id', FILTER_VALIDATE_INT) : null;
$selected_position_id_input = isset($_GET['position_id']) ? filter_input(INPUT_GET, 'position_id', FILTER_VALIDATE_INT) : null;

if ($selected_election_id_input) {
    $selected_election_id = $selected_election_id_input;
    // Update title
    foreach ($elections as $e) { if ($e['id'] == $selected_election_id) {$selected_election_title = $e['title']; break;} }
}
if ($selected_position_id_input) {
    $selected_position_id = $selected_position_id_input;
     // Update title and potentially correct selected election
     foreach ($all_positions as $p) {
        if ($p['id'] == $selected_position_id) {
            $selected_position_title = $p['title'];
            if (!$selected_election_id || $selected_election_id != $p['election_id']) {
                 $selected_election_id = $p['election_id'];
                 foreach ($elections as $e) { if ($e['id'] == $selected_election_id) {$selected_election_title = $e['title']; break;} }
            }
            break;
        }
    }
}


// --- Statistics Calculation (Corrected & Simplified Binding) ---
try {
    $stats_params = [];
    $where_clause_vc = ""; // For voting_codes filter
    $where_clause_votes = ""; // For votes filter (using aliases)

if ($selected_election_id) {
        $where_clause_vc = " WHERE vc.election_id = :election_id ";
        $where_clause_votes = " WHERE e.id = :election_id "; // Filter on elections table alias 'e'
        $stats_params[':election_id'] = $selected_election_id;

        if ($selected_position_id) {
            $where_clause_votes .= " AND p.id = :position_id "; // Filter on positions table alias 'p'
            $stats_params[':position_id'] = $selected_position_id;
        }
    }

    // Use parameter array directly with execute()

    // Total Codes (scoped by election if selected)
    $sql_total_codes = "SELECT COUNT(vc.id) FROM voting_codes vc" . $where_clause_vc;
    $stmt_total_codes = $conn->prepare($sql_total_codes);
    // Bind only if election filter exists
    if ($selected_election_id) $stmt_total_codes->bindParam(':election_id', $stats_params[':election_id'], PDO::PARAM_INT);
    $stmt_total_codes->execute();
    $stats['total_codes'] = (int)$stmt_total_codes->fetchColumn();
    error_log("Stats - Total Codes: {$stats['total_codes']} (Query: $sql_total_codes)");


    // Total Votes Cast (scoped by election and position if selected)
    $sql_total_votes = "SELECT COUNT(v.id) FROM votes v
     INNER JOIN candidates c ON v.candidate_id = c.id 
     INNER JOIN positions p ON c.position_id = p.id 
                       INNER JOIN elections e ON p.election_id = e.id"
                       . $where_clause_votes; // Where clause filters on e.id and p.id
    $stmt_total_votes = $conn->prepare($sql_total_votes);
    $stmt_total_votes->execute($stats_params); // Pass full params array
    $stats['total_votes'] = (int)$stmt_total_votes->fetchColumn();
     error_log("Stats - Total Votes: {$stats['total_votes']} (Query: $sql_total_votes | Params: " . json_encode($stats_params) . ")");


    // Used Codes (scoped by election and position if selected)
    $sql_used_codes = "SELECT COUNT(DISTINCT v.voting_code_id) FROM votes v
                      INNER JOIN candidates c ON v.candidate_id = c.id
                      INNER JOIN positions p ON c.position_id = p.id
                      INNER JOIN elections e ON p.election_id = e.id"
                      . $where_clause_votes; // Where clause filters on e.id and p.id
    $stmt_used_codes = $conn->prepare($sql_used_codes);
    $stmt_used_codes->execute($stats_params); // Pass full params array
    $stats['used_codes'] = (int)$stmt_used_codes->fetchColumn();
    error_log("Stats - Used Codes: {$stats['used_codes']} (Query: $sql_used_codes | Params: " . json_encode($stats_params) . ")");


// Calculate voter turnout
    $stats['voter_turnout'] = ($stats['total_codes'] > 0)
        ? round(((int)$stats['used_codes'] / (int)$stats['total_codes']) * 100, 1)
        : 0;
     error_log("Stats - Turnout: {$stats['voter_turnout']}%");

} catch (PDOException $e) {
    error_log("Statistics Calculation Error: " . $e->getMessage());
    if(is_null($results_error)) $results_error = "Could not calculate election statistics.";
    // Reset stats on error
    $stats = ['total_codes' => 0, 'total_votes' => 0, 'used_codes' => 0, 'voter_turnout' => 0];
}


// --- Results Fetching (Corrected to ensure candidates without votes are shown) ---
try {
    $results_params = [];
    $where_clauses = [];

    // Base query starts from positions to ensure all positions in the filtered election/all elections are considered
    $results_sql = "SELECT
        e.id as election_id,
        COALESCE(e.title, 'Untitled Election') as election_title,
        e.status as election_status,
        p.id as position_id,
        COALESCE(p.title, 'Untitled Position') as position_title,
        c.id as candidate_id,
        COALESCE(c.name, 'Unnamed Candidate') as candidate_name,
        c.photo as candidate_photo,
        COUNT(v.id) as vote_count -- Count votes specifically for this candidate
    FROM positions p
    INNER JOIN elections e ON p.election_id = e.id
    LEFT JOIN candidates c ON p.id = c.position_id -- Left join candidates to include positions without them
    LEFT JOIN votes v ON c.id = v.candidate_id -- Left join votes to include candidates without votes
    ";

    // Apply filters
    if ($selected_election_id) {
        $where_clauses[] = " e.id = :election_id ";
        $results_params[':election_id'] = $selected_election_id;
    }
    if ($selected_position_id) {
        $where_clauses[] = " p.id = :position_id ";
        $results_params[':position_id'] = $selected_position_id;
    }

    if (!empty($where_clauses)) {
         $results_sql .= " WHERE " . implode(" AND ", $where_clauses);
    }

    // Group by all non-aggregated columns, including candidate details
    // This ensures each candidate gets a row, even with 0 votes
    $results_sql .= " GROUP BY e.id, e.title, e.status, p.id, p.title, c.id, c.name, c.photo ";

    // Order appropriately
    $results_sql .= " ORDER BY e.created_at DESC, p.id, vote_count DESC, c.name";

    // Prepare and execute
    $results_stmt = $conn->prepare($results_sql);
    $results_stmt->execute($results_params);
    $results = $results_stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Results Query executed. Found " . count($results) . " rows.");


    // Group results (refined)
    $grouped_results = [];
    foreach ($results as $row) {
        $eid = $row['election_id'];
        $pid = $row['position_id'];

        if (!isset($grouped_results[$eid])) {
            $grouped_results[$eid] = [
                'title' => $row['election_title'],
                'status' => $row['election_status'],
                'positions' => []
            ];
        }
        if (!isset($grouped_results[$eid]['positions'][$pid])) {
             $grouped_results[$eid]['positions'][$pid] = [
                'title' => $row['position_title'],
                'candidates' => [], // Initialize candidates array
                'total_votes' => 0 // Initialize total votes for this position
             ];
        }

        // Add candidate data ONLY if candidate_id is not NULL
        if($row['candidate_id'] !== null) {
             $grouped_results[$eid]['positions'][$pid]['candidates'][] = $row;
        }
    }

    // Calculate total votes, percentages, and identify winners *after* grouping
    foreach ($grouped_results as $eid => &$election_data) {
        if (!empty($election_data['positions'])) {
            foreach ($election_data['positions'] as $pid => &$position_data) {
                $total_position_votes = 0;
                $max_votes = 0;
                if (!empty($position_data['candidates'])) {
                    // First pass: calculate total votes and find max votes for the position
                    foreach ($position_data['candidates'] as $candidate) {
                        $vote_count = (int)$candidate['vote_count'];
                        $total_position_votes += $vote_count;
                        if ($vote_count > $max_votes) {
                            $max_votes = $vote_count;
                        }
                    }
                    $position_data['total_votes'] = $total_position_votes; // Store total votes for the position

                    // Second pass: calculate percentage and mark winners
                    if ($total_position_votes > 0) { // Avoid division by zero and mark winners only if votes exist
                         foreach ($position_data['candidates'] as &$candidate) {
                            $candidate['percentage'] = round(((int)$candidate['vote_count'] / $total_position_votes) * 100, 1);
                            // Mark winner(s) only if max_votes > 0
                            $candidate['is_winner'] = ($max_votes > 0 && (int)$candidate['vote_count'] === $max_votes);
                        }
                        unset($candidate);
                    } else { // No votes cast for this position
                         foreach ($position_data['candidates'] as &$candidate) {
                            $candidate['percentage'] = 0;
                            $candidate['is_winner'] = false;
                        }
                         unset($candidate);
                    }
                }
                // No else needed, 'total_votes' remains 0 if 'candidates' is empty
            }
            unset($position_data);
        }
    }
    unset($election_data);


} catch (PDOException $e) {
    error_log("Results Fetching/Processing Error: " . $e->getMessage());
     if(is_null($results_error)) $results_error = "Could not fetch or process election results.";
    $grouped_results = []; // Ensure it's empty on error
}

// Include the header
require_once "includes/header.php"; // Assumes this outputs starting HTML, head, and opens body/main container
?>

<style nonce="<?php echo htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8'); ?>">
    :root {
        --primary-color: #4e73df; /* Indigo */
        --secondary-color: #858796; /* Gray */
        --success-color: #1cc88a; /* Green */
        --info-color: #36b9cc; /* Teal */
        --warning-color: #f6c23e; /* Yellow */
        --danger-color: #e74a3b; /* Red */
        --light-color: #f8f9fc;
        --lighter-gray: #eaecf4;
        --dark-color: #5a5c69;
        --white-color: #fff;
        --font-family-sans-serif: "Nunito", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
        --border-color: #e3e6f0;
        --shadow-sm: 0 .125rem .25rem rgba(0,0,0,.075);
        --shadow: 0 .15rem 1.75rem 0 rgba(58,59,69,.15); /* Default SB Admin Shadow */
        --shadow-lg: 0 1rem 3rem rgba(0,0,0,.175);
        --border-radius: .35rem;
        --border-radius-lg: .5rem;
        --winner-gold: #ffd700;
        --winner-gold-darker: #f0c400;
    }

    body {
        font-family: var(--font-family-sans-serif);
        background-color: var(--light-color);
        color: var(--dark-color);
        font-size: 0.95rem;
    }

    .container-fluid {
        padding: 1.5rem;
    }

    /* Page Header & Filters */
    .page-header {
        border-bottom: 1px solid var(--border-color);
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
    }
    .page-header h1 {
        color: var(--primary-color);
        font-weight: 600;
    }
    .filter-controls {
        background-color: var(--white-color);
        padding: 1rem 1.25rem;
        border-radius: var(--border-radius);
        box-shadow: var(--shadow-sm);
        border: 1px solid var(--border-color);
        margin-bottom: 1.5rem;
    }
    .filter-controls .form-label {
        font-size: .8rem;
        font-weight: 600;
        color: var(--secondary-color);
        margin-bottom: .25rem;
        display: block;
    }
    .filter-controls .form-select,
    .filter-controls .btn {
        font-size: .875rem;
    }
    .filter-controls .btn i {
        vertical-align: -1px;
    }

    /* Statistics Cards */
    .stats-card {
        transition: all 0.2s ease-in-out;
        border: none;
        border-left: 4px solid var(--primary-color);
        border-radius: var(--border-radius);
        overflow: hidden;
        background-color: var(--white-color);
        box-shadow: var(--shadow-sm);
    }
    .stats-card .card-body { padding: 1rem 1.25rem; }
    .stats-card:hover { transform: scale(1.02); box-shadow: var(--shadow) !important; }
    .stats-card-icon { font-size: 2.2rem; opacity: 0.15; transition: opacity 0.3s ease; }
    .stats-card:hover .stats-card-icon { opacity: 0.25; }
    .stats-card .stat-label { font-size: .75rem; font-weight: 700; text-transform: uppercase; margin-bottom: .1rem; color: var(--secondary-color); }
    .stats-card .stat-value { font-size: 1.7rem; font-weight: 700; line-height: 1.2; color: var(--dark-color); }
    .stats-card .stat-value #voterTurnout + span { font-size: 1.3rem; color: var(--secondary-color); margin-left: 1px; }
    /* Colors */
    .stats-card.border-left-primary { border-left-color: var(--primary-color); }
    .stats-card.border-left-primary .stats-card-icon { color: var(--primary-color); }
    .stats-card.border-left-success { border-left-color: var(--success-color); }
    .stats-card.border-left-success .stats-card-icon { color: var(--success-color); }
    .stats-card.border-left-info { border-left-color: var(--info-color); }
    .stats-card.border-left-info .stats-card-icon { color: var(--info-color); }
    .stats-card.border-left-warning { border-left-color: var(--warning-color); }
    .stats-card.border-left-warning .stats-card-icon { color: var(--warning-color); }

    /* Results Area Styling */
    #resultsContainer {
        position: relative; /* Needed for watermark */
        background-color: transparent; /* Allow body bg to show */
    }

    /* Logo Background/Watermark */
    #resultsContainer::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0; bottom: 0;
        background-image: <?php echo $logo_base64 ? "url('" . $logo_base64 . "')" : "none"; ?>;
        background-repeat: repeat;
        background-position: center center;
        background-size: 200px; /* Larger size */
        opacity: 0.025; /* Even more subtle */
        pointer-events: none;
        z-index: 0; /* Behind cards */
    }

    .election-results-card {
        margin-bottom: 2rem;
        border-radius: var(--border-radius-lg);
        border: none; /* Remove border, rely on shadow */
        box-shadow: var(--shadow);
        background-color: var(--white-color);
        position: relative; /* Above watermark */
        z-index: 1;
        overflow: hidden; /* Ensure content respects border radius */
    }
    .election-results-card .card-header {
        background: linear-gradient(to right, var(--primary-color), #6e8efb); /* Gradient Header */
        color: var(--white-color);
        font-weight: 600;
        font-size: 1.15rem;
        border-bottom: none;
        padding: .8rem 1.25rem;
    }
    .election-results-card .card-header .badge { font-size: .75em; padding: .3em .6em; vertical-align: middle; }
    .card-body { padding: 1.25rem; }

    .position-header {
        padding: .75rem 0 .75rem 0; /* Padding only top/bottom */
        margin-bottom: 1rem;
        border-bottom: 2px solid var(--primary-color); /* Accent line below header */
        font-weight: 700;
        color: var(--primary-color);
        font-size: 1.1rem;
        text-transform: uppercase; /* Distinguish position */
        letter-spacing: .5px;
    }
    .position-header small { font-weight: 400; font-size: 0.9rem; color: var(--secondary-color); text-transform: none; letter-spacing: normal;}

    .candidate-result-item {
        display: flex;
        align-items: center;
        padding: .8rem 0;
        border-bottom: 1px solid var(--lighter-gray);
        gap: 1rem; /* Space between elements */
        flex-wrap: wrap; /* Allow wrapping on small screens */
    }
    .candidate-result-item:last-child { border-bottom: none; padding-bottom: .2rem; }

    .candidate-info {
        display: flex;
        align-items: center;
        flex-grow: 1; /* Take available space */
        min-width: 200px; /* Prevent excessive squishing */
    }
    .candidate-photo {
        width: 45px; height: 45px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid var(--lighter-gray);
        margin-right: .8rem;
        flex-shrink: 0; /* Prevent image shrinking */
    }
    .candidate-details { flex-grow: 1; }
    .candidate-name { font-weight: 600; margin-bottom: 0; font-size: 1rem; color: var(--dark-color); }
    .candidate-votes { font-size: .8rem; color: var(--secondary-color); }

    .candidate-progress {
        flex-basis: 40%; /* Allocate space for progress bar */
        min-width: 150px; /* Minimum width */
        flex-grow: 1;
    }
    .progress {
        height: 20px;
        border-radius: 10px;
        background-color: var(--lighter-gray);
        overflow: hidden;
        box-shadow: inset 0 1px 2px rgba(0,0,0,.075);
        position: relative; /* For text overlay */
    }
    .progress-bar {
        background: linear-gradient(to right, var(--info-color), var(--success-color));
        transition: width .6s ease-out;
        display: flex; /* Use flex for centering text */
        align-items: center;
        justify-content: flex-end; /* Align text to the right */
        padding-right: 8px; /* Padding for text */
        font-size: .75rem;
        font-weight: 600;
        color: var(--white-color);
        text-shadow: 1px 1px 1px rgba(0,0,0,.2);
        white-space: nowrap;
    }
    .progress-bar-label { /* Separate label if needed */
       position: absolute;
       left: 10px;
       line-height: 20px;
       color: #fff;
       font-size: 0.75rem;
       font-weight: bold;
       text-shadow: 1px 1px 1px rgba(0,0,0,.3);
    }

    .candidate-percentage {
        flex-basis: 80px; /* Fixed width for percentage */
        flex-shrink: 0;
        text-align: right;
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--dark-color);
    }

    /* Winner Styling */
    .winner-badge {
        display: inline-block;
        padding: .25em .6em;
        font-size: .7em;
        font-weight: 700;
        line-height: 1;
        color: var(--dark-color);
        text-align: center;
        white-space: nowrap;
        vertical-align: middle;
        border-radius: .25rem;
        background: linear-gradient(to right, var(--winner-gold), var(--winner-gold-darker));
        box-shadow: 0 1px 2px rgba(0,0,0,.2);
        margin-left: .5rem;
        transform: translateY(-2px);
    }
    .winner-badge i { margin-right: .2rem; }


    .no-results-card { border-style: dashed; }
    .no-results { padding: 3rem 1rem; text-align: center; color: var(--secondary-color); }
    .no-results i { font-size: 3rem; margin-bottom: 1rem; display: block; opacity: 0.5; }

    /* Loader */
     #processingLoader { background-color: rgba(255, 255, 255, 0.7); backdrop-filter: blur(3px);}
     #processingLoader > div { color: var(--primary-color); font-weight: 500; background-color: var(--white-color); padding: 1rem 1.5rem; border-radius: var(--border-radius); box-shadow: var(--shadow-lg); display: flex; align-items: center;}
     #processingLoader .spinner-border { color: var(--primary-color) !important; width: 1.5rem; height: 1.5rem;}


    /* Responsive */
    @media (max-width: 767px) {
        .candidate-result-item { flex-direction: column; align-items: stretch; gap: .5rem; }
        .candidate-info { justify-content: center; text-align: center; min-width: 0;}
        .candidate-photo { margin-right: 0; margin-bottom: .5rem; }
        .candidate-progress { flex-basis: 100%; order: 3; } /* Progress bar last */
        .candidate-percentage { flex-basis: 100%; order: 2; text-align: center; margin-bottom: .5rem;} /* Percentage second */
    }

    /* Print styles */
    @media print {
        @page { size: A4 portrait; margin: 10mm; } /* Reduced margin */
        body { background-color: var(--white-color) !important; color: #000 !important; font-size: 9pt; } /* Base print font size */
        /* Hide unwanted */
        .sidebar, .navbar, .filter-controls, #sidebarToggle, .btn, .dropdown { display: none !important; }
        /* Full width */
        .main-content, .main-content.expanded { margin-left: 0 !important; width: 100% !important; padding: 0 !important; }
        .container-fluid { padding: 0 !important; width: 100% !important; max-width: 100% !important; }

        /* Watermark */
        body::after { content: ''; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background-image: <?php echo $logo_base64 ? "url('" . $logo_base64 . "')" : "none"; ?>;
            background-repeat: repeat; background-position: center; background-size: 120px; opacity: 0.05; z-index: -1; }

        /* Titles */
        .page-header h1.h3 { font-size: 14pt; text-align: center; margin-bottom: .5rem; border-bottom: 1px solid #999; padding-bottom: .3rem;}
        .election-results-card .card-header { background: #eee !important; color: #000 !important; border: 1px solid #ccc !important; border-bottom: 1px solid #999 !important; padding: .3rem .6rem; font-size: 11pt; border-radius: 0 !important; }
        .position-header { background-color: #f5f5f5 !important; margin: .5rem 0 !important; padding: .3rem .6rem !important; border: 1px solid #ccc !important; color: #000 !important; font-size: 10pt; }
        .position-header small { color: #444 !important; font-size: 8pt;}

        /* Cards & Layout */
        .card, .stats-card, .election-results-card { box-shadow: none !important; border: 1px solid #ccc !important; margin-bottom: .5rem; page-break-inside: avoid; border-radius: 0 !important; }
        .stats-card { border-left-width: 3px !important; }
        .stats-card .card-body { padding: .3rem .5rem !important;}
        .stats-card .stat-label { font-size: 7pt !important; color: #333 !important;}
        .stats-card .stat-value { font-size: 10pt !important; color: #000 !important; }
        .stats-card-icon { display: none; }

        /* Candidate Items */
        .candidate-result-item { padding: .3rem 0; border-color: #eee;}
        .candidate-info { min-width: 0 !important; }
        .candidate-photo { width: 25px !important; height: 25px !important; border: 1px solid #ccc !important; box-shadow: none !important; margin-right: .5rem;}
        .candidate-name { font-size: 9pt;}
        .candidate-votes { font-size: 8pt; color: #333 !important;}
        .candidate-progress { flex-basis: auto !important; } /* Allow progress to shrink */
        .vote-percentage { font-size: 9pt; }

        /* Progress Bar & Winner */
        .progress { height: 10px !important; background-color: #ddd !important; border: 1px solid #aaa; print-color-adjust: exact !important; -webkit-print-color-adjust: exact !important; }
        .progress-bar { background-color: #555 !important; color: #fff !important; font-size: 6pt !important; line-height: 10px !important; print-color-adjust: exact !important; -webkit-print-color-adjust: exact !important; text-shadow: none !important; justify-content: center !important; padding: 0 !important;}
        .winner-badge { background-color: #ccc !important; color: #000 !important; border: 1px solid #333; padding: 1px 3px !important; font-size: 7pt !important; box-shadow: none !important; transform: none !important; print-color-adjust: exact !important; -webkit-print-color-adjust: exact !important; }
        .winner-badge i { display: none; }
        a { text-decoration: none; color: inherit; }
        .no-results { border: 1px dashed #ccc; font-size: 9pt; padding: .5rem;}
        .no-results i { display: none;}
    }

</style>

<div class="container-fluid">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-4 page-header">
        <h1 class="h3 mb-3 mb-lg-0 text-primary font-weight-bold">Election Results</h1>
    </div>

    <div class="row mb-4 filter-controls">
         <div class="col-lg-5 col-md-6 mb-3 mb-md-0">
              <label for="electionSelect" class="form-label"><i class="bi bi-calendar3 me-1"></i>Filter by Election</label>
              <select class="form-select form-select-sm" id="electionSelect" aria-label="Filter by election">
                    <option value="">All Elections</option>
                    <?php foreach ($elections as $election): ?>
                        <option value="<?php echo $election['id']; ?>" <?php echo $selected_election_id == $election['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($election['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
          <div class="col-lg-5 col-md-6 mb-3 mb-md-0">
              <label for="positionSelect" class="form-label"><i class="bi bi-person-badge me-1"></i>Filter by Position</label>
              <select class="form-select form-select-sm" id="positionSelect" aria-label="Filter by position" <?php echo empty($all_positions) ? 'disabled' : ''; ?>>
                   <option value="">All Positions</option>
                    <?php
                        // Pre-populate based on currently selected election (or all if none selected)
                        foreach ($all_positions as $position) {
                            if (!$selected_election_id || $position['election_id'] == $selected_election_id) {
                                $selected = ($selected_position_id == $position['id']) ? 'selected' : '';
                                echo '<option value="' . $position['id'] . '" data-election-id="' . $position['election_id'] . '" ' . $selected . '>' . htmlspecialchars($position['title']) . '</option>';
                            }
                        }
                    ?>
              </select>
         </div>
         <div class="col-lg-2 col-md-12 d-flex align-items-end justify-content-center justify-content-lg-end gap-2">
              <button type="button" class="btn btn-sm btn-outline-secondary" id="printButton" title="Print Results">
                   <i class="bi bi-printer"></i><span class="d-none d-lg-inline ms-1">Print</span>
              </button> 
        </div>
    </div>

    <!-- <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card stats-card border-left-primary shadow-sm h-100">
                <div class="card-body">
                    <div class="row g-0 align-items-center">
                        <div class="col">
                            <div class="stat-label">Total Codes</div>
                            <div class="stat-value" id="totalCodes">0</div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-ticket-detailed-fill stats-card-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card stats-card border-left-success shadow-sm h-100">
                <div class="card-body">
                    <div class="row g-0 align-items-center">
                        <div class="col">
                            <div class="stat-label">Votes Cast</div>
                            <div class="stat-value" id="totalVotes">0</div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-check-all stats-card-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card stats-card border-left-info shadow-sm h-100">
                <div class="card-body">
                    <div class="row g-0 align-items-center">
                        <div class="col">
                            <div class="stat-label">Codes Used</div>
                            <div class="stat-value" id="usedCodes">0</div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-person-check-fill stats-card-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card stats-card border-left-warning shadow-sm h-100">
                <div class="card-body">
                    <div class="row g-0 align-items-center">
                        <div class="col">
                            <div class="stat-label">Voter Turnout</div>
                            <div class="stat-value">
                                <span id="voterTurnout">0.0</span><span>%</span>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-pie-chart-fill stats-card-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div> -->


    <div id="resultsContainer">
        <?php if ($results_error): ?>
            <div class="alert alert-danger shadow-sm" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($results_error); ?>
                    </div>
        <?php elseif (empty($grouped_results)): ?>
            <div class="card shadow-sm border-0 no-results-card">
                <div class="card-body no-results">
                     <i class="bi bi-clipboard-x"></i> 
                    <p class="mb-0 h5">No Results Found</p>
                    <p class="mt-2"><small>No election data matches the current filter criteria.</small></p>
                </div>
                    </div>
        <?php else: ?>
            <?php foreach ($grouped_results as $election_id_loop => $election_data): ?>
                <div class="card shadow-sm election-results-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><?php echo htmlspecialchars($election_data['title']); ?></span>
                        <?php
                           $status_class = 'secondary'; $status_text_class = 'white';
                           if ($election_data['status'] === 'active') {$status_class = 'success'; $status_text_class='white';}
                           if ($election_data['status'] === 'pending') {$status_class = 'warning'; $status_text_class='dark';}
                           if ($election_data['status'] === 'completed') {$status_class = 'dark'; $status_text_class='white';}
                        ?>
                         <span class="badge bg-<?php echo $status_class; ?> text-<?php echo $status_text_class;?>"><?php echo ucfirst(htmlspecialchars($election_data['status'])); ?></span>
        </div>
        <div class="card-body">
                        <?php if (empty($election_data['positions'])): ?>
                            <p class="text-muted text-center my-3 fst-italic">No positions found for this election.</p>
            <?php else: ?>
                            <?php foreach ($election_data['positions'] as $position_id_loop => $position_data): ?>
                                <div class="position-results mb-4">
                                    <h6 class="position-header"><?php echo htmlspecialchars($position_data['title']); ?>
                                        <small class="text-muted fw-normal ms-2">(Total Votes: <?php echo number_format($position_data['total_votes']); ?>)</small>
                                    </h6>
                                     <?php if (empty($position_data['candidates'])): ?>
                                        <p class="text-muted text-center my-2 fst-italic small">No candidates contested for this position.</p>
                                    <?php else: ?>
                                        <?php foreach ($position_data['candidates'] as $candidate): ?>
                                            <div class="candidate-result-item <?php echo ($candidate['is_winner'] ?? false) ? 'is-winner' : ''; ?>">
                                                 <div class="candidate-info">
                <?php
                                                         $photo_path = '../assets/images/default-avatar.png'; // Reliable default
                                                         if (!empty($candidate['candidate_photo'])) {
                                                             $relative_photo_path = "../" . ltrim($candidate['candidate_photo'], '/');
                                                             if (strpos($relative_photo_path, '../uploads/candidates/') === 0 && file_exists($relative_photo_path)) {
                                                                 $photo_path = htmlspecialchars($relative_photo_path);
                                                             }
                                                         }
                                                     ?>
                                                     <img src="<?php echo $photo_path; ?>"
                                                          alt="<?php echo htmlspecialchars($candidate['candidate_name']); ?>"
                                                          class="candidate-photo"
                                                          onerror="this.style.display='none';">
                                                     <div class="candidate-details">
                                                         <h6 class="candidate-name">
                                                             <?php echo htmlspecialchars($candidate['candidate_name']); ?>
                                                              <?php if ($candidate['is_winner'] ?? false): ?>
                                                                  <span class="winner-badge"><i class="bi bi-star-fill"></i> Winner</span>
                                <?php endif; ?>
                                                         </h6>
                                                         <div class="candidate-votes"><?php echo number_format($candidate['vote_count']); ?> votes</div>
                                </div>
                            </div>
                                                 <div class="candidate-progress">
                                                      <div class="progress" title="<?php echo $candidate['percentage']; ?>% of votes">
                                                            <div class="progress-bar" role="progressbar"
                                                                 style="width: <?php echo $candidate['percentage']; ?>%"
                                                                 aria-valuenow="<?php echo $candidate['percentage']; ?>"
                                                                 aria-valuemin="0" aria-valuemax="100">
                                                                 <?php if ($candidate['percentage'] >= 15): // Show text only if bar is wide enough ?>
                                                                     <?php echo $candidate['percentage']; ?>%
                                                                 <?php endif; ?>
                            </div>
                        </div>
                                                 </div>
                                                 <div class="candidate-percentage">
                                                     <?php echo $candidate['percentage']; ?>%
                        </div>
                    </div>
                <?php endforeach; ?>
                                     <?php endif; // end if candidates exist ?>
                                </div>
                            <?php endforeach; ?>
                         <?php endif; // end if positions exist ?>
                    </div> </div> <?php endforeach; ?>
        <?php endif; // end if results exist ?>
    </div> </div> <?php require_once "includes/footer.php"; ?>

<script nonce="<?php echo htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8'); ?>" src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-ka7Sk0Gln4gmtz2MlQnikT1wXgYsOg+OMhuP+IlRH9sENBO0LRn5q+8nbTov4+1p" crossorigin="anonymous"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8'); ?>" src="https://unpkg.com/countup.js@2.8.0/dist/countUp.umd.js"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8'); ?>" src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8'); ?>" src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8'); ?>" src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>


<script nonce="<?php echo htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8'); ?>">
document.addEventListener('DOMContentLoaded', function() {

    // Store all positions data fetched via PHP for client-side filtering
    const allPositionsData = <?php echo json_encode($all_positions); ?>;
    const initialSelectedPositionId = '<?php echo $selected_position_id ?? ""; ?>'; // Used to restore selection after filtering

    const electionSelect = document.getElementById('electionSelect');
    const positionSelect = document.getElementById('positionSelect');
    const printBtn = document.getElementById('printButton');
    const pdfBtn = document.getElementById('exportPdfButton');
    const excelBtn = document.getElementById('exportExcelButton');

    // --- Initialize Position Filter ---
    function updatePositionFilter() {
        const currentElectionId = electionSelect.value;
        const currentPositionValue = positionSelect.value; // Save currently selected position *before* clearing

        positionSelect.innerHTML = '<option value="">All Positions</option>'; // Reset options

        allPositionsData.forEach(position => {
            // Show only if it matches the selected election OR if "All Elections" is selected
            if (currentElectionId === "" || position.election_id == currentElectionId) {
                const option = document.createElement('option');
                option.value = position.id;
                option.textContent = position.title;
                option.dataset.electionId = position.election_id; // Store election ID for potential future use
                 // Try to re-select the position that was selected before the update
                 // If initialSelectedPositionId is set from URL, prioritize that
                if (initialSelectedPositionId && position.id == initialSelectedPositionId) {
                     option.selected = true;
                } else if (!initialSelectedPositionId && position.id == currentPositionValue) {
                     option.selected = true;
                }
                positionSelect.appendChild(option);
            }
        });

         // Enable/disable position dropdown based on whether an election is selected
         positionSelect.disabled = (currentElectionId === "");

    }

    // Initial population and filtering based on page load state
    updatePositionFilter();


    // --- CountUp Animation ---
    if (typeof CountUp !== 'function') {
        console.error("CountUp library failed to load or is not a function.");
    } else {
        console.log('Initializing CountUp animations...');
        const countUpOptions = {
            duration: 2.0, separator: ',', useEasing: true, useGrouping: true,
            enableScrollSpy: true, scrollSpyDelay: 100, scrollSpyOnce: true
        };
        // Use PHP $stats array which should now be correctly populated
        const statsData = <?php echo json_encode($stats); ?>;
        console.log('Stats data for CountUp:', statsData);

        try {
            const elTotalCodes = document.getElementById('totalCodes');
            const elTotalVotes = document.getElementById('totalVotes');
            const elUsedCodes = document.getElementById('usedCodes');
            const elTurnout = document.getElementById('voterTurnout');

            if (elTotalCodes) new CountUp(elTotalCodes, statsData.total_codes || 0, countUpOptions).start(); else console.warn("Element 'totalCodes' not found");
            if (elTotalVotes) new CountUp(elTotalVotes, statsData.total_votes || 0, countUpOptions).start(); else console.warn("Element 'totalVotes' not found");
            if (elUsedCodes) new CountUp(elUsedCodes, statsData.used_codes || 0, countUpOptions).start(); else console.warn("Element 'usedCodes' not found");
            if (elTurnout) {
                new CountUp(elTurnout, statsData.voter_turnout || 0.0, { ...countUpOptions, decimals: 1 }).start();
                 // Ensure '%' is visible (it's hardcoded in HTML now)
            } else console.warn("Element 'voterTurnout' not found");

            console.log('CountUp initialized successfully.');
        } catch (error) {
            console.error('CountUp Initialization Error:', error);
            // Fallback display handled by PHP echo before this script runs
        }
    }

    // --- Event Listener Setup ---
    if(electionSelect) {
        electionSelect.addEventListener('change', function() {
             updatePositionFilter(); // Update position dropdown first
             // Important: Don't automatically submit when election changes if you want user to maybe select a position too.
             // Let the position dropdown change trigger the filter reload. Or add a "Apply Filter" button.
             // For simplicity now, let's filter immediately. If user wants specific pos, they'll select it next.
             filterResults();
        });
    }
     if(positionSelect) {
         positionSelect.addEventListener('change', function() {
             // When position changes, reload the page with both filters
             filterResults();
         });
     }

    if(printBtn) printBtn.addEventListener('click', printResults);
    if(pdfBtn) pdfBtn.addEventListener('click', exportToPDF);
    if(excelBtn) excelBtn.addEventListener('click', exportToExcel);

    // --- Sidebar State ---
     try { // Basic check, assuming header/footer includes handle the main logic
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const navbar = document.querySelector('.navbar');
        const storedState = localStorage.getItem('sidebarState');
        if (storedState === 'collapsed' && sidebar && mainContent && navbar) {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
            navbar.classList.add('expanded');
        }
    } catch (e) { console.warn("Could not apply sidebar state."); }

}); // End DOMContentLoaded


// --- Global Helper Functions ---

function filterResults() {
    const electionId = document.getElementById('electionSelect').value;
    const positionId = document.getElementById('positionSelect').value;
    const url = new URL(window.location.pathname, window.location.origin);

    if (electionId) url.searchParams.set('election_id', electionId);
    else url.searchParams.delete('election_id');

    if (positionId) url.searchParams.set('position_id', positionId);
    else url.searchParams.delete('position_id');

    // If position is selected, ensure election is also selected (implicitly)
    if (positionId && !electionId) {
         const selectedPosOption = document.querySelector(`#positionSelect option[value="${positionId}"]`);
         if (selectedPosOption && selectedPosOption.dataset.electionId) {
              url.searchParams.set('election_id', selectedPosOption.dataset.electionId);
         }
    }


    window.location.href = url.toString();
}

function printResults() {
    document.body.classList.add('is-printing');
    setTimeout(() => { window.print(); }, 100); // Short delay for style application
    setTimeout(() => { document.body.classList.remove('is-printing'); }, 600); // Remove after print dialog likely handled
}

function showLoader(message = "Processing...") {
     let loader = document.getElementById('processingLoader');
     if (!loader) {
         loader = document.createElement('div');
         loader.id = 'processingLoader';
         loader.style.cssText = `position:fixed; top:0; left:0; width:100%; height:100%; background-color:rgba(255,255,255,0.7); backdrop-filter:blur(3px); color:var(--primary-color); display:flex; justify-content:center; align-items:center; z-index:1060; font-size:1.1rem; flex-direction: column; gap: 1rem;`;
         loader.innerHTML = `<div class="spinner-border" style="width: 3rem; height: 3rem;" role="status"></div><span id="loaderMessage" style="color: var(--dark-color); font-weight: 500;"></span>`;
         document.body.appendChild(loader);
     }
     loader.querySelector('#loaderMessage').textContent = message;
     loader.style.display = 'flex';
     return loader;
 }

 function hideLoader(loaderElement) {
     if (loaderElement) loaderElement.style.display = 'none';
 }

// Export to PDF (Using jsPDF and html2canvas) - Refined
function exportToPDF() {
    const resultsContainer = document.getElementById('resultsContainer');
    const { jsPDF } = window.jspdf;

    if (!resultsContainer || typeof html2canvas === 'undefined' || typeof jsPDF === 'undefined') {
        alert('Error: Required libraries for PDF generation are not loaded correctly.'); return;
    }
    const loader = showLoader("Generating PDF (this might take a moment)...");

    const pdf = new jsPDF({ orientation: 'p', unit: 'mm', format: 'a4' });
            const pdfWidth = pdf.internal.pageSize.getWidth();
    const pdfHeight = pdf.internal.pageSize.getHeight();
    const margin = 12; // Reduced margin
    const contentWidth = pdfWidth - (margin * 2);
    let currentY = margin;

    // Add Header
    pdf.setFontSize(16); pdf.setFont("helvetica", "bold"); pdf.setTextColor(40);
    pdf.text("Election Results", pdfWidth / 2, currentY, { align: 'center' }); currentY += 8;
    pdf.setFontSize(10); pdf.setFont("helvetica", "normal"); pdf.setTextColor(100);
    pdf.text("Election: <?php echo htmlspecialchars(addslashes($selected_election_title)); ?>", margin, currentY);
    pdf.text("Position: <?php echo htmlspecialchars(addslashes($selected_position_title)); ?>", pdfWidth - margin, currentY, { align: 'right' }); currentY += 6;
    pdf.setDrawColor(200); pdf.setLineWidth(0.2); pdf.line(margin, currentY, pdfWidth - margin, currentY); currentY += 8;

    // Process elements sequentially
    const elementsToRender = resultsContainer.querySelectorAll('.election-results-card');
    let elementPromise = Promise.resolve();

    elementsToRender.forEach((element, index) => {
        elementPromise = elementPromise.then(() => {
            return html2canvas(element, { scale: 2, useCORS: true, logging: false, backgroundColor: '#ffffff' })
                .then(canvas => {
                    const imgData = canvas.toDataURL('image/png'); // PNG often better for text
                    const imgProps = pdf.getImageProperties(imgData);
                    let imgHeight = (imgProps.height * contentWidth) / imgProps.width;

                    if (imgHeight > (pdfHeight - margin * 2)) { // If image itself is taller than page
                        imgHeight = pdfHeight - margin * 2; // Cap height
                        console.warn("Card content taller than PDF page, might be clipped.");
                    }

                    if (currentY + imgHeight > pdfHeight - margin) { // Check if it fits on current page
                        pdf.addPage();
                        currentY = margin; // Reset Y for new page
                    } else if (index > 0) {
                        currentY += 5; // Add spacing between elements
                    }

                    pdf.addImage(imgData, 'PNG', margin, currentY, contentWidth, imgHeight);
                    currentY += imgHeight; // Update Y position
                });
        });
    });

    elementPromise.then(() => {
        hideLoader(loader);
        pdf.save('election_results.pdf');
    }).catch(err => {
        hideLoader(loader);
        console.error("PDF Generation Error:", err);
        alert("Failed to generate PDF. See console for details.");
    });
}

// Export to Excel (using SheetJS/XLSX) - Refined
function exportToExcel() {
     if (typeof XLSX === 'undefined') {
         alert('Error: Excel export library (SheetJS) not loaded.'); return;
     }
     const loader = showLoader("Generating Excel file...");

    const data = [];
    const header = ['Election', 'Position', 'Candidate', 'Votes', 'Percentage (%)', 'Winner'];
    data.push(header);

    const electionCards = document.querySelectorAll('.election-results-card');
     if (electionCards.length === 0) { hideLoader(loader); alert("No results data to export."); return; }

    electionCards.forEach(electionCard => {
        const electionTitleElement = electionCard.querySelector('.card-header span:first-child');
        const electionTitle = electionTitleElement ? electionTitleElement.textContent.trim() : 'N/A';
        const positionElements = electionCard.querySelectorAll('.position-results');

        if (positionElements.length === 0) {
             data.push([electionTitle, 'No Positions Found', '', 0, 0.0, 'No']);
        } else {
             positionElements.forEach(positionDiv => {
                const positionHeaderElement = positionDiv.querySelector('.position-header');
                 let positionTitle = 'N/A';
                 if (positionHeaderElement) {
                    const match = positionHeaderElement.textContent.trim().match(/^(.*?)\s*\(/);
                    positionTitle = match ? match[1].trim() : positionHeaderElement.textContent.trim();
                 }

                const candidateItems = positionDiv.querySelectorAll('.candidate-result-item');
                 if (candidateItems.length === 0) {
                    data.push([electionTitle, positionTitle, 'No Candidates Found', 0, 0.0, 'No']);
                 } else {
                     candidateItems.forEach(item => {
                        const candidateNameElement = item.querySelector('.candidate-name');
                        let candidateName = 'N/A';
                        if (candidateNameElement) {
                             const nameClone = candidateNameElement.cloneNode(true);
                             const winnerBadge = nameClone.querySelector('.winner-badge');
                             if (winnerBadge) winnerBadge.remove();
                             candidateName = nameClone.textContent.trim();
                        }
                        const votes = parseInt(item.querySelector('.candidate-votes')?.textContent.trim().split(' ')[0].replace(/,/g, ''), 10) || 0;
                        const percentage = parseFloat(item.querySelector('.candidate-percentage')?.textContent.trim().replace('%', '')) || 0.0;
                        const isWinner = item.querySelector('.winner-badge') ? 'Yes' : 'No';
                        data.push([ electionTitle, positionTitle, candidateName, votes, percentage, isWinner ]);
                    });
                 }
            });
        }
    });

    try {
        const ws = XLSX.utils.aoa_to_sheet(data);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Election Results');
        // Auto-adjust column widths
        const cols = header.map((_, i) => ({ wch: data.reduce((w, r) => Math.max(w, (r[i] || '').toString().length), 10) + 1 })); // Min width 10 + padding
        ws['!cols'] = cols;
        XLSX.writeFile(wb, 'election_results.xlsx');
        hideLoader(loader);
     } catch (e) {
         hideLoader(loader); console.error("Excel Generation Error:", e); alert("Failed to generate Excel file.");
     }
}

</script>

</body>
</html> 