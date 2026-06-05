<?php declare(strict_types=1); // Enforce strict types

// --- Initialization & Config ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Error Reporting (Development vs Production)
// Dev settings:
error_reporting(E_ALL);
ini_set('display_errors', '1');
// Production settings:
// error_reporting(0);
// ini_set('display_errors', '0');
// ini_set('log_errors', '1');

// Set timezone consistently
date_default_timezone_set('Africa/Nairobi'); // EAT

// --- Includes ---
// require_once "includes/init.php"; // Include if needed
require_once "../config/database.php"; // Provides $conn (mysqli object) - Adjust path if needed
require_once "../config/votes_schema.php";
require_once "../config/candidate_description_column.php";
require_once "../config/candidate_class_section.php";
require_once "includes/session.php"; // Provides isAdminLoggedIn() function - Adjust path if needed

// --- Admin Authentication Check ---
if (!isAdminLoggedIn()) {
    header("Location: login.php"); // Redirect to admin login
    exit();
}

// --- Initial Variables ---
$results_error = null;
$elections = [];
$all_positions = []; // Holds all positions for the filter dropdown
$grouped_results = []; // Stores processed results grouped by election/position
$stats = ['total_codes' => 0, 'total_votes' => 0, 'used_codes' => 0, 'voter_turnout' => 0];
$selected_election_id = null;
$selected_position_id = null;
$selected_election_title = "All Elections"; // Default title
$selected_position_title = "All Positions"; // Default title
$logo_path_relative = '../assets/images/gombe-ss-logo.png'; // Path relative to this script (in admin?)
$logo_base64 = null; // For print watermark
$positions_flat = [];
$paginated_positions = [];
$total_candidates = 0;
$total_positions = 0;
$current_page = 1;
$total_pages = 0;
$show_stats = true;
$show_signatures = false;
$election_titles_by_id = [];

// --- Pre-Check DB Connection ---
if (!$conn || $conn->connect_error) {
    $results_error = "Database connection failed: " . ($conn ? $conn->connect_error : 'Check config');
    error_log($results_error);
    // Allow page to render but show error prominently
} else {
    ensure_candidate_class_section_column($conn);
}

// --- Logo Processing (for Print Watermark) ---
if (!$results_error) { // Only proceed if DB connection is okay initially
    try {
        $base_dir = dirname(__DIR__); // Assumes this script is in /admin/
        $logo_path_absolute = realpath($base_dir . '/' . ltrim($logo_path_relative, '/'));

        if ($logo_path_absolute && file_exists($logo_path_absolute)) {
            $image_type = strtolower(pathinfo($logo_path_absolute, PATHINFO_EXTENSION));
            if (in_array($image_type, ['png', 'jpg', 'jpeg', 'gif', 'webp'])) {
                $logo_data = @file_get_contents($logo_path_absolute); // Use @ to suppress warnings on failure
                if ($logo_data !== false) {
                    $logo_base64 = 'data:image/' . $image_type . ';base64,' . base64_encode($logo_data);
                } else { error_log("Failed to read logo file content: " . $logo_path_absolute); }
            } else { error_log("Unsupported logo image type: " . $image_type); }
        } else { error_log("Logo file not found or path invalid: " . ($logo_path_absolute ?: $logo_path_relative)); }
    } catch (Exception $e) { error_log("Error processing logo file: " . $e->getMessage()); }
    if (!$logo_base64) { error_log("Logo Base64 could not be generated for print watermark."); }
}

// --- Data Fetching (Elections & All Positions for Filters) ---
if (!$results_error) { // Proceed only if no critical error yet
    try {
        // Fetch Elections
        $elections_sql = "SELECT id, title FROM elections ORDER BY created_at DESC, title ASC";
        $result_e = $conn->query($elections_sql);
        if ($result_e === false) throw new mysqli_sql_exception("Error fetching elections: " . $conn->error, $conn->errno);
        while ($row = $result_e->fetch_assoc()) { $elections[] = $row; }
        $result_e->free_result();
        foreach ($elections as $e) {
            $election_titles_by_id[(int)$e['id']] = $e['title'];
        }

        // Fetch All Positions
        $positions_sql = "SELECT id, election_id, title FROM positions ORDER BY election_id ASC, id ASC";
        $result_p = $conn->query($positions_sql);
        if ($result_p === false) throw new mysqli_sql_exception("Error fetching all positions: " . $conn->error, $conn->errno);
        while ($row = $result_p->fetch_assoc()) { $all_positions[] = $row; }
        $result_p->free_result();

    } catch (mysqli_sql_exception $e) {
        error_log("Error fetching filters data: " . $e->getMessage());
        $results_error = "Could not load election/position filter lists.";
        $elections = []; $all_positions = []; // Clear on error
    }
}

// --- Process Filters (Get validated IDs from URL) ---
$selected_election_id_input = filter_input(INPUT_GET, 'election_id', FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
$selected_position_id_input = filter_input(INPUT_GET, 'position_id', FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);

if ($selected_election_id_input) {
    $selected_election_id = $selected_election_id_input;
    foreach ($elections as $e) { if ($e['id'] == $selected_election_id) {$selected_election_title = $e['title']; break;} }
}
if ($selected_position_id_input) {
    $selected_position_id = $selected_position_id_input;
     foreach ($all_positions as $p) {
        if ($p['id'] == $selected_position_id) {
            $selected_position_title = $p['title'];
            // If position filter is set, force election filter to match
            if (!$selected_election_id || $selected_election_id != $p['election_id']) {
                $selected_election_id = (int)$p['election_id']; // Ensure it's an int
                foreach ($elections as $e) { if ($e['id'] == $selected_election_id) {$selected_election_title = $e['title']; break;} }
            }
            break;
        }
    }
}


// --- Statistics Calculation (using MySQLi Direct SQL) ---
if (!$results_error) { // Proceed only if no critical error yet
    try {
        // Build WHERE clauses based on validated filters
        $where_clause_vc = ""; // For voting_codes table (aliased as vc)
        $where_clause_votes_join = ""; // For queries joining votes (using aliases e, p)

        if ($selected_election_id) {
            $where_clause_vc = " WHERE vc.election_id = $selected_election_id ";
            $where_clause_votes_join = " WHERE e.id = $selected_election_id ";
            if ($selected_position_id) {
                 // Note: Total Codes doesn't filter by position, only votes do
                 $where_clause_votes_join .= " AND p.id = $selected_position_id ";
            }
        } elseif ($selected_position_id) {
             // If only position is selected, find its election ID first
             // This case is handled above by forcing selected_election_id, but added as defensive check
             $pos_election_id = null;
             foreach($all_positions as $p) { if($p['id'] == $selected_position_id) {$pos_election_id = (int)$p['election_id']; break;} }
             if($pos_election_id) {
                  $where_clause_vc = " WHERE vc.election_id = $pos_election_id "; // Should not happen often
                  $where_clause_votes_join = " WHERE e.id = $pos_election_id AND p.id = $selected_position_id ";
             }
        }


        // --- Total Codes ---
        $sql_total_codes = "SELECT COUNT(vc.id) as total FROM voting_codes vc" . $where_clause_vc;
        $res_total_codes = $conn->query($sql_total_codes);
        if ($res_total_codes === false) throw new mysqli_sql_exception("Stats Error (Total Codes): " . $conn->error, $conn->errno);
        $stats['total_codes'] = (int)($res_total_codes->fetch_assoc()['total'] ?? 0);
        $res_total_codes->free_result();
        // error_log("Stats - Total Codes: {$stats['total_codes']} (Query: $sql_total_codes)");

        // --- Total Votes Cast ---
        $sql_total_votes = "SELECT COUNT(v.id) as total FROM votes v
                            INNER JOIN candidates c ON v.candidate_id = c.id
                            INNER JOIN positions p ON c.position_id = p.id
                            INNER JOIN elections e ON p.election_id = e.id"
                           . $where_clause_votes_join; // Use join alias based WHERE
        $res_total_votes = $conn->query($sql_total_votes);
        if ($res_total_votes === false) throw new mysqli_sql_exception("Stats Error (Total Votes): " . $conn->error, $conn->errno);
        $stats['total_votes'] = (int)($res_total_votes->fetch_assoc()['total'] ?? 0);
        $res_total_votes->free_result();
        // error_log("Stats - Total Votes: {$stats['total_votes']} (Query: $sql_total_votes)");


        // --- Used Codes --- (Count distinct codes present in the votes table, respecting filters)
        $sql_used_codes = "SELECT COUNT(DISTINCT v.voting_code_id) as total FROM votes v
                           INNER JOIN candidates c ON v.candidate_id = c.id
                           INNER JOIN positions p ON c.position_id = p.id
                           INNER JOIN elections e ON p.election_id = e.id"
                          . $where_clause_votes_join; // Use join alias based WHERE
        $res_used_codes = $conn->query($sql_used_codes);
        if ($res_used_codes === false) throw new mysqli_sql_exception("Stats Error (Used Codes): " . $conn->error, $conn->errno);
        $stats['used_codes'] = (int)($res_used_codes->fetch_assoc()['total'] ?? 0);
        $res_used_codes->free_result();
        // error_log("Stats - Used Codes: {$stats['used_codes']} (Query: $sql_used_codes)");


        // --- Calculate Voter Turnout & Unused Codes ---
        $stats['voter_turnout'] = ($stats['total_codes'] > 0)
            ? round(((int)$stats['used_codes'] / (int)$stats['total_codes']) * 100, 1)
            : 0;
        $stats['unused_codes'] = $stats['total_codes'] - $stats['used_codes'];
        // error_log("Stats - Turnout: {$stats['voter_turnout']}%");

    } catch (mysqli_sql_exception $e) {
        error_log("Statistics Calculation DB Error: " . $e->getMessage());
        if(is_null($results_error)) $results_error = "Could not calculate election statistics.";
        $stats = ['total_codes' => 'N/A', 'total_votes' => 'N/A', 'used_codes' => 'N/A', 'voter_turnout' => 'N/A']; // Indicate error
    }
} // End stats calculation block


// --- Results Fetching (using MySQLi Direct SQL) ---
if (!$results_error) { // Proceed only if no critical error yet
    try {
        $where_clauses_results = []; // For main results query WHERE

        $votesFullCols = votes_has_election_position_columns($conn);
        if ($votesFullCols) {
            $voteCountExpr = 'COUNT(v.id)';
            $voteJoinSql = 'LEFT JOIN votes v ON c.id = v.candidate_id AND v.position_id = p.id AND v.election_id = e.id';
        } else {
            $voteCountExpr = 'SUM(CASE WHEN vcscope.id IS NOT NULL THEN 1 ELSE 0 END)';
            $voteJoinSql = 'LEFT JOIN votes v ON c.id = v.candidate_id
        LEFT JOIN voting_codes vcscope ON vcscope.id = v.voting_code_id AND vcscope.election_id = e.id';
        }

        $candidateTextCol = candidate_text_column_name($conn);

        // Base query starting from positions
        $results_sql = "SELECT
            e.id as election_id, COALESCE(e.title, 'Untitled Election') as election_title, e.status as election_status,
            p.id as position_id, COALESCE(p.title, 'Untitled Position') as position_title,
            c.id as candidate_id, COALESCE(c.name, 'N/A') as candidate_name, c.photo as candidate_photo,
            " . candidate_class_section_select_expr($conn, 'c', 'candidate_class_section') . ",
            $voteCountExpr as vote_count
        FROM positions p
        INNER JOIN elections e ON p.election_id = e.id
        LEFT JOIN candidates c ON p.id = c.position_id -- Show positions even with no candidates
        $voteJoinSql
        ";

        // Apply filters (using validated integer IDs)
        if ($selected_election_id) { $where_clauses_results[] = " e.id = $selected_election_id "; }
        if ($selected_position_id) { $where_clauses_results[] = " p.id = $selected_position_id "; }

        if (!empty($where_clauses_results)) {
            $results_sql .= " WHERE " . implode(" AND ", $where_clauses_results);
        }

        // Group by all non-aggregated columns to get vote count per candidate (or show candidate with 0)
        $results_sql .= " GROUP BY e.id, p.id, c.id "; // Grouping by election, position, AND candidate

        // Order positions by when they were added (id), not alphabetically
        $results_sql .= " ORDER BY e.created_at DESC, p.id ASC, vote_count DESC, c.name ASC";

        // Execute main results query
        $results_res = $conn->query($results_sql);
        if ($results_res === false) {
            throw new mysqli_sql_exception("Results Query Error: " . $conn->error . " | SQL: " . $results_sql, $conn->errno);
        }

        // Group results in PHP (refined for clarity)
        $raw_results = [];
        while ($row = $results_res->fetch_assoc()) { $raw_results[] = $row; }
        $results_res->free_result();
        // error_log("Results Query executed. Found " . count($raw_results) . " rows.");

        // Process into desired nested structure
        $grouped_results = [];
        foreach ($raw_results as $row) {
            $eid = (int)$row['election_id'];
            $pid = (int)$row['position_id'];
            $cid = $row['candidate_id'] ? (int)$row['candidate_id'] : null; // Candidate ID can be null if position has no candidates

            // Initialize election group
            if (!isset($grouped_results[$eid])) {
                $grouped_results[$eid] = [
                    'id' => $eid,
                    'title' => $row['election_title'],
                    'status' => $row['election_status'],
                    'positions' => []
                ];
            }
            // Initialize position group
            if (!isset($grouped_results[$eid]['positions'][$pid])) {
                $grouped_results[$eid]['positions'][$pid] = [
                    'id' => $pid,
                    'title' => $row['position_title'],
                    'candidates' => [],
                    'total_votes' => 0 // Initialize position total
                ];
            }
            // Add candidate data if a candidate exists for this row
            if ($cid !== null) {
                $vote_count_int = (int)$row['vote_count'];
                $grouped_results[$eid]['positions'][$pid]['candidates'][] = [
                    'candidate_id' => $cid, // Keep original key for consistency
                    'candidate_name' => $row['candidate_name'],
                    'candidate_photo' => $row['candidate_photo'],
                    'candidate_class_section' => trim((string)($row['candidate_class_section'] ?? '')),
                    'vote_count' => $vote_count_int,
                    'percentage' => 0.0, // Calculated later
                    'is_winner' => false // Determined later
                 ];
            }
        } // End grouping loop

        // Calculate total votes per position, percentages, and identify winners
        foreach ($grouped_results as $eid => &$election_data) { // Use reference
            if (!empty($election_data['positions'])) {
                foreach ($election_data['positions'] as $pid => &$position_data) { // Use reference
                    $total_position_votes = 0;
                    $max_votes = 0;
                    if (!empty($position_data['candidates'])) {
                        // First pass: Calculate total and find max votes
                        foreach ($position_data['candidates'] as $candidate) {
                            $vote_count = (int)$candidate['vote_count'];
                            $total_position_votes += $vote_count;
                            if ($vote_count > $max_votes) { $max_votes = $vote_count; }
                        }
                        $position_data['total_votes'] = $total_position_votes;

                        // Second pass: Calculate percentage and mark winners
                        if ($total_position_votes > 0) {
                            foreach ($position_data['candidates'] as &$candidate) { // Use reference
                                $candidate['percentage'] = round(((int)$candidate['vote_count'] / $total_position_votes) * 100, 1);
                                $candidate['is_winner'] = ($max_votes > 0 && (int)$candidate['vote_count'] === $max_votes);
                            } unset($candidate); // Break reference
                        } else { // No votes cast
                            foreach ($position_data['candidates'] as &$candidate) { $candidate['percentage'] = 0; $candidate['is_winner'] = false; } unset($candidate);
                        }
                    }
                    // If no candidates, total_votes remains 0
                } unset($position_data); // Break reference
            }
        } unset($election_data); // Break reference

    } catch (mysqli_sql_exception $e) {
        error_log("Results Fetching/Processing DB Error: " . $e->getMessage());
        if(is_null($results_error)) $results_error = "Could not fetch or process election results due to a database error.";
        $grouped_results = []; // Ensure empty on error
    } catch (Exception $e) {
         error_log("Results Fetching/Processing General Error: " . $e->getMessage());
         if(is_null($results_error)) $results_error = "An unexpected error occurred while processing results.";
         $grouped_results = [];
    }

} // End initial data fetching block

// --- Build flat position list (show every position and every candidate on screen) ---
if (!$results_error && !empty($grouped_results)) {
    foreach ($grouped_results as $election_data) {
        foreach ($election_data['positions'] as $position_data) {
            $candidate_list = $position_data['candidates'] ?? [];
            $total_candidates += count($candidate_list);
            $positions_flat[] = [
                'election_id' => (int)$election_data['id'],
                'election_title' => $election_data['title'],
                'election_status' => $election_data['status'],
                'position_id' => (int)$position_data['id'],
                'position_title' => $position_data['title'],
                'position_total_votes' => (int)$position_data['total_votes'],
                'candidates' => $candidate_list,
            ];
        }
    }
    $total_positions = count($positions_flat);
    $paginated_positions = $positions_flat;
    $total_pages = 1;
    $current_page = 1;
    $show_stats = true;
    $show_signatures = $total_positions > 0;
}

/**
 * Resolve candidate photo path for display.
 */
function results_candidate_photo_path(array $candidate): string
{
    $photo_path = '../assets/images/default-avatar.png';
    if (!empty($candidate['candidate_photo'])) {
        $relative_photo_path = '../' . ltrim($candidate['candidate_photo'], '/');
        if (strpos($relative_photo_path, '../uploads/candidates/') === 0 && file_exists($relative_photo_path)) {
            $photo_path = $relative_photo_path;
        }
    }
    return $photo_path;
}

// --- Include HTML Header ---
require_once "includes/header.php";
?>

<style nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
    /* --- Styles (Copied from previous version, seems reasonable) --- */
     :root { --primary-color: #4e73df; --secondary-color: #858796; --success-color: #1cc88a; --info-color: #36b9cc; --warning-color: #f6c23e; --danger-color: #e74a3b; --light-color: #f8f9fc; --lighter-gray: #eaecf4; --dark-color: #5a5c69; --white-color: #fff; --font-family-sans-serif: "Nunito", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji"; --border-color: #e3e6f0; --shadow-sm: 0 .125rem .25rem rgba(0,0,0,.075); --shadow: 0 .15rem 1.75rem 0 rgba(58,59,69,.15); --shadow-lg: 0 1rem 3rem rgba(0,0,0,.175); --border-radius: .35rem; --border-radius-lg: .5rem; --winner-gold: #ffd700; --winner-gold-darker: #f0c400; } body { font-family: var(--font-family-sans-serif); background-color: var(--light-color); color: var(--dark-color); font-size: 0.95rem; } .container-fluid { padding: 1.5rem; } .page-header { border-bottom: 1px solid var(--border-color); margin-bottom: 1.5rem; padding-bottom: 1rem; } .page-header h1 { color: var(--primary-color); font-weight: 600; } .filter-controls { background-color: var(--white-color); padding: 1rem 1.25rem; border-radius: var(--border-radius); box-shadow: var(--shadow-sm); border: 1px solid var(--border-color); margin-bottom: 1.5rem; } .filter-controls .form-label { font-size: .8rem; font-weight: 600; color: var(--secondary-color); margin-bottom: .25rem; display: block; } .filter-controls .form-select, .filter-controls .btn { font-size: .875rem; } .filter-controls .btn i { vertical-align: -1px; } .stats-card { transition: all 0.2s ease-in-out; border: none; border-left: 4px solid var(--primary-color); border-radius: var(--border-radius); overflow: hidden; background-color: var(--white-color); box-shadow: var(--shadow-sm); } .stats-card .card-body { padding: 1rem 1.25rem; } .stats-card:hover { transform: scale(1.02); box-shadow: var(--shadow) !important; } .stats-card-icon { font-size: 2.2rem; opacity: 0.15; transition: opacity 0.3s ease; } .stats-card:hover .stats-card-icon { opacity: 0.25; } .stats-card .stat-label { font-size: .75rem; font-weight: 700; text-transform: uppercase; margin-bottom: .1rem; color: var(--secondary-color); } .stats-card .stat-value { font-size: 1.7rem; font-weight: 700; line-height: 1.2; color: var(--dark-color); } .stats-card .stat-value #voterTurnout + span { font-size: 1.3rem; color: var(--secondary-color); margin-left: 1px; } .stats-card.border-left-primary { border-left-color: var(--primary-color); } .stats-card.border-left-primary .stats-card-icon { color: var(--primary-color); } .stats-card.border-left-success { border-left-color: var(--success-color); } .stats-card.border-left-success .stats-card-icon { color: var(--success-color); } .stats-card.border-left-info { border-left-color: var(--info-color); } .stats-card.border-left-info .stats-card-icon { color: var(--info-color); } .stats-card.border-left-warning { border-left-color: var(--warning-color); } .stats-card.border-left-warning .stats-card-icon { color: var(--warning-color); } #resultsContainer { position: relative; background-color: transparent; } #resultsContainer::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background-image: <?php echo $logo_base64 ? "url('" . $logo_base64 . "')" : "none"; ?>; background-repeat: repeat; background-position: center center; background-size: 200px; opacity: 0.025; pointer-events: none; z-index: 0; } .election-results-card { margin-bottom: 2rem; border-radius: var(--border-radius-lg); border: none; box-shadow: var(--shadow); background-color: var(--white-color); position: relative; z-index: 1; overflow: hidden; } .election-results-card .card-header { background: linear-gradient(to right, var(--primary-color), #6e8efb); color: var(--white-color); font-weight: 600; font-size: 1.15rem; border-bottom: none; padding: .8rem 1.25rem; } .election-results-card .card-header .badge { font-size: .75em; padding: .3em .6em; vertical-align: middle; } .card-body { padding: 1.25rem; } .position-header { padding: .75rem 0; margin: 0 0 1rem; border-bottom: 2px solid var(--primary-color); font-weight: 700; color: var(--primary-color); font-size: 1.15rem; text-transform: uppercase; letter-spacing: .5px; text-align: center; } .position-header small { font-weight: 400; font-size: 0.85rem; color: var(--secondary-color); text-transform: none; letter-spacing: normal; display: block; margin-top: .25rem; } .results-candidate-sheet { margin-bottom: 1rem; } .candidate-result-item { display: flex; align-items: center; gap: .75rem; padding: .4rem .65rem; margin-bottom: .35rem; border: 1px solid var(--border-color); border-radius: var(--border-radius); background: var(--white-color); box-shadow: var(--shadow-sm); } .candidate-result-item:last-child { margin-bottom: 0; } .candidate-photo-col { flex: 0 0 auto; } .candidate-photo-frame { width: 100px; height: 120px; border: 1.5px solid var(--dark-color); border-radius: 3px; overflow: hidden; background: #f0f2f8; flex-shrink: 0; } .candidate-photo { width: 100%; height: 100%; object-fit: cover; display: block; } .candidate-details-col { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; justify-content: center; gap: .2rem; } .candidate-name-row { display: flex; flex-wrap: wrap; align-items: baseline; gap: .25rem .45rem; line-height: 1.2; } .candidate-name { font-weight: 700; margin: 0; font-size: .8rem; color: var(--dark-color); text-transform: uppercase; } .candidate-meta { font-size: .72rem; color: var(--secondary-color); } .candidate-progress-wrap { width: 100%; } .candidate-progress { height: 20px; border-radius: 10px; background-color: var(--lighter-gray); overflow: hidden; position: relative; box-shadow: inset 0 1px 2px rgba(0,0,0,.075); } .candidate-progress .progress-bar { height: 100%; background: linear-gradient(90deg, var(--primary-color), var(--info-color)); border-radius: 10px 0 0 10px; transition: width .5s ease; min-width: 0; } .candidate-progress .progress-percent-label { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: .7rem; font-weight: 700; color: var(--dark-color); pointer-events: none; z-index: 1; text-shadow: 0 0 3px #fff, 0 0 3px #fff; } .candidate-vote-count { font-size: .7rem; color: var(--secondary-color); font-weight: 600; margin: 0; line-height: 1.1; } .winner-badge { display: inline-block; padding: .25em .6em; font-size: .7em; font-weight: 700; line-height: 1; color: var(--dark-color); text-align: center; white-space: nowrap; vertical-align: middle; border-radius: .25rem; background: linear-gradient(to right, var(--winner-gold), var(--winner-gold-darker)); box-shadow: 0 1px 2px rgba(0,0,0,.2); margin-left: .5rem; transform: translateY(-2px); } .winner-badge i { margin-right: .2rem; } .no-results-card { border-style: dashed; border-color: var(--border-color); } .no-results { padding: 3rem 1rem; text-align: center; color: var(--secondary-color); } .no-results i { font-size: 3rem; margin-bottom: 1rem; display: block; opacity: 0.5; } #processingLoader { background-color: rgba(255, 255, 255, 0.7); backdrop-filter: blur(3px);} #processingLoader > div { color: var(--primary-color); font-weight: 500; background-color: var(--white-color); padding: 1rem 1.5rem; border-radius: var(--border-radius); box-shadow: var(--shadow-lg); display: flex; align-items: center;} #processingLoader .spinner-border { color: var(--primary-color) !important; width: 1.5rem; height: 1.5rem;}
    .results-pagination { margin: 1.5rem 0 1rem; }
    .results-pagination .page-link { font-size: .875rem; }
    .results-page-indicator { text-align: center; font-size: .85rem; color: var(--secondary-color); margin-top: .5rem; }
    .signature-section { margin-top: 2rem; padding-top: 1.25rem; border-top: 2px solid var(--dark-color); }
    .signature-row { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 1.5rem; }
    .signature-row-two .signature-item { flex: 1 1 40%; max-width: 48%; }
    .signature-item { flex: 1 1 180px; text-align: center; }
    .signature-label { font-size: .75rem; font-weight: 700; text-transform: uppercase; margin-bottom: .5rem; color: var(--dark-color); }
    .signature-line { border-bottom: 1px solid var(--dark-color); height: 2.5rem; margin: 0 auto; max-width: 220px; }
    .signature-hint { font-size: .75rem; color: var(--secondary-color); margin-top: .35rem; font-style: italic; }
    .print-position-page-footer { display: none; }
    @media (max-width: 767px) {
        .candidate-result-item { flex-direction: row; align-items: center; }
        .candidate-photo-frame { width: 80px; height: 96px; }
        .candidate-details-col { width: auto; }
    }
    @media print {
        @page {
            size: A4 portrait;
            margin: 12mm 12mm 18mm 12mm;
        }
        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        body { background-color: #fff !important; color: #000 !important; font-size: 10pt; margin: 0 !important; padding: 0 !important; }
        .sidebar, .navbar, .filter-controls, #sidebarToggle, .btn, .dropdown, #processingLoader { display: none !important; }
        .main-content, .main-content.expanded { margin-left: 0 !important; width: 100% !important; padding: 0 !important; }
        .container-fluid { padding: 0 0 14mm 0 !important; width: 100% !important; max-width: 100% !important; }
        body::after { content: ''; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-image: <?php echo $logo_base64 ? "url('" . $logo_base64 . "')" : "none"; ?>; background-repeat: repeat; background-position: center; background-size: 100px; opacity: 0.04; z-index: -1; }
        .page-header { display: none !important; }
        
        #statsCardsRow { display: grid !important; grid-template-columns: repeat(4, 1fr) !important; gap: 3mm !important; margin: 0 0 5mm 0 !important; }
        .row.g-3.mb-4 > [class*="col-"] { width: 100% !important; max-width: 100% !important; padding: 0 !important; margin: 0 !important; }
        .stats-card { height: 22mm !important; min-height: 22mm !important; border: 1px solid #ccc !important; border-left: 3px solid var(--primary-color) !important; border-radius: 4px !important; background: #fff !important; box-shadow: none !important; overflow: hidden !important; break-inside: avoid !important; page-break-inside: avoid !important; }
        .border-left-success { border-left-color: var(--success-color) !important; }
        .border-left-info { border-left-color: var(--info-color) !important; }
        .border-left-warning { border-left-color: var(--warning-color) !important; }
        .stats-card .card-body { padding: 3mm 4mm !important; height: 100% !important; }
        .stats-card .stat-label { font-size: 6pt !important; font-weight: 700 !important; text-transform: uppercase !important; color: #555 !important; line-height: 1 !important; margin-bottom: 1mm !important; }
        .stats-card .stat-value { font-size: 14pt !important; font-weight: 700 !important; color: #000 !important; line-height: 1 !important; }
        .stats-card-icon { display: none !important; }
        
        #resultsContainer { margin: 0 !important; padding: 0 !important; overflow: visible !important; }
        #resultsContainer::before { display: none !important; }
        .card { overflow: visible !important; }
        .election-results-card { display: block !important; border: 1px solid #ccc !important; border-radius: 4px !important; background: #fff !important; box-shadow: none !important; overflow: visible !important; margin: 0 0 4mm 0 !important; break-inside: auto !important; page-break-inside: auto !important; }
        .election-results-card .card-header { background: linear-gradient(135deg, var(--primary-color), #5e7df0) !important; color: #fff !important; border: none !important; padding: 3mm 4mm !important; font-size: 11pt !important; font-weight: 700 !important; border-radius: 4px 4px 0 0 !important; min-height: 12mm !important; }
        .election-results-card .card-header .badge { border-radius: 4px !important; padding: 1.5px 6px !important; font-size: 7pt !important; background: rgba(255,255,255,0.2) !important; }
        .election-results-card .card-body { padding: 4mm !important; overflow: visible !important; }
        .position-results { margin: 0 0 4mm 0 !important; break-inside: avoid !important; page-break-inside: avoid !important; overflow: visible !important; page-break-after: always !important; }
        .position-results:last-child { page-break-after: auto !important; }
        .position-header { background: transparent !important; color: var(--primary-color) !important; border: none !important; border-bottom: 2px solid var(--primary-color) !important; padding: 0 0 2mm 0 !important; margin: 0 0 3mm 0 !important; font-size: 10pt !important; font-weight: 700 !important; text-transform: uppercase !important; }
        .position-header small { color: #333 !important; font-size: 8pt !important; text-transform: none !important; }
        .candidate-result-item { display: flex !important; flex-direction: row !important; align-items: center !important; gap: 3mm !important; width: 100% !important; padding: 2mm 3mm !important; border: 1px solid #ccc !important; border-radius: 2px !important; margin-bottom: 2.5mm !important; background: #fff !important; overflow: visible !important; break-inside: avoid !important; page-break-inside: avoid !important; }
        .candidate-photo-col { flex: 0 0 auto !important; display: block !important; }
        .candidate-photo-frame { width: 30mm !important; height: 36mm !important; border: 1px solid #000 !important; border-radius: 2px !important; overflow: hidden !important; background: #f5f5f5 !important; }
        .candidate-photo { width: 100% !important; height: 100% !important; object-fit: cover !important; display: block !important; }
        .candidate-details-col { flex: 1 1 auto !important; min-width: 0 !important; display: flex !important; flex-direction: column !important; gap: 1mm !important; }
        .candidate-name-row { display: flex !important; flex-wrap: wrap !important; align-items: baseline !important; gap: 1mm 2mm !important; }
        .candidate-name { font-size: 7pt !important; font-weight: 700 !important; color: #000 !important; margin: 0 !important; text-transform: uppercase !important; }
        .candidate-meta { font-size: 6.5pt !important; color: #333 !important; }
        .candidate-progress { height: 5mm !important; border-radius: 2.5mm !important; background: #e9ecef !important; border: 1px solid #dee2e6 !important; overflow: hidden !important; position: relative !important; }
        .candidate-progress .progress-bar { height: 100% !important; background: linear-gradient(90deg, #4e73df, #36b9cc) !important; border-radius: 2.5mm 0 0 2.5mm !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .candidate-progress .progress-percent-label { font-size: 6pt !important; font-weight: 700 !important; color: #000 !important; text-shadow: none !important; }
        .candidate-vote-count { font-size: 6.5pt !important; color: #333 !important; font-weight: 600 !important; }
        .winner-badge { display: inline-block !important; background: linear-gradient(135deg, var(--winner-gold), var(--winner-gold-darker)) !important; color: #2c2c2c !important; border: 1px solid #b8860b !important; border-radius: 3px !important; padding: 1px 4px !important; font-size: 6pt !important; font-weight: 700 !important; margin-top: 1mm !important; white-space: nowrap !important; }
        .results-pagination, .results-page-indicator { display: none !important; }
        a { text-decoration: none !important; color: inherit !important; }
        .no-results { background: #fff !important; border-radius: 4px !important; }
        
        .signature-section { display: block !important; margin-top: 8mm !important; padding-top: 5mm !important; border-top: 2px solid #000 !important; page-break-inside: avoid !important; }
        .signature-row { display: flex !important; justify-content: space-between !important; gap: 10mm !important; }
        .signature-label { font-size: 8pt !important; font-weight: 700 !important; }
        .signature-line { border-bottom: 1px solid #000 !important; height: 8mm !important; }
        .signature-hint { font-size: 7pt !important; }
        .print-position-page-footer {
            display: block !important;
            text-align: center;
            font-size: 8pt;
            color: #555;
            margin-top: 6mm;
            padding-top: 2mm;
        }
        .position-results .signature-section {
            margin-top: 6mm !important;
            padding-top: 4mm !important;
        }
    }
</style>

<div class="container-fluid">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-4 page-header">
        <h1 class="h3 mb-3 mb-lg-0 text-primary font-weight-bold">Election Results</h1>
        <?php /* Maybe add a date range or other info here */ ?>
    </div>

    <div class="row mb-4 filter-controls">
         <div class="col-lg-5 col-md-6 mb-3 mb-md-0">
             <label for="electionSelect" class="form-label"><i class="bi bi-calendar3 me-1"></i>Filter by Election</label>
             <select class="form-select form-select-sm" id="electionSelect" aria-label="Filter by election">
                 <option value="">All Elections</option>
                 <?php foreach ($elections as $election): ?>
                     <option value="<?php echo $election['id']; ?>" <?php echo ($selected_election_id == $election['id']) ? 'selected' : ''; ?>>
                         <?php echo htmlspecialchars($election['title']); ?>
                     </option>
                 <?php endforeach; ?>
                 <?php if (empty($elections)): ?> <option value="" disabled>No elections found</option> <?php endif; ?>
             </select>
         </div>
         <div class="col-lg-5 col-md-6 mb-3 mb-md-0">
             <label for="positionSelect" class="form-label"><i class="bi bi-person-badge me-1"></i>Filter by Position</label>
             <select class="form-select form-select-sm" id="positionSelect" aria-label="Filter by position" <?php echo empty($all_positions) ? 'disabled' : ''; ?>>
                 <option value="">All Positions</option>
                 <?php
                 $show_election_in_position_label = !$selected_election_id && count($elections) > 1;
                 foreach ($all_positions as $p):
                     $pos_election_id = (int)$p['election_id'];
                     if ($selected_election_id && $pos_election_id !== $selected_election_id) {
                         continue;
                     }
                     $pos_label = $p['title'];
                     if ($show_election_in_position_label && isset($election_titles_by_id[$pos_election_id])) {
                         $pos_label .= ' (' . $election_titles_by_id[$pos_election_id] . ')';
                     }
                 ?>
                     <option value="<?php echo (int)$p['id']; ?>" data-election-id="<?php echo $pos_election_id; ?>" <?php echo ($selected_position_id === (int)$p['id']) ? 'selected' : ''; ?>>
                         <?php echo htmlspecialchars($pos_label); ?>
                     </option>
                 <?php endforeach; ?>
             </select>
         </div>
         <div class="col-lg-2 col-md-12 d-flex align-items-end justify-content-center justify-content-lg-end gap-2">
             <button type="button" class="btn btn-sm btn-outline-secondary w-100 w-lg-auto" id="printButton" title="Print Results">
                 <i class="bi bi-printer"></i><span class="d-none d-lg-inline ms-1">Print</span>
             </button>
             <?php /* Add Export Buttons Here if needed, driven by JS */ ?>
         </div>
    </div>

    <?php if ($show_stats): ?>
    <div class="row g-3 mb-4" id="statsCardsRow">
        <div class="col-xl-3 col-md-6">
            <div class="card stats-card border-left-primary h-100">
                <div class="card-body"> <div class="row g-0 align-items-center"> <div class="col"> <div class="stat-label">Total Codes</div> <div class="stat-value"><?php echo ($stats['total_codes'] === 'N/A' ? 'N/A' : number_format($stats['total_codes'])); ?></div> </div> <div class="col-auto"> <i class="bi bi-ticket-detailed-fill stats-card-icon"></i> </div> </div> </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
             <div class="card stats-card border-left-success h-100">
                 <div class="card-body"> <div class="row g-0 align-items-center"> <div class="col"> <div class="stat-label">Votes Cast</div> <div class="stat-value"><?php echo ($stats['total_votes'] === 'N/A' ? 'N/A' : number_format($stats['total_votes'])); ?></div> </div> <div class="col-auto"> <i class="bi bi-check-all stats-card-icon"></i> </div> </div> </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card stats-card border-left-info h-100">
                 <div class="card-body"> <div class="row g-0 align-items-center"> <div class="col"> <div class="stat-label">Codes Used</div> <div class="stat-value"><?php echo ($stats['used_codes'] === 'N/A' ? 'N/A' : number_format($stats['used_codes'])); ?></div> </div> <div class="col-auto"> <i class="bi bi-person-check-fill stats-card-icon"></i> </div> </div> </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
             <div class="card stats-card border-left-warning h-100">
                 <div class="card-body"> <div class="row g-0 align-items-center"> <div class="col"> <div class="stat-label">Voter Turnout</div> <div class="stat-value"><span id="voterTurnout"><?php echo ($stats['voter_turnout'] === 'N/A' ? 'N/A' : $stats['voter_turnout']); ?></span><span>%</span></div> </div> <div class="col-auto"> <i class="bi bi-pie-chart-fill stats-card-icon"></i> </div> </div> </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div id="resultsContainer">
        <?php if ($results_error): ?>
            <div class="alert alert-danger shadow-sm" role="alert"> <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($results_error); ?> </div>
        <?php elseif (empty($grouped_results) || empty($positions_flat)): ?>
            <div class="card shadow-sm border-0 no-results-card"> <div class="card-body no-results"> <i class="bi bi-clipboard-x"></i> <p class="mb-0 h5">No Results Found</p> <p class="mt-2"><small>No election data matches the current filter criteria, or voting has not occurred.</small></p> </div> </div>
        <?php else: ?>
            <?php
            $last_election_id = null;
            $print_position_page = 0;
            foreach ($paginated_positions as $position_entry):
                $print_position_page++;
                $is_last_position_page = ($print_position_page === $total_positions);
                if ($last_election_id !== $position_entry['election_id']):
                    if ($last_election_id !== null): ?>
                        </div></div>
                    <?php endif;
                    $last_election_id = $position_entry['election_id'];
                    $status_class = 'secondary';
                    $status_text_class = 'white';
                    if ($position_entry['election_status'] === 'active') { $status_class = 'success'; }
                    elseif ($position_entry['election_status'] === 'pending') { $status_class = 'warning'; $status_text_class = 'dark'; }
                    elseif ($position_entry['election_status'] === 'completed') { $status_class = 'dark'; }
            ?>
                <div class="card shadow-sm election-results-card results-candidate-sheet">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><?php echo htmlspecialchars($position_entry['election_title']); ?></span>
                        <span class="badge bg-<?php echo $status_class; ?> text-<?php echo $status_text_class; ?>"><?php echo ucfirst(htmlspecialchars($position_entry['election_status'])); ?></span>
                    </div>
                    <div class="card-body">
            <?php endif; ?>

                <div class="position-results">
                    <h6 class="position-header">
                        <?php echo htmlspecialchars($position_entry['position_title']); ?>
                    </h6>
                    <?php if (empty($position_entry['candidates'])): ?>
                        <p class="text-muted text-center my-2 fst-italic small">No candidates contested for this position.</p>
                    <?php else: ?>
                        <?php foreach ($position_entry['candidates'] as $candidate):
                            $percentage = $candidate['percentage'] ?? 0;
                            $photo_path = htmlspecialchars(results_candidate_photo_path($candidate), ENT_QUOTES, 'UTF-8');
                        ?>
                        <div class="candidate-result-item<?php echo ($candidate['is_winner'] ?? false) ? ' is-winner' : ''; ?>">
                            <div class="candidate-photo-col">
                                <div class="candidate-photo-frame">
                                    <img src="<?php echo $photo_path; ?>" alt="<?php echo htmlspecialchars($candidate['candidate_name']); ?>" class="candidate-photo" onerror="this.style.display='none';">
                                </div>
                            </div>
                            <div class="candidate-details-col">
                                <div class="candidate-name-row">
                                    <h6 class="candidate-name"><?php echo htmlspecialchars($candidate['candidate_name']); ?></h6>
                                    <?php echo candidate_class_section_display_html($candidate['candidate_class_section'] ?? '', 'candidate-meta'); ?>
                                    <?php if ($candidate['is_winner'] ?? false): ?>
                                        <span class="winner-badge"><i class="bi bi-star-fill"></i> Winner</span>
                                    <?php endif; ?>
                                </div>
                                <div class="candidate-progress-wrap">
                                    <div class="progress candidate-progress" role="progressbar" aria-valuenow="<?php echo $percentage; ?>" aria-valuemin="0" aria-valuemax="100" title="<?php echo $percentage; ?>% of votes">
                                        <div class="progress-bar" style="width: <?php echo $percentage; ?>%;"></div>
                                        <span class="progress-percent-label"><?php echo $percentage; ?>%</span>
                                    </div>
                                </div>
                                <p class="candidate-vote-count mb-0"><?php echo number_format((int)$candidate['vote_count']); ?> vote<?php echo (int)$candidate['vote_count'] === 1 ? '' : 's'; ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if ($is_last_position_page && $show_signatures): ?>
                    <div class="signature-section" id="resultsSignatureSection">
                        <div class="signature-row signature-row-two">
                            <div class="signature-item">
                                <div class="signature-label">Patron Prefects</div>
                                <div class="signature-line"></div>
                            </div>
                            <div class="signature-item">
                                <div class="signature-label">Chairperson Electoral Commission</div>
                                <div class="signature-line"></div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="print-position-page-footer" aria-hidden="true">
                        Page <?php echo (int)$print_position_page; ?> of <?php echo (int)$total_positions; ?>
                    </div>
                </div>

            <?php endforeach;
            if ($last_election_id !== null): ?>
                    </div></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if (!empty($positions_flat)): ?>
        <p class="results-page-indicator mb-0 text-center">
            Showing <?php echo (int)$total_positions; ?> position(s) and <?php echo (int)$total_candidates; ?> candidate(s)
            <?php if ($selected_position_id): ?>
                &middot; filtered to <strong><?php echo htmlspecialchars($selected_position_title); ?></strong>
            <?php endif; ?>
        </p>
    <?php endif; ?>

 </div>
 <div id="processingLoader" class="position-fixed top-0 start-0 w-100 h-100 justify-content-center align-items-center" style="display: none; z-index: 1060;">
    <div><div class="spinner-border spinner-border-sm me-2" role="status"></div> <span id="loaderMessage">Processing...</span></div>
</div>


<?php require_once "includes/footer.php"; // Assumes includes Bootstrap JS etc. ?>

<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="https://unpkg.com/countup.js@2.8.0/dist/countUp.umd.js"></script>
<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>


<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
document.addEventListener('DOMContentLoaded', function() {

    // Store all positions data fetched via PHP for client-side filtering
    // Ensure PHP echoes valid JSON here, even if empty array []
    const allPositionsData = <?php echo json_encode($all_positions ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const electionTitlesById = <?php echo json_encode($election_titles_by_id ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const initialSelectedPositionId = <?php echo json_encode($selected_position_id ?? null); ?>;
    const showElectionInPositionLabel = <?php echo json_encode(!$selected_election_id && count($elections) > 1); ?>;

    const electionSelect = document.getElementById('electionSelect');
    const positionSelect = document.getElementById('positionSelect');
    const printBtn = document.getElementById('printButton');
    const pdfBtn = document.getElementById('exportPdfButton');
    const excelBtn = document.getElementById('exportExcelButton');

    // --- Helper: Escape HTML ---
    function escapeHtml(unsafe) { if (unsafe === null || typeof unsafe === 'undefined') return ''; const div = document.createElement('div'); div.textContent = unsafe; return div.innerHTML; }


    // --- Initialize Position Filter ---
    function updatePositionFilter() {
         if (!positionSelect || !electionSelect) return; // Exit if elements don't exist

         const currentElectionId = electionSelect.value;
         // Find the currently selected option's value, or use the initial ID from PHP if available
         const currentPositionValue = positionSelect.value || initialSelectedPositionId || "";

         positionSelect.innerHTML = '<option value="">All Positions</option>'; // Reset options

         let foundCurrentSelection = false;
         let visibleCount = 0;
         allPositionsData.forEach(position => {
             if (currentElectionId === "" || String(position.election_id) === currentElectionId) {
                 visibleCount++;
                 const option = document.createElement('option');
                 option.value = position.id;
                 let label = position.title;
                 if (showElectionInPositionLabel && electionTitlesById[String(position.election_id)]) {
                     label += ' (' + electionTitlesById[String(position.election_id)] + ')';
                 }
                 option.textContent = label;
                 option.dataset.electionId = position.election_id;

                 if (String(position.id) === String(currentPositionValue)) {
                     option.selected = true;
                     foundCurrentSelection = true;
                 }
                 positionSelect.appendChild(option);
             }
         });

         if (!foundCurrentSelection && currentPositionValue !== "") {
             positionSelect.value = "";
         }

         positionSelect.disabled = visibleCount === 0;
    }

    // Initial population and filtering based on page load state
    updatePositionFilter();


    // --- CountUp Animation ---
    if (typeof CountUp !== 'function') { console.error("CountUp library failed."); }
    else {
        console.log('Initializing CountUp animations...');
        const countUpOptions = { duration: 1.8, separator: ',', useEasing: true, useGrouping: true, enableScrollSpy: true, scrollSpyDelay: 80, scrollSpyOnce: true };
        const statsData = <?php echo json_encode($stats ?? []); ?>;
        console.log('Stats data for CountUp:', statsData);

        try {
            const elTotalCodes = document.getElementById('totalCodes'); // Assuming these IDs exist in your HTML based on commented section
            const elTotalVotes = document.getElementById('totalVotes');
            const elUsedCodes = document.getElementById('usedCodes');
            const elTurnout = document.getElementById('voterTurnout');

            // Helper to check if value is numeric before starting countup
            const startCountUp = (el, value, options) => {
                if (el && typeof value === 'number' && !isNaN(value)) {
                     new CountUp(el, value, options).start();
                } else if (el) {
                     el.textContent = value; // Display 'N/A' or 0 directly if not valid number
                     console.warn(`CountUp: Invalid value '${value}' for element ID ${el.id}`);
                } else { console.warn(`CountUp: Element not found for value ${value}`); }
            };

            startCountUp(elTotalCodes, statsData.total_codes, countUpOptions);
            startCountUp(elTotalVotes, statsData.total_votes, countUpOptions);
            startCountUp(elUsedCodes, statsData.used_codes, countUpOptions);
            startCountUp(elTurnout, statsData.voter_turnout, { ...countUpOptions, decimals: 1 });

        } catch (error) { console.error('CountUp Init Error:', error); }
    }

    // --- Event Listener Setup ---
    electionSelect?.addEventListener('change', function() {
         updatePositionFilter();
         filterResults();
    });
    positionSelect?.addEventListener('change', filterResults); // Reload page with position filter

    printBtn?.addEventListener('click', printResults);
    pdfBtn?.addEventListener('click', exportToPDF);
    excelBtn?.addEventListener('click', exportToExcel);

}); // End DOMContentLoaded


// --- Global Helper Functions ---

function filterResults() {
     const electionId = document.getElementById('electionSelect')?.value || "";
     const positionId = document.getElementById('positionSelect')?.value || "";
     const params = new URLSearchParams();
     if (electionId) params.set('election_id', electionId);
     if (positionId) params.set('position_id', positionId);
     const query = params.toString();
     window.location.href = 'results.php' + (query ? '?' + query : '');
}

function showLoader(message = "Processing...") {
     let loader = document.getElementById('processingLoader');
     if (!loader) { loader = document.createElement('div'); loader.id = 'processingLoader'; loader.style.cssText = `position:fixed; top:0; left:0; width:100%; height:100%; background-color:rgba(255,255,255,0.8); backdrop-filter:blur(4px); color:var(--primary-color); display:flex; justify-content:center; align-items:center; z-index:1060; font-size:1.1rem; flex-direction: column; gap: 1rem;`; loader.innerHTML = `<div><div class="spinner-border" style="width: 3rem; height: 3rem;" role="status"></div> <span id="loaderMessage" style="color: var(--dark-color); font-weight: 500; display: block; margin-top: 1rem;"></span></div>`; document.body.appendChild(loader); }
     loader.querySelector('#loaderMessage').textContent = message; loader.style.display = 'flex'; return loader;
}
function hideLoader(loaderElement) { if (loaderElement) loaderElement.style.display = 'none'; }


function printResults() {
    window.print();
}

// Export to PDF (Using jsPDF and html2canvas) - Needs libraries loaded
function exportToPDF() {
    const resultsContainer = document.getElementById('resultsContainer');
    if (!resultsContainer || typeof html2canvas === 'undefined' || typeof jspdf === 'undefined') { alert('PDF generation libraries not loaded.'); return; }
    const loader = showLoader("Generating PDF...");
    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF({ orientation: 'p', unit: 'mm', format: 'a4' });
    const pdfWidth = pdf.internal.pageSize.getWidth(); const margin = 10; const contentWidth = pdfWidth - margin * 2; let currentY = margin;

    // Add Header
    pdf.setFontSize(16); pdf.setFont("helvetica", "bold"); pdf.text("Election Results", pdfWidth / 2, currentY, { align: 'center' }); currentY += 8;
    pdf.setFontSize(10); pdf.setFont("helvetica", "normal");
    pdf.text("Election: <?php echo htmlspecialchars(addslashes($selected_election_title)); ?>", margin, currentY);
    pdf.text("Position: <?php echo htmlspecialchars(addslashes($selected_position_title)); ?>", pdfWidth - margin, currentY, { align: 'right' }); currentY += 6;
    pdf.setDrawColor(200); pdf.setLineWidth(0.2); pdf.line(margin, currentY, pdfWidth - margin, currentY); currentY += 8;

    // Process elements sequentially
    const elementsToRender = resultsContainer.querySelectorAll('.election-results-card'); let elementPromise = Promise.resolve();
    elementsToRender.forEach(element => { elementPromise = elementPromise.then(() => html2canvas(element, { scale: 1.5, useCORS: true, logging: false }).then(canvas => { const imgData = canvas.toDataURL('image/jpeg', 0.9); const imgProps = pdf.getImageProperties(imgData); let imgHeight = (imgProps.height * contentWidth) / imgProps.width; let pageBreak = false; if (currentY + imgHeight + margin > pdf.internal.pageSize.getHeight()) { if (imgHeight > pdf.internal.pageSize.getHeight() - margin * 2) { imgHeight = pdf.internal.pageSize.getHeight() - margin * 2; console.warn("Element too tall, might be clipped."); } else { pageBreak = true; } } if (pageBreak) { pdf.addPage(); currentY = margin; } else if (currentY > margin + 8) { currentY += 5; } pdf.addImage(imgData, 'JPEG', margin, currentY, contentWidth, imgHeight); currentY += imgHeight; })); });
    elementPromise.then(() => { pdf.save('election_results.pdf'); }).catch(err => { console.error("PDF Error:", err); alert("Failed to generate PDF."); }).finally(() => hideLoader(loader));
}

// Export to Excel (using SheetJS/XLSX) - Needs library loaded
function exportToExcel() {
     if (typeof XLSX === 'undefined') { alert('Excel export library (SheetJS) not loaded.'); return; }
     const loader = showLoader("Generating Excel...");
     const data = [['Election', 'Position', 'Candidate', 'Class / Section', 'Votes', 'Percentage (%)', 'Winner']]; // Header Row
     document.querySelectorAll('.election-results-card').forEach(eCard => { const eTitle = eCard.querySelector('.card-header span:first-child')?.textContent.trim() || 'N/A'; eCard.querySelectorAll('.position-results').forEach(pDiv => { const pTitleH = pDiv.querySelector('.position-header'); let pTitle = pTitleH ? pTitleH.textContent.trim() : 'N/A'; pDiv.querySelectorAll('.candidate-result-item').forEach(item => { const cNameEl = item.querySelector('.candidate-name'); let cName = cNameEl ? cNameEl.textContent.trim() : 'N/A'; const classEl = item.querySelector('.candidate-meta, .candidate-class-section'); let cClass = classEl ? classEl.textContent.trim().replace(/^\(|\)$/g, '') : ''; const voteText = item.querySelector('.candidate-vote-count')?.textContent.trim() || '0'; const votes = parseInt(voteText.replace(/,/g, ''), 10) || 0; const perc = parseFloat(item.querySelector('.progress-percent-label')?.textContent.trim().replace(/[()%]/g, '')) || 0.0; const isWinner = item.querySelector('.winner-badge') ? 'Yes' : 'No'; data.push([eTitle, pTitle, cName, cClass, votes, perc, isWinner]); }); if(pDiv.querySelectorAll('.candidate-result-item').length === 0) data.push([eTitle, pTitle, 'No Candidates', '', 0, 0.0, 'No']); }); if(eCard.querySelectorAll('.position-results').length === 0) data.push([eTitle, 'No Positions', '', '', 0, 0.0, 'No']); });
     if(data.length <= 1) { hideLoader(loader); alert("No data to export."); return; }
     try { const ws = XLSX.utils.aoa_to_sheet(data); const wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, ws, 'Election Results'); const cols = data[0].map((_, i) => ({ wch: data.reduce((w, r) => Math.max(w, (r[i] || '').toString().length), 10) + 1 })); ws['!cols'] = cols; XLSX.writeFile(wb, 'election_results.xlsx'); } catch(e) { console.error("Excel Error:", e); alert("Failed to generate Excel."); } finally { hideLoader(loader); }
}

</script>

</body>
</html>
<?php
// Final connection close
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $conn->close();
}
?>