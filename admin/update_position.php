<?php
session_start();
require_once "../config/database.php"; // Assumes $conn is a mysqli object

// --- Response Helper Function ---
// Simplifies sending JSON responses and exiting
function send_json_response($success, $message) {
    // Ensure headers are not already sent
    if (!headers_sent()) {
         header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => (bool)$success, 'message' => $message]);
    exit();
}

// --- Check if admin is logged in ---
if (!isset($_SESSION['admin_id'])) {
    send_json_response(false, 'Unauthorized access.');
}

// --- Check if it's a POST request ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response(false, 'Invalid request method.');
}

// --- Get and Validate Form Data ---
// Use trim and check if the keys exist. Cast IDs to int.
$position_id = isset($_POST['position_id']) ? (int)trim($_POST['position_id']) : 0;
$election_id = isset($_POST['election_id']) ? (int)trim($_POST['election_id']) : 0;
$title = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? ''); // Allow empty description

// Basic validation - check if IDs are valid and title is not empty
// Description can be empty based on DB schema (allows NULL or empty text)
if ($position_id <= 0 || $election_id <= 0 || empty($title)) {
     // Improved validation message
    send_json_response(false, 'Missing or invalid required fields (Position ID, Election ID, Title).');
}

// --- Database Operations ---
// Check if connection object exists before calling methods
if (!$conn || $conn->connect_error) {
     error_log("Database connection error: " . ($conn ? $conn->connect_error : 'Unknown error'));
     send_json_response(false, 'Database connection failed.');
}

try {
    // --- Sanitize/Escape String Inputs using mysqli::real_escape_string ---
    $title_escaped = $conn->real_escape_string($title);
    $description_escaped = $conn->real_escape_string($description); // Escape description

    // --- Construct the Direct SQL UPDATE Query ---
    // Remember to add single quotes ' ' around escaped string variables
    $sql = "UPDATE positions SET
                title = '$title_escaped',
                election_id = $election_id,       -- Integer, no quotes needed
                description = '$description_escaped' -- String, needs quotes
            WHERE id = $position_id";          

    // --- Execute the Query using mysqli::query ---
    $result = $conn->query($sql);

    // --- Check for Query Execution Errors ---
    if ($result === false) {
        // Query failed to execute
        error_log("Error updating position (ID: $position_id): " . $conn->error . " | SQL: " . $sql); // Log error
        send_json_response(false, 'Database error during update.');
    } else {
        // --- Query executed, check affected rows ---
        // $conn->affected_rows gives the number of rows changed by the last UPDATE/DELETE/INSERT
        if ($conn->affected_rows > 0) {
            // Success - at least one row was updated
            send_json_response(true, 'Position updated successfully.');
        } else {
            // Query succeeded, but no rows were changed.
            // This could mean the position ID didn't exist, OR the submitted data was identical to existing data.
            // Check if the position ID actually exists to provide a more specific message
            $check_sql = "SELECT id FROM positions WHERE id = $position_id";
            $check_result = $conn->query($check_sql);
            if ($check_result && $check_result->num_rows > 0) {
                 $check_result->free(); // Free check result
                 send_json_response(true, 'Position details submitted were the same as current data. No changes made.'); // Treat as success, but inform user
            } else {
                 if($check_result) $check_result->free(); // Free check result if it exists
                 send_json_response(false, 'Update failed: Position not found with the specified ID.');
            }
        }
    }

} catch (Exception $e) {
    // Catch any unexpected exceptions
    error_log("Unexpected error updating position (ID: $position_id): " . $e->getMessage());
    // Don't attempt rollback here as we didn't start a transaction
    send_json_response(false, "An unexpected server error occurred.");

} finally {
     // Optional: Close the connection
     if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
         $conn->close();
     }
}

// Note: The script now exits via send_json_response(), so no code runs below finally.
?>