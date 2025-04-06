<?php declare(strict_types=1);

/**
 * AJAX Handler for Live Election Results (MySQLi Direct SQL Version)
 * Handles fetching results for the latest active election.
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
error_reporting(E_ALL); ini_set('display_errors', '1'); // Dev settings
date_default_timezone_set('Africa/Nairobi'); // EAT

// --- Helper Function: Send JSON Response ---
if (!function_exists('send_json_response')) {
    function send_json_response(bool $success, $data = null, ?string $message = null): void {
        if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
        $response = ['success' => $success];
        if ($message !== null) { $response['message'] = $message; }
        if ($data !== null) { $response['data'] = $data; }
        if (!$success && http_response_code() === 200) { http_response_code(500); }
        echo json_encode($response); exit();
    }
}

// --- Includes (Corrected Paths relative to project root) ---
require_once "config/database.php"; // Assumes config folder is directly under project root
 
// --- Pre-Checks ---
if (!$conn || $conn->connect_error) {
    $errorMsg = "Database connection failed: " . ($conn ? $conn->connect_error : 'Check config');
    error_log("AJAX Live Results DB Connection Error: " . $errorMsg);
    http_response_code(500); send_json_response(false, null, 'Database connection error.');
}
// Optional: Add admin check if needed - REMOVED for public display assumption
// if (!isAdminLoggedIn()) { http_response_code(401); send_json_response(false, null, 'Unauthorized access.'); }

 
// Allow GET for fetching results
// if ($_SERVER['REQUEST_METHOD'] !== 'GET') { // Changed from POST
//     http_response_code(405); send_json_response(false, null, 'Invalid request method. Only GET is accepted.');
// }


// --- Main Logic ---
try {
    // 1. Find the latest *active* election
    $election_status_active = 'active';
    $election_sql = "SELECT id, title FROM elections WHERE status = '$election_status_active' ORDER BY start_date DESC, id DESC LIMIT 1";
    $election_result = $conn->query($election_sql);
    if ($election_result === false) throw new mysqli_sql_exception("Error finding active election: " . $conn->error, $conn->errno);

    $election_data = null;
    if ($election_result->num_rows > 0) { $election_data = $election_result->fetch_assoc(); }
    $election_result->free_result();

    if (!$election_data) { send_json_response(true, ['election' => null, 'positions' => []], "No active election found."); }

    $active_election_id = (int)$election_data['id'];

    // 2. Fetch positions, candidates, and vote counts (Optimized Query)
    $positions_output = []; // Initialize array to store results grouped by position
    $positions_candidates_sql = "SELECT
                                    p.id as position_id, p.title as position_title,
                                    c.id as candidate_id, c.name as candidate_name, c.photo as candidate_photo,
                                    COUNT(DISTINCT v.id) as vote_count
                                 FROM positions p
                                 LEFT JOIN candidates c ON p.id = c.position_id
                                 LEFT JOIN votes v ON c.id = v.candidate_id AND p.id = v.position_id AND v.election_id = $active_election_id
                                 WHERE p.election_id = $active_election_id
                                 GROUP BY p.id, c.id
                                 ORDER BY p.id ASC, vote_count DESC, c.name ASC";

    $pc_result = $conn->query($positions_candidates_sql);
    if ($pc_result === false) throw new mysqli_sql_exception("Error fetching results data: " . $conn->error, $conn->errno);

    // 3. Process results: Group by position, calculate totals/winners
    if ($pc_result->num_rows > 0) {
         while ($row = $pc_result->fetch_assoc()) {
              $pos_id = (int)$row['position_id'];
              if (!isset($positions_output[$pos_id])) { $positions_output[$pos_id] = ['id' => $pos_id,'title' => $row['position_title'],'total_votes' => 0,'candidates' => []]; }
              if ($row['candidate_id'] !== null) {
                   $vote_count = (int)$row['vote_count'];
                   $positions_output[$pos_id]['candidates'][] = ['id' => (int)$row['candidate_id'],'name' => $row['candidate_name'],'photo' => $row['candidate_photo'],'vote_count' => $vote_count];
                   $positions_output[$pos_id]['total_votes'] += $vote_count;
              }
         }
         // Post-process: Calculate percentages and winners
         foreach ($positions_output as $pos_id => &$position_data) { /* ... calculation logic as before ... */
              if ($position_data['total_votes'] > 0 && !empty($position_data['candidates'])) { $max_votes = 0; foreach ($position_data['candidates'] as $candidate) { if ($candidate['vote_count'] > $max_votes) { $max_votes = $candidate['vote_count']; } } foreach ($position_data['candidates'] as &$candidate_data) { $candidate_data['percentage'] = round(($candidate_data['vote_count'] / $position_data['total_votes']) * 100, 1); $candidate_data['is_winner'] = ($candidate_data['vote_count'] === $max_votes); } unset($candidate_data); } else { foreach ($position_data['candidates'] as &$candidate_data) { $candidate_data['percentage'] = 0; $candidate_data['is_winner'] = false; } unset($candidate_data); }
         } unset($position_data);
    }
    $pc_result->free_result();

    // 4. Send final data structure
    send_json_response(true, [ 'election' => $election_data, 'positions' => array_values($positions_output) ], "Live results fetched successfully.");

} catch (mysqli_sql_exception $db_ex) { // Catch specific DB errors
    error_log("Live Results AJAX DB Error: " . $db_ex->getMessage() . " (Code: " . $db_ex->getCode() . ")");
    http_response_code(500); // Ensure 500 for DB errors
    send_json_response(false, null, "A database error occurred while fetching results.");
} catch (Exception $e) { // Catch other general errors
    error_log("Live Results AJAX General Error: " . $e->getMessage());
    http_response_code(500); // Ensure 500 for unexpected errors
    send_json_response(false, null, "An unexpected server error occurred: " . $e->getMessage());
} finally {
    // Close connection if open
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
        $conn->close();
    }
}

 