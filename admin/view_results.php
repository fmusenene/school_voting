<?php
// --- Initialization & Config ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
error_reporting(E_ALL); // Dev
ini_set('display_errors', 1); // Dev
date_default_timezone_set('Africa/Nairobi'); // EAT

require_once "../config/database.php"; // Provides $conn (mysqli connection)
require_once "includes/session.php"; // Provides isAdminLoggedIn()

// --- Security: Admin Check ---
if (!isAdminLoggedIn()) {
    header("Location: /school_voting/admin/login.php"); // Adjust path if needed
    exit();
}

// --- Input Validation ---
$election_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($election_id <= 0) {
    $_SESSION['error'] = "Invalid Election ID specified.";
    header("Location: elections.php");
    exit();
}

// --- Data Fetching ---
$election = null;
$positions = [];
$results_data = []; // Store results per position
$page_error = null;

// Check DB Connection
if (!$conn || $conn->connect_error) {
    $page_error = "Database connection failed: " . ($conn ? $conn->connect_error : 'Unknown error');
    error_log($page_error); // Log the detailed error
} else {
    try {
        // Get election details
        $election_sql = "SELECT id, title, status FROM elections WHERE id = $election_id"; // Embed integer ID
        $election_result = mysqli_query($conn, $election_sql);

        if ($election_result === false) {
            throw new Exception("Error fetching election details: " . mysqli_error($conn));
        }
        if (mysqli_num_rows($election_result) === 0) {
             mysqli_free_result($election_result);
            $_SESSION['error'] = "Election not found.";
            header("Location: elections.php");
            exit();
        }
        $election = mysqli_fetch_assoc($election_result);
        mysqli_free_result($election_result);

        // Get positions for this election
        $positions_sql = "SELECT id, title FROM positions WHERE election_id = $election_id ORDER BY title ASC";
        $positions_result = mysqli_query($conn, $positions_sql);

        if ($positions_result === false) {
             throw new Exception("Error fetching positions: " . mysqli_error($conn));
        }

        // Loop through positions to get candidates and votes
        while ($position = mysqli_fetch_assoc($positions_result)) {
            $position_id = (int)$position['id']; // Ensure integer
            $candidates = [];

            // Get candidates and their vote counts for this position using subquery
            $candidates_sql = "SELECT c.id, c.name, c.photo,
                                   (SELECT COUNT(*) FROM votes WHERE candidate_id = c.id AND position_id = $position_id) as vote_count
                               FROM candidates c
                               WHERE c.position_id = $position_id
                               ORDER BY vote_count DESC, c.name ASC"; // Added name sort for ties

            $candidates_result = mysqli_query($conn, $candidates_sql);
            if ($candidates_result === false) {
                 // Log error but potentially continue to next position? Or fail all? Let's throw.
                 throw new Exception("Error fetching candidates for position '{$position['title']}': " . mysqli_error($conn));
            }

            // Fetch all candidates for this position
            while ($candidate_row = mysqli_fetch_assoc($candidates_result)) {
                $candidates[] = $candidate_row;
            }
            mysqli_free_result($candidates_result);

            // Calculate total votes and determine winner(s) for this position
            $total_votes = 0;
            $max_votes = 0;
            if (!empty($candidates)) {
                $vote_counts = array_column($candidates, 'vote_count');
                $total_votes = array_sum($vote_counts);
                $max_votes = max($vote_counts);
            }

            $winners = [];
            if ($max_votes > 0) { // Only declare winners if there are votes
                $winners = array_filter($candidates, function($c) use ($max_votes) {
                    return $c['vote_count'] == $max_votes;
                });
            }

            // Store results for this position
            $results_data[$position_id] = [
                'position_info' => $position,
                'candidates' => $candidates,
                'total_votes' => $total_votes,
                'winners' => $winners // Store winner info
            ];
        }
        mysqli_free_result($positions_result);

    } catch (Exception $e) {
        error_log("Election Results Page Error (Election ID: $election_id): " . $e->getMessage());
        $page_error = "Failed to load election results. Please try again later.";
        // Reset data arrays on error
        $election = $election ?: ['id' => $election_id, 'title' => 'Unknown Election', 'status' => 'unknown'];
        $results_data = [];
    }
} // End DB connection check

// Generate CSP nonce if needed
if (empty($_SESSION['csp_nonce'])) {
    $_SESSION['csp_nonce'] = base64_encode(random_bytes(16));
}
$nonce = htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8');

// --- Include HTML Header ---
require_once "includes/header.php";
?>

<style nonce="<?php echo $nonce; ?>">
    /* --- Styles (mostly unchanged, ensure nonce) --- */
    :root {
         --primary-color: #0d6efd;
         --success-color: #198754;
         --warning-color: #ffc107;
         --secondary-color: #6c757d;
         --light-color: #f8f9fa;
         --dark-color: #212529;
         --blue-progress: #1890ff; /* Specific blue for progress */
         --winner-bg: #e3fcef;
         --winner-text: #00a854;
         --border-color: #dee2e6;
    }
    body { background-color: var(--light-color); }
    .results-card { background: white; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); margin-bottom: 1.5rem; overflow: hidden; }
    .results-header { background: var(--light-color); padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-color); }
    .results-header h3 { margin: 0; color: var(--dark-color); font-size: 1.25rem; font-weight: 600;}
    .results-body { padding: 1.5rem; }
    .candidate-card { background: #fdfdff; border: 1px solid #f0f0f0; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; transition: transform 0.2s ease-out, box-shadow 0.2s ease-out; }
    .candidate-card:hover { transform: translateY(-3px); box-shadow: 0 4px 8px rgba(0,0,0,0.06); }
    .candidate-info { display: flex; align-items: center; margin-bottom: 0.5rem; }
    .candidate-photo { width: 60px; height: 60px; border-radius: 50%; object-fit: cover; margin-right: 1rem; border: 2px solid var(--border-color); }
    .candidate-photo-default { background-color: var(--secondary-color); color: white; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; } /* For default icon */
    .candidate-name { font-size: 1.1rem; font-weight: 600; color: var(--dark-color); margin: 0; }
    .candidate-votes { font-size: 1.5rem; font-weight: 700; color: var(--blue-progress); margin: 0.25rem 0; }
    .candidate-percentage { font-size: 0.9rem; color: var(--secondary-color); margin-left: 0.5rem;}
    .progress { height: 8px; background-color: #e9ecef; border-radius: 4px; overflow: hidden; margin-top: 0.5rem; }
    .progress-bar { background-color: var(--blue-progress); transition: width 0.4s ease-in-out; }
    .winner-badge { background: var(--winner-bg); color: var(--winner-text); padding: 0.25rem 0.75rem; border-radius: 50px; font-size: 0.8rem; font-weight: 600; margin-left: 0.75rem; vertical-align: middle; border: 1px solid var(--winner-text); }
    .back-button { color: var(--dark-color); border: 1px solid var(--border-color); background: var(--white-color); padding: 0.5rem 1rem; border-radius: 5px; font-weight: 500; transition: background 0.2s; text-decoration: none; display: inline-flex; align-items: center; margin-bottom: 1.5rem; }
    .back-button:hover { background: var(--light-color); color: var(--dark-color); }
    .back-button i { margin-right: 0.5rem; }
    .election-status-badge { font-size: 0.9rem; }
</style>

<div class="container-fluid py-4">
    <a href="elections.php" class="back-button shadow-sm">
        <i class="bi bi-arrow-left"></i> Back to Elections List
    </a>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0 text-gray-800"><?php echo htmlspecialchars($election['title'] ?? 'Election'); ?> Results</h1>
        <div>
            <?php
                $status = $election['status'] ?? 'unknown';
                $badge_class = 'secondary'; // default
                if ($status === 'active') $badge_class = 'success';
                elseif ($status === 'pending') $badge_class = 'warning';
            ?>
            <span class="badge bg-<?php echo $badge_class; ?> election-status-badge">
                Status: <?php echo ucfirst(htmlspecialchars($status)); ?>
            </span>
        </div>
    </div>

     <?php if ($page_error): ?>
         <div class="alert alert-danger">
             <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($page_error); ?>
         </div>
     <?php endif; ?>


    <?php
    // Check if there's data to display even if no page error occurred during fetch setup
    if (!$page_error && !empty($results_data)):
        foreach ($results_data as $pos_id => $data):
            $position = $data['position_info'];
            $candidates = $data['candidates'];
            $total_votes = $data['total_votes'];
            $winners = $data['winners'];
            $winner_ids = array_column($winners, 'id'); // Get IDs of winners
    ?>
        <div class="results-card">
            <div class="results-header">
                <h3><?php echo htmlspecialchars($position['title']); ?></h3>
            </div>
            <div class="results-body">
                <?php if (count($candidates) > 0): ?>
                    <?php foreach ($candidates as $candidate):
                        $percentage = ($total_votes > 0) ? round(($candidate['vote_count'] / $total_votes) * 100) : 0;
                        $is_winner = in_array($candidate['id'], $winner_ids);
                    ?>
                        <div class="candidate-card">
                            <div class="candidate-info">
                                <?php
                                    $photo_display_path = 'assets/images/default-avatar.png'; // Default
                                    if (!empty($candidate['photo'])) {
                                        $file_check_path = dirname(__DIR__) . '/' . ltrim($candidate['photo'], '/');
                                        if (file_exists($file_check_path)) {
                                            $photo_display_path = '../' . ltrim($candidate['photo'], '/');
                                        }
                                    }
                                ?>
                                <img src="<?php echo htmlspecialchars($photo_display_path); ?>"
                                     alt="<?php echo htmlspecialchars($candidate['name']); ?>"
                                     class="candidate-photo"
                                     onerror="this.onerror=null; this.src='assets/images/default-avatar.png';">

                                <div>
                                    <h4 class="candidate-name mb-0">
                                        <?php echo htmlspecialchars($candidate['name']); ?>
                                        <?php if ($is_winner && $total_votes > 0): // Show winner badge only if votes exist ?>
                                            <span class="winner-badge">Winner</span>
                                        <?php endif; ?>
                                    </h4>
                                     <div class="candidate-votes">
                                        <?php echo $candidate['vote_count']; ?> vote(s)
                                        <?php if ($total_votes > 0): ?>
                                             <span class="candidate-percentage">(<?php echo $percentage; ?>%)</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <?php if ($total_votes > 0): ?>
                                <div class="progress">
                                    <div class="progress-bar"
                                         role="progressbar"
                                         style="width: <?php echo $percentage; ?>%"
                                         aria-valuenow="<?php echo $percentage; ?>"
                                         aria-valuemin="0"
                                         aria-valuemax="100">
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div> <?php endforeach; ?>
                     <p class="text-muted mt-3 mb-0">Total Votes Cast for this Position: <strong><?php echo $total_votes; ?></strong></p>
                <?php else: ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-people fs-2"></i>
                        <p class="mt-2 mb-0">No candidates were registered for this position.</p>
                    </div>
                <?php endif; ?>
            </div> </div> <?php
        endforeach; // End loop through positions
    elseif (!$page_error): // No positions found for the election, and no other error occurred
    ?>
        <div class="results-card">
             <div class="results-body">
                  <div class="text-center text-muted py-5">
                      <i class="bi bi-list-task fs-2"></i>
                      <p class="mt-2 mb-0">No positions were found for this election.</p>
                  </div>
             </div>
        </div>
    <?php endif; ?>

</div> <?php
// Include Footer
require_once "includes/footer.php";

// Close connection if it exists and is open
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    mysqli_close($conn);
}
?>