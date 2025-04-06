<?php
session_start();
require_once "../config/database.php"; // Assumes $conn is a mysqli object

// --- Response Helper Function ---
// Simplifies sending JSON responses and exiting
function send_json_response($success, $message) {
    // Ensure headers are not already sent
    if (!headers_sent()) {
         header('Content-Type: application/json; charset=utf-8'); // Added charset
    }
    echo json_encode(['success' => (bool)$success, 'message' => $message]);
    exit();
}

// --- Check if admin is logged in ---
if (!isset($_SESSION['admin_id'])) {
    // For API endpoints, returning JSON is usually better than redirecting
    send_json_response(false, 'Unauthorized access.');
}

// --- Check if ID is provided and valid ---
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    send_json_response(false, 'Invalid or missing Position ID.');
}

// Sanitize the ID by casting to integer - Crucial for security!
$position_id = (int)$_GET['id'];

// --- Database Operations ---
$query_failed = false;
$error_message = ''; // Store specific error

// Start transaction using mysqli
// Check if connection object exists before calling methods
if (!$conn || $conn->connect_error) {
     // Log the detailed connection error server-side
     error_log("Database connection error: " . ($conn ? $conn->connect_error : 'Unknown error'));
     send_json_response(false, 'Database connection failed.');
}

$conn->begin_transaction(); // Use mysqli transaction method

try {
    // --- Direct SQL execution using mysqli::query ---

    // 1. Delete related votes
    $delete_votes_sql = "DELETE v FROM votes v
                         INNER JOIN candidates c ON v.candidate_id = c.id
                         WHERE c.position_id = $position_id";
    $result1 = $conn->query($delete_votes_sql);
    if ($result1 === false) {
        $query_failed = true;
        $error_message = "Error deleting votes: " . $conn->error; // Get mysqli error
    }

    // 2. Delete related candidates (only proceed if previous query succeeded)
    if (!$query_failed) {
        $delete_candidates_sql = "DELETE FROM candidates WHERE position_id = $position_id";
        $result2 = $conn->query($delete_candidates_sql);
        if ($result2 === false) {
            $query_failed = true;
            $error_message = "Error deleting candidates: " . $conn->error;
        }
    }

    // 3. Finally delete the position (only proceed if previous queries succeeded)
    if (!$query_failed) {
        $delete_position_sql = "DELETE FROM positions WHERE id = $position_id";
        $result3 = $conn->query($delete_position_sql);
        if ($result3 === false) {
            $query_failed = true;
            $error_message = "Error deleting position record: " . $conn->error;
        } elseif ($conn->affected_rows === 0 && !$query_failed) {
             // Optional: Consider if the position not existing should be an error
             // This depends on application logic. We'll treat it as success for now.
             // $query_failed = true;
             // $error_message = "Position with ID $position_id not found.";
        }
    }

    // Check overall success or failure and commit/rollback
    if ($query_failed) {
        $conn->rollback(); // mysqli rollback
        send_json_response(false, "Transaction failed: " . $error_message);
    } else {
        $conn->commit(); // mysqli commit
        send_json_response(true, "Position (ID: $position_id) and related data deleted successfully.");
    }

} catch (Exception $e) {
    // Catch any unexpected exceptions during the process
    // Ensure rollback happens even if $conn exists but query failed before check
    if ($conn && !$conn->connect_error) { // Check connection before rollback attempt
        $conn->rollback();
    }
    // Log the detailed error for the admin/developer
    error_log("Unexpected error during position deletion (ID: $position_id): " . $e->getMessage());
    send_json_response(false, "An unexpected server error occurred during deletion.");

} finally {
     // Optional: Close the connection
     if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
         $conn->close();
     }
}

// Note: The script now exits via send_json_response(), so no code runs below finally.

?>