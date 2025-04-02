<?php
session_start();
require_once "../config/database.php";
require_once "includes/header.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: /school_voting/admin/login.php");
    exit();
}

// Function to generate unique voting codes
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

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['delete_codes'])) {
        if (isset($_POST['selected_codes']) && is_array($_POST['selected_codes'])) {
            $selected_codes = array_map('intval', $_POST['selected_codes']);
            
            // Check if any of the selected codes have been used
            $check_used_sql = "SELECT COUNT(*) FROM voting_codes vc 
                             INNER JOIN votes v ON vc.id = v.voting_code_id 
                             WHERE vc.id IN (" . implode(',', array_fill(0, count($selected_codes), '?')) . ")";
            $check_stmt = $conn->prepare($check_used_sql);
            $check_stmt->execute($selected_codes);
            $used_count = $check_stmt->fetchColumn();
            
            if ($used_count > 0) {
                $error = "Cannot delete used voting codes. Please unselect used codes and try again.";
            } else {
                $placeholders = str_repeat('?,', count($selected_codes) - 1) . '?';
                
                // Delete the selected unused codes
                $delete_sql = "DELETE FROM voting_codes WHERE id IN ($placeholders)";
                $delete_stmt = $conn->prepare($delete_sql);
                if ($delete_stmt->execute($selected_codes)) {
                    $success = "Selected voting codes deleted successfully!";
                } else {
                    $error = "Error deleting voting codes";
                }
            }
        }
    } else if (isset($_POST['action']) && $_POST['action'] === 'create') {
        $election_id = $_POST['election_id'];
        $quantity = (int)$_POST['quantity'];
        
        try {
            $conn->beginTransaction();
            
            // Generate unique voting codes
            $insert_sql = "INSERT INTO voting_codes (code, election_id) VALUES (?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            
            $generated_codes = [];
            $success_count = 0;
            
            for ($i = 0; $i < $quantity; $i++) {
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
                $success = "$success_count unique voting codes generated successfully!";
            } else {
                $error = "Failed to generate any unique codes. Please try again.";
            }
        } catch (PDOException $e) {
            $conn->rollBack();
            $error = "Error generating voting codes: " . $e->getMessage();
        }
    }
}

// Get all elections for the dropdown
$elections_sql = "SELECT * FROM elections ORDER BY title";
$elections = $conn->query($elections_sql)->fetchAll(PDO::FETCH_ASSOC);

// Get selected election ID from URL
$selected_election_id = isset($_GET['election_id']) ? (int)$_GET['election_id'] : null;

// Get voting codes with election and usage information
$voting_codes_sql = "SELECT 
    vc.*, 
    e.title as election_title,
    e.status as election_status,
    MAX(v.created_at) as used_at,
    (SELECT COUNT(*) FROM votes WHERE voting_code_id = vc.id) as vote_count,
    CASE 
        WHEN EXISTS (SELECT 1 FROM votes WHERE voting_code_id = vc.id) THEN 'Used'
        ELSE 'Available'
    END as status
FROM voting_codes vc
LEFT JOIN elections e ON vc.election_id = e.id
LEFT JOIN votes v ON vc.id = v.voting_code_id
GROUP BY vc.id, vc.code, vc.election_id, e.title, e.status
ORDER BY vc.created_at DESC";

if ($selected_election_id) {
    $voting_codes_sql = "SELECT 
        vc.*, 
        e.title as election_title,
        e.status as election_status,
        MAX(v.created_at) as used_at,
        (SELECT COUNT(*) FROM votes WHERE voting_code_id = vc.id) as vote_count,
        CASE 
            WHEN EXISTS (SELECT 1 FROM votes WHERE voting_code_id = vc.id) THEN 'Used'
            ELSE 'Available'
        END as status
    FROM voting_codes vc
    LEFT JOIN elections e ON vc.election_id = e.id
    LEFT JOIN votes v ON vc.id = v.voting_code_id
    WHERE vc.election_id = ?
    GROUP BY vc.id, vc.code, vc.election_id, e.title, e.status
    ORDER BY vc.created_at DESC";
    $stmt = $conn->prepare($voting_codes_sql);
    $stmt->execute([$selected_election_id]);
} else {
    $stmt = $conn->prepare($voting_codes_sql);
    $stmt->execute();
}
$voting_codes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats_sql = "SELECT 
    COUNT(DISTINCT vc.id) as total_codes,
    SUM(CASE WHEN EXISTS (SELECT 1 FROM votes v2 WHERE v2.voting_code_id = vc.id) THEN 1 ELSE 0 END) as used_codes
FROM voting_codes vc";

if ($selected_election_id) {
    $stats_sql .= " WHERE vc.election_id = ?";
    $stats_stmt = $conn->prepare($stats_sql);
    $stats_stmt->execute([$selected_election_id]);
} else {
    $stats_stmt = $conn->prepare($stats_sql);
    $stats_stmt->execute();
}
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Calculate unused codes
$stats['unused_codes'] = $stats['total_codes'] - $stats['used_codes'];

// Calculate percentages
$used_percentage = $stats['total_codes'] > 0 ? 
    round(($stats['used_codes'] / $stats['total_codes']) * 100, 1) : 0;
$unused_percentage = $stats['total_codes'] > 0 ? 
    round(($stats['unused_codes'] / $stats['total_codes']) * 100, 1) : 0;
?>

<div class="container-fluid">
    <!-- Header Section -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">Manage Voting Codes</h2>
            <p class="text-muted mb-0">Generate and manage voting codes for elections</p>
        </div>
        <div class="d-flex gap-2">
            <select class="form-select" id="electionSelect" onchange="filterCodes(this.value)">
                <option value="">All Elections</option>
                <?php foreach ($elections as $election): ?>
                    <option value="<?php echo $election['id']; ?>" <?php echo $selected_election_id == $election['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($election['title']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-primary btn-sm px-3" data-bs-toggle="modal" data-bs-target="#generateCodesModal">
                <i class="bi bi-plus-circle"></i> Generate New Codes
            </button>
        </div>
    </div>

    <?php if (isset($success)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo $success; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card stat-card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-primary bg-opacity-10 rounded-circle p-3 me-3">
                            <i class="bi bi-key-fill text-primary fs-4"></i>
                        </div>
                        <div>
                            <h6 class="card-title text-muted mb-1">Total Voting Codes</h6>
                            <p class="card-text display-6 mb-0"><?php echo number_format($stats['total_codes']); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-success bg-opacity-10 rounded-circle p-3 me-3">
                            <i class="bi bi-check-circle-fill text-success fs-4"></i>
                        </div>
                        <div>
                            <h6 class="card-title text-muted mb-1">Codes Used</h6>
                            <p class="card-text display-6 mb-0"><?php echo number_format($stats['used_codes']); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-warning bg-opacity-10 rounded-circle p-3 me-3">
                            <i class="bi bi-hourglass-split text-warning fs-4"></i>
                        </div>
                        <div>
                            <h6 class="card-title text-muted mb-1">Codes Remaining</h6>
                            <p class="card-text display-6 mb-0"><?php echo number_format($stats['unused_codes']); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Voting Codes Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Voting Codes List</h5>
            </div>
        </div>
        <div class="card-body">
            <form method="post" id="deleteForm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th width="40">
                                    <input type="checkbox" class="form-check-input select-all">
                                </th>
                                <th>Code</th>
                                <th>Election</th>
                                <th>Status</th>
                                <th>Used At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($voting_codes as $code): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="selected_codes[]" value="<?php echo $code['id']; ?>" class="form-check-input code-checkbox">
                                    </td>
                                    <td>
                                        <code class="bg-light px-2 py-1 rounded"><?php echo htmlspecialchars($code['code']); ?></code>
                                    </td>
                                    <td>
                                        <span class="badge bg-success"><?php echo htmlspecialchars($code['election_status']); ?></span>
                                        <?php echo htmlspecialchars($code['election_title'] ?? 'Unassigned'); ?>
                                    </td>
                                    <td>
                                        <?php if ($code['vote_count'] > 0): ?>
                                            <span class="badge bg-danger">Used</span>
                                        <?php else: ?>
                                            <span class="badge bg-info">Available</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($code['vote_count'] > 0): ?>
                                            <span class="text-danger">
                                                <?php echo date('M d, Y H:i', strtotime($code['used_at'])); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteCode(<?php echo $code['id']; ?>)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="text-end mt-3">
                    <button type="submit" name="delete_codes" class="btn btn-danger btn-sm" onclick="return confirmDelete()">
                        <i class="bi bi-trash"></i> Delete Selected
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Generate Codes Modal -->
<div class="modal fade" id="generateCodesModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title">Generate Voting Codes</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    
                    <div class="mb-4">
                        <label class="form-label">Election</label>
                        <select class="form-select" name="election_id" required>
                            <option value="">Select Election</option>
                            <?php foreach ($elections as $election): ?>
                                <option value="<?php echo $election['id']; ?>">
                                    <?php echo htmlspecialchars($election['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label">Number of Codes</label>
                        <input type="number" class="form-control" name="quantity" min="1" max="100" value="10" required>
                        <div class="form-text">Maximum 100 codes can be generated at a time</div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-lg"></i> Generate Codes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.stat-card {
    transition: transform 0.2s;
}
.stat-card:hover {
    transform: translateY(-5px);
}
.stat-icon {
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.table > :not(caption) > * > * {
    padding: 1rem;
}
code {
    font-size: 0.9rem;
    color: #666;
}
</style>

<script>
document.querySelector('.select-all').addEventListener('change', function() {
    document.querySelectorAll('.code-checkbox').forEach(checkbox => {
        checkbox.checked = this.checked;
    });
});

function filterCodes(electionId) {
    if (electionId) {
        window.location.href = `voting_codes.php?election_id=${electionId}`;
    } else {
        window.location.href = 'voting_codes.php';
    }
}

function confirmDelete() {
    const selectedCodes = document.querySelectorAll('.code-checkbox:checked');
    if (selectedCodes.length === 0) {
        alert('Please select at least one voting code to delete.');
        return false;
    }
    return confirm('Are you sure you want to delete the selected voting codes?');
}
</script>

<?php require_once "includes/footer.php"; ?> 