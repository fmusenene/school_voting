<?php
// --- Early Initialization & Config ---
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Error Reporting (Development) - Comment out/change for Production
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Production settings:
// ini_set('display_errors', 0);
// error_reporting(0);

// Set Timezone
date_default_timezone_set('Africa/Nairobi'); // EAT Timezone

// Include base dependencies AFTER potential session start
require_once "../config/database.php"; // Provides $conn (mysqli connection)
require_once "includes/session.php"; // Provides isAdminLoggedIn()

// Check if DB connection is valid early
if (!$conn || $conn->connect_error) {
    // If this is an AJAX request, send JSON error, otherwise handle differently
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
         header('Content-Type: application/json; charset=utf-8');
         http_response_code(500); // Internal Server Error
         echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
         exit;
    } else {
         // For page load, you might want to show a user-friendly error page
         die("Database connection failed. Please check configuration or contact support.");
    }
}

// --- Security: Admin Check ---
// Perform this check early, especially before handling POST/GET actions
if (!isAdminLoggedIn()) {
    // If it's an AJAX request, send 401/403 error
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
         header('Content-Type: application/json; charset=utf-8');
         http_response_code(401); // Unauthorized
         echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please log in.']);
         exit;
    } else {
        // For page load, redirect to login
        header("Location: login.php");
        exit();
    }
}

// --- Helper Function: Send JSON Response ---
function send_json_response($success, $data = null, $message = null, $data_key = 'data') {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $response = ['success' => (bool)$success];
    if ($message !== null) {
        $response['message'] = $message;
    }
    if ($success && $data !== null && $data_key !== null) {
        $response[$data_key] = $data;
    } elseif (!$success && $data !== null) {
        // $response['error_details'] = $data; // Optional debug info
    }
    // Set HTTP status code based on success/failure for better AJAX handling
    if (!$success && http_response_code() === 200) { // Only set if not already set to an error code
        http_response_code(400); // Bad Request (default error) or 500 for server issues
    }
    echo json_encode($response);
    exit(); // IMPORTANT: Always exit after sending JSON for AJAX handlers
}

// --- Helper Function: Handle File Upload ---
// Returns the database-storable path on success, empty string if no file, throws Exception on error.
function handleFileUpload($file_input_name) {
    if (!isset($_FILES[$file_input_name]) || $_FILES[$file_input_name]['error'] === UPLOAD_ERR_NO_FILE) {
        return ''; // No file uploaded or field not present
    }

    $file = $_FILES[$file_input_name];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload error: Code ' . $file['error']);
    }

    if ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
        throw new Exception('File is too large. Maximum size allowed is 5MB.');
    }

    // Use __DIR__ for reliable base path calculation
    // Assumes 'admin' folder is one level below project root where 'uploads' exists
    $project_root = dirname(__DIR__); // Directory containing 'admin' and 'uploads'
    $upload_dir_absolute = $project_root . '/uploads/candidates/';
    $upload_path_relative_for_db = 'uploads/candidates/'; // Path to store in DB

    if (!is_dir($upload_dir_absolute)) {
        if (!mkdir($upload_dir_absolute, 0775, true)) {
            throw new Exception('Failed to create upload directory. Check permissions.');
        }
    }
    if (!is_writable($upload_dir_absolute)) {
        throw new Exception('Upload directory is not writable.');
    }

    $file_info = pathinfo($file['name']);
    $file_extension = strtolower($file_info['extension'] ?? '');
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($file_extension, $allowed_extensions)) {
        throw new Exception('Invalid file type. Only JPG, JPEG, PNG, GIF, WEBP are allowed.');
    }

    // Prevent path traversal and sanitize filename (though uniqid is better)
    $base_name = preg_replace("/[^a-zA-Z0-9\s_-]/", "", $file_info['filename']); // Basic sanitize
    $new_filename = uniqid('cand_', true) . '.' . $file_extension;
    $target_path_absolute = $upload_dir_absolute . $new_filename;

    if (move_uploaded_file($file['tmp_name'], $target_path_absolute)) {
        return $upload_path_relative_for_db . $new_filename;
    } else {
        throw new Exception('Failed to move uploaded file. Check server logs.');
    }
}

// --- Helper Function: Delete Photo File ---
function deletePhotoFile($relative_photo_path) {
     if (empty($relative_photo_path)) return false;
     // Construct absolute path reliably using __DIR__
     $project_root = dirname(__DIR__);
     $absolute_path = $project_root . '/' . ltrim($relative_photo_path, '/');

     // Security check: Ensure path stays within the intended directory (simple check)
     if (strpos(realpath($absolute_path), realpath($project_root . '/uploads/candidates')) !== 0) {
         error_log("Attempt to delete file outside designated directory: " . $absolute_path);
         return false; // Path traversal attempt or invalid path structure
     }

     if (file_exists($absolute_path) && is_file($absolute_path)) {
         if (!unlink($absolute_path)) {
             error_log("Failed to delete candidate photo file: " . $absolute_path);
             return false; // Failed to delete
         }
         return true; // Successfully deleted
     }
     return false; // File doesn't exist or is not a file
}


// --- START AJAX ACTION HANDLING ---
// Check for specific 'action' parameter (use $_REQUEST to catch GET/POST)
$action = $_REQUEST['action'] ?? null;

// --- Action: Get Single Candidate (for Edit Modal) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_single') {
    $candidate_id_get = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($candidate_id_get <= 0) {
        send_json_response(false, null, 'Invalid Candidate ID requested.');
    }

    try {
        $sql = "SELECT c.*, p.title as position_title, e.title as election_title
                FROM candidates c
                LEFT JOIN positions p ON c.position_id = p.id
                LEFT JOIN elections e ON p.election_id = e.id
                WHERE c.id = $candidate_id_get"; // Embed integer ID

        $result = mysqli_query($conn, $sql);
        if ($result === false) {
            throw new Exception("Database query error: " . mysqli_error($conn));
        }

        if (mysqli_num_rows($result) > 0) {
            $candidate = mysqli_fetch_assoc($result);
            // Add a full photo URL or path relative to web root if needed by JS
            $candidate['photo_url'] = !empty($candidate['photo']) ? '../' . ltrim($candidate['photo'], '/') : 'assets/images/default-avatar.png';
            mysqli_free_result($result);
            send_json_response(true, $candidate, 'Candidate data retrieved.', 'candidate');
        } else {
            mysqli_free_result($result);
            send_json_response(false, null, 'Candidate not found.');
        }
    } catch (Exception $e) {
        error_log("Get Single Candidate Error (ID: $candidate_id_get): " . $e->getMessage());
        send_json_response(false, null, 'Error fetching candidate details: ' . $e->getMessage());
    }
    // exit() is handled by send_json_response()
}

// --- Action: Create Candidate ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && $action === 'create') {
    try {
        // Validation
        $name = trim($_POST['name'] ?? '');
        $position_id = filter_input(INPUT_POST, 'position_id', FILTER_VALIDATE_INT);
        $description = trim($_POST['description'] ?? '');

        if (empty($name) || !$position_id || $position_id <= 0) {
            throw new Exception("Name and a valid Position are required.");
        }

        // Photo Upload (returns path or empty string, throws Exception on error)
        $photo_path_db = handleFileUpload('photo');

        // Database Insertion
        $nameEsc = mysqli_real_escape_string($conn, $name);
        $descriptionEsc = mysqli_real_escape_string($conn, $description);

        if (!empty($photo_path_db)) {
            $photo_value = "'" . mysqli_real_escape_string($conn, $photo_path_db) . "'";
        } else {
            $photo_value = "NULL"; // Use SQL NULL if no photo
        }

        $sql = "INSERT INTO candidates (name, position_id, description, photo)
                VALUES ('$nameEsc', $position_id, '$descriptionEsc', $photo_value)";

        if (mysqli_query($conn, $sql)) {
            // Optionally fetch the newly created candidate data to return? For now, just success.
            $new_candidate_id = mysqli_insert_id($conn);
            send_json_response(true, ['new_id' => $new_candidate_id], 'Candidate added successfully!');
        } else {
            // If insert failed, attempt to delete uploaded photo if one exists
            if (!empty($photo_path_db)) {
                deletePhotoFile($photo_path_db);
            }
            throw new Exception("Database error while adding candidate: " . mysqli_error($conn));
        }

    } catch (Exception $e) {
        error_log("Add Candidate Error: " . $e->getMessage());
        send_json_response(false, null, $e->getMessage()); // Send specific error back
    }
    // exit() handled by send_json_response()
}

// --- Action: Edit Candidate ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && $action === 'edit') {
    $candidate_id_edit = isset($_POST['candidate_id']) ? (int)trim($_POST['candidate_id']) : 0;

    try {
        // Validation
        $name = trim($_POST['name'] ?? '');
        $position_id = filter_input(INPUT_POST, 'position_id', FILTER_VALIDATE_INT);
        $description = trim($_POST['description'] ?? '');

        if ($candidate_id_edit <= 0 || empty($name) || !$position_id || $position_id <= 0) {
            throw new Exception("Missing or invalid required fields (Candidate ID, Name, Position ID).");
        }

        // Photo Upload Handling (returns path or empty string, throws Exception on error)
        $new_photo_path_db = handleFileUpload('photo'); // 'photo' is the name attribute of the file input

        // Fetch old photo path BEFORE updating database, needed for deletion if new photo uploaded
        $old_photo_path = null;
        if (!empty($new_photo_path_db)) {
             $sql_old_photo = "SELECT photo FROM candidates WHERE id = $candidate_id_edit";
             $res_old_photo = mysqli_query($conn, $sql_old_photo);
             if ($res_old_photo && mysqli_num_rows($res_old_photo) > 0) {
                  $old_photo_row = mysqli_fetch_assoc($res_old_photo);
                  $old_photo_path = $old_photo_row['photo']; // Can be null or empty
                  mysqli_free_result($res_old_photo);
             } // Ignore error fetching old photo? Or throw? For now, proceed.
        }

        // Sanitize/Escape Data for SQL
        $name_escaped = mysqli_real_escape_string($conn, $name);
        $description_escaped = mysqli_real_escape_string($conn, $description);

        // Construct Conditional UPDATE SQL
        $sql = "UPDATE candidates SET ";
        $sql .= "name = '$name_escaped', ";
        $sql .= "description = '$description_escaped', ";
        $sql .= "position_id = $position_id ";

        // Only update photo column if a new photo was successfully uploaded
        if (!empty($new_photo_path_db)) {
            $photo_path_escaped = mysqli_real_escape_string($conn, $new_photo_path_db);
            $sql .= ", photo = '$photo_path_escaped' "; // Update with new path
        }
        // If no new photo uploaded, the 'photo' column remains unchanged.

        $sql .= " WHERE id = $candidate_id_edit";

        // Execute UPDATE Query
        $update_result = mysqli_query($conn, $sql);

        if ($update_result === false) {
            // If update failed, attempt to delete the NEWLY uploaded photo if one exists
            if (!empty($new_photo_path_db)) {
                deletePhotoFile($new_photo_path_db);
            }
            throw new Exception("Database error during update: " . mysqli_error($conn));
        }

        // Update succeeded. Delete the OLD photo if a NEW one was uploaded and the old one existed.
        if (!empty($new_photo_path_db) && !empty($old_photo_path)) {
             deletePhotoFile($old_photo_path);
        }

        // Fetch updated data to return
        $select_sql = "SELECT c.*, p.title as position_title, e.title as election_title
                       FROM candidates c
                       LEFT JOIN positions p ON c.position_id = p.id
                       LEFT JOIN elections e ON p.election_id = e.id
                       WHERE c.id = $candidate_id_edit";
        $select_result = mysqli_query($conn, $select_sql);

        if ($select_result && mysqli_num_rows($select_result) > 0) {
             $updated_candidate = mysqli_fetch_assoc($select_result);
             $updated_candidate['photo_url'] = !empty($updated_candidate['photo']) ? '../' . ltrim($updated_candidate['photo'], '/') : 'assets/images/default-avatar.png';
             mysqli_free_result($select_result);
             send_json_response(true, $updated_candidate, 'Candidate updated successfully.', 'candidate');
        } else {
             // Update worked but couldn't fetch - unlikely but possible.
             error_log("Update succeeded but failed to fetch updated candidate (ID: $candidate_id_edit): " . mysqli_error($conn));
             send_json_response(true, null, 'Candidate updated, but failed to retrieve confirmation data.'); // Still report success? Or partial error?
        }

    } catch (Exception $e) {
        error_log("Edit Candidate Error (ID: $candidate_id_edit): " . $e->getMessage());
        send_json_response(false, null, $e->getMessage()); // Send specific error back
    }
    // exit() handled by send_json_response()
}


// --- Action: Delete Single Candidate ---
// Allow GET or POST for simplicity via JS fetch, check $_REQUEST
if ($action === 'delete_single' && isset($_REQUEST['id'])) {
    $candidate_id_del = isset($_REQUEST['id']) && is_numeric($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;

    if ($candidate_id_del <= 0) {
        send_json_response(false, null, 'Invalid Candidate ID for deletion.');
    }

    try {
        // Fetch photo path BEFORE deleting from DB
        $sql_photo = "SELECT photo FROM candidates WHERE id = $candidate_id_del";
        $res_photo = mysqli_query($conn, $sql_photo);
        $photo_to_delete = null;
        if ($res_photo && mysqli_num_rows($res_photo) > 0) {
             $photo_row = mysqli_fetch_assoc($res_photo);
             $photo_to_delete = $photo_row['photo']; // May be null/empty
             mysqli_free_result($res_photo);
        } // Ignore error fetching photo? Or throw? Proceed for now.

        // Delete candidate from database (CASCADE should handle votes)
        $delete_sql = "DELETE FROM candidates WHERE id = $candidate_id_del";
        if (mysqli_query($conn, $delete_sql)) {
            if (mysqli_affected_rows($conn) > 0) {
                // Deletion successful, now delete the photo file
                deletePhotoFile($photo_to_delete);
                send_json_response(true, null, 'Candidate deleted successfully!');
            } else {
                // Query ok, but no rows deleted (candidate likely already gone)
                send_json_response(false, null, 'Candidate not found or already deleted.');
            }
        } else {
            // Database delete query failed
            throw new Exception("Database error during delete: " . mysqli_error($conn));
        }
    } catch (Exception $e) {
        error_log("Delete Single Candidate Error (ID: $candidate_id_del): " . $e->getMessage());
        send_json_response(false, null, $e->getMessage());
    }
    // exit() handled by send_json_response()
}


// --- Action: Delete Bulk Candidates ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && $action === 'delete_bulk') {
    $ids_to_delete = [];
    if (!empty($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
        // Sanitize all IDs to integers
        $ids_to_delete = array_map('intval', $_POST['selected_ids']);
        // Filter out any invalid IDs (e.g., 0 or negative)
        $ids_to_delete = array_filter($ids_to_delete, function($id) { return $id > 0; });
    }

    if (empty($ids_to_delete)) {
         send_json_response(false, null, 'No valid candidate IDs selected for deletion.');
    }

    $ids_list = implode(',', $ids_to_delete); // Safe because all elements are integers

    try {
        // Fetch photos for deletion BEFORE deleting DB records
        $sql_photos = "SELECT photo FROM candidates WHERE id IN ($ids_list)";
        $result_photos = mysqli_query($conn, $sql_photos);
        if (!$result_photos) {
            throw new Exception("Error fetching candidate photos for bulk delete: " . mysqli_error($conn));
        }
        $photos_to_delete_paths = [];
        while ($row = mysqli_fetch_assoc($result_photos)) {
            if (!empty($row['photo'])) {
                 $photos_to_delete_paths[] = $row['photo'];
            }
        }
        mysqli_free_result($result_photos);

        // Delete candidates from database (CASCADE should handle votes)
        $delete_sql = "DELETE FROM candidates WHERE id IN ($ids_list)";
        if (mysqli_query($conn, $delete_sql)) {
             $deleted_count = mysqli_affected_rows($conn);

             // Attempt to delete photo files
             $files_deleted_count = 0;
             $files_error_count = 0;
             foreach ($photos_to_delete_paths as $photo_file_path) {
                 if (deletePhotoFile($photo_file_path)) {
                      $files_deleted_count++;
                 } else {
                      // Log errors for files that failed to delete, but don't stop the success response for DB deletion
                      $files_error_count++;
                 }
             }

             $message = "$deleted_count candidate(s) deleted successfully.";
             if ($files_error_count > 0) {
                  $message .= " ($files_error_count photo file(s) could not be deleted, check logs.)";
             }
             send_json_response(true, ['deleted_db_count' => $deleted_count], $message);

        } else {
            // Database delete query failed
            throw new Exception("Database error during bulk delete: " . mysqli_error($conn));
        }
    } catch (Exception $e) {
        error_log("Bulk Delete Error: " . $e->getMessage());
        send_json_response(false, null, $e->getMessage());
    }
    // exit() handled by send_json_response()
}

// --- END AJAX ACTION HANDLING ---
// If the script reaches here, it means no AJAX action was handled (or it's not an AJAX request),
// so proceed with rendering the HTML page.


// --- Generate CSP nonce --- (Needs to be done before any output)
if (empty($_SESSION['csp_nonce'])) {
    $_SESSION['csp_nonce'] = base64_encode(random_bytes(16));
}
$nonce = htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8');

// --- Initial Data Fetch for Page Load ---
$elections = [];
$positions_for_filter = []; // Positions just for the filter dropdown
$candidates = [];
$stats = ['total_candidates' => 0, 'total_positions' => 0, 'total_votes' => 0];
$fetch_error = null;
$selected_election = 'all'; // Default
$selected_position = 'all'; // Default

try {
    // Get selected filters (Sanitize GET input)
    $selected_election_input = filter_input(INPUT_GET, 'election_id', FILTER_SANITIZE_SPECIAL_CHARS);
    $selected_position_input = filter_input(INPUT_GET, 'position_id', FILTER_SANITIZE_SPECIAL_CHARS);

    $selected_election = ($selected_election_input !== null && $selected_election_input !== 'all')
        ? (int)$selected_election_input // Cast to int if not 'all'
        : 'all';
    $selected_position = ($selected_position_input !== null && $selected_position_input !== 'all')
        ? (int)$selected_position_input // Cast to int if not 'all'
        : 'all';

    // Check if integer IDs are valid
    if ($selected_election !== 'all' && $selected_election <= 0) $selected_election = 'all';
    if ($selected_position !== 'all' && $selected_position <= 0) $selected_position = 'all';


    // Get all elections for filter
    $query_elections = "SELECT id, title FROM elections ORDER BY start_date DESC, title ASC";
    $result_elections = mysqli_query($conn, $query_elections);
    if ($result_elections) {
        while ($row = mysqli_fetch_assoc($result_elections)) {
            $elections[] = $row;
        }
        mysqli_free_result($result_elections);
    } else {
        throw new Exception("Elections filter query error: " . mysqli_error($conn));
    }

    // Get positions for filter dropdown (Always fetch all for JS filtering, simpler)
    // Alternatively, pre-filter by PHP if preferred, but JS filtering gives faster UI response
    $positions_filter_sql = "SELECT p.id, p.title, p.election_id, e.title as election_title
                             FROM positions p
                             LEFT JOIN elections e ON p.election_id = e.id
                             ORDER BY e.start_date DESC, p.title ASC";
    $result_positions_filter = mysqli_query($conn, $positions_filter_sql);
    if ($result_positions_filter) {
        while ($row = mysqli_fetch_assoc($result_positions_filter)) {
            $positions_for_filter[] = $row;
        }
        mysqli_free_result($result_positions_filter);
    } else {
        throw new Exception("Positions filter query error: " . mysqli_error($conn));
    }

    // Fetch Main Candidate List based on filters
    $sql_candidates = "SELECT c.id, c.name, c.description, c.photo, c.position_id,
                           p.title as position_title, p.election_id, e.title as election_title
                       FROM candidates c
                       LEFT JOIN positions p ON c.position_id = p.id
                       LEFT JOIN elections e ON p.election_id = e.id
                       WHERE 1=1"; // Start WHERE clause

    // Apply filters using integer values directly (already sanitized/cast)
    if ($selected_election !== 'all') {
        $sql_candidates .= " AND p.election_id = $selected_election";
    }
    if ($selected_position !== 'all') {
        $sql_candidates .= " AND c.position_id = $selected_position";
    }
    $sql_candidates .= " ORDER BY e.start_date DESC, p.title ASC, c.name ASC";

    $result_candidates = mysqli_query($conn, $sql_candidates);
    if ($result_candidates) {
        while ($row = mysqli_fetch_assoc($result_candidates)) {
            // Prepare photo path for HTML display
            $photo_display_path = 'assets/images/default-avatar.png'; // Default
             if (!empty($row['photo'])) {
                 // Construct path relative to the web root from the script's perspective
                 // Assumes 'admin' is one level down from root
                 $file_check_path = dirname(__DIR__) . '/' . ltrim($row['photo'], '/');
                 if (file_exists($file_check_path)) {
                     // Use path relative to web root for src attribute
                     $photo_display_path = '../' . ltrim($row['photo'], '/');
                 }
            }
            $row['photo_display_path'] = htmlspecialchars($photo_display_path, ENT_QUOTES, 'UTF-8');
            $candidates[] = $row;
        }
        mysqli_free_result($result_candidates);
    } else {
        throw new Exception("Candidates list query error: " . mysqli_error($conn));
    }

    // Fetch Overall Stats (Ensure queries are robust)
    $res_stat_cand = mysqli_query($conn, "SELECT COUNT(*) as total FROM candidates");
    $stats['total_candidates'] = ($res_stat_cand) ? (int)mysqli_fetch_assoc($res_stat_cand)['total'] : 0;
    if($res_stat_cand) mysqli_free_result($res_stat_cand);

    $res_stat_pos = mysqli_query($conn, "SELECT COUNT(*) as total FROM positions");
    $stats['total_positions'] = ($res_stat_pos) ? (int)mysqli_fetch_assoc($res_stat_pos)['total'] : 0;
    if($res_stat_pos) mysqli_free_result($res_stat_pos);

    $res_stat_votes = mysqli_query($conn, "SELECT COUNT(*) as total FROM votes");
    $stats['total_votes'] = ($res_stat_votes) ? (int)mysqli_fetch_assoc($res_stat_votes)['total'] : 0;
    if($res_stat_votes) mysqli_free_result($res_stat_votes);

} catch (Exception $e) {
    error_log("Candidates Page Load Error: " . $e->getMessage());
    $fetch_error = "Could not load page data due to a database issue. Please try again later.";
    // Reset data arrays on error to prevent partial display
    $elections = [];
    $positions_for_filter = [];
    $candidates = [];
    $stats = ['total_candidates' => 'N/A', 'total_positions' => 'N/A', 'total_votes' => 'N/A']; // Show error indicator
}


// --- Include HTML Header ---
// This should be done AFTER all data fetching and potential AJAX exits
require_once "includes/header.php"; // Assumes this outputs <!DOCTYPE html> etc.
?>

<style nonce="<?php echo $nonce; ?>">
    /* --- Styles (largely unchanged, ensure nonce is applied) --- */
    :root {
        --primary-hue: 226; --primary-color: hsl(var(--primary-hue), 76%, 58%); --primary-light: hsl(var(--primary-hue), 76%, 95%); --primary-dark: hsl(var(--primary-hue), 70%, 48%);
        --secondary-color: #858796; --success-color: #1cc88a; --info-color: #36b9cc; --warning-color: #f6c23e; --danger-color: #e74a3b;
        --light-color: #f8f9fc; --white-color: #fff; --dark-color: #5a5c69; --gray-100: #f8f9fc; --gray-200: #eaecf4; --gray-300: #dddfeb; --gray-400: #d1d3e2; --gray-500: #b7b9cc; --gray-600: #858796; --border-color: #e3e6f0;
        --font-family-primary: "Poppins", sans-serif; --font-family-secondary: "Nunito", sans-serif;
        --shadow-sm: 0 .125rem .25rem rgba(0,0,0,.075); --shadow: 0 .15rem 1.75rem 0 rgba(58,59,69,.15); --shadow-lg: 0 1rem 3rem rgba(0,0,0,.175); --border-radius: .35rem; --border-radius-lg: .5rem;
    }
    body { font-family: var(--font-family-secondary); background-color: var(--light-color); font-size: 0.95rem; color: var(--dark-color); }
    .container-fluid { padding: 1.5rem 2rem; max-width: 1600px;} /* Limit max width */

    /* Page Header */
    .page-header { margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border-color); }
    .page-header h1 { color: var(--primary-dark); font-family: var(--font-family-primary); font-weight: 700; font-size: 1.75rem; }
    .page-header .btn { box-shadow: var(--shadow-sm); font-weight: 600; font-size: 0.875rem; }

    /* Stats Cards */
    .stats-card { transition: all 0.25s ease-out; border: none; border-left: 4px solid var(--primary-color); border-radius: var(--border-radius); background-color: var(--white-color); box-shadow: var(--shadow-sm); height: 100%; display: flex; flex-direction: column; }
    .stats-card .card-body { padding: 1.1rem 1.25rem; position: relative; flex-grow: 1; display: flex; flex-direction: column; justify-content: center;}
    .stats-card:hover { transform: translateY(-3px); box-shadow: var(--shadow); }
    .stats-card-icon { font-size: 2.2rem; opacity: 0.1; position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); transition: all .3s ease; color: var(--gray-400); }
    .stats-card:hover .stats-card-icon { opacity: 0.15; transform: translateY(-50%) scale(1.1); }
    .stats-card .stat-label { font-size: .7rem; font-weight: 700; text-transform: uppercase; margin-bottom: .1rem; color: var(--secondary-color); letter-spacing: .5px;}
    .stats-card .stat-value { font-size: 1.9rem; font-weight: 700; line-height: 1.1; color: var(--dark-color); }
    .stats-card .stat-detail { font-size: 0.8rem; color: var(--secondary-color); margin-top: 0.25rem;}
    /* Colors */
    .stats-card.border-left-primary { border-left-color: var(--primary-color); } .stats-card.border-left-primary .stat-label { color: var(--primary-color); }
    .stats-card.border-left-success { border-left-color: var(--success-color); } .stats-card.border-left-success .stat-label { color: var(--success-color); }
    .stats-card.border-left-warning { border-left-color: var(--warning-color); } .stats-card.border-left-warning .stat-label { color: var(--warning-color); }

    /* Filter Bar */
    .filter-bar { background-color: var(--white-color); padding: .75rem 1.25rem; border-radius: var(--border-radius); box-shadow: var(--shadow-sm); border: 1px solid var(--border-color); }
    .filter-bar label { font-weight: 600; font-size: 0.9rem; color: var(--primary-dark); margin-bottom: 0; }
    .filter-bar .form-select { max-width: 320px; font-size: 0.9rem; }

    /* Candidates Table Card */
    .candidates-card { border: none; border-radius: var(--border-radius-lg); box-shadow: var(--shadow); overflow: hidden; /* Contain table borders */ }
    .candidates-card .card-header { background-color: var(--white-color); border-bottom: 1px solid var(--border-color); font-weight: 700; color: var(--primary-dark); padding: 1rem 1.25rem; }
    .candidates-table { margin-bottom: 0; }
    .candidates-table thead th { background-color: var(--gray-100); border-bottom: 2px solid var(--border-color); border-top: none; font-size: .8rem; font-weight: 700; text-transform: uppercase; color: var(--gray-600); padding: .75rem 1rem; white-space: nowrap; letter-spacing: .5px; vertical-align: middle;}
    .candidates-table tbody td { padding: .7rem 1rem; vertical-align: middle; border-top: 1px solid var(--border-color); font-size: 0.9rem; }
    .candidates-table tbody tr:first-child td { border-top: none; }
    .candidates-table tbody tr:hover { background-color: var(--primary-light); }
    .candidates-table .candidate-name { font-weight: 600; color: var(--dark-color); font-size: 0.95rem;}
    .candidates-table .position-details { font-size: 0.85rem; color: var(--secondary-color); display: block; max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;}
    .candidates-table .candidate-photo { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 2px solid var(--gray-200); box-shadow: var(--shadow-sm);}
    .candidates-table .action-buttons .btn { padding: 0.25rem 0.5rem; font-size: 0.8rem; margin-left: 0.3rem; box-shadow: var(--shadow-sm); border-radius: var(--border-radius); transition: transform 0.1s ease-out; }
    .candidates-table .action-buttons .btn:hover { transform: scale(1.1); }
    .candidates-table .form-check-input { cursor: pointer; width: 1.1em; height: 1.1em;}
    .candidates-table .no-candidates td { text-align: center; padding: 3rem 1rem; }
    .candidates-table .no-candidates i { font-size: 3rem; margin-bottom: 1rem; display: block; color: var(--gray-400); }
    .candidates-table .no-candidates p { color: var(--gray-500); font-size: 1.1rem;}
    .candidates-table .link-secondary { color: var(--secondary-color); }
    .candidates-table .link-secondary:hover { color: var(--primary-dark); }


    /* Table Header Actions (Bulk Actions) */
    .table-actions { padding: .75rem 1.25rem; background-color: var(--gray-100); border-top: 1px solid var(--border-color); border-bottom-left-radius: var(--border-radius-lg); border-bottom-right-radius: var(--border-radius-lg); }
    .table-actions .btn { font-size: .8rem; }

    /* Modals */
    .modal-header { background-color: var(--primary-light); border-bottom: 1px solid var(--border-color); }
    .modal-title { font-family: var(--font-family-primary); color: var(--primary-dark); font-weight: 600; }
    .modal-footer { background-color: var(--gray-100); border-top: 1px solid var(--border-color); }
    .was-validated .form-control:invalid, .form-control.is-invalid { border-color: var(--danger-color) !important; background-image: none !important;} /* Override BS styles */
    .was-validated .form-control:invalid:focus, .form-control.is-invalid:focus { box-shadow: 0 0 0 .25rem rgba(231, 74, 59, .25); border-color: var(--danger-color) !important; }
    .was-validated .form-select:invalid, .form-select.is-invalid { border-color: var(--danger-color) !important; background-image: none !important;}
    .was-validated .form-select:invalid:focus, .form-select.is-invalid:focus { box-shadow: 0 0 0 .25rem rgba(231, 74, 59, .25); border-color: var(--danger-color) !important; }
    .invalid-feedback { font-weight: 500; }
    #deleteSingleConfirmModal .modal-header, #deleteBulkConfirmModal .modal-header { background-color: var(--danger-color); color: white; }
    #deleteSingleConfirmModal .btn-close, #deleteBulkConfirmModal .btn-close { filter: brightness(0) invert(1);}
    #add_photoPreview, #edit_photoPreview { max-width: 150px; max-height: 150px; margin-top: .5rem; border-radius: var(--border-radius); border: 1px solid var(--border-color); }

    /* Notification Toast */
    .toast-container { z-index: 1090; }
    .notification-toast { min-width: 300px; border-radius: var(--border-radius); box-shadow: var(--shadow-lg); padding: 0; border: none; background-clip: padding-box; }
    .notification-toast .notification-content { display: flex; align-items: center; padding: .75rem 1rem;}
    .notification-toast .toast-body { padding: 0; color: white; font-weight: 500; flex-grow: 1;}
    .notification-toast .notification-icon { font-size: 1.3rem; margin-right: .75rem; line-height: 1;}
    .notification-toast .btn-close { background: transparent; border: 0; padding: 0; opacity: 0.8; margin-left: auto; font-size: 1.2rem; line-height: 1; }
    .notification-toast .btn-close:hover { opacity: 1;}
    .notification-toast .btn-close-white { color: white; text-shadow: none; }

    /* Button loading state */
    .btn .spinner-border { vertical-align: text-bottom; }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center page-header">
        <h1 class="h2 mb-0 text-gray-800">Manage Candidates</h1>
        <button type="button" class="btn btn-primary btn-sm shadow-sm" data-bs-toggle="modal" data-bs-target="#newCandidateModal">
            <i class="bi bi-person-plus-fill me-1"></i> Add New Candidate
        </button>
    </div>

    <?php if ($fetch_error): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($fetch_error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-lg-4 col-md-6">
            <div class="stats-card border-left-primary h-100">
                <div class="card-body">
                    <div>
                        <div class="stat-label text-primary">Total Candidates</div>
                        <div class="stat-value" id="stat-total-candidates"><?php echo htmlspecialchars($stats['total_candidates'] ?? 0); ?></div>
                    </div>
                    <div class="stat-detail">Across all elections</div>
                    <i class="bi bi-people-fill stats-card-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="stats-card border-left-success h-100">
                <div class="card-body">
                    <div>
                        <div class="stat-label text-success">Total Positions</div>
                        <div class="stat-value" id="stat-total-positions"><?php echo htmlspecialchars($stats['total_positions'] ?? 0); ?></div>
                    </div>
                    <div class="stat-detail">Across all elections</div>
                     <i class="bi bi-tag-fill stats-card-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
             <div class="stats-card border-left-warning h-100">
                <div class="card-body">
                    <div>
                        <div class="stat-label text-warning">Total Votes Recorded</div>
                        <div class="stat-value" id="stat-total-votes"><?php echo htmlspecialchars($stats['total_votes'] ?? 0); ?></div>
                    </div>
                    <div class="stat-detail">Across all elections</div>
                    <i class="bi bi-check2-square stats-card-icon"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="filter-bar mb-4">
        <div class="row g-3 align-items-center">
            <div class="col-md-auto">
                <label for="electionFilter" class="col-form-label">Filter:</label>
            </div>
            <div class="col-md-4">
                <select class="form-select form-select-sm" id="electionFilter" aria-label="Filter by Election">
                    <option value="all" <?php echo $selected_election === 'all' ? 'selected' : ''; ?>>All Elections</option>
                    <?php foreach ($elections as $election): ?>
                        <option value="<?php echo $election['id']; ?>" <?php echo $selected_election == $election['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($election['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <select class="form-select form-select-sm" id="positionFilter" aria-label="Filter by Position" <?php echo empty($positions_for_filter) ? 'disabled' : ''; ?>>
                    <option value="all" <?php echo $selected_position === 'all' ? 'selected' : ''; ?>>All Positions</option>
                    <?php foreach ($positions_for_filter as $position): ?>
                        <option value="<?php echo $position['id']; ?>"
                                data-election-id="<?php echo $position['election_id']; ?>"
                                <?php echo $selected_position == $position['id'] ? 'selected' : ''; ?>
                                <?php // JS will handle hiding/showing based on election filter
                                     // PHP initial state based on load
                                     if ($selected_election !== 'all' && $selected_election && $position['election_id'] != $selected_election) { echo ' style="display:none;"'; } ?>
                                >
                            <?php echo htmlspecialchars($position['title']); ?> (<?php echo htmlspecialchars($position['election_title'] ?? 'N/A'); ?>)
                        </option>
                    <?php endforeach; ?>
                     <?php if(empty($positions_for_filter)): ?>
                         <option value="" disabled>No positions available</option>
                     <?php endif; ?>
                </select>
            </div>
            </div>
    </div>

    <div class="card candidates-card border-0 shadow mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 text-primary font-weight-bold"><i class="bi bi-person-lines-fill me-2"></i>Candidates List</h6>
        </div>
        <div class="card-body p-0">
             <form id="candidatesTableForm"> <div class="table-responsive">
                    <table class="table table-hover align-middle candidates-table mb-0">
                        <thead>
                            <tr>
                                <th scope="col" class="text-center" style="width: 1%;">
                                    <input class="form-check-input" type="checkbox" id="selectAllCheckbox" title="Select/Deselect All" aria-label="Select all candidates">
                                </th>
                                <th scope="col" style="width: 1%;">Photo</th>
                                <th scope="col">Name</th>
                                <th scope="col">Position</th>
                                <th scope="col">Election</th>
                                <th scope="col" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($candidates)): ?>
                                <tr class="no-candidates">
                                    <td colspan="6">
                                        <i class="bi bi-person-x"></i>
                                        <p class="mb-0 text-muted">
                                            <?php echo ($selected_election !== 'all' || $selected_position !== 'all') ? 'No candidates match the current filter.' : 'No candidates added yet.'; ?>
                                        </p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($candidates as $candidate): ?>
                                    <tr id="candidate-row-<?php echo $candidate['id']; ?>">
                                        <td class="text-center">
                                            <input class="form-check-input candidate-checkbox" type="checkbox" name="selected_ids[]" value="<?php echo $candidate['id']; ?>" aria-label="Select candidate <?php echo htmlspecialchars($candidate['name']); ?>">
                                        </td>
                                        <td>
                                            <img src="<?php echo $candidate['photo_display_path']; // Already escaped ?>"
                                                 class="candidate-photo"
                                                 alt="<?php echo htmlspecialchars($candidate['name']); ?> photo"
                                                 onerror="this.src='assets/images/default-avatar.png'; this.onerror=null;">
                                        </td>
                                        <td>
                                            <span class="candidate-name"><?php echo htmlspecialchars($candidate['name']); ?></span>
                                            <?php if(!empty($candidate['description'])): ?>
                                            <small class="position-details d-block text-muted" title="<?php echo htmlspecialchars($candidate['description']); ?>">
                                                <?php echo htmlspecialchars($candidate['description']); ?>
                                            </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                             <?php if($candidate['position_id']): ?>
                                            <a href="?position_id=<?php echo $candidate['position_id']; ?>&election_id=<?php echo $candidate['election_id']; ?>" class="link-secondary text-decoration-none" title="Filter by this position">
                                                <?php echo htmlspecialchars($candidate['position_title'] ?? '[N/A]'); ?>
                                            </a>
                                             <?php else: ?>
                                                  <span class="text-muted">[N/A]</span>
                                             <?php endif; ?>
                                        </td>
                                        <td>
                                             <?php if($candidate['election_id']): ?>
                                            <a href="?election_id=<?php echo $candidate['election_id']; ?>" class="link-secondary text-decoration-none" title="Filter by this election">
                                                <?php echo htmlspecialchars($candidate['election_title'] ?? '[N/A]'); ?>
                                            </a>
                                             <?php else: ?>
                                                  <span class="text-muted">[N/A]</span>
                                             <?php endif; ?>
                                        </td>
                                        <td class="text-center text-nowrap action-buttons">
                                            <button type="button" class="btn btn-outline-primary btn-sm edit-candidate-btn"
                                                    data-candidate-id="<?php echo $candidate['id']; ?>"
                                                    data-bs-toggle="tooltip" data-bs-placement="top" title="Edit Candidate">
                                                <i class="bi bi-pencil-fill"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-danger btn-sm delete-candidate-btn"
                                                    data-candidate-id="<?php echo $candidate['id']; ?>"
                                                    data-candidate-name="<?php echo htmlspecialchars($candidate['name']); ?>"
                                                    data-bs-toggle="tooltip" data-bs-placement="top" title="Delete Candidate">
                                                <i class="bi bi-trash3-fill"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                 <?php if (!empty($candidates)): ?>
                 <div class="table-actions px-3 py-2 border-top d-flex align-items-center bg-light">
                      <small class="text-muted me-3"><span id="selectedCount">0</span> selected</small>
                      <button type="button" class="btn btn-sm btn-danger" id="deleteSelectedBtnBulk" disabled>
                           <i class="bi bi-trash-fill me-1"></i> Delete Selected (<span id="selectedCountBtn">0</span>)
                      </button>
                 </div>
                 <?php endif; ?>
            </form>
        </div>
    </div>
</div> <div class="modal fade" id="newCandidateModal" tabindex="-1" aria-labelledby="newCandidateModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="newCandidateModalLabel"><i class="bi bi-person-plus-fill me-2"></i>Add New Candidate</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addCandidateForm" method="POST" action="candidates.php" enctype="multipart/form-data" novalidate>
                <div class="modal-body p-4">
                    <div id="addModalAlertPlaceholder"></div>
                    <input type="hidden" name="action" value="create">
                    <div class="row g-3">
                         <div class="col-md-8">
                            <div class="mb-3">
                                 <label for="add_name" class="form-label">Candidate Name*</label>
                                 <input type="text" class="form-control" id="add_name" name="name" required maxlength="100">
                                 <div class="invalid-feedback">Please enter the candidate's name.</div>
                            </div>
                            <div class="mb-3">
                                 <label for="add_position_id" class="form-label">Position*</label>
                                 <select class="form-select" id="add_position_id" name="position_id" required <?php echo empty($positions_for_filter) ? 'disabled' : ''; ?>>
                                     <option value="" selected disabled>-- Select Position --</option>
                                     <?php foreach ($positions_for_filter as $position): ?>
                                         <option value="<?php echo $position['id']; ?>"
                                                 <?php // Pre-select if filtered
                                                      echo ($selected_position !== 'all' && $selected_position == $position['id']) ? 'selected' : '';
                                                 ?>
                                                 >
                                             <?php echo htmlspecialchars($position['title'] . ' (' . ($position['election_title'] ?? 'N/A') . ')'); ?>
                                         </option>
                                     <?php endforeach; ?>
                                      <?php if(empty($positions_for_filter)): ?>
                                           <option value="" disabled>No positions found. Add positions first.</option>
                                      <?php endif; ?>
                                 </select>
                                 <div class="invalid-feedback">Please select a position.</div>
                            </div>
                            <div class="mb-3">
                                 <label for="add_description" class="form-label">Short Description/Slogan (Optional)</label>
                                 <textarea class="form-control" id="add_description" name="description" rows="2" maxlength="500"></textarea>
                            </div>
                         </div>
                         <div class="col-md-4 text-center">
                             <label for="add_photo" class="form-label d-block mb-1">Photo</label>
                             <img id="add_photoPreview" src="assets/images/default-avatar.png" alt="Photo Preview" class="img-thumbnail mb-2" style="height: 150px; width: 150px; object-fit: cover;">
                             <input type="file" class="form-control form-control-sm" id="add_photo" name="photo" accept="image/jpeg, image/png, image/gif, image/webp">
                             <small class="form-text text-muted d-block mt-1">Max 5MB. JPG, PNG, GIF, WEBP.</small>
                         </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="addCandidateSubmitBtn" <?php echo empty($positions_for_filter) ? 'disabled' : ''; ?>>
                        <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
                        <i class="bi bi-plus-lg me-1"></i>Add Candidate
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editCandidateModal" tabindex="-1" aria-labelledby="editCandidateModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editCandidateModalLabel"><i class="bi bi-pencil-square me-2"></i>Edit Candidate</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
             <form id="editCandidateForm" method="POST" action="candidates.php" enctype="multipart/form-data" novalidate>
                 <input type="hidden" id="edit_candidate_id" name="candidate_id">
                 <input type="hidden" name="action" value="edit">
                <div class="modal-body p-4">
                     <div id="editModalAlertPlaceholder"></div>
                     <div class="text-center p-4" id="editModalLoading" style="display: none;">
                          <div class="spinner-border text-primary" role="status">
                               <span class="visually-hidden">Loading...</span>
                          </div>
                          <p class="mt-2 mb-0">Loading candidate data...</p>
                     </div>
                     <div id="editModalFormContent">
                        <div class="row g-3">
                             <div class="col-md-8">
                                <div class="mb-3">
                                     <label for="edit_name" class="form-label">Candidate Name*</label>
                                     <input type="text" class="form-control" id="edit_name" name="name" required maxlength="100">
                                     <div class="invalid-feedback">Name is required.</div>
                                </div>
                                <div class="mb-3">
                                     <label for="edit_position_id" class="form-label">Position*</label>
                                     <select class="form-select" id="edit_position_id" name="position_id" required <?php echo empty($positions_for_filter) ? 'disabled' : ''; ?>>
                                         <option value="">-- Select Position --</option>
                                         <?php foreach ($positions_for_filter as $position): ?>
                                             <option value="<?php echo $position['id']; ?>">
                                                 <?php echo htmlspecialchars($position['title'] . ' (' . ($position['election_title'] ?? 'N/A') . ')'); ?>
                                             </option>
                                         <?php endforeach; ?>
                                          <?php if(empty($positions_for_filter)): ?>
                                               <option value="" disabled>No positions available</option>
                                          <?php endif; ?>
                                     </select>
                                     <div class="invalid-feedback">Please select a position.</div>
                                </div>
                                <div class="mb-3">
                                     <label for="edit_description" class="form-label">Description (Optional)</label>
                                     <textarea class="form-control" id="edit_description" name="description" rows="3" maxlength="500"></textarea>
                                </div>
                             </div>
                             <div class="col-md-4 text-center">
                                  <label class="form-label d-block mb-1">Current Photo</label>
                                  <img id="edit_photoPreview" src="assets/images/default-avatar.png" alt="Current Photo" class="img-thumbnail mb-2" style="height: 150px; width: 150px; object-fit: cover;">
                                  <label for="edit_photo" class="form-label">Change Photo (Optional)</label>
                                  <input type="file" class="form-control form-control-sm" id="edit_photo" name="photo" accept="image/jpeg, image/png, image/gif, image/webp">
                                  <small class="form-text text-muted d-block mt-1">Leave empty to keep current photo. Max 5MB.</small>
                             </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="editCandidateSubmitBtn" <?php echo empty($positions_for_filter) ? 'disabled' : ''; ?>>
                        <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
                        <i class="bi bi-save-fill me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<div class="modal fade" id="deleteSingleConfirmModal" tabindex="-1" aria-labelledby="deleteSingleConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white">
                 <h5 class="modal-title" id="deleteSingleConfirmModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Deletion</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center py-4">
                <p class="lead mb-2">Delete candidate "<strong id="candidateToDeleteName"></strong>"?</p>
                <p class="text-danger"><small>This will also remove any votes cast for this candidate.<br>This action cannot be undone.</small></p>
                <input type="hidden" id="deleteCandidateIdSingle">
            </div>
             <div class="modal-footer justify-content-center border-0">
                 <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                 <button type="button" class="btn btn-danger px-4" id="confirmDeleteSingleBtn">
                      <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
                      <i class="bi bi-trash3-fill me-1"></i>Delete
                 </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteBulkConfirmModal" tabindex="-1" aria-labelledby="deleteBulkConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white">
                 <h5 class="modal-title" id="deleteBulkConfirmModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Bulk Deletion</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center py-4">
                <p class="lead mb-2">Are you sure you want to delete the <strong id="bulkDeleteCount">0</strong> selected candidate(s)?</p>
                <p class="text-danger"><small>This will also remove any votes cast for these candidates.<br>This action cannot be undone.</small></p>
            </div>
             <div class="modal-footer justify-content-center border-0">
                 <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                 <button type="button" class="btn btn-danger px-4" id="confirmDeleteBulkBtn">
                      <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
                      <i class="bi bi-trash3-fill me-1"></i>Delete Selected
                 </button>
            </div>
        </div>
    </div>
</div>


<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1090">
    <div id="notificationToast" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4000"> 
        <div class="d-flex notification-content">
            <div class="toast-body d-flex align-items-center">
                <span class="notification-icon me-2 fs-4"></span>
                <span id="notificationMessage" class="fw-medium"></span>
            </div>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>


<?php
// Include Footer (also ensures connection might be closed if footer does it)
require_once "includes/footer.php";

// Close connection if it wasn't closed by the footer and is still open
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    mysqli_close($conn);
}
?>

<script nonce="<?php echo $nonce; ?>" src="https://unpkg.com/countup.js@2.8.0/dist/countUp.umd.js"></script>

<script nonce="<?php echo $nonce; ?>">
document.addEventListener('DOMContentLoaded', function () {
    // --- Initialize Bootstrap Components ---
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (el) { return new bootstrap.Tooltip(el) });

    // Get Modal instances safely
    const getModalInstance = (id) => { const el = document.getElementById(id); return el ? bootstrap.Modal.getOrCreateInstance(el) : null; };
    const newCandidateModal = getModalInstance('newCandidateModal');
    const editCandidateModal = getModalInstance('editCandidateModal');
    const deleteSingleModal = getModalInstance('deleteSingleConfirmModal');
    const deleteBulkModal = getModalInstance('deleteBulkConfirmModal');

    // Get Toast instance safely
    const notificationToastEl = document.getElementById('notificationToast');
    const notificationToast = notificationToastEl ? bootstrap.Toast.getOrCreateInstance(notificationToastEl) : null;

    // --- DOM References ---
    const addForm = document.getElementById('addCandidateForm');
    const editForm = document.getElementById('editCandidateForm');
    const tableForm = document.getElementById('candidatesTableForm'); // Form wrapping the table
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    // Need to re-query inside functions if table content changes dynamically
    const getCandidateCheckboxes = () => document.querySelectorAll('.candidate-checkbox');
    const deleteSelectedBtnBulk = document.getElementById('deleteSelectedBtnBulk');
    const selectedCountSpan = document.getElementById('selectedCount');
    const selectedCountBtnSpan = document.getElementById('selectedCountBtn'); // Span inside bulk delete button
    const electionFilter = document.getElementById('electionFilter');
    const positionFilter = document.getElementById('positionFilter');
    const addModalAlertPlaceholder = document.getElementById('addModalAlertPlaceholder');
    const editModalAlertPlaceholder = document.getElementById('editModalAlertPlaceholder');
    const addPhotoInput = document.getElementById('add_photo');
    const addPhotoPreview = document.getElementById('add_photoPreview');
    const editPhotoInput = document.getElementById('edit_photo');
    const editPhotoPreview = document.getElementById('edit_photoPreview');
    const editModalLoading = document.getElementById('editModalLoading');
    const editModalFormContent = document.getElementById('editModalFormContent');

    // --- CountUp Animations ---
    if (typeof CountUp === 'function') {
        const countUpOptions = { duration: 1.5, enableScrollSpy: true, scrollSpyDelay: 50, scrollSpyOnce: true };
        try {
            // Use PHP-rendered values directly for initial load
            const initialStats = <?php echo json_encode($stats); ?>;
            const elCandTotal = document.getElementById('stat-total-candidates');
            const elPosTotal = document.getElementById('stat-total-positions');
            const elVotesTotal = document.getElementById('stat-total-votes');

            if(elCandTotal && initialStats.total_candidates !== 'N/A') new CountUp(elCandTotal, initialStats.total_candidates || 0, countUpOptions).start();
            if(elPosTotal && initialStats.total_positions !== 'N/A') new CountUp(elPosTotal, initialStats.total_positions || 0, countUpOptions).start();
            if(elVotesTotal && initialStats.total_votes !== 'N/A') new CountUp(elVotesTotal, initialStats.total_votes || 0, countUpOptions).start();
        } catch (e) { console.error("CountUp init error:", e); }
    } else { console.warn("CountUp library not available."); }


    // --- Helper: Show Notification Toast ---
    function showNotification(message, type = 'success') {
        const msgEl = document.getElementById('notificationMessage');
        const iconEl = notificationToastEl?.querySelector('.notification-icon');
        if (!notificationToast || !msgEl || !iconEl || !notificationToastEl) return;

        // Reset classes
        notificationToastEl.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-info', 'text-dark');
        notificationToastEl.querySelector('.toast-body')?.classList.remove('text-dark');
        notificationToastEl.querySelector('.btn-close')?.classList.remove('text-dark');

        iconEl.innerHTML = '';
        message = String(message || 'Action completed.').substring(0, 200); // Limit message length
        msgEl.textContent = message;

        let iconClass = 'bi-check-circle-fill';
        let bgClass = 'bg-success';

        if (type === 'danger') { iconClass = 'bi-x-octagon-fill'; bgClass = 'bg-danger'; }
        else if (type === 'warning') { iconClass = 'bi-exclamation-triangle-fill'; bgClass = 'bg-warning'; notificationToastEl.querySelector('.toast-body')?.classList.add('text-dark'); notificationToastEl.querySelector('.btn-close')?.classList.add('text-dark'); } // Dark text on warning
        else if (type === 'info') { iconClass = 'bi-info-circle-fill'; bgClass = 'bg-info'; }

        notificationToastEl.classList.add(bgClass);
        iconEl.innerHTML = `<i class="bi ${iconClass}"></i>`;
        notificationToast.show();
    }

    // --- Helper: Show Modal Alert ---
     function showModalAlert(placeholder, message, type = 'danger') {
          if(placeholder) {
               const alertClass = `alert-${type}`;
               const iconClass = type === 'danger' ? 'bi-exclamation-triangle-fill' : (type === 'warning' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill');
               placeholder.innerHTML = `<div class="alert ${alertClass} alert-dismissible fade show d-flex align-items-center" role="alert" style="font-size: 0.9rem; padding: 0.75rem 1rem;">
                    <i class="bi ${iconClass} me-2 fs-5"></i>
                    <div>${message}</div>
                    <button type="button" class="btn-close" style="padding: 0.75rem;" data-bs-dismiss="alert" aria-label="Close"></button>
               </div>`;
          }
     }

    // --- Helper: Button State Management ---
    function updateButtonState(button, isLoading, originalHTML = null, loadingText = 'Processing...') {
        if (!button) return;
        if (isLoading) {
            button.dataset.originalHtml = button.innerHTML; // Store original HTML
            button.disabled = true;
            const spinner = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;
            const textNode = Array.from(button.childNodes).find(node => node.nodeType === Node.TEXT_NODE && node.textContent.trim().length > 0);
            let newText = loadingText;
            // Preserve icon if exists
            const icon = button.querySelector('i');
            button.innerHTML = spinner + (icon ? icon.outerHTML : '') + ` ${newText}`;
        } else {
            button.disabled = false;
            // Restore original HTML if stored, otherwise use provided or default
            button.innerHTML = button.dataset.originalHtml || originalHTML || 'Submit';
        }
    }


    // --- Filtering Logic (Page Reload) ---
    function applyFilters() {
        const electionId = electionFilter ? electionFilter.value : 'all';
        const positionId = positionFilter ? positionFilter.value : 'all';
        const url = new URL(window.location.pathname, window.location.origin); // Use pathname to stay on candidates.php
        if (electionId !== 'all') url.searchParams.set('election_id', electionId); else url.searchParams.delete('election_id');
        if (positionId !== 'all') url.searchParams.set('position_id', positionId); else url.searchParams.delete('position_id');
        window.location.href = url.toString(); // Reload page with new query parameters
    }

     // Update position dropdown options when election changes (client-side visibility toggle)
     if(electionFilter && positionFilter) {
         electionFilter.addEventListener('change', function() {
             const selectedElection = this.value;
             let hasVisibleOptions = false;
             Array.from(positionFilter.options).forEach(option => {
                  if (option.value === "" || option.value === "all") { // Keep placeholder and 'All' option visible
                       option.style.display = '';
                       hasVisibleOptions = true; // Assume 'All' is always an option
                       return;
                  }
                  const electionMatch = selectedElection === 'all' || option.dataset.electionId == selectedElection;
                  option.style.display = electionMatch ? '' : 'none';
                  if (electionMatch) {
                       hasVisibleOptions = true;
                  }
             });

              // If the currently selected position is now hidden, reset to 'all'
             if (positionFilter.options[positionFilter.selectedIndex]?.style.display === 'none') {
                  positionFilter.value = 'all';
             }

              // Disable position filter if no relevant options exist (excluding 'all')
             positionFilter.disabled = !hasVisibleOptions && selectedElection !== 'all';

              // Optionally trigger applyFilters immediately or wait for position change
              applyFilters();
         });

          // Trigger filter application when position changes
          positionFilter.addEventListener('change', applyFilters);
     }


    // --- Photo Preview Logic ---
    function setupPhotoPreview(inputEl, previewEl) {
        if(inputEl && previewEl) {
            inputEl.addEventListener('change', function(event) {
                const file = event.target.files[0];
                if (file && file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        previewEl.src = e.target.result;
                        previewEl.style.display = 'block';
                    }
                    reader.readAsDataURL(file);
                } else {
                     // Reset to default if no file or invalid file selected
                     previewEl.src = 'assets/images/default-avatar.png';
                     previewEl.style.display = 'block'; // Ensure preview area is visible
                     if (file) { // If a file was selected but invalid
                           showNotification('Invalid file type selected. Please choose an image.', 'warning');
                           inputEl.value = ''; // Clear the invalid file selection
                     }
                }
            });
        }
    }
    setupPhotoPreview(addPhotoInput, addPhotoPreview);
    setupPhotoPreview(editPhotoInput, editPhotoPreview);


    // --- Add Candidate Form Handling (AJAX) ---
    const addCandidateSubmitBtn = document.getElementById('addCandidateSubmitBtn');
    if (addForm && addCandidateSubmitBtn) {
        addForm.addEventListener('submit', function(e) {
            e.preventDefault();
            addModalAlertPlaceholder.innerHTML = ''; // Clear previous alerts
            addForm.classList.remove('was-validated'); // Reset validation state

            if (!addForm.checkValidity()) {
                 e.stopPropagation();
                 addForm.classList.add('was-validated');
                 showModalAlert(addModalAlertPlaceholder, 'Please review the form and fill all required fields correctly.', 'warning');
                 return;
            }

            updateButtonState(addCandidateSubmitBtn, true, null, 'Adding...'); // Set loading state
            const formData = new FormData(addForm); // Includes file and action='create'

            fetch('candidates.php', { // POST to self
                method: 'POST',
                body: formData,
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'} // Identify as AJAX
            })
            .then(response => response.json().then(data => ({ status: response.status, ok: response.ok, body: data }))) // Process JSON regardless of status
            .then(({ status, ok, body }) => {
                if (ok && body.success) {
                    showNotification(body.message || 'Candidate added successfully!', 'success');
                    if(newCandidateModal) newCandidateModal.hide();
                    // Consider updating table via JS instead of reloading for better UX
                    setTimeout(() => window.location.reload(), 1500); // Reload page for simplicity
                } else {
                     throw new Error(body.message || `Failed to add candidate (HTTP ${status})`);
                }
            })
            .catch(error => {
                 console.error("Add Candidate Fetch Error:", error);
                 showModalAlert(addModalAlertPlaceholder, error.message || 'An unexpected error occurred.', 'danger');
            })
            .finally(() => {
                 // Use stored original HTML to reset button
                 updateButtonState(addCandidateSubmitBtn, false);
            });
        });
    }


    // --- Edit Candidate Handling (AJAX) ---
    const editCandidateSubmitBtn = document.getElementById('editCandidateSubmitBtn');
    const editPositionIdSelect = document.getElementById('edit_position_id'); // Select element

    // Attach listener to table body for dynamically added rows, or use querySelectorAll if rows are static on load
    document.querySelector('.candidates-table tbody')?.addEventListener('click', function(event) {
         const editButton = event.target.closest('.edit-candidate-btn');
         if (!editButton) return; // Exit if click wasn't on an edit button or its child

         const candidateId = editButton.getAttribute('data-candidate-id');
         if (!candidateId) return;

         // Reset edit form and show loading state
         editModalAlertPlaceholder.innerHTML = '';
         editForm?.reset(); // Reset form fields
         editForm?.classList.remove('was-validated');
         if(editModalFormContent) editModalFormContent.style.display = 'none'; // Hide form
         if(editModalLoading) editModalLoading.style.display = 'block'; // Show loading
         if(editPhotoPreview) editPhotoPreview.src = 'assets/images/default-avatar.png'; // Reset preview
         if(editCandidateModal) editCandidateModal.show();

         // Fetch existing data
         fetch(`candidates.php?action=get_single&id=${candidateId}`, { // Target self with GET action
             headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
         })
         .then(response => response.json().then(data => ({ status: response.status, ok: response.ok, body: data })))
         .then(({ status, ok, body }) => {
             if (ok && body.success && body.candidate) {
                 const cand = body.candidate;
                 // Populate form fields
                 document.getElementById('edit_candidate_id').value = cand.id;
                 document.getElementById('edit_name').value = cand.name;
                 document.getElementById('edit_position_id').value = cand.position_id; // Set selected option
                 document.getElementById('edit_description').value = cand.description || '';
                 // Update photo preview
                 if(editPhotoPreview) {
                      editPhotoPreview.src = cand.photo_url || 'assets/images/default-avatar.png';
                      editPhotoPreview.style.display = 'block';
                 }
                 // Show form, hide loading
                 if(editModalFormContent) editModalFormContent.style.display = '';
                 if(editModalLoading) editModalLoading.style.display = 'none';
             } else {
                 throw new Error(body.message || `Could not load candidate data (HTTP ${status})`);
             }
         })
         .catch(error => {
             console.error('Fetch Edit Data Error:', error);
             showNotification('Error loading candidate details for editing.', 'danger');
             if(editCandidateModal) editCandidateModal.hide(); // Close modal on fetch error
         });
    });

    // Edit Form Submit (AJAX)
    if(editForm && editCandidateSubmitBtn) {
        editForm.addEventListener('submit', function(e) {
             e.preventDefault();
             editModalAlertPlaceholder.innerHTML = '';
             editForm.classList.remove('was-validated');

             if (!editForm.checkValidity()) {
                  e.stopPropagation();
                  editForm.classList.add('was-validated');
                  showModalAlert(editModalAlertPlaceholder, 'Please review required fields.', 'warning');
                  return;
             }

             updateButtonState(editCandidateSubmitBtn, true, null, 'Saving...');
             const formData = new FormData(editForm); // Includes file if selected, and action='edit'

             fetch('candidates.php', { // POST to self
                 method: 'POST',
                 body: formData,
                 headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
             })
             .then(response => response.json().then(data => ({ status: response.status, ok: response.ok, body: data })))
             .then(({ status, ok, body }) => {
                 if (ok && body.success) {
                     showNotification(body.message || 'Candidate updated successfully!', 'success');
                     if(editCandidateModal) editCandidateModal.hide();
                     // TODO: Update table row dynamically instead of reloading
                     setTimeout(() => window.location.reload(), 1500); // Reload page for simplicity
                 } else {
                     throw new Error(body.message || `Failed to update candidate (HTTP ${status})`);
                 }
             })
             .catch(error => {
                 console.error("Edit Candidate Fetch Error:", error);
                 showModalAlert(editModalAlertPlaceholder, error.message || 'An unexpected error occurred.', 'danger');
             })
             .finally(() => {
                  updateButtonState(editCandidateSubmitBtn, false);
             });
        });
    }


     // --- Delete Candidate Handling (Single - AJAX) ---
     const deleteCandidateIdSingleInput = document.getElementById('deleteCandidateIdSingle');
     const candidateToDeleteNameEl = document.getElementById('candidateToDeleteName');
     const confirmDeleteSingleBtn = document.getElementById('confirmDeleteSingleBtn');

     document.querySelector('.candidates-table tbody')?.addEventListener('click', function(event) {
         const deleteButton = event.target.closest('.delete-candidate-btn');
         if (!deleteButton) return;

         const candidateId = deleteButton.getAttribute('data-candidate-id');
         const candidateName = deleteButton.getAttribute('data-candidate-name');
         if(deleteCandidateIdSingleInput) deleteCandidateIdSingleInput.value = candidateId;
         if(candidateToDeleteNameEl) candidateToDeleteNameEl.textContent = candidateName || 'this candidate';
         if(deleteSingleModal) deleteSingleModal.show();
     });

     if(confirmDeleteSingleBtn && deleteCandidateIdSingleInput) {
         confirmDeleteSingleBtn.addEventListener('click', function() {
             const candidateId = deleteCandidateIdSingleInput.value;
             if (!candidateId) return;

             updateButtonState(this, true, null, 'Deleting...');

             // POST action=delete_single to self
             const formData = new FormData();
             formData.append('action', 'delete_single');
             formData.append('id', candidateId);

             fetch('candidates.php', {
                  method: 'POST', // Use POST for delete actions if preferred over GET
                  body: formData,
                  headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
             })
             .then(response => response.json().then(data => ({ status: response.status, ok: response.ok, body: data })))
             .then(({ status, ok, body }) => {
                 if (ok && body.success) {
                     showNotification(body.message || 'Candidate deleted!', 'success');
                     if(deleteSingleModal) deleteSingleModal.hide();
                     // Remove row visually
                     const rowToRemove = document.getElementById(`candidate-row-${candidateId}`);
                     if(rowToRemove) {
                          rowToRemove.style.transition = 'opacity 0.3s ease-out';
                          rowToRemove.style.opacity = '0';
                          setTimeout(() => {
                               rowToRemove.remove();
                               updateSelectedCount(); // Update counts after removing row
                          }, 300);
                     } else {
                           console.warn("Row not found for deletion:", candidateId);
                           setTimeout(() => window.location.reload(), 1200); // Fallback reload if row not found
                     }
                 } else {
                     throw new Error(body.message || `Failed to delete candidate (HTTP ${status})`);
                 }
             })
             .catch(error => {
                 console.error("Delete Single Candidate Fetch Error:", error);
                 showNotification(error.message || 'Error deleting candidate.', 'danger');
                 if(deleteSingleModal) deleteSingleModal.hide();
             })
             .finally(() => {
                  updateButtonState(confirmDeleteSingleBtn, false); // Reset button
             });
         });
     }


     // --- Bulk Delete Handling (AJAX) ---
     const confirmDeleteBulkBtn = document.getElementById('confirmDeleteBulkBtn');
     const bulkDeleteCountEl = document.getElementById('bulkDeleteCount');

     function updateSelectedCount() {
         const selectedCheckboxes = document.querySelectorAll('.candidate-checkbox:checked');
         const count = selectedCheckboxes.length;
         const allCheckboxes = getCandidateCheckboxes(); // Re-query in case rows changed

         if(selectedCountSpan) selectedCountSpan.textContent = count;
         if(selectedCountBtnSpan) selectedCountBtnSpan.textContent = count; // Update count in button text

         const showBulkButton = count > 0;
         if(deleteSelectedBtnBulk) deleteSelectedBtnBulk.disabled = !showBulkButton;
         if(confirmDeleteBulkBtn) confirmDeleteBulkBtn.disabled = !showBulkButton; // Enable/disable modal confirm
         if(bulkDeleteCountEl) bulkDeleteCountEl.textContent = count;

         // Update select all checkbox state
         if(selectAllCheckbox) {
             selectAllCheckbox.checked = allCheckboxes.length > 0 && count === allCheckboxes.length;
             selectAllCheckbox.indeterminate = count > 0 && count < allCheckboxes.length;
         }
     }

     // Initial count update on page load
     updateSelectedCount();

     // Select All checkbox listener
     if(selectAllCheckbox) {
         selectAllCheckbox.addEventListener('change', function() {
             getCandidateCheckboxes().forEach(checkbox => checkbox.checked = this.checked);
             updateSelectedCount();
         });
     }

     // Individual checkbox listeners (delegated to table body for dynamic rows)
     document.querySelector('.candidates-table tbody')?.addEventListener('change', function(event) {
          if (event.target.classList.contains('candidate-checkbox')) {
               updateSelectedCount();
          }
     });


     // Trigger bulk delete modal
     if(deleteSelectedBtnBulk) {
         deleteSelectedBtnBulk.addEventListener('click', function() {
             if (document.querySelectorAll('.candidate-checkbox:checked').length > 0) {
                  if(deleteBulkModal) deleteBulkModal.show();
             } else {
                  showNotification("Please select at least one candidate to delete.", "warning");
             }
         });
     }

     // Confirm bulk delete action
     if(confirmDeleteBulkBtn && tableForm) { // Check tableForm exists
         confirmDeleteBulkBtn.addEventListener('click', function() {
             const selectedCheckboxes = document.querySelectorAll('.candidate-checkbox:checked');
             const selectedIds = Array.from(selectedCheckboxes).map(cb => cb.value);
             if (selectedIds.length === 0) return;

             updateButtonState(this, true, null, 'Deleting...');

             const formData = new FormData(); // Don't use tableForm directly, build dynamically
             formData.append('action', 'delete_bulk');
             selectedIds.forEach(id => formData.append('selected_ids[]', id));

             // POST to self (candidates.php)
             fetch('candidates.php', {
                  method: 'POST',
                  body: formData,
                  headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
             })
             .then(response => response.json().then(data => ({ status: response.status, ok: response.ok, body: data })))
             .then(({ status, ok, body }) => {
                 if (ok && body.success) {
                      showNotification(body.message || 'Selected candidates deleted!', 'success');
                      if(deleteBulkModal) deleteBulkModal.hide();
                      // Remove rows visually
                      selectedIds.forEach(id => {
                          const row = document.getElementById(`candidate-row-${id}`);
                          if(row) {
                               row.style.transition = 'opacity 0.3s ease-out';
                               row.style.opacity = '0';
                               setTimeout(() => row.remove(), 300);
                          } else {
                               console.warn("Row not found for bulk deletion:", id);
                          }
                      });
                      // Reset select all and counts after slight delay for animations
                       setTimeout(updateSelectedCount, 350);
                 } else {
                      throw new Error(body.message || `Failed to delete candidates (HTTP ${status})`);
                 }
             })
             .catch(error => {
                  console.error("Bulk Delete Fetch Error:", error);
                  showNotification(error.message || 'Error during bulk delete.', 'danger');
                  if(deleteBulkModal) deleteBulkModal.hide();
             })
             .finally(() => {
                  updateButtonState(confirmDeleteBulkBtn, false); // Reset button
             });
         });
     }


    // --- Reset Modals on Close ---
    [ newCandidateModal, editCandidateModal, deleteSingleModal, deleteBulkModal ].forEach(modal => {
        const modalEl = modal ? modal._element : null; // Get underlying DOM element
        if(modalEl) {
            modalEl.addEventListener('hidden.bs.modal', function () {
                const form = this.querySelector('form');
                if (form) {
                     form.classList.remove('was-validated');
                     form.reset(); // Reset form fields
                }
                // Clear alert placeholders
                this.querySelectorAll('#addModalAlertPlaceholder, #editModalAlertPlaceholder').forEach(p => p.innerHTML = '');

                // Reset specific buttons to their initial state using stored original HTML or default
                const buttonsToReset = [
                     { id: 'addCandidateSubmitBtn', defaultHtml: '<i class="bi bi-plus-lg me-1"></i>Add Candidate' },
                     { id: 'editCandidateSubmitBtn', defaultHtml: '<i class="bi bi-save-fill me-1"></i> Save Changes' },
                     { id: 'confirmDeleteSingleBtn', defaultHtml: '<i class="bi bi-trash3-fill me-1"></i>Delete' },
                     { id: 'confirmDeleteBulkBtn', defaultHtml: '<i class="bi bi-trash3-fill me-1"></i>Delete Selected' }
                ];
                buttonsToReset.forEach(btnInfo => {
                     const button = this.querySelector(`#${btnInfo.id}`);
                     if(button) {
                           updateButtonState(button, false, button.dataset.originalHtml || btnInfo.defaultHtml); // Restore or use default
                           delete button.dataset.originalHtml; // Clean up stored html
                     }
                });

                // Reset image previews
                this.querySelectorAll('#add_photoPreview, #edit_photoPreview').forEach(preview => {
                     if(preview) preview.src = 'assets/images/default-avatar.png';
                });
                // Reset loading indicator in edit modal
                if(editModalLoading) editModalLoading.style.display = 'none';
                if(editModalFormContent) editModalFormContent.style.display = '';
            });
        }
    });

    // --- Sidebar Toggle Logic ---
    // (Keep as is - UI enhancement, not directly related to AJAX/DB)
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const navbar = document.querySelector('.navbar');
    if (sidebarToggle && sidebar && mainContent && navbar) {
        const applySidebarState = (state) => { sidebar.classList.toggle('collapsed', state === 'collapsed'); mainContent.classList.toggle('expanded', state === 'collapsed'); navbar.classList.toggle('expanded', state === 'collapsed'); };
        sidebarToggle.addEventListener('click', (e) => { e.preventDefault(); const newState = sidebar.classList.contains('collapsed') ? 'expanded' : 'collapsed'; applySidebarState(newState); try { localStorage.setItem('sidebarState', newState); } catch (e) { console.warn("LocalStorage not available for sidebar state.");} });
        try { const storedState = localStorage.getItem('sidebarState'); if (storedState) applySidebarState(storedState); } catch (e) { console.warn("LocalStorage not available for sidebar state."); }
    }

}); // End DOMContentLoaded
</script>

</body>
</html>