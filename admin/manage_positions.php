<?php
// --- Early Initialization & Config ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
error_reporting(E_ALL); // Dev
ini_set('display_errors', 1); // Dev
date_default_timezone_set('Africa/Nairobi'); // EAT

require_once "../config/database.php"; // Provides $conn (mysqli connection)
require_once "includes/session.php"; // Provides isAdminLoggedIn()

// Check DB Connection early
if (!$conn || $conn->connect_error) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
         header('Content-Type: application/json; charset=utf-8'); http_response_code(500);
         echo json_encode(['success' => false, 'message' => 'Database connection failed.']); exit;
    } else { die("Database connection failed."); }
}

// --- Security: Admin Check ---
if (!isAdminLoggedIn()) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
         header('Content-Type: application/json; charset=utf-8'); http_response_code(401);
         echo json_encode(['success' => false, 'message' => 'Unauthorized access.']); exit;
    } else { header("Location: login.php"); exit(); }
}

// --- Helper Function: Send JSON Response ---
function send_json_response($success, $data = null, $message = null, $data_key = 'data') {
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
    $response = ['success' => (bool)$success];
    if ($message !== null) { $response['message'] = $message; }
    if ($success && $data !== null && $data_key !== null) { $response[$data_key] = $data; }
    if (!$success && http_response_code() === 200) { http_response_code(400); }
    echo json_encode($response); exit();
}

// --- AJAX ACTION HANDLING ---
$action = $_REQUEST['action'] ?? null; // Use $_REQUEST to catch POST/GET if needed

// --- Action: Create Position ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_position') {
    // Get election_id from POST data now for AJAX
    $election_id_create = filter_input(INPUT_POST, 'election_id', FILTER_VALIDATE_INT);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');

    try {
        if (!$election_id_create || $election_id_create <= 0) {
            throw new Exception("Invalid or missing Election ID.");
        }
        if (empty($title)) {
            throw new Exception("Position Title is required.");
        }

        $titleEsc = mysqli_real_escape_string($conn, $title);
        $descriptionEsc = mysqli_real_escape_string($conn, $description);

        $sql = "INSERT INTO positions (election_id, title, description)
                VALUES ($election_id_create, '$titleEsc', '$descriptionEsc')";

        if (mysqli_query($conn, $sql)) {
            $new_id = mysqli_insert_id($conn);
            send_json_response(true, ['new_id' => $new_id], 'Position created successfully!');
        } else {
            throw new Exception("Database error creating position: " . mysqli_error($conn));
        }
    } catch (Exception $e) {
        send_json_response(false, null, $e->getMessage());
    }
}

// --- Action: Delete Positions (Bulk) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_positions') {
    $ids_to_delete = [];
    if (!empty($_POST['selected_positions']) && is_array($_POST['selected_positions'])) {
        $ids_to_delete = array_map('intval', $_POST['selected_positions']);
        $ids_to_delete = array_filter($ids_to_delete, function($id) { return $id > 0; });
    }

    if (empty($ids_to_delete)) {
         send_json_response(false, null, 'No valid positions selected for deletion.');
    }

    $positions_string = implode(',', $ids_to_delete); // Safe as all are integers

    try {
        // Note: Foreign key constraints with ON DELETE CASCADE handle deleting related candidates/votes
        $delete_sql = "DELETE FROM positions WHERE id IN ($positions_string)";
        if (mysqli_query($conn, $delete_sql)) {
            $deleted_count = mysqli_affected_rows($conn);
            if ($deleted_count > 0) {
                send_json_response(true, ['deleted_count' => $deleted_count], "$deleted_count position(s) deleted successfully!");
            } else {
                // Query OK, but nothing deleted (maybe IDs were invalid or already deleted)
                send_json_response(false, null, 'No matching positions found to delete.');
            }
        } else {
            throw new Exception("Database error deleting positions: " . mysqli_error($conn));
        }
    } catch (Exception $e) {
         send_json_response(false, null, $e->getMessage());
    }
}

// --- END AJAX ACTION HANDLING ---
// Proceed with page load if no AJAX action was handled

// --- Page Load Data Fetching ---
$election_id = filter_input(INPUT_GET, 'election_id', FILTER_VALIDATE_INT);
$election = null;
$positions = [];
$page_error = null;

if (!$election_id || $election_id <= 0) {
    // Redirect or show error if election_id is invalid for page load
    header("Location: elections.php?error=" . urlencode("Invalid Election ID specified."));
    exit();
}

try {
    // Get election details
    $election_sql = "SELECT id, title FROM elections WHERE id = $election_id";
    $election_result = mysqli_query($conn, $election_sql);
    if ($election_result && mysqli_num_rows($election_result) > 0) {
        $election = mysqli_fetch_assoc($election_result);
        mysqli_free_result($election_result);
    } else {
         // Election not found, redirect or set error
         if ($election_result) mysqli_free_result($election_result);
         header("Location: elections.php?error=" . urlencode("Election not found."));
         exit();
    }

    // Get current positions for this election
    $positions_sql = "SELECT id, title, description FROM positions WHERE election_id = $election_id ORDER BY title ASC";
    $positions_result = mysqli_query($conn, $positions_sql);
    if ($positions_result) {
        while ($row = mysqli_fetch_assoc($positions_result)) {
            $positions[] = $row;
        }
        mysqli_free_result($positions_result);
    } else {
        throw new Exception("Error fetching positions: " . mysqli_error($conn));
    }

} catch (Exception $e) {
    error_log("Manage Positions Page Error (Election ID: $election_id): " . $e->getMessage());
    $page_error = "Failed to load position data. Please try again later.";
    $election = $election ?: ['id' => $election_id, 'title' => 'Unknown Election']; // Provide fallback title
    $positions = []; // Clear positions on error
}

// Generate CSP nonce if needed (should be done before including header)
if (empty($_SESSION['csp_nonce'])) {
    $_SESSION['csp_nonce'] = base64_encode(random_bytes(16));
}
$nonce = htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8');

// --- Include HTML Header ---
require_once "includes/header.php"; // Assumes this includes <!DOCTYPE html>, <head>, opening <body> etc.
?>

<style nonce="<?php echo $nonce; ?>">
    body { background-color: #f8f9fa; }
    .card { box-shadow: 0 .125rem .25rem rgba(0,0,0,.075); border: 1px solid #e3e6f0; }
    .card-header { background-color: #f8f9fc; border-bottom: 1px solid #e3e6f0; font-weight: bold; }
    .table th, .table td { vertical-align: middle; }
    .action-buttons .btn { margin-right: 5px; }
    .form-check-input { cursor: pointer; }
    .delete-btn { margin-top: 10px; }
    #feedbackPlaceholder .alert { font-size: 0.9rem; padding: 0.75rem 1rem; }
    /* Loading spinner styles */
    .spinner-border-sm { width: 1em; height: 1em; border-width: .2em; }
</style>

<div class="container mt-4 mb-5">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="elections.php">Elections</a></li>
        <li class="breadcrumb-item active" aria-current="page">
            Manage Positions: <?php echo htmlspecialchars($election['title'] ?? 'Unknown'); ?>
        </li>
      </ol>
    </nav>

    <h2 class="mb-4">Manage Positions - <span class="text-primary"><?php echo htmlspecialchars($election['title'] ?? 'Unknown'); ?></span></h2>

     <div id="feedbackPlaceholder" class="mb-3"></div>

     <?php if ($page_error): ?>
         <div class="alert alert-danger">
             <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($page_error); ?>
         </div>
     <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-plus-circle-fill me-2"></i>Create New Position
        </div>
        <div class="card-body">
            <form id="createPositionForm" novalidate>
                <input type="hidden" name="election_id" value="<?php echo $election_id; ?>">
                <input type="hidden" name="action" value="create_position">

                <div class="row g-3">
                    <div class="col-md-6 mb-3">
                        <label for="title" class="form-label">Position Title*</label>
                        <input type="text" class="form-control" id="title" name="title" required maxlength="100">
                        <div class="invalid-feedback">Please enter a title for the position.</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="description" class="form-label">Description (Optional)</label>
                        <input type="text" class="form-control" id="description" name="description" maxlength="255">
                    </div>
                </div>
                 <div class="d-flex justify-content-between">
                      <button type="submit" id="createPositionBtn" class="btn btn-primary">
                           <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
                           <i class="bi bi-plus-lg me-1"></i>Create Position
                      </button>
                      <a href="elections.php" class="btn btn-outline-secondary">
                           <i class="bi bi-arrow-left me-1"></i>Back to Elections
                      </a>
                 </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
             <i class="bi bi-list-ul me-2"></i>Current Positions (<?php echo count($positions); ?>)
        </div>
        <div class="card-body">
            <?php if (empty($positions) && !$page_error): ?>
                <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>No positions have been created for this election yet.</div>
            <?php elseif (!$page_error): ?>
                <form id="positionsListForm">
                     <input type="hidden" name="action" value="delete_positions">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th style="width: 5%;">
                                         <input type="checkbox" class="form-check-input select-all" title="Select/Deselect All" aria-label="Select all positions">
                                    </th>
                                    <th>Title</th>
                                    <th>Description</th>
                                    <th style="width: 25%;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($positions as $position): ?>
                                    <tr id="position-row-<?php echo $position['id']; ?>">
                                        <td>
                                             <input type="checkbox" name="selected_positions[]" value="<?php echo $position['id']; ?>" class="form-check-input position-checkbox" aria-label="Select position <?php echo htmlspecialchars($position['title']); ?>">
                                        </td>
                                        <td><?php echo htmlspecialchars($position['title']); ?></td>
                                        <td><?php echo htmlspecialchars($position['description']); ?></td>
                                        <td class="action-buttons">
                                            <a href="edit_position.php?id=<?php echo $position['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit Position">
                                                 <i class="bi bi-pencil-fill"></i> Edit
                                            </a>
                                            <a href="candidates.php?position_id=<?php echo $position['id']; ?>&election_id=<?php echo $election_id; ?>" class="btn btn-sm btn-outline-success" title="Manage Candidates for this Position">
                                                 <i class="bi bi-people-fill"></i> Candidates
                                            </a>
                                             <button type="button" class="btn btn-sm btn-outline-danger delete-single-position-btn" data-position-id="<?php echo $position['id']; ?>" data-position-title="<?php echo htmlspecialchars($position['title']); ?>" title="Delete Position">
                                                    <i class="bi bi-trash3-fill"></i>
                                                </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" id="deleteSelectedBtn" class="btn btn-danger delete-btn" disabled>
                         <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
                         <i class="bi bi-trash-fill me-1"></i>Delete Selected (<span id="selectedCount">0</span>)
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div> <div class="modal fade" id="deleteSinglePosConfirmModal" tabindex="-1" aria-labelledby="deleteSinglePosConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white"><h5 class="modal-title" id="deleteSinglePosConfirmModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Deletion</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body text-center py-4"><p class="lead mb-2">Delete position "<strong id="positionToDeleteName"></strong>"?</p><p class="text-danger"><small>This will delete the position and all associated candidates and votes.<br>This action cannot be undone.</small></p><input type="hidden" id="deletePositionIdSingle"></div>
            <div class="modal-footer justify-content-center border-0"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger px-4" id="confirmDeleteSinglePosBtn"><span class="spinner-border spinner-border-sm d-none me-1"></span><i class="bi bi-trash3-fill me-1"></i>Delete</button></div>
        </div>
    </div>
</div>
<div class="modal fade" id="deleteBulkPosConfirmModal" tabindex="-1" aria-labelledby="deleteBulkPosConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
             <div class="modal-header bg-danger text-white"><h5 class="modal-title" id="deleteBulkPosConfirmModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Bulk Deletion</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body text-center py-4"><p class="lead mb-2">Delete the selected <strong id="bulkDeletePosCount">0</strong> position(s)?</p><p class="text-danger"><small>This will delete the positions and all associated candidates and votes.<br>This action cannot be undone.</small></p></div>
            <div class="modal-footer justify-content-center border-0"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger px-4" id="confirmDeleteBulkPosBtn"><span class="spinner-border spinner-border-sm d-none me-1"></span><i class="bi bi-trash3-fill me-1"></i>Delete Selected</button></div>
        </div>
    </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1090">
    <div id="notificationToast" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4000">
        <div class="d-flex notification-content">
            <div class="toast-body d-flex align-items-center"><span class="notification-icon me-2 fs-4"></span><span id="notificationMessage" class="fw-medium"></span></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-ka7Sk0Gln4gmtz2MlQnikT1wXgYsOg+OMhuP+IlRH9sENBO0LRn5q+8nbTov4+1p" crossorigin="anonymous"></script>

<script nonce="<?php echo $nonce; ?>">

// --- Helper: Show Notification Toast (same as candidates.php) ---
function showNotification(message, type = 'success') {
    const notificationToastEl = document.getElementById('notificationToast');
    const notificationToast = notificationToastEl ? bootstrap.Toast.getOrCreateInstance(notificationToastEl) : null;
    const msgEl = document.getElementById('notificationMessage');
    const iconEl = notificationToastEl?.querySelector('.notification-icon');
    if (!notificationToast || !msgEl || !iconEl || !notificationToastEl) return;

    notificationToastEl.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-info', 'text-dark');
    notificationToastEl.querySelector('.toast-body')?.classList.remove('text-dark');
    notificationToastEl.querySelector('.btn-close')?.classList.remove('text-dark', 'btn-close-white'); // Reset close button color
    notificationToastEl.querySelector('.btn-close')?.classList.add('btn-close-white'); // Add default back


    iconEl.innerHTML = ''; message = String(message || 'Action completed.').substring(0, 200); msgEl.textContent = message;
    let iconClass = 'bi-check-circle-fill'; let bgClass = 'bg-success'; let isDarkText = false;

    if (type === 'danger') { iconClass = 'bi-x-octagon-fill'; bgClass = 'bg-danger'; }
    else if (type === 'warning') { iconClass = 'bi-exclamation-triangle-fill'; bgClass = 'bg-warning'; isDarkText = true; }
    else if (type === 'info') { iconClass = 'bi-info-circle-fill'; bgClass = 'bg-info'; }

    notificationToastEl.classList.add(bgClass);
    if (isDarkText) {
         notificationToastEl.querySelector('.toast-body')?.classList.add('text-dark');
         notificationToastEl.querySelector('.btn-close')?.classList.remove('btn-close-white');
         notificationToastEl.querySelector('.btn-close')?.classList.add('text-dark');
    }
    iconEl.innerHTML = `<i class="bi ${iconClass}"></i>`; notificationToast.show();
}

// --- Helper: Update Button State (same as candidates.php) ---
function updateButtonState(button, isLoading, originalHTML = null, loadingText = 'Processing...') {
     if (!button) return;
     if (isLoading) {
          button.dataset.originalHtml = button.innerHTML;
          button.disabled = true;
          const spinner = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;
          const icon = button.querySelector('i'); // Find existing icon
          button.innerHTML = spinner + (icon ? icon.outerHTML : '') + ` ${loadingText}`;
     } else {
          button.disabled = false;
          button.innerHTML = button.dataset.originalHtml || originalHTML || 'Submit';
     }
}


document.addEventListener('DOMContentLoaded', function() {
    const createForm = document.getElementById('createPositionForm');
    const listForm = document.getElementById('positionsListForm');
    const selectAllCheckbox = document.querySelector('.select-all');
    const positionCheckboxes = document.querySelectorAll('.position-checkbox');
    const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
    const selectedCountSpan = document.getElementById('selectedCount');
    const feedbackPlaceholder = document.getElementById('feedbackPlaceholder');
    const createPositionBtn = document.getElementById('createPositionBtn');

    // Get Modal instances safely
    const deleteSingleModalInstance = getModalInstance('deleteSinglePosConfirmModal');
    const deleteBulkModalInstance = getModalInstance('deleteBulkPosConfirmModal');
    const confirmDeleteSinglePosBtn = document.getElementById('confirmDeleteSinglePosBtn');
    const confirmDeleteBulkPosBtn = document.getElementById('confirmDeleteBulkPosBtn');
    const positionToDeleteNameEl = document.getElementById('positionToDeleteName');
    const deletePositionIdSingleInput = document.getElementById('deletePositionIdSingle');
    const bulkDeletePosCountEl = document.getElementById('bulkDeletePosCount');


    // --- Create Position (AJAX) ---
    if (createForm && createPositionBtn) {
        createForm.addEventListener('submit', function(e) {
            e.preventDefault();
            feedbackPlaceholder.innerHTML = ''; // Clear old feedback
            createForm.classList.remove('was-validated');

            if (!createForm.checkValidity()) {
                e.stopPropagation();
                createForm.classList.add('was-validated');
                showNotification('Please fill required fields correctly.', 'warning');
                return;
            }

            updateButtonState(createPositionBtn, true, null, 'Creating...');
            const formData = new FormData(createForm);

            fetch('<?php echo basename($_SERVER['PHP_SELF']); ?>', { // POST to self
                method: 'POST',
                body: formData,
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(response => response.json().then(data => ({ ok: response.ok, body: data })))
            .then(({ ok, body }) => {
                if (ok && body.success) {
                    showNotification(body.message || 'Position created!', 'success');
                    createForm.reset(); // Clear the form
                    // Reload page to show the new position in the list
                    // Alternatively, dynamically add the row (more complex)
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    throw new Error(body.message || 'Failed to create position.');
                }
            })
            .catch(error => {
                console.error("Create Position Error:", error);
                feedbackPlaceholder.innerHTML = `<div class="alert alert-danger">${error.message || 'An unexpected error occurred.'}</div>`;
            })
            .finally(() => {
                updateButtonState(createPositionBtn, false); // Restore button
            });
        });
    }

    // --- Bulk Delete Positions (AJAX) ---
    if (listForm && deleteSelectedBtn) {
        listForm.addEventListener('submit', function(e) {
            e.preventDefault(); // Prevent default form submission

            const selectedCheckboxes = document.querySelectorAll('.position-checkbox:checked');
            if (selectedCheckboxes.length === 0) {
                showNotification('Please select at least one position to delete.', 'warning');
                return;
            }

            // Show confirmation modal
            if(bulkDeletePosCountEl) bulkDeletePosCountEl.textContent = selectedCheckboxes.length;
            if(deleteBulkModalInstance) deleteBulkModalInstance.show();

        });

        // Handle confirmation from bulk delete modal
        if(confirmDeleteBulkPosBtn) {
             confirmDeleteBulkPosBtn.addEventListener('click', function() {
                  const selectedCheckboxes = document.querySelectorAll('.position-checkbox:checked');
                  if (selectedCheckboxes.length === 0) return; // Should be disabled, but check anyway

                  updateButtonState(this, true, null, 'Deleting...'); // Update modal button
                  updateButtonState(deleteSelectedBtn, true); // Also update main button

                  const formData = new FormData(listForm); // Contains action and selected_positions[]

                  fetch('<?php echo basename($_SERVER['PHP_SELF']); ?>', { // POST to self
                      method: 'POST',
                      body: formData,
                      headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
                  })
                  .then(response => response.json().then(data => ({ ok: response.ok, body: data })))
                  .then(({ ok, body }) => {
                      if (ok && body.success) {
                           showNotification(body.message || 'Selected positions deleted!', 'success');
                           // Remove rows visually
                           selectedCheckboxes.forEach(checkbox => {
                                const row = checkbox.closest('tr');
                                if(row) {
                                     row.style.transition = 'opacity 0.3s ease-out';
                                     row.style.opacity = '0';
                                     setTimeout(() => row.remove(), 300);
                                }
                           });
                            // Reset select all and counts after delay
                            setTimeout(updateDeleteButtonState, 350);
                      } else {
                           throw new Error(body.message || 'Failed to delete positions.');
                      }
                  })
                  .catch(error => {
                       console.error("Bulk Delete Error:", error);
                       showNotification(error.message || 'An unexpected error occurred during deletion.', 'danger');
                  })
                  .finally(() => {
                      if(deleteBulkModalInstance) deleteBulkModalInstance.hide();
                      updateButtonState(confirmDeleteBulkPosBtn, false); // Reset modal button
                      updateButtonState(deleteSelectedBtn, false); // Reset main button (if not done by updateDeleteButtonState)
                      updateDeleteButtonState(); // Ensure correct state after operation
                  });
             });
        }
    }

    // --- Single Delete Position (AJAX) ---
     document.querySelector('.table tbody')?.addEventListener('click', function(event) {
         const deleteButton = event.target.closest('.delete-single-position-btn');
         if (!deleteButton) return;

         const positionId = deleteButton.dataset.positionId;
         const positionTitle = deleteButton.dataset.positionTitle;

         if(positionToDeleteNameEl) positionToDeleteNameEl.textContent = positionTitle || 'this position';
         if(deletePositionIdSingleInput) deletePositionIdSingleInput.value = positionId;
         if(deleteSingleModalInstance) deleteSingleModalInstance.show();
     });

     if(confirmDeleteSinglePosBtn && deletePositionIdSingleInput) {
         confirmDeleteSinglePosBtn.addEventListener('click', function() {
             const positionId = deletePositionIdSingleInput.value;
             if (!positionId) return;

             updateButtonState(this, true, null, 'Deleting...');

             const formData = new FormData();
             formData.append('action', 'delete_single'); // Need PHP handler for this action
             formData.append('id', positionId);

             fetch('<?php echo basename($_SERVER['PHP_SELF']); ?>', { // POST to self
                 method: 'POST',
                 body: formData,
                 headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
             })
             .then(response => response.json().then(data => ({ ok: response.ok, body: data })))
             .then(({ ok, body }) => {
                 if (ok && body.success) {
                      showNotification(body.message || 'Position deleted!', 'success');
                      // Remove row visually
                      const row = document.getElementById(`position-row-${positionId}`);
                      if(row) {
                           row.style.transition = 'opacity 0.3s ease-out';
                           row.style.opacity = '0';
                           setTimeout(() => { row.remove(); updateDeleteButtonState(); }, 300);
                      } else {
                           setTimeout(() => window.location.reload(), 1200); // Fallback reload
                      }
                 } else {
                      throw new Error(body.message || 'Failed to delete position.');
                 }
             })
             .catch(error => {
                 console.error("Single Delete Error:", error);
                 showNotification(error.message || 'An error occurred.', 'danger');
             })
             .finally(() => {
                 if(deleteSingleModalInstance) deleteSingleModalInstance.hide();
                 updateButtonState(this, false); // Restore button state
             });
         });
     }

    // --- Checkbox Logic ---
    function updateDeleteButtonState() {
        const selectedCount = document.querySelectorAll('.position-checkbox:checked').length;
        if (deleteSelectedBtn) {
            deleteSelectedBtn.disabled = selectedCount === 0;
        }
        if (selectedCountSpan) {
             selectedCountSpan.textContent = selectedCount; // Update count in button text
        }
         // Update select all checkbox state
         const allCheckboxes = document.querySelectorAll('.position-checkbox');
         if(selectAllCheckbox) {
             selectAllCheckbox.checked = allCheckboxes.length > 0 && selectedCount === allCheckboxes.length;
             selectAllCheckbox.indeterminate = selectedCount > 0 && selectedCount < allCheckboxes.length;
         }
    }

    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            document.querySelectorAll('.position-checkbox').forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateDeleteButtonState();
        });
    }

    document.querySelectorAll('.position-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', updateDeleteButtonState);
    });

    // Initial state
    updateDeleteButtonState();


    // --- Modal Cleanup on Hide (reuse logic) ---
     [deleteSingleModalInstance, deleteBulkModalInstance].forEach(modal => {
          const modalEl = modal ? modal._element : null;
          if(modalEl) {
               modalEl.addEventListener('hidden.bs.modal', function () {
                    // Reset confirmation buttons
                    const buttonsToReset = [
                         { id: 'confirmDeleteSinglePosBtn', defaultHtml: '<i class="bi bi-trash3-fill me-1"></i>Delete' },
                         { id: 'confirmDeleteBulkPosBtn', defaultHtml: '<i class="bi bi-trash3-fill me-1"></i>Delete Selected' }
                    ];
                    buttonsToReset.forEach(btnInfo => {
                         const button = this.querySelector(`#${btnInfo.id}`);
                         if(button) {
                              updateButtonState(button, false, button.dataset.originalHtml || btnInfo.defaultHtml);
                              delete button.dataset.originalHtml;
                         }
                    });
               });
          }
     });

     // Function to get modal instance safely (reuse from candidates)
     function getModalInstance(id) { const el = document.getElementById(id); return el ? bootstrap.Modal.getOrCreateInstance(el) : null; };


}); // End DOMContentLoaded
</script>

</body>
</html>