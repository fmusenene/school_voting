<?php
session_start();
require_once "../config/database.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: /school_voting/admin/login.php");
    exit();
}

if (!isset($_GET['id'])) {
    $_SESSION['error'] = "No election specified for deletion";
    header("Location: elections.php");
    exit();
}

$election_id = (int)$_GET['id'];

try {
    $conn->beginTransaction();

    // First delete related votes
    $delete_votes = "DELETE v FROM votes v 
                    INNER JOIN candidates c ON v.candidate_id = c.id 
                    INNER JOIN positions p ON c.position_id = p.id 
                    WHERE p.election_id = ?";
    $stmt = $conn->prepare($delete_votes);
    $stmt->execute([$election_id]);

    // Delete candidates
    $delete_candidates = "DELETE c FROM candidates c 
                         INNER JOIN positions p ON c.position_id = p.id 
                         WHERE p.election_id = ?";
    $stmt = $conn->prepare($delete_candidates);
    $stmt->execute([$election_id]);

    // Delete positions
    $delete_positions = "DELETE FROM positions WHERE election_id = ?";
    $stmt = $conn->prepare($delete_positions);
    $stmt->execute([$election_id]);

    // Finally delete the election
    $delete_election = "DELETE FROM elections WHERE id = ?";
    $stmt = $conn->prepare($delete_election);
    $stmt->execute([$election_id]);

    $conn->commit();
    $_SESSION['success'] = "Election and all related data deleted successfully";
} catch (PDOException $e) {
    $conn->rollBack();
    $_SESSION['error'] = "Error deleting election: " . $e->getMessage();
}

header("Location: elections.php");
exit();
?> 