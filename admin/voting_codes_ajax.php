<?php
/**
 * AJAX Handler for Voting Code Management (MySQLi Direct SQL Version)
 *
 * Handles fetching, creating, and deleting voting codes via AJAX requests.
 * IMPORTANT: This version uses direct SQL execution as requested, which is less
 * secure than prepared statements. Use with caution.
 */

// --- Initialization & Config ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
error_reporting(E_ALL); // Dev: Show all errors
ini_set('display_errors', 1); // Dev: Display errors
// Production: error_reporting(0); ini_set('display_errors', 0);
date_default_timezone_set('Africa/Nairobi'); // EAT: Ensure correct timezone

// Adjust relative paths if needed for includes
require_once "../config/database.php"; // Provides $conn (mysqli object)
require_once "includes/session.php"; // Provides isAdminLoggedIn() function

// --- Helper Function: Send JSON Response ---
// (Ensure this function is defined only ONCE - preferably in an included file)
if (!function_exists('send_json_response')) {
    function send_json_response($success, $data = null, $message = null, $data_key = 'data') {
        if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
        $response = ['success' => (bool)$success];
        if ($message !== null) { $response['message'] = $message; }
        // Using 'data' as the key for the payload to match JS expectations
        if ($data !== null) { $response['data'] = $data; }
        if (!$success && http_response_code() === 200) { http_response_code(400); } // Default Bad Request
        echo json_encode($response); exit(); // Exit script after sending response
    }
}

// --- Helper Function: Generate Unique Voting Code (using MySQLi) ---
// (Ensure defined ONCE)
if (!function_exists('generateVotingCode')) {
    function generateVotingCode($conn, $length = 6) {
        if (!$conn || $conn->connect_error) throw new Exception("DB connection error in generateVotingCode.");
        $attempts = 0; $max_attempts = 100; // Safety limit
        do {
            if ($attempts >= $max_attempts) throw new Exception("Unable to generate unique code after $max_attempts attempts (check available codespace/uniqueness constraint).");
            // Generate random numeric string
            $code = ''; for ($i = 0; $i < $length; $i++) { $code .= mt_rand(0, 9); }
            // Escape the generated code for the SQL query
            $code_escaped = mysqli_real_escape_string($conn, $code);
            // Check uniqueness in the database
            $check_sql = "SELECT id FROM voting_codes WHERE code = '$code_escaped' LIMIT 1";
            $check_result = mysqli_query($conn, $check_sql);
            if ($check_result === false) throw new Exception("DB error checking code uniqueness: " . mysqli_error($conn));
            $exists = (mysqli_num_rows($check_result) > 0);
            mysqli_free_result($check_result); // Free result memory
            $attempts++;
        } while ($exists); // Loop if code already exists
        return $code;
    }
}

// --- Pre-Checks (Essential for Security and Functionality) ---

// 1. Check DB Connection
if (!$conn || $conn->connect_error) {
    $errorMsg = "Database connection failed: " . ($conn ? $conn->connect_error : 'Check config');
    error_log("AJAX Voting Codes DB Connection Error: " . $errorMsg);
    http_response_code(500); send_json_response(false, null, 'Database connection error.');
}

// 2. Check Admin Login
if (!isAdminLoggedIn()) { // Assumes this function checks session validity
    http_response_code(401); send_json_response(false, null, 'Unauthorized access.');
}

// 3. Check if it's an AJAX Request (Security Best Practice)
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403); send_json_response(false, null, "Forbidden: Invalid request type.");
}

// 4. Check Request Method (Require POST for actions that modify data)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); send_json_response(false, null, 'Invalid request method. Only POST is accepted.');
}

// --- AJAX ACTION HANDLING ROUTER ---
$action = $_POST['action'] ?? ''; // Get action from POST data

try {
    // Route to the appropriate function based on the action
    switch ($action) {
        case 'fetch_codes':
            ajaxFetchVotingCodes($conn);
            break; // Essential: prevents fall-through
        case 'create':
            ajaxCreateVotingCodes($conn);
            break; // Essential: prevents fall-through
        case 'delete': // Handles single code deletion
            ajaxDeleteVotingCode($conn);
            break; // Essential: prevents fall-through
        case 'delete_bulk': // Handles bulk code deletion
            ajaxDeleteBulkCodes($conn);
            break; // Essential: prevents fall-through
        default:
            // If action doesn't match known handlers
            send_json_response(false, null, "Invalid action specified.");
    }
} catch (Exception $e) {
     // Catch any unexpected errors thrown from handler functions or router
     error_log("Voting Code Action General Error: Action='$action', Error: " . $e->getMessage());
     // Check if still in transaction before rollback attempt (safer)
     if ($conn->thread_id && $conn->server_info && $conn->stat() !== null && $conn->field_count > 0) { // Simple active checks might vary
        // Only rollback if a transaction might be active; specific flag is better if possible
        // For simplicity, we attempt rollback, mysqli handles it gracefully if not active
        $conn->rollback();
     }
     http_response_code(500); // Internal Server Error for unexpected issues
     send_json_response(false, null, "An unexpected server error occurred: " . $e->getMessage()); // Provide message for debugging if needed
} finally {
    // Ensure connection is closed if it was successfully opened
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
        mysqli_close($conn);
    }
}
// --- End Main Logic ---


// === Function Implementations using MySQLi ===

/**
 * Fetches a paginated and searchable list of voting codes.
 * Uses direct mysqli queries.
 * @param mysqli $conn Database connection object.
 */
function ajaxFetchVotingCodes($conn) {
    try {
        // --- Input Sanitization & Pagination Setup ---
        $page = isset($_POST['page']) && filter_var($_POST['page'], FILTER_VALIDATE_INT) ? (int)$_POST['page'] : 1;
        $records_per_page = isset($_POST['records_per_page']) && filter_var($_POST['records_per_page'], FILTER_VALIDATE_INT) ? (int)$_POST['records_per_page'] : 10;
        $search = isset($_POST['search']) ? trim($_POST['search']) : '';
        $filter_election_id = isset($_POST['election_id']) && filter_var($_POST['election_id'], FILTER_VALIDATE_INT) ? (int)$_POST['election_id'] : null;

        // Validate limits
        if ($records_per_page < 1 || $records_per_page > 100) $records_per_page = 10;
        if ($page < 1) $page = 1;
        $offset = ($page - 1) * $records_per_page;

        // --- Build Query Parts (Optimized JOIN/GROUP BY) ---
        $select_clause = "SELECT vc.id, vc.code, vc.election_id, vc.created_at,
                                 e.title as election_title, e.status as election_status,
                                 MAX(v.created_at) as used_at, -- Get usage timestamp
                                 COUNT(DISTINCT v.id) as vote_count -- Count actual votes linked
                          ";
        $from_clause = " FROM voting_codes vc
                         LEFT JOIN elections e ON vc.election_id = e.id
                         LEFT JOIN votes v ON vc.id = v.voting_code_id"; // Join gets usage info
        $where_clause = " WHERE 1=1"; // Base condition
        $group_by_clause = " GROUP BY vc.id"; // Essential for aggregate functions (COUNT, MAX)
        $order_by_clause = " ORDER BY vc.created_at DESC"; // Order results

        // Apply Filters to WHERE clause
        if ($filter_election_id && $filter_election_id > 0) {
            $where_clause .= " AND vc.election_id = $filter_election_id"; // Embed validated integer
        }
        if (!empty($search)) {
            $search_escaped = $conn->real_escape_string($search); // Escape search term
            // Add quotes and wildcards for LIKE comparison
            $where_clause .= " AND (vc.code LIKE '%$search_escaped%' OR e.title LIKE '%$search_escaped%')";
        }

        // --- Get Total Records Count (with filters) ---
        // Use the same FROM and WHERE clauses for accurate counting
        $count_sql = "SELECT COUNT(DISTINCT vc.id)" . $from_clause . $where_clause;
        $count_result = mysqli_query($conn, $count_sql);
        if ($count_result === false) throw new Exception("Error counting records: " . mysqli_error($conn));
        $total_records_row = mysqli_fetch_row($count_result);
        $total_records = (int)$total_records_row[0];
        mysqli_free_result($count_result);

        // --- Calculate Total Pages & Adjust Current Page ---
        $total_pages = ($records_per_page > 0) ? ceil($total_records / $records_per_page) : 0;
        // Adjust page number if it's out of bounds after filtering
        if ($page > $total_pages && $total_pages > 0) { $page = $total_pages; }
        if ($page < 1) { $page = 1; } // Ensure page is at least 1
        $offset = ($page - 1) * $records_per_page; // Recalculate offset
        $limit_clause = " LIMIT $records_per_page OFFSET $offset"; // Define LIMIT clause

        // --- Build and Execute Main Fetch Query ---
        $sql = $select_clause . $from_clause . $where_clause . $group_by_clause . $order_by_clause . $limit_clause;
        $result = mysqli_query($conn, $sql);
        if ($result === false) throw new Exception("Error fetching voting codes: " . mysqli_error($conn));

        // --- Fetch Results and Process ---
        $codes = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $row['vote_count'] = (int)$row['vote_count']; // Ensure integer
            $row['status'] = ($row['vote_count'] > 0) ? 'Used' : 'Available'; // Determine status
            $row['created_at_formatted'] = date('M d, Y H:i', strtotime($row['created_at']));
            $row['used_at_formatted'] = !empty($row['used_at']) ? date('M d, Y H:i', strtotime($row['used_at'])) : 'N/A';
            $row['is_deletable'] = ($row['status'] === 'Available'); // Add flag for UI button state
            $codes[] = $row;
        }
        mysqli_free_result($result); // Free result memory

        // --- Send JSON Response ---
        // The data payload matches the structure expected by the original JS
        send_json_response(true, [
            'codes' => $codes,
            'total_pages' => $total_pages,
            'total_records' => $total_records,
            'current_page' => $page,
            'records_per_page' => $records_per_page
        ], "Voting codes fetched successfully");

    } catch (Exception $e) {
        error_log("Fetch Voting Codes AJAX Error: " . $e->getMessage());
        send_json_response(false, null, "Error fetching data: " . $e->getMessage());
    }
}

/**
 * Creates a batch of unique voting codes for a specific election.
 * Uses direct mysqli queries within a transaction.
 * @param mysqli $conn Database connection object.
 */
function ajaxCreateVotingCodes($conn) {
    $isInTransaction = false; // Flag to track transaction state for rollback safety
    try {
        // --- Input Validation ---
        $election_id = filter_input(INPUT_POST, 'election_id', FILTER_VALIDATE_INT);
        $num_codes = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT); // Use 'quantity' from form

        if (!$election_id || $election_id <= 0) throw new Exception("Please select a valid Election.");
        if (!$num_codes || $num_codes < 1) throw new Exception("Number of codes must be at least 1.");
        if ($num_codes > 1000) throw new Exception("Cannot generate more than 1000 codes at once.");

        // --- Check if Election Exists ---
        $check_election_sql = "SELECT id FROM elections WHERE id = $election_id LIMIT 1";
        $check_election_res = mysqli_query($conn, $check_election_sql);
        if($check_election_res === false) throw new Exception("Error checking election existence: ".mysqli_error($conn));
        if(mysqli_num_rows($check_election_res) === 0) {mysqli_free_result($check_election_res); throw new Exception("Selected election (ID: $election_id) does not exist.");}
        mysqli_free_result($check_election_res);

        // --- Generation Process within Transaction ---
        $conn->begin_transaction();
        $isInTransaction = true;
        $success_count = 0; $failed_attempts = 0; $max_generation_attempts_total = $num_codes * 10; // Allow retries

        for ($i = 0; $i < $num_codes; $i++) {
            try {
                $code = generateVotingCode($conn); // Generate unique code
                $code_escaped = mysqli_real_escape_string($conn, $code); // Escape generated code
                // Insert using Direct SQL
                $insert_sql = "INSERT INTO voting_codes (code, election_id) VALUES ('$code_escaped', $election_id)";
                $insert_result = mysqli_query($conn, $insert_sql);

                if ($insert_result) { $success_count++; }
                else { error_log("Failed insert code '$code_escaped': " . mysqli_error($conn)); $failed_attempts++; }
                // Safety break if excessive failures occur
                if ($failed_attempts > $max_generation_attempts_total && $success_count == 0) throw new Exception("Failed uniqueness check excessively.");

            } catch (Exception $gen_ex) {
                 // Catch errors from generateVotingCode or the insert query within the loop
                 error_log("Code Gen/Insert Error loop $i: " . $gen_ex->getMessage()); $failed_attempts++;
                 // Decide if failure is critical - maybe stop if too many errors?
                 if ($failed_attempts > $max_generation_attempts_total / 2) throw new Exception("Code generation failed multiple times. Aborting batch.");
                 // Continue to next iteration otherwise
            }
        } // End for loop

        // --- Commit or Rollback ---
        if ($success_count > 0) {
            if (!$conn->commit()) { $isInTransaction = false; throw new Exception("Transaction commit failed: " . mysqli_error($conn));}
            $isInTransaction = false; // Mark transaction finished
            // Send success response with count
            send_json_response(true, ['generated_count' => $success_count], "$success_count voting code(s) generated successfully.");
        } else {
            $conn->rollback(); // Rollback if zero codes were successfully inserted
            $isInTransaction = false;
            send_json_response(false, null, "Failed to generate any unique voting codes. Please check logs or try again.");
        }
    } catch (Exception $e) {
        // Rollback only if transaction was started and might be active
        if ($isInTransaction && $conn && $conn->thread_id) { $conn->rollback(); }
        error_log("Create Voting Codes AJAX Error: " . $e->getMessage());
        send_json_response(false, null, "Error generating codes: " . $e->getMessage());
    }
}

/**
 * Deletes a single, unused voting code.
 * Uses direct mysqli queries.
 * @param mysqli $conn Database connection object.
 */
function ajaxDeleteVotingCode($conn) {
    try {
        // --- Input Validation ---
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id || $id <= 0) throw new Exception("Invalid voting code ID.");

        // --- Check if code has been used ---
        // Efficiently check if any votes exist for this code ID
        $check_used_sql = "SELECT id FROM votes WHERE voting_code_id = $id LIMIT 1";
        $check_res = mysqli_query($conn, $check_used_sql);
        if ($check_res === false) throw new Exception("Error checking code usage: " . mysqli_error($conn));

        $is_used = (mysqli_num_rows($check_res) > 0);
        mysqli_free_result($check_res);

        if ($is_used) {
            send_json_response(false, null, "Cannot delete a voting code that has already been used.");
            // No exit() needed here as send_json_response exits
        }

        // --- Delete the code ---
        // We already know it's not used from the check above
        $delete_sql = "DELETE FROM voting_codes WHERE id = $id";
        $delete_result = mysqli_query($conn, $delete_sql);

        if ($delete_result === false) throw new Exception("Database error deleting code: " . mysqli_error($conn));

        if (mysqli_affected_rows($conn) > 0) {
            send_json_response(true, null, "Voting code deleted successfully.");
        } else {
            // Code might have been deleted by another request between check and delete
            send_json_response(false, null, "Could not delete code (not found or already deleted).");
        }
    } catch (Exception $e) {
        error_log("Delete Voting Code AJAX Error (ID: " . ($id ?? 'unknown') . "): " . $e->getMessage());
        send_json_response(false, null, "Error deleting code: " . $e->getMessage());
    }
}

/**
 * Deletes multiple unused voting codes.
 * Uses direct mysqli queries.
 * @param mysqli $conn Database connection object.
 */
function ajaxDeleteBulkCodes($conn) {
    $ids_to_delete = [];
    // Ensure the key matches the one sent by JS ('selected_codes[]')
    if (!empty($_POST['selected_codes']) && is_array($_POST['selected_codes'])) {
        $ids_to_delete = array_map('intval', $_POST['selected_codes']);
        // Filter out invalid IDs (e.g., 0 or negative)
        $ids_to_delete = array_filter($ids_to_delete, function($id) { return $id > 0; });
    }
    if (empty($ids_to_delete)) send_json_response(false, null, 'No valid codes selected.');

    $ids_list = implode(',', $ids_to_delete); // Safe as all elements are integers
    try {
        // --- Delete only codes that have NOT been used ---
        // Using NOT EXISTS is generally efficient for this check
        $delete_sql = "DELETE FROM voting_codes
                       WHERE id IN ($ids_list)
                       AND NOT EXISTS (SELECT 1 FROM votes WHERE voting_code_id = voting_codes.id)";
        $delete_result = mysqli_query($conn, $delete_sql);
        if ($delete_result === false) throw new Exception("DB error during bulk delete: " . mysqli_error($conn));

        $deleted_count = mysqli_affected_rows($conn); // Get number of rows actually deleted
        $total_selected = count($ids_to_delete);
        $not_deleted_count = $total_selected - $deleted_count; // Calculate how many were skipped (likely used)

        $message = "$deleted_count code(s) deleted successfully.";
        if ($not_deleted_count > 0) {
             $message .= " $not_deleted_count selected code(s) were not deleted because they have already been used.";
        }
        // Return success even if some were skipped, but provide counts
        send_json_response(true, ['deleted_count' => $deleted_count, 'not_deleted_count' => $not_deleted_count], $message);

    } catch (Exception $e) {
        error_log("Bulk Delete Voting Codes AJAX Error: " . $e->getMessage());
        send_json_response(false, null, "Error during bulk delete: " . $e->getMessage());
    }
}

?>