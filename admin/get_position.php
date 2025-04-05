<?php
session_start();
require_once "../config/database.php"; // Assumes $conn is a mysqli object

// --- Response Helper Function ---
// Simplifies sending JSON responses and exiting
function send_json_response($success, $data, $message = null) {
    // Ensure headers are not already sent
    if (!headers_sent()) {
         header('Content-Type: application/json; charset=utf-8'); // Added charset
    }
    $response = ['success' => (bool)$success];
    if ($message !== null) {
        $response['message'] = $message;
    }
    // Add data/position key only on success and if data is not null
    if ($success && $data !== null) {
        $response['position'] = $data; // Changed key name based on original code
    } elseif (!$success && $data !== null) {
        // Add data under a different key for debugging if needed, but usually just message for failure
        // $response['error_details'] = $data;
    }
    echo json_encode($response);
    exit();
}

// --- Check if admin is logged in ---
if (!isset($_SESSION['admin_id'])) {
    send_json_response(false, null, 'Unauthorized access.');
}

// --- Check if ID is provided and valid ---
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    send_json_response(false, null, 'Invalid or missing Position ID.');
}

// Sanitize the ID by casting to integer - Crucial for security!
$position_id = (int)$_GET['id'];

// --- Database Operations ---
$position = null; // Initialize position data variable

// Check if connection object exists before calling methods
if (!$conn || $conn->connect_error) {
     // Log the detailed connection error server-side
     error_log("Database connection error: " . ($conn ? $conn->connect_error : 'Unknown error'));
     send_json_response(false, null, 'Database connection failed.');
}

try {
    // --- Direct SQL execution using mysqli::query ---
    $sql = "SELECT * FROM positions WHERE id = $position_id"; // Embed integer ID

    $result = $conn->query($sql);

    // Check if query execution failed
    if ($result === false) {
        // Log the detailed error server-side
        error_log("Error fetching position (ID: $position_id): " . $conn->error . " | SQL: " . $sql);
        send_json_response(false, null, 'Database query failed.');
    }

    // Check if a position was found
    if ($result->num_rows > 0) {
        // Fetch the position data as an associative array
        $position = $result->fetch_assoc();
        // Free the result set memory
        $result->free();
        // Send success response
        send_json_response(true, $position, 'Position data retrieved successfully.'); // Pass position data
    } else {
        // No position found with that ID
        $result->free(); // Still free the result set
        send_json_response(false, null, 'Position not found.');
    }

} catch (Exception $e) {
    // Catch any unexpected exceptions
    // Log the detailed error for the admin/developer
    error_log("Unexpected error fetching position (ID: $position_id): " . $e->getMessage());
    send_json_response(false, null, "An unexpected server error occurred.");

} finally {
     // Optional: Close the connection
     if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
         $conn->close();
     }
}

// Note: The script now exits via send_json_response(), so no code runs below finally.
?>