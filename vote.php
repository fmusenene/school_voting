<?php declare(strict_types=1); // Enforce strict types

// Start session **before** any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Includes ---
// Adjust relative paths if necessary
require_once "config/database.php"; // Provides $conn (mysqli object)
// require_once "includes/init.php"; // Include if needed
// require_once "includes/session.php"; // Include if it contains helpers used here

// --- Configuration & Security ---
date_default_timezone_set('Africa/Nairobi'); // EAT
error_reporting(E_ALL); // Dev
ini_set('display_errors', '1'); // Dev
// Production: error_reporting(0); ini_set('display_errors', '0'); ini_set('log_errors', '1');

// --- Session Validation & Data Initialization ---
// Check for essential session variables
if (!isset($_SESSION['voting_code'], $_SESSION['election_id'], $_SESSION['voting_code_id'])) {
    // If session is incomplete, treat as invalid login
    if (session_status() === PHP_SESSION_STARTED) { // Check before destroying
        session_unset();
        session_destroy();
    }
    header("Location: index.php?error=session_invalid"); // Redirect to login
    exit();
}

// Assign session variables locally and ensure correct types
$voting_code_session = $_SESSION['voting_code']; // Keep original code string if needed for display
$election_id = (int)$_SESSION['election_id'];
$voting_code_db_id = (int)$_SESSION['voting_code_id'];

// Initialize variables for page display and processing
$error = ''; // User-facing errors
$election = null; // Holds election details
$positions = []; // Holds positions and their candidates, indexed by position ID
$vote_processed_successfully = false; // Flag for JS success handling

// Check DB Connection (essential before proceeding)
if (!$conn || $conn->connect_error) {
    error_log("Vote Page DB Connection Error: " . ($conn ? $conn->connect_error : 'Check config'));
    // Do not show detailed error to user, maybe redirect or show generic message
    // Set error to prevent form rendering later
    $error = "Database connection error. Please contact support.";
}

// --- Data Fetching and Pre-Checks (using MySQLi Direct SQL - ONLY if DB connection is OK) ---
if (!$error) {
    try {
        // 1. Fetch Election Details & Check if Active
        $sql_check_election = "SELECT id, title, description
                               FROM elections
                               WHERE id = $election_id AND status = 'active'
                               LIMIT 1"; // Embed validated integer ID
        $result_election = $conn->query($sql_check_election);
        if ($result_election === false) throw new mysqli_sql_exception("DB error checking election status: " . $conn->error, $conn->errno);
        if ($result_election->num_rows === 0) {
            $result_election->free_result();
            throw new Exception("The election is no longer active."); // Custom exception for flow control
        }
        $election = $result_election->fetch_assoc();
        $result_election->free_result();

        // 2. Re-validate Voting Code (Check if used since login)
        $sql_check_code = "SELECT is_used FROM voting_codes WHERE id = $voting_code_db_id LIMIT 1"; // Embed validated integer ID
        $result_code = $conn->query($sql_check_code);
        if ($result_code === false) throw new mysqli_sql_exception("DB error re-validating code: " . $conn->error, $conn->errno);
        if ($result_code->num_rows === 0) { $result_code->free_result(); throw new Exception("Your voting code is no longer valid."); }
        $code_status = $result_code->fetch_assoc();
        $result_code->free_result();
        if (!empty($code_status['is_used'])) throw new Exception("This voting code has already been used."); // Custom exception

        // 3. Fetch Positions and Associated Candidates
        $sql_positions_candidates = "SELECT p.id as position_id, p.title as position_title,
                                            c.id as candidate_id, c.name as candidate_name, c.photo as candidate_photo
                                     FROM positions p
                                     LEFT JOIN candidates c ON p.id = c.position_id
                                     WHERE p.election_id = $election_id
                                     ORDER BY p.id ASC, c.name ASC"; // Embed validated integer ID
        $result_positions = $conn->query($sql_positions_candidates);
        if ($result_positions === false) throw new mysqli_sql_exception("DB error fetching positions/candidates: " . $conn->error, $conn->errno);

        if ($result_positions->num_rows === 0) {
            $error = "Voting cannot proceed: No positions defined for this election.";
        } else {
            // Group candidates by position
            while ($row = $result_positions->fetch_assoc()) {
                $pos_id = (int)$row['position_id'];
                if (!isset($positions[$pos_id])) { $positions[$pos_id] = ['id' => $pos_id,'title' => $row['position_title'],'candidates' => []]; }
                if ($row['candidate_id'] !== null) {
                    $photo_path_relative = 'assets/images/default-avatar.png'; // Default
                    if (!empty($row['candidate_photo'])) {
                        $potential_path = ltrim((string)$row['candidate_photo'], '/');
                        // Construct absolute path carefully relative to project root (one level up from current script assumed)
                        $absolute_path = dirname(__DIR__) . '/' . $potential_path;
                        if (strpos($potential_path, 'uploads/candidates/') === 0 && file_exists($absolute_path)) {
                            // Use path relative to *this* vote.php file for HTML src
                            $photo_path_relative = htmlspecialchars($potential_path);
                         } else { error_log("Vote Page: Candidate photo missing/invalid path: " . $absolute_path); }
                    }
                    $positions[$pos_id]['candidates'][] = ['id' => (int)$row['candidate_id'],'name' => $row['candidate_name'],'photo' => $photo_path_relative];
                }
            }
            // Filter out positions that might have no candidates after joins/processing
            $positions = array_filter($positions, fn($pos) => !empty($pos['candidates']));
            if (empty($positions)) { $error = "Voting cannot proceed: No candidates added for available positions."; }
        }
        $result_positions->free_result(); // Free memory

    } catch (mysqli_sql_exception $db_ex) { // Catch specific DB errors
        error_log("Vote Page Load - Database Error: " . $db_ex->getMessage() . " (Code: " . $db_ex->getCode() . ")");
        $error = "An error occurred while loading voting information. Please try again later.";
        $positions = []; // Prevent form rendering on DB error
    } catch (Exception $e) { // Catch validation exceptions (election inactive, code used)
        // Log out and redirect with specific error message
        error_log("Vote Page Session Validation Error: " . $e->getMessage());
        if (session_status() === PHP_SESSION_STARTED) { session_unset(); session_destroy(); }
        if (session_status() === PHP_SESSION_NONE) { session_start(); } // Start briefly for flash message
        $_SESSION['login_error'] = $e->getMessage() . " Please log in again.";
        header("Location: index.php"); // Redirect to login
        exit();
    }
} // End DB connection check and initial data load

// --- Handle Vote Form Submission ---
$submitted_votes = []; // Store submitted votes for potential re-population
$missing_votes_for_positions = []; // Store IDs of positions without votes

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$error && !empty($positions)) {
    $submitted_votes = $_POST['votes'] ?? [];
    $required_position_ids = array_keys($positions);
    // Validate all positions have a selection
    foreach ($required_position_ids as $pos_id) {
        // Check if key exists AND the value is a valid integer
        if (!isset($submitted_votes[$pos_id]) || !filter_var($submitted_votes[$pos_id], FILTER_VALIDATE_INT)) {
            $missing_votes_for_positions[] = $pos_id; // Add position ID to missing list
        }
    }

    if (!empty($missing_votes_for_positions)) {
        $error = "Please make a selection for all positions before submitting.";
        // Keep $submitted_votes to repopulate form below
    } else {
        // Proceed with vote processing within a transaction
        $isInTransaction = false; // Flag for rollback safety
        try {
            $conn->begin_transaction(); $isInTransaction = true;

            // 1. Lock and re-verify code is still unused
            $sql_lock_code = "SELECT is_used FROM voting_codes WHERE id = $voting_code_db_id FOR UPDATE";
            $result_lock = $conn->query($sql_lock_code);
            if ($result_lock === false) throw new mysqli_sql_exception("DB lock error: " . $conn->error, $conn->errno);
            if ($result_lock->num_rows === 0) { $result_lock->free_result(); throw new Exception("Voting code invalid (lock check)."); }
            $locked_code_status = $result_lock->fetch_assoc(); $result_lock->free_result();
            if (!empty($locked_code_status['is_used'])) throw new Exception("Voting code already used (lock check).");

            // 2. Mark Voting Code as Used
            $sql_mark_used = "UPDATE voting_codes SET is_used = 1, used_at = NOW() WHERE id = $voting_code_db_id";
            $update_result = $conn->query($sql_mark_used);
            // Check if update query failed OR didn't affect exactly one row
            if ($update_result === false || $conn->affected_rows !== 1) {
                 throw new Exception("Failed to update voting code status. Rows affected: " . $conn->affected_rows);
            }

            // 3. Record Votes
            foreach ($submitted_votes as $position_id => $candidate_id) {
                $position_id_int = filter_var($position_id, FILTER_VALIDATE_INT);
                $candidate_id_int = filter_var($candidate_id, FILTER_VALIDATE_INT);
                // Final validation against loaded $positions data
                if ($position_id_int && $candidate_id_int && isset($positions[$position_id_int])) {
                    $candidate_exists = false; foreach($positions[$position_id_int]['candidates'] as $c) { if ($c['id'] === $candidate_id_int) { $candidate_exists = true; break; } }
                    if ($candidate_exists) {
                        // Construct direct SQL INSERT (all values are integers)
                        $sql_insert_vote = "INSERT INTO votes (election_id, position_id, candidate_id, voting_code_id) VALUES ($election_id, $position_id_int, $candidate_id_int, $voting_code_db_id)";
                        if ($conn->query($sql_insert_vote) === false) {
                            // Log the specific insert error
                            error_log("Vote Submission Insert Error: Pos $position_id_int, Cand $candidate_id_int - " . $conn->error);
                            // Fail the entire transaction if even one vote fails
                            throw new Exception("An error occurred while saving one of your votes.");
                        }
                    } else { error_log("Vote Submit: Skipped invalid candidate ($candidate_id_int) for position ($position_id_int)."); }
                } else { error_log("Vote Submit: Skipped invalid pos ($position_id) or cand ($candidate_id)."); }
            }

            // 4. Commit Transaction
            if (!$conn->commit()) { $isInTransaction = false; throw new mysqli_sql_exception("Transaction commit failed: " . $conn->error, $conn->errno); }
            $isInTransaction = false; // Mark as finished

            // 5. Success
            if (session_status() === PHP_SESSION_STARTED) { session_unset(); session_destroy(); }
            $vote_processed_successfully = true;

            // Respond for AJAX or set flag for non-AJAX JS
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success' => true]); exit();
            }
            // Non-AJAX success handled by JS flag below

        } catch (Exception $e) { // Catch both mysqli_sql_exception and general Exception
            if ($isInTransaction && $conn && $conn->thread_id) { $conn->rollback(); } // Rollback on any error during transaction
            error_log("Vote Submission Exception: " . $e->getMessage());
            $error = "Error submitting vote: " . $e->getMessage();
            // If code used error, destroy session as it's now invalid for retry
            if (stripos($e->getMessage(), 'already been used') !== false) { if (session_status() === PHP_SESSION_STARTED) { session_unset(); session_destroy(); } }
            // Respond with JSON error if AJAX
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                $http_code = (stripos($error,'already been used') !== false ? 409 : 500); // 409 Conflict or 500 Server Error
                header('Content-Type: application/json; charset=utf-8', true, $http_code); echo json_encode(['success' => false, 'error' => $error]); exit();
            }
            // Non-AJAX errors will be displayed via the $error variable in the HTML below
        }
    }
} // End POST handling

// --- Generate CSP nonce for HTML output ---
// Regenerate if needed, ensure it happens *after* potential redirects and session restarts
if (empty($_SESSION['csp_nonce'])) { $_SESSION['csp_nonce'] = base64_encode(random_bytes(16)); }
$nonce = htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8');

// --- HTML Output Starts Here ---
?>
<?php
// Include HTML Header (or use fallback structure)
if (file_exists("includes/header.php")) {
    require_once "includes/header.php";
} else {
    // Fallback basic HTML structure
    ?>
    <!DOCTYPE html> <html lang="en"> <head> <meta charset="UTF-8"> <meta name="viewport" content="width=device-width, initial-scale=1.0"> <title>Cast Your Vote - <?php echo htmlspecialchars($election['title'] ?? 'School Election'); ?></title> <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"> <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"> <link rel="preconnect" href="https://fonts.googleapis.com"> <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin> <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet"> </head> <body>
    <?php
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
        <?php /* Alert display logic */ ?>
        <?php if (!empty($error) && empty($_POST)): ?> <div class="alert alert-danger d-flex align-items-center alert-dismissible fade show" role="alert"><i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i><div><?php echo htmlspecialchars($error); ?></div><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div> <?php endif; ?>
        <div id="validationAlert" class="alert alert-warning d-flex align-items-center <?php echo empty($missing_votes_for_positions) ? 'd-none' : ''; ?>" role="alert"><i class="bi bi-exclamation-circle-fill flex-shrink-0"></i><div id="validationAlertMessage"><?php echo !empty($missing_votes_for_positions) ? htmlspecialchars($error) : 'Please make a selection for all positions highlighted below.'; ?></div></div>
        <div id="ajaxErrorAlertPlaceholder"></div>
    </div>

    <div id="votingFormArea">
        <?php if (!empty($positions) && empty($error)): // Render form only if positions exist and no critical errors ?>
            <form method="post" id="votingForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" novalidate>
                <?php foreach ($positions as $position_id => $position): ?>
                    <?php /* Position section rendering */ ?>
                    <?php $is_missing = in_array($position_id, $missing_votes_for_positions); ?>
                    <section class="position-section <?php echo $is_missing ? 'validation-error' : ''; ?>" id="position-<?php echo $position_id; ?>" aria-labelledby="position-title-<?php echo $position_id; ?>">
                        <h2 class="position-title" id="position-title-<?php echo $position_id; ?>"> <?php echo htmlspecialchars(ucwords($position['title'])); ?> <?php if($is_missing): ?> <span class="text-danger small" role="alert">(Selection Required)</span> <?php endif; ?> </h2>
                        <?php if (empty($position['candidates'])): ?> <p class="text-center text-muted fst-italic">No candidates available.</p> <?php else: ?>
                            <div class="candidates-list"> <?php foreach ($position['candidates'] as $candidate): ?> <?php $is_checked = (isset($submitted_votes[$position_id]) && $submitted_votes[$position_id] == $candidate['id']); ?>
                                <div class="candidate-card <?php echo $is_checked ? 'selected' : ''; ?>" tabindex="0" role="radio" aria-checked="<?php echo $is_checked ? 'true' : 'false'; ?>" aria-labelledby="candidate-name-<?php echo $candidate['id']; ?>">
                                    <label class="candidate-option d-flex flex-column text-decoration-none p-0 h-100">
                                        <input type="radio" class="visually-hidden candidate-radio" name="votes[<?php echo $position_id; ?>]" value="<?php echo $candidate['id']; ?>" required id="candidate-radio-<?php echo $candidate['id']; ?>" <?php echo $is_checked ? ' checked' : ''; ?> >
                                        <div class="candidate-photo-wrapper"> <img src="<?php echo htmlspecialchars($candidate['photo']); ?>" alt="Photo of <?php echo htmlspecialchars($candidate['name']); ?>" class="candidate-photo" loading="lazy" onerror="this.onerror=null; this.src='assets/images/default-avatar.png';"> </div>
                                        <div class="candidate-info"> <h3 class="candidate-name" id="candidate-name-<?php echo $candidate['id']; ?>"><?php echo htmlspecialchars($candidate['name']); ?></h3> </div>
                                    </label>
                                </div> <?php endforeach; ?>
                            </div>
                       <?php endif; ?>
                    </section>
                <?php endforeach; ?>
                <div class="submit-button-wrapper">
                    <button type="submit" class="submit-button" id="submitVoteBtn"> <span class="spinner-border spinner-border-sm d-none me-1"></span> <i class="bi bi-send-check-fill me-2"></i>Confirm & Submit Vote </button>
                </div>
            </form>
        <?php elseif(empty($error)): ?> <div class="alert alert-info text-center shadow-sm" role="alert"><i class="bi bi-info-circle-fill me-2"></i> Voting is currently unavailable. No positions or candidates are set up.</div>
        <?php endif; ?>
    </div>

     <div id="voteSuccessMessage" class="d-none"> <div class="icon-wrapper"><i class="bi bi-check-lg"></i></div> <h2>Vote Submitted!</h2> <p>Thank you for casting your vote.</p> <p class="mt-3"><small>You will be redirected shortly...</small></p> </div>

</div>

<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
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
             if (isLoading) {
                 button.dataset.originalHtml = button.innerHTML; button.disabled = true;
                 const spinner = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;
                 const icon = button.querySelector('i');
                 button.innerHTML = spinner + (icon ? icon.outerHTML : '') + ` ${escapeHtml(loadingText)}`;
             } else {
                 button.disabled = false;
                 button.innerHTML = button.dataset.originalHtml || '<i class="bi bi-send-check-fill me-2"></i>Confirm & Submit Vote'; // Restore default
                 delete button.dataset.originalHtml;
             }
        }
        // --- Helper: Show AJAX Error Alert ---
        function showAjaxError(message) {
            if (ajaxErrorAlertPlaceholder) {
                const safeMessage = message ? String(message).replace(/</g, "&lt;").replace(/>/g, "&gt;") : 'An unexpected error occurred.';
                ajaxErrorAlertPlaceholder.innerHTML = `<div class="alert alert-danger d-flex align-items-center alert-dismissible fade show" role="alert"><i class="bi bi-exclamation-triangle-fill flex-shrink-0 me-2"></i><div>${safeMessage}</div><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>`;
                ajaxErrorAlertPlaceholder.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else { alert("Error: " + message); }
        }
        // --- Helper: Escape HTML ---
        function escapeHtml(unsafe) { const div = document.createElement('div'); div.textContent = unsafe ?? ''; return div.innerHTML; }


        // --- Helper: Handle Candidate Selection UI ---
        function handleSelection(cardElement) {
            const positionSection = cardElement.closest('.position-section'); if (!positionSection) return;
            positionSection.querySelectorAll('.candidate-card').forEach(card => { card.classList.remove('selected'); card.setAttribute('aria-checked', 'false'); });
            cardElement.classList.add('selected'); cardElement.setAttribute('aria-checked', 'true');
            const radio = cardElement.querySelector('.candidate-radio'); if (radio) radio.checked = true;
            positionSection.classList.remove('validation-error'); // Remove specific error highlight
            // Check if the whole form is now valid, if so, hide the general validation alert
            if (checkFormValidity(false)) { // Check silently
                 validationAlert?.classList.add('d-none');
            }
        }

        // --- Attach Event Listeners to Candidate Cards ---
        document.querySelectorAll('.candidates-list').forEach(list => {
             list.addEventListener('click', (event) => { const card = event.target.closest('.candidate-card'); if (card) handleSelection(card); });
             list.addEventListener('keydown', (event) => { const card = event.target.closest('.candidate-card'); if (card === document.activeElement && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); handleSelection(card); } });
        });

        // --- Form Validation Logic ---
        function checkFormValidity(showErrors = true) {
             let allVoted = true; let firstErrorSection = null;
             if (validationAlert && showErrors) validationAlert.classList.add('d-none'); // Hide alert before checking

             const positionSections = document.querySelectorAll('.position-section');
             if (positionSections.length === 0) {
                 // If there are no position sections (e.g., PHP error loading them), form is invalid
                 if(showErrors) showAjaxError("Cannot submit vote: No positions loaded."); // Use AJAX error display
                 return false;
             }

             positionSections.forEach(section => {
                 const hasCandidates = section.querySelector('.candidate-card');
                 const positionIdInput = section.querySelector(`input[name^="votes["]`);
                 section.classList.remove('validation-error'); // Clear previous state

                 // Only validate if the section actually has candidates displayed
                 if (hasCandidates && positionIdInput) {
                     const positionId = positionIdInput.name.match(/\[(\d+)\]/)?.[1]; // Extract ID safely
                     if (positionId) {
                          const isSelected = section.querySelector(`input[name="votes[${positionId}]"]:checked`);
                          if (!isSelected) { // Invalid if candidates exist but none selected
                              allVoted = false;
                              if (showErrors) {
                                   section.classList.add('validation-error');
                                   if (!firstErrorSection) firstErrorSection = section; // Track first error
                              }
                          }
                     } else {
                         console.warn("Could not extract position ID from input name in section:", section.id);
                         // Treat as invalid if ID cannot be determined? Or ignore? Let's ignore for now.
                     }
                 } else {
                     // If no candidates, the section is implicitly valid (nothing to select)
                     console.log("Skipping validation for section with no candidates:", section.id);
                 }
             });

             if (!allVoted && showErrors && validationAlert) {
                 if(validationAlertMessage) validationAlertMessage.textContent = "Please select one candidate for each position highlighted below.";
                 validationAlert.classList.remove('d-none'); // Show general validation alert
                 if (firstErrorSection) firstErrorSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
             }
             return allVoted;
        }

        // --- Pre-select based on PHP POST error ---
        <?php if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($submitted_votes)): ?> Object.entries(<?php echo json_encode($submitted_votes); ?>).forEach(([posId, candId]) => { const radio = document.getElementById(`candidate-radio-${candId}`); if (radio) { const card = radio.closest('.candidate-card'); if (card) handleSelection(card); } }); <?php if (!empty($missing_votes_for_positions)): ?> checkFormValidity(true); <?php endif; ?> <?php endif; ?>


        // --- AJAX Form Submission ---
        if (votingForm && submitButton) {
             votingForm.addEventListener('submit', async function(event) {
                 event.preventDefault(); // Always prevent default for AJAX
                 // Re-validate client-side just before submitting
                 if (!checkFormValidity(true)) {
                      console.log("Client validation failed before submit.");
                      return; // Stop submission
                 }

                 const originalButtonHtml = submitButton.innerHTML;
                 updateButtonState(submitButton, true, 'Submitting...');
                 if (ajaxErrorAlertPlaceholder) ajaxErrorAlertPlaceholder.innerHTML = '';
                 validationAlert?.classList.add('d-none'); // Hide validation warnings

                 try {
                      const formData = new FormData(votingForm);
                      // AJAX Post to the current file (which handles POST)
                      const response = await fetch('<?php echo basename($_SERVER['PHP_SELF']); ?>', {
                           method: 'POST', body: formData,
                           headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                      });

                      let result;
                      const contentType = response.headers.get("content-type");
                      if (contentType?.includes("application/json")) { result = await response.json(); }
                      else { const text = await response.text(); console.error("Non-JSON response:", text); throw new Error(`Server error (Status: ${response.status}). Check logs.`); }

                      if (response.ok && result.success) {
                           console.log("Vote success via AJAX.");
                           if(votingFormArea) votingFormArea.style.display = 'none';
                           if(voteSuccessMessage) voteSuccessMessage.classList.remove('d-none');
                           // Redirect after showing success message
                           setTimeout(() => { window.location.href = 'index.php?voted=success'; }, 3000);
                      } else {
                           // Throw error using server message or generic message
                           throw new Error(result.error || result.message || `Vote submission failed (Status: ${response.status}).`);
                      }
                 } catch (error) {
                      console.error('Vote Submission Fetch Error:', error);
                      // Show error in the dedicated AJAX placeholder
                      showAjaxError(error.message || 'A network or server error occurred. Please try again.');
                      // Only reset button on error
                      updateButtonState(submitButton, false, originalButtonHtml);
                 }
                 // No 'finally' needed if button reset only happens on error
             });
        } else { // Form or button not found
             console.warn("Voting form (#votingForm) or submit button (#submitVoteBtn) not found.");
             if (submitButton) submitButton.disabled = true; // Disable button if form missing
        }

        // --- Handle non-AJAX success flag from PHP (Fallback) ---
        <?php if ($vote_processed_successfully): ?>
             console.log("PHP flag indicates vote success. Displaying message/redirecting.");
             if(votingFormArea) votingFormArea.style.display = 'none';
             if(voteSuccessMessage) voteSuccessMessage.classList.remove('d-none');
             setTimeout(() => { window.location.href = 'index.php?voted=success'; }, 3000);
        <?php endif; ?>

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