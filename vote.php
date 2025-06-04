<?php declare(strict_types=1); // Enforce strict types

// Start session **before** any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Includes ---
require_once "config/database.php"; // Provides $conn (mysqli object)
// require_once "includes/init.php"; // Include if needed
// require_once "includes/session.php"; // Include if it contains helpers used here

// --- Configuration & Security ---
date_default_timezone_set('Africa/Nairobi'); // EAT
error_reporting(E_ALL); // Dev
ini_set('display_errors', '1'); // Dev
// Production: error_reporting(0); ini_set('display_errors', '0'); ini_set('log_errors', '1');

// --- Session Validation & Data Initialization ---
if (!isset($_SESSION['voting_code'], $_SESSION['election_id'], $_SESSION['voting_code_id'])) {  
    header("Location: index.php?error=session_invalid"); exit();
}
$voting_code_session = $_SESSION['voting_code'];
$election_id = (int)$_SESSION['election_id'];
$voting_code_db_id = (int)$_SESSION['voting_code_id'];

$error = ''; $election = null; $positions = []; $vote_processed_successfully = false;

// --- DB Connection Check ---
if (!$conn || $conn->connect_error) {
    error_log("Vote Page DB Connection Error: " . ($conn ? $conn->connect_error : 'Check config'));
    $error = "Database connection error. Please contact support.";
}

// --- Data Fetching and Pre-Checks (using MySQLi Direct SQL - ONLY if DB connection is OK) ---
if (!$error) {
    try {
        // 1. Fetch Election Details & Check if Active
        $sql_check_election = "SELECT id, title, description FROM elections WHERE id = $election_id AND status = 'active' LIMIT 1";
        $result_election = $conn->query($sql_check_election);
        if ($result_election === false) throw new mysqli_sql_exception("DB error checking election status: " . $conn->error, $conn->errno);
        if ($result_election->num_rows === 0) { $result_election->free_result(); throw new Exception("The election is no longer active."); }
        $election = $result_election->fetch_assoc(); $result_election->free_result();

        // 2. Re-validate Voting Code (Check if used)
        $sql_check_code = "SELECT is_used FROM voting_codes WHERE id = $voting_code_db_id LIMIT 1";
        $result_code = $conn->query($sql_check_code);
        if ($result_code === false) throw new mysqli_sql_exception("DB error re-validating code: " . $conn->error, $conn->errno);
        if ($result_code->num_rows === 0) { $result_code->free_result(); throw new Exception("Your voting code is no longer valid."); }
        $code_status = $result_code->fetch_assoc(); $result_code->free_result();
        if (!empty($code_status['is_used'])) throw new Exception("This voting code has already been used.");

        // 3. Fetch Positions and Associated Candidates
        $sql_positions_candidates = "SELECT p.id as position_id, p.title as position_title, c.id as candidate_id, c.name as candidate_name, c.photo as candidate_photo FROM positions p LEFT JOIN candidates c ON p.id = c.position_id WHERE p.election_id = $election_id ORDER BY p.id ASC, c.name ASC";
        $result_positions = $conn->query($sql_positions_candidates);
        if ($result_positions === false) throw new mysqli_sql_exception("DB error fetching positions/candidates: " . $conn->error, $conn->errno);

        if ($result_positions->num_rows === 0 && !$error) { // Check $error again in case it was set by previous queries implicitly
            $error = "Voting cannot proceed: No positions defined for this election.";
        } else {
            while ($row = $result_positions->fetch_assoc()) {
                $pos_id = (int)$row['position_id'];
                if (!isset($positions[$pos_id])) { $positions[$pos_id] = ['id' => $pos_id,'title' => $row['position_title'],'candidates' => []]; }
                if ($row['candidate_id'] !== null) {
                    $photo_path_for_html = 'assets/images/default-avatar.png'; // Default
                    if (!empty($row['candidate_photo'])) {
                        $potential_path = ltrim((string)$row['candidate_photo'], '/');
                        // Removed file_exists check as requested
                        // Basic check on path structure remains (optional)
                        if (strpos($potential_path, 'uploads/candidates/') === 0) {
                             // Assume path stored is usable relative to the web root or this script
                             // Add '../' if needed: $photo_path_for_html = '../' . htmlspecialchars($potential_path, ENT_QUOTES, 'UTF-8');
                             $photo_path_for_html = htmlspecialchars($potential_path, ENT_QUOTES, 'UTF-8');
                        } else { error_log("Vote Page: Candidate photo path may be invalid: " . $potential_path); }
                    }
                    $positions[$pos_id]['candidates'][] = ['id' => (int)$row['candidate_id'],'name' => $row['candidate_name'],'photo' => $photo_path_for_html];
                }
            } // end while
            $positions = array_filter($positions, fn($pos) => !empty($pos['candidates']));
            if (empty($positions) && !$error) { $error = "Voting cannot proceed: No candidates added for available positions."; }
        }
        $result_positions->free_result();

    } catch (mysqli_sql_exception $db_ex) {
        error_log("Vote Page Load - Database Error: " . $db_ex->getMessage() . " (Code: " . $db_ex->getCode() . ")");
        $error = "An error occurred while loading voting information. Please try again later.";
        $positions = []; // Prevent form rendering
    } catch (Exception $e) {
        error_log("Vote Page Session Validation Error: " . $e->getMessage()); 
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_SESSION['login_error'] = $e->getMessage() . " Please log in again.";
        header("Location: index.php"); exit();
    }
}

// --- Handle Vote Form Submission ---
$submitted_votes = []; $missing_votes_for_positions = [];
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$error && !empty($positions)) {
    $submitted_votes = $_POST['votes'] ?? []; $required_position_ids = array_keys($positions);
    foreach ($required_position_ids as $pos_id) { if (!isset($submitted_votes[$pos_id]) || !filter_var($submitted_votes[$pos_id], FILTER_VALIDATE_INT)) { $missing_votes_for_positions[] = $pos_id; } }
    if (!empty($missing_votes_for_positions)) { $error = "Please make a selection for all positions before submitting."; }
    else {
        $isInTransaction = false; try { $conn->begin_transaction(); $isInTransaction = true;
            $sql_lock_code = "SELECT is_used FROM voting_codes WHERE id = $voting_code_db_id FOR UPDATE"; $result_lock = $conn->query($sql_lock_code); if ($result_lock === false) throw new mysqli_sql_exception("DB lock error: " . $conn->error, $conn->errno); if ($result_lock->num_rows === 0) { $result_lock->free_result(); throw new Exception("Voting code invalid."); } $locked_code_status = $result_lock->fetch_assoc(); $result_lock->free_result(); if (!empty($locked_code_status['is_used'])) throw new Exception("Voting code already used.");
            $sql_mark_used = "UPDATE voting_codes SET is_used = 1, used_at = NOW() WHERE id = $voting_code_db_id"; $update_result = $conn->query($sql_mark_used); if ($update_result === false || $conn->affected_rows !== 1) throw new Exception("Failed to update voting code status.");
            foreach ($submitted_votes as $position_id => $candidate_id) { $position_id_int = filter_var($position_id, FILTER_VALIDATE_INT); $candidate_id_int = filter_var($candidate_id, FILTER_VALIDATE_INT); if ($position_id_int && $candidate_id_int && isset($positions[$position_id_int])) { $candidate_exists = false; foreach($positions[$position_id_int]['candidates'] as $c) { if ($c['id'] === $candidate_id_int) { $candidate_exists = true; break; } } if ($candidate_exists) { $sql_insert_vote = "INSERT INTO votes (election_id, position_id, candidate_id, voting_code_id) VALUES ($election_id, $position_id_int, $candidate_id_int, $voting_code_db_id)"; if ($conn->query($sql_insert_vote) === false) throw new Exception("Error saving vote for position ID $position_id_int."); } else { error_log("Vote Submit: Skipped invalid cand $candidate_id_int for pos $position_id_int."); } } else { error_log("Vote Submit: Skipped invalid pos $position_id or cand $candidate_id."); } }
            if (!$conn->commit()) { $isInTransaction = false; throw new mysqli_sql_exception("Transaction commit failed: " . $conn->error, $conn->errno); } $isInTransaction = false;
      $vote_processed_successfully = true;
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success' => true]); exit(); }
        } catch (Exception $e) { if ($isInTransaction && $conn && $conn->thread_id) { $conn->rollback(); } error_log("Vote Submission Exception: " . $e->getMessage()); $error = "Error submitting vote: " . $e->getMessage(); if (stripos($e->getMessage(), 'already been used') !== false) { if (session_status() === PHP_SESSION_STARTED) { session_unset(); session_destroy(); } } if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') { $http_code = (stripos($error,'already been used') !== false ? 409 : 500); header('Content-Type: application/json; charset=utf-8', true, $http_code); echo json_encode(['success' => false, 'error' => $error]); exit(); } }
    }
}

// --- Generate CSP nonce for HTML output ---
if (empty($_SESSION['csp_nonce'])) { $_SESSION['csp_nonce'] = base64_encode(random_bytes(16)); }
$nonce = htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8');


// --- Data Fetching and Pre-Checks ---
// This block confirms the session data is still valid before allowing voting
try {
    // 1. Fetch Election Details & Check if Active (Simplified check as requested)
    $stmt_election = $conn->prepare("SELECT id, title, description FROM elections WHERE id = :election_id AND status = 'active'");
    $stmt_election->bindParam(':election_id', $election_id, PDO::PARAM_INT);
    $stmt_election->execute();
    $election = $stmt_election->fetch(PDO::FETCH_ASSOC);

    if (!$election) {
        // Election not active, invalidate session and redirect
        session_destroy();
        header("Location: index.php?error=election_inactive");
        exit();
    }

    // 2. Re-validate Voting Code (Check if used since login)
    $stmt_code = $conn->prepare("SELECT is_used FROM voting_codes WHERE id = :id");
    $stmt_code->bindParam(':id', $voting_code_db_id, PDO::PARAM_INT);
    $stmt_code->execute();
    $code_status = $stmt_code->fetch(PDO::FETCH_ASSOC);

    // Code must exist and be unused
    if (!$code_status || $code_status['is_used']) {
        session_destroy();
        header("Location: index.php?error=already_voted");
        exit();
    }

    // 3. Fetch Positions and Associated Candidates for this Election
    $stmt_positions = $conn->prepare("
        SELECT p.id as position_id, p.title as position_title,
               c.id as candidate_id, c.name as candidate_name, c.photo as candidate_photo
        FROM positions p
        LEFT JOIN candidates c ON p.id = c.position_id
        WHERE p.election_id = :election_id
        ORDER BY p.id ASC, c.name ASC -- Consistent ordering
    ");
    $stmt_positions->bindParam(':election_id', $election_id, PDO::PARAM_INT);
    $stmt_positions->execute();

    $results = $stmt_positions->fetchAll(PDO::FETCH_ASSOC);
    if (empty($results)) {
        $error = "Voting cannot proceed: No positions are defined for this election.";
    } else {
        // Group candidates by position
        foreach ($results as $row) {
            if (!isset($positions[$row['position_id']])) {
                $positions[$row['position_id']] = [
                    'id' => $row['position_id'],
                    'title' => $row['position_title'], // Keep original case
                    'candidates' => []
                ];
            }
            // Only add candidate info if a candidate exists for this position row
            if ($row['candidate_id'] !== null) {
                 $photo_path_relative = 'assets/images/default-avatar.png'; // Default avatar path
                 if (!empty($row['candidate_photo'])) {
                    $potential_path = ltrim($row['candidate_photo'], '/');
                     // Basic validation: ensure it's within the expected folder and exists
                     if (strpos($potential_path, 'uploads/candidates/') === 0 && file_exists($potential_path)) {
                         $photo_path_relative = htmlspecialchars($potential_path);
                     } else {
                         error_log("Vote Page: Candidate photo missing or invalid path: " . $potential_path);
                     }
                 }
                 $positions[$row['position_id']]['candidates'][] = [
                    'id' => $row['candidate_id'],
                    'name' => $row['candidate_name'],
                    'photo' => $photo_path_relative
                ];
            }
        }
        // Filter out positions that ended up with no candidates
        $positions = array_filter($positions, fn($pos) => !empty($pos['candidates']));
         if (empty($positions)) {
             $error = "Voting cannot proceed: No candidates have been added for the available positions.";
         }
    }

} catch (PDOException $e) {
    error_log("Vote Page - Database Error: " . $e->getMessage());
    $error = "An error occurred while loading voting information. Please try again later or contact support.";
    $positions = []; // Prevent form rendering on error
}

// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$error && !empty($positions)) {
    $submitted_votes = $_POST['votes'] ?? [];
    $required_position_ids = array_keys($positions);
    $missing_votes_for_positions = [];

    // Server-side validation: Ensure a vote for every rendered position
    foreach ($required_position_ids as $pos_id) {
        if (!isset($submitted_votes[$pos_id]) || empty($submitted_votes[$pos_id])) {
            $missing_votes_for_positions[] = $pos_id;
        }
    }

    if (!empty($missing_votes_for_positions)) {
        $error = "Please make a selection for all positions before submitting.";
    } else {
        // Proceed with vote processing within a transaction
        try {
            $conn->beginTransaction();

            // 1. Lock and verify code is still unused
            $stmt_lock_code = $conn->prepare("SELECT is_used FROM voting_codes WHERE id = :id FOR UPDATE");
            $stmt_lock_code->bindParam(':id', $voting_code_db_id, PDO::PARAM_INT);
            $stmt_lock_code->execute();
            $locked_code_status = $stmt_lock_code->fetch(PDO::FETCH_ASSOC);

            if (!$locked_code_status || $locked_code_status['is_used']) {
                 throw new Exception("This voting code has already been used or is invalid.");
            }

            // 2. Mark Voting Code as Used
            $stmt_mark_used = $conn->prepare("UPDATE voting_codes SET is_used = 1, used_at = NOW() WHERE id = :id");
            $stmt_mark_used->bindParam(':id', $voting_code_db_id, PDO::PARAM_INT);
            $update_success = $stmt_mark_used->execute();

            if (!$update_success || $stmt_mark_used->rowCount() === 0) {
                 throw new Exception("Failed to mark the voting code as used. Please try again.");
            }

            // 3. Record Votes (Including all relevant IDs)
            $stmt_insert_vote = $conn->prepare("INSERT INTO votes (candidate_id, voting_code_id) VALUES (:candidate_id, :voting_code_id)");

            foreach ($submitted_votes as $position_id => $candidate_id) {
                 $position_id_int = filter_var($position_id, FILTER_VALIDATE_INT);
                 $candidate_id_int = filter_var($candidate_id, FILTER_VALIDATE_INT);

                // Validate submitted IDs against the loaded data for this election
                if ($position_id_int && $candidate_id_int && isset($positions[$position_id_int])) {
                    $candidate_exists_in_position = false;
                    foreach($positions[$position_id_int]['candidates'] as $candidate_data) {
                        if ($candidate_data['id'] == $candidate_id_int) {
                            $candidate_exists_in_position = true;
                            break;
                        }
                    }
                    if ($candidate_exists_in_position) {
                        $stmt_insert_vote->execute([
                             ':candidate_id' => $candidate_id_int,
                             ':voting_code_id' => $voting_code_db_id
                         ]);
                    } else { error_log("Vote Submission: Skipped invalid candidate ($candidate_id_int) for position ($position_id_int)."); }
                 } else { error_log("Vote Submission: Skipped invalid position ($position_id) or candidate ($candidate_id)."); }
            }

            // 4. Commit Transaction
            $conn->commit();

            // 5. Success: Clear session, set flag for JS
            session_destroy();
            $vote_processed_successfully = true;

            // If AJAX, send success (JS will handle redirect)
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit();
            }
            // If not AJAX, JS will detect $vote_processed_successfully flag below

        } catch (Exception $e) {
            $conn->rollBack();
            error_log("Vote Submission Exception: " . $e->getMessage());
            $error = "Error submitting vote: " . $e->getMessage();
             // If code used error, destroy session because login is now invalid
             if (strpos($e->getMessage(), 'already been used') !== false) {
                 session_destroy();
             }
             // Respond with JSON error if AJAX
             if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                header('Content-Type: application/json', true, (strpos($error,'already been used') !== false ? 409 : 500)); // Conflict or Server Error
                echo json_encode(['success' => false, 'error' => $error]); exit();
             }
        }
    } // End validation check
}
?>
<style nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
    /* --- Styles (Use the styles from the previous corrected version) --- */
     :root { /* ... CSS Variables ... */ --primary-hue: 226; --primary-color: hsl(var(--primary-hue), 76%, 58%); --primary-light: hsl(var(--primary-hue), 76%, 95%); --primary-dark: hsl(var(--primary-hue), 70%, 48%); --secondary-color: #6c757d; --success-color: #198754; --success-dark: #146c43; --success-light: #d1e7dd; --light-color: #f8f9fa; --dark-color: #5a5c69; --white-color: #fff; --border-color: #dee2e6; --danger-color: #dc3545; --danger-light: #f8d7da; --font-family-primary: "Poppins", sans-serif; --font-family-secondary: "Nunito", sans-serif; --shadow-sm: 0 .125rem .25rem rgba(0,0,0,.075); --shadow: 0 .25rem .8rem rgba(58,59,69,.1); --shadow-lg: 0 1rem 3rem rgba(0,0,0,.15); --border-radius: .4rem; --border-radius-lg: .6rem; } html { scroll-behavior: smooth; } body { font-family: var(--font-family-secondary); background-color: var(--light-color); color: var(--dark-color); margin: 0; padding: 1.5rem; line-height: 1.6; } .main-container { max-width: 1140px; margin: 1rem auto; padding: clamp(1.5rem, 3vw, 2.5rem); background-color: var(--white-color); border-radius: var(--border-radius-lg); box-shadow: var(--shadow); border-top: 5px solid var(--primary-color); } .election-header { text-align: center; margin-bottom: 2.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); } .logo-container { display: flex; justify-content: center; align-items: center; gap: 2.5rem; margin-bottom: 1.5rem; } .logo { max-width: 70px; max-height: 70px; object-fit: contain; } .election-header h1 { color: var(--primary-dark); font-family: var(--font-family-primary); font-weight: 700; margin-bottom: .5rem; font-size: clamp(1.6rem, 4vw, 2.1rem); } .election-header p { color: var(--secondary-color); font-size: clamp(1rem, 2.5vw, 1.1rem); margin-bottom: 0;} .position-section { margin-bottom: 3rem; padding: 1.5rem; border: 1px solid transparent; border-radius: var(--border-radius); transition: border-color 0.3s ease, background-color 0.3s ease; } .position-title { font-family: var(--font-family-primary); font-size: 1.4rem; font-weight: 700; color: var(--primary-color); margin-bottom: 1.5rem; padding-bottom: .75rem; border-bottom: 2px solid var(--primary-light); text-align: left; } .candidates-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 1.75rem; } .candidate-card { border: 2px solid var(--border-color); border-radius: var(--border-radius); transition: all 0.25s ease-in-out; cursor: pointer; background: var(--white-color); padding: 0; overflow: hidden; position: relative; box-shadow: var(--shadow-sm); display: flex; flex-direction: column; height: 100%; } .candidate-card:hover { border-color: var(--primary-color); transform: translateY(-5px) scale(1.01); box-shadow: var(--shadow); z-index: 10;} .candidate-card.selected { border-color: var(--success-color); box-shadow: 0 0 0 3px rgba(25, 135, 84, 0.5); transform: translateY(-3px) scale(1.03); z-index: 11; } .candidate-card.selected::before { content: '\F26E'; font-family: 'bootstrap-icons'; position: absolute; top: 10px; right: 10px; font-size: 1.7rem; font-weight: bold; color: var(--white-color); background-color: var(--success-color); width: 32px; height: 32px; line-height: 32px; text-align: center; border-radius: 50%; z-index: 2; box-shadow: 0 1px 3px rgba(0,0,0,.3); opacity: 0; transform: scale(0.5) rotate(-45deg); animation: popInCheckRotate 0.4s cubic-bezier(0.18, 0.89, 0.32, 1.28) forwards .1s; } @keyframes popInCheckRotate { to { opacity: 1; transform: scale(1) rotate(0deg); } } .candidate-photo-wrapper { width: 100%; aspect-ratio: 1 / 1; background-color: #f0f0f0; overflow: hidden; } .candidate-photo { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease; border-bottom: 1px solid var(--border-color); } .candidate-card:hover .candidate-photo { transform: scale(1.05); } .candidate-info { padding: 1rem 1.25rem; text-align: center; margin-top: auto; background-color: var(--white-color); } .candidate-name { font-size: 1.05rem; font-weight: 600; color: var(--dark-color); margin-bottom: 0; font-family: var(--font-family-primary); } .candidate-option input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; } .submit-button-wrapper { text-align: center; margin-top: 3rem; padding-top: 2rem; border-top: 1px solid var(--border-color); } .submit-button { background: linear-gradient(45deg, var(--success-color), var(--success-dark)); color: white; border: none; padding: .8rem 3rem; border-radius: 50px; cursor: pointer; font-size: 1.1rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; transition: all 0.3s ease; box-shadow: 0 5px 15px rgba(25, 135, 84, 0.3); } .submit-button:hover:not(:disabled) { box-shadow: 0 8px 20px rgba(25, 135, 84, 0.4); transform: translateY(-2px); filter: brightness(1.05); } .submit-button:disabled { background: var(--secondary-color); opacity: 0.6; cursor: not-allowed; box-shadow: none; transform: none; } .submit-button .spinner-border { width: 1.1rem; height: 1.1rem; vertical-align: -2px; } .position-section.validation-error { border: 2px solid var(--danger-color) !important; background-color: rgba(220, 53, 69, 0.05); box-shadow: 0 0 0 2px var(--danger-color); } .position-section.validation-error .position-title { color: var(--danger-color); border-bottom-color: rgba(220, 53, 69, 0.3); } #validationAlert { margin-bottom: 1.5rem; font-weight: 500;} .alert-container { margin-bottom: 1.5rem; } .alert .bi { font-size: 1.2rem; vertical-align: -2px; margin-right: .7rem;} #voteSuccessMessage { text-align: center; padding: 3rem 1rem; background-color: var(--success-light); border: 1px solid var(--success-color); border-radius: var(--border-radius-lg); margin-top: 2rem; box-shadow: var(--shadow); } #voteSuccessMessage .icon-wrapper { width: 80px; height: 80px; margin: 0 auto 1.5rem auto; background-color: var(--success-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; animation: success-scale-up 0.5s ease-out; } #voteSuccessMessage .bi-check-lg { font-size: 3rem; color: white; } #voteSuccessMessage h2 { font-family: var(--font-family-primary); color: var(--success-dark); margin-bottom: 0.5rem; font-weight: 700;} #voteSuccessMessage p { color: var(--dark-color); font-size: 1.1rem; } @keyframes success-scale-up { from { transform: scale(0.7); opacity: 0; } to { transform: scale(1); opacity: 1; } } @media (max-width: 576px) { .main-container { padding: 1.5rem;} .candidates-list { grid-template-columns: 1fr; gap: 1rem;} .position-title {font-size: 1.15rem;} .submit-button { padding: .7rem 1.5rem; font-size: 1rem;} .election-header h1 { font-size: 1.5rem;} .election-header p { font-size: 0.95rem;} }
</style>

<div class="main-container">
    <header class="election-header">
        <?php /* Header HTML */ ?>
        <?php if ($election): ?> <div class="logo-container"> <img src="assets/images/electoral-commission-logo.png" alt="EC Logo" class="logo" onerror="this.style.display='none'"> <img src="assets/images/gombe-ss-logo.png" alt="School Logo" class="logo" onerror="this.style.display='none'"> </div> <h1><?php echo htmlspecialchars($election['title']); ?></h1> <?php if (!empty($election['description'])): ?> <p class="lead text-muted"><?php echo htmlspecialchars($election['description']); ?></p> <?php endif; ?> <p class="lead">Select one candidate per position.</p> <?php else: ?> <h1>Election Voting</h1> <p class="lead">Loading election details...</p> <?php endif; ?>
    </header>

    <div class="alert-container">
        <?php /* Alert display logic - Shows general page errors if not a POST response */ ?>
        <?php if (!empty($error) && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
             <div class="alert alert-danger d-flex align-items-center alert-dismissible fade show" role="alert"><i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i><div><?php echo htmlspecialchars($error); ?></div><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
        <?php endif; ?>
        <?php /* Validation errors shown here, potentially populated by PHP on failed POST or by JS */ ?>
        <div id="validationAlert" class="alert alert-warning d-flex align-items-center <?php echo empty($missing_votes_for_positions) ? 'd-none' : ''; ?>" role="alert"><i class="bi bi-exclamation-circle-fill flex-shrink-0"></i><div id="validationAlertMessage"><?php echo !empty($missing_votes_for_positions) ? htmlspecialchars($error) : 'Please make a selection for all positions highlighted below.'; ?></div></div>
        <?php /* AJAX errors shown here */ ?>
        <div id="ajaxErrorAlertPlaceholder"></div>
    </div>

    <div id="votingFormArea">
        <?php if (!empty($positions) && empty($error)): // Render form only if positions exist and no critical errors ?>
            <form method="post" id="votingForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" novalidate>
                <?php foreach ($positions as $position_id => $position): ?>
                    <?php $is_missing = in_array($position_id, $missing_votes_for_positions); ?>
                    <section class="position-section <?php echo $is_missing ? 'validation-error' : ''; ?>" id="position-<?php echo $position_id; ?>" aria-labelledby="position-title-<?php echo $position_id; ?>">
                        <h2 class="position-title" id="position-title-<?php echo $position_id; ?>"> <?php echo htmlspecialchars(ucwords($position['title'])); ?> <?php if($is_missing): ?> <span class="text-danger small" role="alert">(Selection Required)</span> <?php endif; ?> </h2>
                        <?php if (empty($position['candidates'])): ?> <p class="text-center text-muted fst-italic">No candidates available.</p> <?php else: ?>
                            <div class="candidates-list"> <?php foreach ($position['candidates'] as $candidate): ?> <?php $is_checked = (isset($submitted_votes[$position_id]) && $submitted_votes[$position_id] == $candidate['id']); ?>
                                <div class="candidate-card <?php echo $is_checked ? 'selected' : ''; ?>" tabindex="0" role="radio" aria-checked="<?php echo $is_checked ? 'true' : 'false'; ?>" aria-labelledby="candidate-name-<?php echo $candidate['id']; ?>">
                                    <label class="candidate-option d-flex flex-column text-decoration-none p-0 h-100">
                                        <input type="radio" class="visually-hidden candidate-radio" name="votes[<?php echo $position_id; ?>]" value="<?php echo $candidate['id']; ?>" required id="candidate-radio-<?php echo $candidate['id']; ?>" <?php echo $is_checked ? ' checked' : ''; ?> >
                                        <div class="candidate-photo-wrapper">
                                             <img src="<?php echo $candidate['photo']; ?>" alt="Photo of <?php echo htmlspecialchars($candidate['name']); ?>" class="candidate-photo" loading="lazy" onerror="this.onerror=null; this.src='assets/images/default-avatar.png';">
                                        </div>
                                        <div class="candidate-info"> <h3 class="candidate-name" id="candidate-name-<?php echo $candidate['id']; ?>"><?php echo htmlspecialchars($candidate['name']); ?></h3> </div>
                                    </label>
                                </div> <?php endforeach; ?>
                            </div>
                       <?php endif; ?>
                    </section>
                <?php endforeach; ?>
                <div class="submit-button-wrapper"> <button type="submit" class="submit-button" id="submitVoteBtn"> <span class="spinner-border spinner-border-sm d-none me-1"></span> <i class="bi bi-send-check-fill me-2"></i>Confirm & Submit Vote </button> </div>
            </form>
        <?php elseif(empty($error)): ?> <div class="alert alert-info text-center shadow-sm" role="alert"><i class="bi bi-info-circle-fill me-2"></i> Voting is currently unavailable. No positions or candidates are set up.</div>
        <?php endif; ?>
    </div> <div id="voteSuccessMessage" class="d-none"> <div class="icon-wrapper"><i class="bi bi-check-lg"></i></div> <h2>Vote Submitted!</h2> <p>Thank you for casting your vote.</p> <p class="mt-3"><small>You will be redirected shortly...</small></p> </div>

</div> <script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
    // --- JavaScript (Use the JS block from the previous corrected version) ---
    // It includes helpers, candidate selection UI, form validation,
    // and AJAX submission logic which correctly handles the button state.
      document.addEventListener('DOMContentLoaded', function() {
           // --- DOM References ---
           const votingForm = document.getElementById('votingForm');
           const submitButton = document.getElementById('submitVoteBtn');
           const validationAlert = document.getElementById('validationAlert');
           const validationAlertMessage = document.getElementById('validationAlertMessage');
           const ajaxErrorAlertPlaceholder = document.getElementById('ajaxErrorAlertPlaceholder');
           const votingFormArea = document.getElementById('votingFormArea');
           const voteSuccessMessage = document.getElementById('voteSuccessMessage');

           // --- Helper: Update Button State ---
           function updateButtonState(button, isLoading, loadingText = 'Processing...') {
               if (!button) return;
               if (isLoading) { button.dataset.originalHtml = button.innerHTML; button.disabled = true; const spinner = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`; const icon = button.querySelector('i'); button.innerHTML = spinner + (icon ? icon.outerHTML : '') + ` ${escapeHtml(loadingText)}`; }
               else { button.disabled = false; button.innerHTML = button.dataset.originalHtml || '<i class="bi bi-send-check-fill me-2"></i>Confirm & Submit Vote'; delete button.dataset.originalHtml; }
           }
           // --- Helper: Show AJAX Error Alert ---
            function showAjaxError(message) { if (ajaxErrorAlertPlaceholder) { const safeMessage = message ? String(message).replace(/</g, "&lt;").replace(/>/g, "&gt;") : 'An unexpected error occurred.'; ajaxErrorAlertPlaceholder.innerHTML = `<div class="alert alert-danger d-flex align-items-center alert-dismissible fade show" role="alert"><i class="bi bi-exclamation-triangle-fill flex-shrink-0 me-2"></i><div>${safeMessage}</div><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>`; ajaxErrorAlertPlaceholder.scrollIntoView({ behavior: 'smooth', block: 'start' }); } else { alert("Error: " + message); } }
           // --- Helper: Escape HTML ---
           function escapeHtml(unsafe) { const div = document.createElement('div'); div.textContent = unsafe ?? ''; return div.innerHTML; }

           // --- Helper: Handle Candidate Selection UI ---
           function handleSelection(cardElement) { const positionSection = cardElement.closest('.position-section'); if (!positionSection) return; positionSection.querySelectorAll('.candidate-card').forEach(card => { card.classList.remove('selected'); card.setAttribute('aria-checked', 'false'); }); cardElement.classList.add('selected'); cardElement.setAttribute('aria-checked', 'true'); const radio = cardElement.querySelector('.candidate-radio'); if (radio) radio.checked = true; positionSection.classList.remove('validation-error'); if (checkFormValidity(false)) { validationAlert?.classList.add('d-none'); } }

           // --- Attach Event Listeners to Candidate Cards ---
           document.querySelectorAll('.candidates-list').forEach(list => { list.addEventListener('click', (event) => { const card = event.target.closest('.candidate-card'); if (card) handleSelection(card); }); list.addEventListener('keydown', (event) => { const card = event.target.closest('.candidate-card'); if (card === document.activeElement && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); handleSelection(card); } }); });

           // --- Form Validation Logic ---
           function checkFormValidity(showErrors = true) { let allVoted = true; let firstErrorSection = null; if (validationAlert && showErrors) validationAlert.classList.add('d-none'); const positionSections = document.querySelectorAll('.position-section'); if (positionSections.length === 0) { if(showErrors) showAjaxError("Cannot submit vote: No positions loaded."); return false; } positionSections.forEach(section => { const hasCandidates = section.querySelector('.candidate-card'); const positionIdInput = section.querySelector(`input[name^="votes["]`); section.classList.remove('validation-error'); if (hasCandidates && positionIdInput) { const positionId = positionIdInput.name.match(/\[(\d+)\]/)?.[1]; if (positionId) { const isSelected = section.querySelector(`input[name="votes[${positionId}]"]:checked`); if (!isSelected) { allVoted = false; if (showErrors) { section.classList.add('validation-error'); if (!firstErrorSection) firstErrorSection = section; } } } } }); if (!allVoted && showErrors && validationAlert) { if(validationAlertMessage) validationAlertMessage.textContent = "Please select one candidate for each position highlighted below."; validationAlert.classList.remove('d-none'); if (firstErrorSection) firstErrorSection.scrollIntoView({ behavior: 'smooth', block: 'center' }); } return allVoted; }

           // --- Pre-select based on PHP POST error ---
           <?php if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($submitted_votes)): ?> Object.entries(<?php echo json_encode($submitted_votes); ?>).forEach(([posId, candId]) => { const radio = document.getElementById(`candidate-radio-${candId}`); if (radio) { const card = radio.closest('.candidate-card'); if (card) handleSelection(card); } }); <?php if (!empty($missing_votes_for_positions)): ?> checkFormValidity(true); <?php endif; ?> <?php endif; ?>

           // --- AJAX Form Submission ---
           if (votingForm && submitButton) {
                votingForm.addEventListener('submit', async function(event) {
                    event.preventDefault();
                    if (!checkFormValidity(true)) { console.log("Client validation failed."); return; }
                    const originalButtonHtml = submitButton.innerHTML;
                    updateButtonState(submitButton, true, 'Submitting...');
                    if (ajaxErrorAlertPlaceholder) ajaxErrorAlertPlaceholder.innerHTML = '';
                    validationAlert?.classList.add('d-none');
                    try {
                         const formData = new FormData(votingForm);
                         const response = await fetch('<?php echo basename($_SERVER['PHP_SELF']); ?>', { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
                         let result; const contentType = response.headers.get("content-type");
                         if (contentType?.includes("application/json")) { result = await response.json(); } else { const text = await response.text(); console.error("Non-JSON response:", text); throw new Error(`Server error (Status: ${response.status}). Check logs.`); }
                         if (response.ok && result.success) { console.log("Vote success via AJAX."); if(votingFormArea) votingFormArea.style.display = 'none'; if(voteSuccessMessage) voteSuccessMessage.classList.remove('d-none'); setTimeout(() => { window.location.href = 'index.php?voted=success'; }, 3000); }
                         else { console.log("Vote submission failed on server (AJAX).", result); throw new Error(result.error || result.message || `Vote submission failed (Status: ${response.status}).`); }
                    } catch (error) { console.error('Vote Submission Fetch Error:', error); showAjaxError(error.message); updateButtonState(submitButton, false, originalButtonHtml); }
                });
           } else { console.warn("Voting form or submit button not found."); if (submitButton) submitButton.disabled = true; }

           // --- Handle non-AJAX success flag from PHP ---
           <?php if ($vote_processed_successfully): ?> console.log("PHP flag indicates vote success. Displaying message/redirecting."); if(votingFormArea) votingFormArea.style.display = 'none'; if(voteSuccessMessage) voteSuccessMessage.classList.remove('d-none'); setTimeout(() => { window.location.href = 'index.php?voted=success'; }, 3000); <?php endif; ?>

      }); // End DOMContentLoaded
 </script>

</body>
</html>
<?php
// Final connection close
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $conn->close();
}
?>