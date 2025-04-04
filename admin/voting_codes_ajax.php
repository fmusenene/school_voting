<?php
require_once "includes/init.php";
require_once "includes/database.php";
require_once "includes/session.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    sendJsonResponse(false, "Unauthorized access");
    exit();
}

// Check if it's an AJAX request
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    sendJsonResponse(false, "Invalid request");
    exit();
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'fetch_codes':
            fetchVotingCodes();
            break;
            
        case 'create':
            createVotingCodes();
            break;
            
        case 'delete':
            deleteVotingCode();
            break;
            
        default:
            sendJsonResponse(false, "Invalid action");
    }
} else {
    sendJsonResponse(false, "Invalid request method");
}

// Function to fetch voting codes
function fetchVotingCodes() {
    global $conn;
    
    try {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $records_per_page = isset($_POST['records_per_page']) ? (int)$_POST['records_per_page'] : 10;
        $search = isset($_POST['search']) ? trim($_POST['search']) : '';
        
        $offset = ($page - 1) * $records_per_page;
        
        // Build query
        $sql = "SELECT vc.*, e.title as election_title, 
                (SELECT COUNT(*) FROM votes WHERE voting_code_id = vc.id) as vote_count
                FROM voting_codes vc
                LEFT JOIN elections e ON vc.election_id = e.id";
        
        $params = [];
        
        if ($search) {
            $sql .= " WHERE vc.code LIKE ? OR e.title LIKE ?";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        // Get total records
        $count_sql = str_replace("vc.*, e.title as election_title, (SELECT COUNT(*) FROM votes WHERE voting_code_id = vc.id) as vote_count", "COUNT(*)", $sql);
        $count_stmt = $conn->prepare($count_sql);
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        
        // Add pagination
        $sql .= " ORDER BY vc.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $records_per_page;
        $params[] = $offset;
        
        // Execute query
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $codes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate total pages
        $total_pages = ceil($total_records / $records_per_page);
        
        sendJsonResponse(true, "Voting codes fetched successfully", [
            'codes' => $codes,
            'total_pages' => $total_pages,
            'total_records' => $total_records,
            'current_page' => $page,
            'records_per_page' => $records_per_page
        ]);
        
    } catch (PDOException $e) {
        sendJsonResponse(false, "Error fetching voting codes: " . $e->getMessage());
    }
}

// Function to create voting codes
function createVotingCodes() {
    global $conn;
    
    try {
        $election_id = isset($_POST['election_id']) ? (int)$_POST['election_id'] : 0;
        $num_codes = isset($_POST['num_codes']) ? (int)$_POST['num_codes'] : 0;
        
        if (!$election_id || !$num_codes) {
            sendJsonResponse(false, "Invalid parameters");
            return;
        }
        
        if ($num_codes < 1 || $num_codes > 1000) {
            sendJsonResponse(false, "Number of codes must be between 1 and 1000");
            return;
        }
        
        $conn->beginTransaction();
        
        $insert_sql = "INSERT INTO voting_codes (code, election_id) VALUES (?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        
        $generated_codes = [];
        $success_count = 0;
        
        for ($i = 0; $i < $num_codes; $i++) {
            try {
                $code = generateVotingCode();
                $insert_stmt->execute([$code, $election_id]);
                $generated_codes[] = $code;
                $success_count++;
            } catch (Exception $e) {
                continue;
            }
        }
        
        $conn->commit();
        
        if ($success_count > 0) {
            sendJsonResponse(true, "$success_count unique voting codes generated successfully");
        } else {
            sendJsonResponse(false, "Failed to generate any unique codes. Please try again.");
        }
        
    } catch (PDOException $e) {
        $conn->rollBack();
        sendJsonResponse(false, "Error generating voting codes: " . $e->getMessage());
    }
}

// Function to delete voting code
function deleteVotingCode() {
    global $conn;
    
    try {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        
        if (!$id) {
            sendJsonResponse(false, "Invalid voting code ID");
            return;
        }
        
        // Check if code has been used
        $check_sql = "SELECT COUNT(*) FROM votes WHERE voting_code_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->execute([$id]);
        $used_count = $check_stmt->fetchColumn();
        
        if ($used_count > 0) {
            sendJsonResponse(false, "Cannot delete used voting code");
            return;
        }
        
        // Delete the code
        $delete_sql = "DELETE FROM voting_codes WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        
        if ($delete_stmt->execute([$id])) {
            sendJsonResponse(true, "Voting code deleted successfully");
        } else {
            sendJsonResponse(false, "Error deleting voting code");
        }
        
    } catch (PDOException $e) {
        sendJsonResponse(false, "Error deleting voting code: " . $e->getMessage());
    }
}

// Function to generate unique voting code
function generateVotingCode() {
    global $conn;
    $attempts = 0;
    $max_attempts = 100; // Prevent infinite loop
    
    do {
        // Generate a random 6-digit number
        $code = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // Check if code already exists
        $check_sql = "SELECT COUNT(*) FROM voting_codes WHERE code = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->execute([$code]);
        $exists = $check_stmt->fetchColumn() > 0;
        
        $attempts++;
    } while ($exists && $attempts < $max_attempts);
    
    if ($attempts >= $max_attempts) {
        throw new Exception("Unable to generate unique code after $max_attempts attempts");
    }
    
    return $code;
}

// Function to send JSON response
function sendJsonResponse($success, $message, $data = null) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}