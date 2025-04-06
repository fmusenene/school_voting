<?php
session_start();
require_once "../config/database.php"; // Assumes $conn is a mysqli object

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: /admin/login.php");
    exit();
}

// Validate that an ID was provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { // Added is_numeric check
    $_SESSION['error'] = "Invalid or missing election ID for deletion.";
    header("Location: elections.php");
    exit();
}

// Sanitize the ID by casting to integer - This is crucial for security here!
$election_id = (int)$_GET['id'];

// Flag to track if any query fails
$query_failed = false;
$error_message = ''; // Store specific error

// Start transaction using mysqli
$conn->begin_transaction(); // Use mysqli transaction method

try {
    // --- Direct SQL execution using mysqli::query ---

    // 1. Delete related votes
    // Construct SQL string directly with the integer ID
    $delete_votes_sql = "DELETE v FROM votes v
                         INNER JOIN candidates c ON v.candidate_id = c.id
                         INNER JOIN positions p ON c.position_id = p.id
                         WHERE p.election_id = $election_id";
    $result1 = $conn->query($delete_votes_sql);
    if ($result1 === false) {
        $query_failed = true;
        $error_message = "Error deleting votes: " . $conn->error; // Get mysqli error
    }

    // 2. Delete candidates (only proceed if previous query succeeded)
    if (!$query_failed) {
        $delete_candidates_sql = "DELETE c FROM candidates c
                                  INNER JOIN positions p ON c.position_id = p.id
                                  WHERE p.election_id = $election_id";
        $result2 = $conn->query($delete_candidates_sql);
        if ($result2 === false) {
            $query_failed = true;
            $error_message = "Error deleting candidates: " . $conn->error;
        }
    }

    // 3. Delete positions (only proceed if previous queries succeeded)
    if (!$query_failed) {
        $delete_positions_sql = "DELETE FROM positions WHERE election_id = $election_id";
        $result3 = $conn->query($delete_positions_sql);
        if ($result3 === false) {
            $query_failed = true;
            $error_message = "Error deleting positions: " . $conn->error;
        }
    }

    // 4. Finally delete the election (only proceed if previous queries succeeded)
    if (!$query_failed) {
        $delete_election_sql = "DELETE FROM elections WHERE id = $election_id";
        $result4 = $conn->query($delete_election_sql);
        if ($result4 === false) {
            $query_failed = true;
            $error_message = "Error deleting election record: " . $conn->error;
        } elseif ($conn->affected_rows === 0 && !$query_failed) {
             // Optional: Check if the election ID actually existed
             // $query_failed = true; // Uncomment if not finding the election is an error
             // $error_message = "Election with ID $election_id not found.";
        }
    }

    // Check overall success or failure
    if ($query_failed) {
        // If any query failed, roll back the transaction
        $conn->rollback(); // mysqli rollback
        $_SESSION['error'] = "Transaction failed: " . $error_message;
    } else {
        // If all queries succeeded, commit the transaction
        $conn->commit(); // mysqli commit
        $_SESSION['success'] = "Election (ID: $election_id) and all related data deleted successfully.";
    }

} catch (Exception $e) {
    // Catch any unexpected exceptions during the process
    $conn->rollback(); // Roll back on any exception
    // Log the detailed error for the admin/developer
    error_log("Unexpected error during election deletion (ID: $election_id): " . $e->getMessage());
    $_SESSION['error'] = "An unexpected error occurred during deletion. Please contact support.";

} finally {
    // Optional: It's good practice ensure autocommit is back to its default state,
    // though begin_transaction usually handles this reasonably well.
    // $conn->autocommit(true); // Uncomment if you encounter issues in subsequent operations

    // Optional: Close the connection if the script ends here
     if (isset($conn) && $conn instanceof mysqli) {
         $conn->close();
     }
}

// Redirect back to the elections list page regardless of success/failure
header("Location: elections.php");
exit();
?>