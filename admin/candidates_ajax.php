<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set content type to JSON
header('Content-Type: application/json');

// Include required files
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/session.php';

// Function to send JSON response
function sendJsonResponse($success, $message, $data = null) {
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response);
    exit();
}

try {
    // Check if admin is logged in
    if (!isset($_SESSION['admin_id'])) {
        error_log("Unauthorized access attempt to candidates_ajax.php");
        sendJsonResponse(false, 'Unauthorized access');
    }

    // Check if it's an AJAX request
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        error_log("Invalid request to candidates_ajax.php - not AJAX");
        sendJsonResponse(false, 'Invalid request');
    }

    // Check if database connection is available
    if (!isset($conn)) {
        error_log("Database connection not available in candidates_ajax.php");
        sendJsonResponse(false, 'Database connection error');
    }

    // Handle POST requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'fetch_candidates':
                try {
                    // Get pagination parameters
                    $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
                    $records_per_page = isset($_POST['records_per_page']) ? (int)$_POST['records_per_page'] : 10;
                    $search = isset($_POST['search']) ? trim($_POST['search']) : '';
                    $election_id = isset($_POST['election_id']) ? (int)$_POST['election_id'] : null;

                    // Calculate offset
                    $offset = ($page - 1) * $records_per_page;

                    // Build query
                    $query = "SELECT c.*, p.title as position_title, e.title as election_title, 
                             (SELECT COUNT(*) FROM votes WHERE candidate_id = c.id) as vote_count 
                             FROM candidates c 
                             LEFT JOIN positions p ON c.position_id = p.id 
                             LEFT JOIN elections e ON p.election_id = e.id 
                             WHERE 1=1";
                    $params = [];

                    // Add search condition
                    if (!empty($search)) {
                        $query .= " AND (c.name LIKE ? OR p.title LIKE ? OR e.title LIKE ?)";
                        $search_param = "%$search%";
                        $params = array_merge($params, [$search_param, $search_param, $search_param]);
                    }

                    // Add election filter
                    if ($election_id) {
                        $query .= " AND e.id = ?";
                        $params[] = $election_id;
                    }

                    // Get total records
                    $count_query = str_replace("c.*, p.title as position_title, e.title as election_title, (SELECT COUNT(*) FROM votes WHERE candidate_id = c.id) as vote_count", "COUNT(*)", $query);
                    $stmt = $conn->prepare($count_query);
                    $stmt->execute($params);
                    $total_records = $stmt->fetchColumn();
                    $total_pages = ceil($total_records / $records_per_page);

                    // Add pagination
                    $query .= " ORDER BY c.name ASC LIMIT ? OFFSET ?";
                    $params[] = $records_per_page;
                    $params[] = $offset;

                    // Execute query
                    $stmt = $conn->prepare($query);
                    $stmt->execute($params);
                    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Calculate start and end record numbers
                    $start_record = $total_records > 0 ? $offset + 1 : 0;
                    $end_record = min($offset + $records_per_page, $total_records);

                    // Log the number of candidates being sent
                    error_log("Sending " . count($candidates) . " candidates in response");

                    sendJsonResponse(true, 'Success', [
                        'candidates' => $candidates,
                        'total_records' => $total_records,
                        'total_pages' => $total_pages,
                        'current_page' => $page,
                        'records_per_page' => $records_per_page,
                        'start_record' => $start_record,
                        'end_record' => $end_record
                    ]);

                } catch (PDOException $e) {
                    error_log("Database error in fetch_candidates: " . $e->getMessage());
                    sendJsonResponse(false, 'Database error occurred');
                }
                break;

            case 'delete_candidate':
                try {
                    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
                    
                    if ($id <= 0) {
                        sendJsonResponse(false, 'Invalid candidate ID');
                    }

                    // Check if candidate exists
                    $stmt = $conn->prepare("SELECT id FROM candidates WHERE id = ?");
                    $stmt->execute([$id]);
                    
                    if (!$stmt->fetch()) {
                        sendJsonResponse(false, 'Candidate not found');
                    }

                    // Delete candidate
                    $stmt = $conn->prepare("DELETE FROM candidates WHERE id = ?");
                    $stmt->execute([$id]);

                    sendJsonResponse(true, 'Candidate deleted successfully');
                } catch (PDOException $e) {
                    error_log("Database error in delete_candidate: " . $e->getMessage());
                    sendJsonResponse(false, 'Error deleting candidate');
                }
                break;

            default:
                sendJsonResponse(false, 'Invalid action');
        }
    } else {
        sendJsonResponse(false, 'Invalid request method');
    }
} catch (Exception $e) {
    error_log("General error in candidates_ajax.php: " . $e->getMessage());
    sendJsonResponse(false, 'An error occurred while processing your request');
} 