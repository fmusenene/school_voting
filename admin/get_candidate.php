<?php
session_start();
require_once "../config/database.php"; // Assumes $conn is a mysqli object

// --- Response Helper Function ---
// Simplifies sending JSON responses and exiting
function send_json_response($success, $data = null, $message = null, $data_key = 'data') { // Added $data_key parameter
    // Ensure headers are not already sent
    if (!headers_sent()) {
         header('Content-Type: application/json; charset=utf-8');
    }
    $response = ['success' => (bool)$success];
    if ($message !== null) {
        $response['message'] = $message;
    }
    // Add data under the specified key only on success and if data is not null
    if ($success && $data !== null && $data_key !== null) {
        $response[$data_key] = $data;
    } elseif (!$success && $data !== null) {
        // Optional: Add error data if needed for debugging
        // $response['error_details'] = $data;
    }
    echo json_encode($response);
    exit();
}

// --- Check if admin is logged in ---
if (!isset($_SESSION['admin_id'])) {
    send_json_response(false, null, 'Unauthorized access.');
}

// --- Get and Validate Candidate ID ---
// Use isset and is_numeric for better validation before casting
$candidate_id = null;
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $candidate_id = (int)$_GET['id']; // Cast to integer
}

if ($candidate_id === null || $candidate_id <= 0) { // Check if valid ID
    send_json_response(false, null, 'Invalid or missing Candidate ID.');
}


// --- Database Operations ---
// Check if connection object exists before calling methods
if (!$conn || $conn->connect_error) {
     error_log("Database connection error: " . ($conn ? $conn->connect_error : 'Unknown error'));
     send_json_response(false, null, 'Database connection failed.');
}

try {
    // --- Construct Direct SQL Query ---
    // Embed the sanitized integer ID directly into the SQL string
    $sql = "SELECT c.*, p.title as position_title, e.title as election_title
            FROM candidates c
            LEFT JOIN positions p ON c.position_id = p.id
            LEFT JOIN elections e ON p.election_id = e.id
            WHERE c.id = $candidate_id"; // Embed integer ID

    // --- Execute Query using mysqli::query ---
    $result = $conn->query($sql);

    // --- Check for Query Execution Errors ---
    if ($result === false) {
        // Log the detailed error server-side
        error_log("Error fetching candidate (ID: $candidate_id): " . $conn->error . " | SQL: " . $sql);
        send_json_response(false, null, 'Database query failed.');
    }

    // --- Check if Candidate Found and Fetch Data ---
    if ($result->num_rows > 0) {
        // Fetch the candidate data
        $candidate = $result->fetch_assoc();
        // Free the result set memory
        $result->free();
        // Send success response with candidate data under the 'candidate' key
        send_json_response(true, $candidate, 'Candidate data retrieved successfully.', 'candidate'); // Specify 'candidate' as the data key
    } else {
        // No candidate found with that ID
        $result->free(); // Still free the result set
        send_json_response(false, null, 'Candidate not found.');
    }

} catch (Exception $e) {
    // Catch any unexpected exceptions
    error_log("Unexpected error fetching candidate (ID: $candidate_id): " . $e->getMessage());
    send_json_response(false, null, "An unexpected server error occurred.");

} finally {
     // Optional: Close the connection
     if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
         $conn->close();
     }
}

// Note: The script now exits via send_json_response().
?>