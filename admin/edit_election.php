<?php
require_once "../config/database.php"; // Assumes $conn is a mysqli object here
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Get JSON data from request body
$data = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($data['id']) || !isset($data['title']) || !isset($data['start_date']) || !isset($data['end_date']) || !isset($data['status'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

// --- SECURITY WARNING: Direct SQL construction ahead! ---
// --- This is highly discouraged due to SQL injection risks! ---
// --- Using mysqli prepared statements is the SAFE alternative ---

try {
    // Sanitize/Escape input values for direct inclusion in SQL using mysqli
    $id = (int)$data['id']; // Cast to integer for safety

    // Use real_escape_string for strings (DOES NOT add quotes)
    $title_escaped = $conn->real_escape_string($data['title']);
    $description_escaped = isset($data['description']) ? $conn->real_escape_string($data['description']) : null; // Handle potential null
    $start_date_escaped = $conn->real_escape_string($data['start_date']);
    $end_date_escaped = $conn->real_escape_string($data['end_date']);
    $status_escaped = $conn->real_escape_string($data['status']);

    // Construct the SQL query string directly
    // *** Manually add single quotes ' ' around the escaped string variables ***
    $sql = "UPDATE elections
            SET title = '$title_escaped', "; // Add quotes!

    // Handle potentially null description correctly in SQL
    if ($description_escaped === null) {
        $sql .= "description = NULL, ";
    } else {
        $sql .= "description = '$description_escaped', "; // Add quotes!
    }

    $sql .= "start_date = '$start_date_escaped', "; // Add quotes!
    $sql .= "end_date = '$end_date_escaped', ";   // Add quotes!
    $sql .= "status = '$status_escaped' ";        // Add quotes!
    $sql .= "WHERE id = $id"; // Integer doesn't need quotes

    // Execute the query directly using mysqli::query()
    // query() returns true on success for UPDATE/INSERT/DELETE, or false on failure.
    $result = $conn->query($sql);

    // Check if the execution failed
    if ($result === false) {
        // Provide MySQLi specific error
        throw new Exception('Failed to update election. SQL Error: ' . $conn->error);
    } else {
        // Execution succeeded
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Election updated successfully'
            // Optional: Check $conn->affected_rows if you need to know if anything actually changed
        ]);
    }

} catch (Exception $e) {
    header('Content-Type: application/json');
    // Log the detailed error for debugging
    error_log("Error updating election: " . $e->getMessage() . " | SQL was: " . ($sql ?? 'Not constructed'));
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while updating the election.'
        // 'debug_message' => 'Error updating election: ' . $e->getMessage() // Only for development/debugging
    ]);
} finally {
    // Optional: Close the connection if your script ends here
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>