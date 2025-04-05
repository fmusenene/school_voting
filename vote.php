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

// --- Data Fetching and Pre-Checks (using MySQLi Direct SQL) ---
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
            while ($row = $result_positions->fetch_assoc()) {
                $pos_id = (int)$row['position_id'];
                if (!isset($positions[$pos_id])) { $positions[$pos_id] = ['id' => $pos_id,'title' => $row['position_title'],'candidates' => []]; }
                if ($row['candidate_id'] !== null) {
                    $photo_path_relative = 'assets/images/default-avatar.png'; // Default
                    if (!empty($row['candidate_photo'])) {
                        $potential_path = ltrim((string)$row['candidate_photo'], '/');
                        $absolute_path = dirname(__DIR__) . '/' . $potential_path;
                        if (strpos($potential_path, 'uploads/candidates/') === 0 && file_exists($absolute_path)) { $photo_path_relative = htmlspecialchars($potential_path); }
                        else { error_log("Vote Page: Candidate photo missing/invalid path: " . $absolute_path); }
                    }
                    $positions[$pos_id]['candidates'][] = ['id' => (int)$row['candidate_id'],'name' => $row['candidate_name'],'photo' => $photo_path_relative];
                }
            }
            $positions = array_filter($positions, fn($pos) => !empty($pos['candidates']));
            if (empty($positions)) { $error = "Voting cannot proceed: No candidates added for available positions."; }
        }
        $result_positions->free_result();

    } catch (mysqli_sql_exception $db_ex) { // Catch specific DB errors
        error_log("Vote Page Load - Database Error: " . $db_ex->getMessage() . " (Code: " . $db_ex->getCode() . ")");
        $error = "An error occurred while loading voting information. Please try again later.";
        $positions = []; // Prevent form rendering
    } catch (Exception $e) { // Catch validation exceptions (election inactive, code used)
        // Log out and redirect with specific error message
        error_log("Vote Page Session Validation Error: " . $e->getMessage()); 
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_SESSION['login_error'] = $e->getMessage() . " Please log in again.";
        header("Location: index.php");
        exit();
    }
} // End DB connection check and initial data load

// --- Handle Vote Form Submission ---
$submitted_votes = [];
$missing_votes_for_positions = [];
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$error && !empty($positions)) {
    $submitted_votes = $_POST['votes'] ?? [];
    $required_position_ids = array_keys($positions);
    // Validate all positions have a selection
    foreach ($required_position_ids as $pos_id) {
        if (!isset($submitted_votes[$pos_id]) || !filter_var($submitted_votes[$pos_id], FILTER_VALIDATE_INT)) { $missing_votes_for_positions[] = $pos_id; }
    }

    if (!empty($missing_votes_for_positions)) {
        $error = "Please make a selection for all positions before submitting.";
    } else {
        // Proceed with vote processing within a transaction
        $isInTransaction = false;
        try {
            $conn->begin_transaction(); $isInTransaction = true;

            // 1. Lock and re-verify code is still unused
            $sql_lock_code = "SELECT is_used FROM voting_codes WHERE id = $voting_code_db_id FOR UPDATE";
            $result_lock = $conn->query($sql_lock_code);
            if ($result_lock === false) throw new mysqli_sql_exception("DB lock error: " . $conn->error, $conn->errno);
            if ($result_lock->num_rows === 0) { $result_lock->free_result(); throw new Exception("Voting code invalid."); }
            $locked_code_status = $result_lock->fetch_assoc(); $result_lock->free_result();
            if (!empty($locked_code_status['is_used'])) throw new Exception("Voting code already used.");

            // 2. Mark Voting Code as Used
            $sql_mark_used = "UPDATE voting_codes SET is_used = 1, used_at = NOW() WHERE id = $voting_code_db_id";
            $update_result = $conn->query($sql_mark_used);
            if ($update_result === false || $conn->affected_rows !== 1) throw new Exception("Failed to update voting code status.");

            // 3. Record Votes
            foreach ($submitted_votes as $position_id => $candidate_id) {
                $position_id_int = filter_var($position_id, FILTER_VALIDATE_INT);
                $candidate_id_int = filter_var($candidate_id, FILTER_VALIDATE_INT);
                // Final validation against loaded $positions data
                if ($position_id_int && $candidate_id_int && isset($positions[$position_id_int])) {
                    $candidate_exists = false; foreach($positions[$position_id_int]['candidates'] as $c) { if ($c['id'] === $candidate_id_int) { $candidate_exists = true; break; } }
                    if ($candidate_exists) {
                        $sql_insert_vote = "INSERT INTO votes (election_id, position_id, candidate_id, voting_code_id) VALUES ($election_id, $position_id_int, $candidate_id_int, $voting_code_db_id)";
                        if ($conn->query($sql_insert_vote) === false) throw new Exception("Error saving vote for position ID $position_id_int.");
                    } else { error_log("Vote Submit: Skipped invalid cand $candidate_id_int for pos $position_id_int."); }
                } else { error_log("Vote Submit: Skipped invalid pos $position_id or cand $candidate_id."); }
            }

            // 4. Commit
            if (!$conn->commit()) { $isInTransaction = false; throw new mysqli_sql_exception("Transaction commit failed: " . $conn->error, $conn->errno); }
            $isInTransaction = false;

            // 5. Success 
            $vote_processed_successfully = true;

            // Respond for AJAX or set flag for non-AJAX JS
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success' => true]); exit();
            }
            // Non-AJAX success handled by JS flag below

        } catch (Exception $e) {
            if ($isInTransaction && $conn && $conn->thread_id) { $conn->rollback(); }
            error_log("Vote Submission Exception: " . $e->getMessage());
            $error = "Error submitting vote: " . $e->getMessage(); 
            // Respond with JSON error if AJAX
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                $http_code = (stripos($error,'already been used') !== false ? 409 : 500);
                header('Content-Type: application/json; charset=utf-8', true, $http_code); echo json_encode(['success' => false, 'error' => $error]); exit();
            }
        }
    }
}

// --- Generate CSP nonce for HTML output ---
if (empty($_SESSION['csp_nonce'])) { $_SESSION['csp_nonce'] = base64_encode(random_bytes(16)); }
$nonce = htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8');

// --- Include HTML Header ---
// Use actual header include if available
if (file_exists("includes/header.php")) {
    require_once "includes/header.php";
} else {
    // Fallback basic HTML structure
    ?>
    <!DOCTYPE html> <html lang="en"> <head> <meta charset="UTF-8"> <meta name="viewport" content="width=device-width, initial-scale=1.0"> <title>Cast Your Vote - <?php echo htmlspecialchars($election['title'] ?? 'School Election'); ?></title> <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"> <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"> <link rel="preconnect" href="https://fonts.googleapis.com"> <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin> <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet"> </head> <body>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cast Your Vote - <?php echo htmlspecialchars($election['title'] ?? 'School Election'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">

    <style nonce="<?php echo $nonce; ?>">
        :root {
            --primary-hue: 226; --primary-color: hsl(var(--primary-hue), 76%, 58%); --primary-light: hsl(var(--primary-hue), 76%, 95%); --primary-dark: hsl(var(--primary-hue), 70%, 48%);
            --secondary-color: #858796; --success-color: #1cc88a; --success-dark: #17a673; --success-light: #e8f9f4;
            --light-color: #f8f9fc; --dark-color: #5a5c69; --white-color: #fff; --border-color: #e3e6f0; --danger-color: #e74a3b; --danger-light: #feebee;
            --font-family-primary: "Poppins", sans-serif; --font-family-secondary: "Nunito", sans-serif;
            --shadow-sm: 0 .125rem .25rem rgba(0,0,0,.075); --shadow: 0 .25rem .8rem rgba(58,59,69,.1); --shadow-lg: 0 1rem 3rem rgba(0,0,0,.15);
            --border-radius: .4rem; --border-radius-lg: .6rem;
        }
        html { scroll-behavior: smooth; }
        body { font-family: var(--font-family-secondary); background-color: var(--light-color); color: var(--dark-color); margin: 0; padding: 1.5rem; line-height: 1.6; }
        .main-container { max-width: 1140px; margin: 1rem auto; padding: clamp(1.5rem, 3vw, 2.5rem); background-color: var(--white-color); border-radius: var(--border-radius-lg); box-shadow: var(--shadow); border-top: 5px solid var(--primary-color); }
        .election-header { text-align: center; margin-bottom: 2.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); }
        .logo-container { display: flex; justify-content: center; align-items: center; gap: 2.5rem; margin-bottom: 1.5rem; }
        .logo { width: 70px; height: 70px; object-fit: contain; }
        .election-header h1 { color: var(--primary-dark); font-family: var(--font-family-primary); font-weight: 700; margin-bottom: .5rem; font-size: clamp(1.6rem, 4vw, 2.1rem); }
        .election-header p { color: var(--secondary-color); font-size: clamp(1rem, 2.5vw, 1.1rem); margin-bottom: 0;}
        .position-section { margin-bottom: 3rem; }
        .position-title { font-family: var(--font-family-primary); font-size: 1.4rem; font-weight: 700; color: var(--primary-color); margin-bottom: 1.5rem; padding-bottom: .75rem; border-bottom: 2px solid var(--primary-light); text-align: left; }

        /* Candidate Grid */
        .candidates-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 1.75rem; }
        .candidate-card {
            border: 2px solid var(--border-color); /* Slightly thicker default border */
            border-radius: var(--border-radius); transition: all 0.25s ease-in-out; cursor: pointer;
            background: var(--white-color); padding: 0; overflow: hidden; position: relative;
            box-shadow: var(--shadow-sm); display: flex; flex-direction: column; height: 100%; /* Ensure cards in row are same height */
        }
        .candidate-card:hover { border-color: var(--primary-color); transform: translateY(-5px) scale(1.01); box-shadow: var(--shadow); z-index: 10;}
        .candidate-card.selected {
            border-color: var(--success-color);
            box-shadow: 0 0 0 3px var(--success-color);
            transform: translateY(-3px) scale(1.03);
            z-index: 11; /* Bring selected slightly more forward */
        }
        .candidate-card.selected::before {
            content: '\F26E'; font-family: 'bootstrap-icons'; position: absolute; top: 10px; right: 10px;
            font-size: 1.7rem; font-weight: bold; color: var(--white-color); background-color: var(--success-color);
            width: 32px; height: 32px; line-height: 32px; text-align: center; border-radius: 50%;
            z-index: 2; box-shadow: 0 1px 3px rgba(0,0,0,.3); opacity: 0; transform: scale(0.5) rotate(-45deg);
            animation: popInCheckRotate 0.4s cubic-bezier(0.18, 0.89, 0.32, 1.28) forwards .1s;
        }
        @keyframes popInCheckRotate { to { opacity: 1; transform: scale(1) rotate(0deg); } }

        .candidate-photo-wrapper { width: 100%; aspect-ratio: 1 / 1; background-color: #f0f0f0; overflow: hidden; }
        .candidate-photo { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease; border-bottom: 1px solid var(--border-color); }
        .candidate-card:hover .candidate-photo { transform: scale(1.05); }
        .candidate-info { padding: 1rem 1.25rem; text-align: center; margin-top: auto; background-color: var(--white-color); } /* Ensure info background is white */
        .candidate-name { font-size: 1.05rem; font-weight: 600; color: var(--dark-color); margin-bottom: 0; font-family: var(--font-family-primary); }
        .candidate-option input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }

        /* Submit Area */
        .submit-button-wrapper { text-align: center; margin-top: 3rem; padding-top: 2rem; border-top: 1px solid var(--border-color); }
        .submit-button { background: linear-gradient(45deg, var(--success-color), var(--success-dark)); color: white; border: none; padding: .8rem 3rem; border-radius: 50px; cursor: pointer; font-size: 1.1rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; transition: all 0.3s ease; box-shadow: 0 5px 15px rgba(28, 200, 138, 0.3); }
        .submit-button:hover:not(:disabled) { box-shadow: 0 8px 20px rgba(28, 200, 138, 0.4); transform: translateY(-2px); filter: brightness(1.05); }
        .submit-button:disabled { background: var(--secondary-color); opacity: 0.6; cursor: not-allowed; box-shadow: none; transform: none; }
        .submit-button .spinner-border { width: 1.1rem; height: 1.1rem; vertical-align: -2px; }

        /* Validation & Error Styles */
        .position-section.validation-error { border-color: var(--danger-color) !important; background-color: var(--danger-light); box-shadow: 0 0 0 2px var(--danger-color); }
        .position-section.validation-error .position-title { color: var(--danger-color); border-bottom-color: var(--danger-color); }
        #validationAlert { margin-bottom: 1.5rem; font-weight: 500;}
        .alert-container { margin-bottom: 1.5rem; }
        .alert .bi { font-size: 1.2rem; vertical-align: -2px; margin-right: .7rem;}

        /* Success Message Div (alternative to modal) */
        #voteSuccessMessage { text-align: center; padding: 3rem 1rem; background-color: var(--success-light); border: 1px solid var(--success-color); border-radius: var(--border-radius-lg); margin-top: 2rem; box-shadow: var(--shadow); }
        #voteSuccessMessage .icon-wrapper { width: 80px; height: 80px; margin: 0 auto 1.5rem auto; background-color: var(--success-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; animation: success-scale-up 0.5s ease-out; }
        #voteSuccessMessage .bi-check-lg { font-size: 3rem; color: white; }
        #voteSuccessMessage h2 { font-family: var(--font-family-primary); color: var(--success-dark); margin-bottom: 0.5rem; font-weight: 700;}
        #voteSuccessMessage p { color: var(--dark-color); font-size: 1.1rem; }
        @keyframes success-scale-up { from { transform: scale(0.7); opacity: 0; } to { transform: scale(1); opacity: 1; } }


        /* Responsive */
        @media (max-width: 576px) {
             .main-container { padding: 1.5rem;}
             .candidates-list { grid-template-columns: 1fr; gap: 1rem;}
             .position-title {font-size: 1.15rem;}
             .submit-button { padding: .7rem 1.5rem; font-size: 1rem;}
             .election-header h1 { font-size: 1.5rem;}
             .election-header p { font-size: 0.95rem;}
        }
    </style>
</head>
<body>
    <?php /* Removed modal HTML */ ?>

    <div class="main-container">
        <header class="election-header">
            <?php if ($election): ?>
            <div class="logo-container">
                <img src="assets/images/electoral-commission-logo.png" alt="Electoral Commission Logo" class="logo" onerror="this.style.display='none'">
                <img src="assets/images/gombe-ss-logo.png" alt="School Logo" class="logo" onerror="this.style.display='none'">
            </div>
            <h1><?php echo htmlspecialchars($election['title']); ?></h1>
            <p class="lead">Select one candidate per position.</p>
            <?php else: ?>
                 <h1>Election Voting</h1>
                 <p class="lead">Loading election details...</p>
            <?php endif; ?>
        </header>

        <div class="alert-container">
            <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-center alert-dismissible fade show" role="alert">
                   <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
             <div id="validationAlert" class="alert alert-warning d-flex align-items-center d-none" role="alert">
                  <i class="bi bi-exclamation-circle-fill flex-shrink-0"></i>
                  <div id="validationAlertMessage">Please make a selection for all positions highlighted below.</div>
             </div>
        </div>

        <div id="votingFormArea">
            <?php if (!empty($positions) && !$error): // Render form only if positions exist and no critical error ?>
            <form method="post" id="votingForm" novalidate>
                <?php foreach ($positions as $position_id => $position): ?>
                    <section class="position-section <?php echo (in_array($position_id, $missing_votes_for_positions ?? [])) ? 'validation-error' : ''; ?>" id="position-<?php echo $position_id; ?>" aria-labelledby="position-title-<?php echo $position_id; ?>">
                        <h2 class="position-title" id="position-title-<?php echo $position_id; ?>"><?php echo htmlspecialchars(ucwords($position['title'])); ?></h2>
                        <?php if (empty($position['candidates'])): ?>
                             <p class="text-center text-muted fst-italic">No candidates available for this position.</p>
                        <?php else: ?>
                             <div class="candidates-list">
                                 <?php foreach ($position['candidates'] as $candidate): ?>
                                    <div class="candidate-card" tabindex="0" role="radio" aria-checked="false" aria-labelledby="candidate-name-<?php echo $candidate['id']; ?>">
                                        <label class="candidate-option d-block text-decoration-none p-0">
                                            <input type="radio" class="visually-hidden" name="votes[<?php echo $position_id; ?>]"
                                                   value="<?php echo $candidate['id']; ?>" required
                                                   id="candidate-radio-<?php echo $candidate['id']; ?>"
                                                   <?php echo (isset($submitted_votes[$position_id]) && $submitted_votes[$position_id] == $candidate['id']) ? ' checked' : ''; ?>
                                                   >
                                            <div class="candidate-photo-wrapper">
                                                <img src="<?php echo $candidate['photo']; ?>"
                                                     alt="Photo of <?php echo htmlspecialchars($candidate['name']); ?>"
                                                     class="candidate-photo" loading="lazy"
                                                     onerror="this.onerror=null; this.src='assets/images/default-avatar.png';">
                                            </div>
                                            <div class="candidate-info">
                                                <h3 class="candidate-name" id="candidate-name-<?php echo $candidate['id']; ?>"><?php echo htmlspecialchars($candidate['name']); ?></h3>
                                            </div>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                         <?php endif; ?>
                    </section>
                <?php endforeach; ?>

                <div class="submit-button-wrapper">
                    <button type="submit" class="submit-button" id="submitVoteBtn">
                         <i class="bi bi-send-check-fill me-2"></i>Confirm & Submit Vote
                    </button>
                </div>
            </form>
             <?php elseif(!$error): // No positions loaded, but no PHP error ?>
                 <div class="alert alert-info text-center shadow-sm" role="alert">
                     <i class="bi bi-info-circle-fill me-2"></i> Voting is currently unavailable. No positions or candidates are set up for this election.
                 </div>
            <?php endif; ?>
        </div> <div id="voteSuccessMessage" class="d-none">
              <div class="icon-wrapper"><i class="bi bi-check-lg"></i></div>
              <h2>Vote Submitted!</h2>
              <p>Thank you for participating.</p>
              <p class="mt-3"><small>You will be redirected shortly...</small></p>
         </div>

    </div> <script nonce="<?php echo $nonce; ?>" src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

    <script nonce="<?php echo $nonce; ?>">
        document.addEventListener('DOMContentLoaded', function() {
            const votingForm = document.getElementById('votingForm');
            const submitButton = document.getElementById('submitVoteBtn');
            const validationAlert = document.getElementById('validationAlert');
            const validationAlertMessage = document.getElementById('validationAlertMessage');
            const alertContainer = document.querySelector('.alert-container'); // For general errors
            const votingFormArea = document.getElementById('votingFormArea');
            const voteSuccessMessage = document.getElementById('voteSuccessMessage');

            // --- Helper: Handle Candidate Selection ---
            function handleSelection(cardElement) {
                const positionSection = cardElement.closest('.position-section');
                if (!positionSection) return;
                positionSection.querySelectorAll('.candidate-card').forEach(card => {
                    card.classList.remove('selected'); card.setAttribute('aria-checked', 'false');
                });
                cardElement.classList.add('selected'); cardElement.setAttribute('aria-checked', 'true');
                const radio = cardElement.querySelector('input[type="radio"]');
                if (radio) radio.checked = true;
                positionSection.classList.remove('validation-error');
                // Hide general validation alert if form becomes valid
                if (checkFormValidity(false)) { validationAlert.classList.add('d-none'); }
            }

            // --- Attach Event Listeners ---
            document.querySelectorAll('.candidates-list').forEach(list => {
                list.addEventListener('click', (event) => {
                    const card = event.target.closest('.candidate-card');
                    if (card) handleSelection(card);
                });
                list.addEventListener('keydown', (event) => {
                     const card = event.target.closest('.candidate-card');
                     if (card && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); handleSelection(card); }
                 });
            });

             // --- Form Validation ---
             function checkFormValidity(showErrors = false) {
                let allVoted = true;
                let firstErrorSection = null;
                if (showErrors) validationAlert.classList.add('d-none'); // Hide if re-validating

                document.querySelectorAll('.position-section').forEach(section => {
                    const positionId = section.id.replace('position-', '');
                    const isSelected = section.querySelector(`input[name="votes[${positionId}]"]:checked`);
                    const hasCandidates = section.querySelector('.candidate-card');
                    section.classList.remove('validation-error'); // Clear previous

                    if (!isSelected && hasCandidates) { // Only validate if candidates exist
                        allVoted = false;
                        if (showErrors) {
                             section.classList.add('validation-error');
                             if (!firstErrorSection) firstErrorSection = section;
                        }
                    }
                });

                if (!allVoted && showErrors) {
                    if(validationAlertMessage) validationAlertMessage.textContent = "Please select one candidate for each position highlighted below.";
                    validationAlert.classList.remove('d-none'); // Show alert
                    if (firstErrorSection) firstErrorSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return allVoted;
             }

             // --- Pre-select based on POST data (if validation failed server-side) ---
             <?php if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($submitted_votes)): ?>
                 Object.entries(<?php echo json_encode($submitted_votes); ?>).forEach(([posId, candId]) => {
                     const radio = document.getElementById(`candidate-radio-${candId}`);
                     if (radio) { const card = radio.closest('.candidate-card'); if (card) handleSelection(card); }
                 });
                 <?php if (!empty($missing_votes_for_positions)): ?>
                     checkFormValidity(true); // Highlight errors on initial load
                 <?php endif; ?>
             <?php endif; ?>

            // --- Form Submission Handling (Simplified Success) ---
            if (votingForm) {
                votingForm.addEventListener('submit', async function(event) {
                    event.preventDefault();
                    if (!checkFormValidity(true)) return; // Stop if invalid

                    if (submitButton) { submitButton.disabled = true; submitButton.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...`; }
                    alertContainer.innerHTML = ''; // Clear previous general errors
                    validationAlert.classList.add('d-none'); // Hide validation specific alert

                    try {
                        const formData = new FormData(votingForm);
                        const response = await fetch('vote.php', {
                            method: 'POST', body: formData,
                            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                        });

                        let result;
                        const contentType = response.headers.get("content-type");
                        if (contentType?.includes("application/json")) { result = await response.json(); }
                        else { const text = await response.text(); console.error("Non-JSON response:", text); throw new Error(`Server Error (Status: ${response.status}). Contact support.`); }

                        if (response.ok && result.success) {
                            // SUCCESS: Hide form, show success message, redirect
                            if(votingFormArea) votingFormArea.style.display = 'none';
                            if(voteSuccessMessage) voteSuccessMessage.classList.remove('d-none');
                            setTimeout(() => { window.location.href = 'index.php?voted=success'; }, 3000); // 3 second redirect
                        } else {
                            throw new Error(result.error || `Vote submission failed (Status: ${response.status}).`);
                        }
                    } catch (error) {
                        console.error('Submission Error:', error);
                         // Display error
                         if (alertContainer) {
                             alertContainer.innerHTML = `
                                 <div class="alert alert-danger d-flex align-items-center alert-dismissible fade show" role="alert">
                                    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
                                     <div>${error.message || 'An unexpected error occurred. Please try again.'}</div>
                                     <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                 </div>`;
                             const newAlert = alertContainer.querySelector('.alert');
                             if(newAlert) newAlert.scrollIntoView({ behavior: 'smooth', block: 'center' });
                         } else { alert('Vote submission failed: ' + error.message); }
                         // Re-enable button
                         if (submitButton) { submitButton.disabled = false; submitButton.innerHTML = `<i class="bi bi-send-check-fill me-2"></i>Confirm & Submit Vote`; }
                    }
                });
            } else { // Form not found
                console.warn("Voting form element (#votingForm) not found.");
                 if (submitButton) submitButton.disabled = true;
            }

            // --- Handle case where PHP flag indicates success (non-AJAX fallback) ---
            <?php if ($vote_processed_successfully): ?>
                 console.log("PHP flag indicates vote success. Hiding form and redirecting.");
                 if(votingFormArea) votingFormArea.style.display = 'none';
                 if(voteSuccessMessage) voteSuccessMessage.classList.remove('d-none');
                 setTimeout(() => { window.location.href = 'index.php?voted=success'; }, 3000);
            <?php endif; ?>

        }); // End DOMContentLoaded
    </script>

</body>
</html>