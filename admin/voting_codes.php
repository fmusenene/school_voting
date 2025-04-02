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
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $code;
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['delete_codes'])) {
        if (isset($_POST['selected_codes']) && is_array($_POST['selected_codes'])) {
            $selected_codes = array_map('intval', $_POST['selected_codes']);
            $placeholders = str_repeat('?,', count($selected_codes) - 1) . '?';
            
            // Delete the selected codes
            $delete_sql = "DELETE FROM voting_codes WHERE id IN ($placeholders)";
            $delete_stmt = $conn->prepare($delete_sql);
            if ($delete_stmt->execute($selected_codes)) {
                $success = "Selected voting codes deleted successfully!";
            } else {
                $error = "Error deleting voting codes";
            }
        }
    } else if (isset($_POST['action']) && $_POST['action'] === 'create') {
        $election_id = $_POST['election_id'];
        $quantity = (int)$_POST['quantity'];
        
        try {
            $conn->beginTransaction();
            
            // Generate voting codes
            $insert_sql = "INSERT INTO voting_codes (code, election_id) VALUES (?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            
            for ($i = 0; $i < $quantity; $i++) {
                $code = generateVotingCode();
                $insert_stmt->execute([$code, $election_id]);
            }
            
            $conn->commit();
            $success = "$quantity voting codes generated successfully!";
        } catch (PDOException $e) {
            $conn->rollBack();
            $error = "Error generating voting codes: " . $e->getMessage();
        }
    }
}

// Get all elections for dropdown
$elections_sql = "SELECT * FROM elections ORDER BY created_at DESC";
$elections_result = $conn->query($elections_sql);

// Get selected election or default to the most recent one
$selected_election_id = isset($_GET['election_id']) ? (int)$_GET['election_id'] : null;
if (!$selected_election_id) {
    $first_election = $elections_result->fetch(PDO::FETCH_ASSOC);
    $selected_election_id = $first_election ? $first_election['id'] : null;
}

// Get voting codes statistics
$stats_sql = "SELECT 
                COUNT(DISTINCT vc.id) as total_codes,
                COUNT(DISTINCT CASE WHEN vc.is_used = 1 THEN vc.id END) as used_codes,
                COUNT(DISTINCT CASE WHEN vc.is_used = 0 THEN vc.id END) as unused_codes
              FROM voting_codes vc
              " . ($selected_election_id ? "WHERE vc.election_id = ?" : "");
$stats_stmt = $conn->prepare($stats_sql);
if ($selected_election_id) {
    $stats_stmt->execute([$selected_election_id]);
} else {
    $stats_stmt->execute();
}
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get all voting codes with election titles
$codes_sql = "SELECT vc.*, e.title as election_title 
              FROM voting_codes vc 
              LEFT JOIN elections e ON vc.election_id = e.id 
              " . ($selected_election_id ? "WHERE vc.election_id = ?" : "") . "
              ORDER BY vc.id DESC";
$codes_stmt = $conn->prepare($codes_sql);
if ($selected_election_id) {
    $codes_stmt->execute([$selected_election_id]);
} else {
    $codes_stmt->execute();
}
$codes_result = $codes_stmt;
?>

<div class="container-fluid">
    <!-- Header Section -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">Manage Voting Codes</h2>
            <p class="text-muted mb-0">Generate and manage voting codes for elections</p>
        </div>
        <div class="d-flex gap-2">
            <select class="form-select" id="electionSelect" onchange="window.location.href='voting_codes.php' + (this.value ? '?election_id=' + this.value : '')">
                <option value="">All Elections</option>
                <?php 
                $elections_result->execute();
                while ($election = $elections_result->fetch(PDO::FETCH_ASSOC)): 
                ?>
                    <option value="<?php echo $election['id']; ?>" <?php echo $election['id'] == $selected_election_id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($election['title']); ?>
                    </option>
                <?php endwhile; ?>
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
                            <p class="card-text display-6 mb-0"><?php echo $stats['total_codes']; ?></p>
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
                            <h6 class="card-title text-muted mb-1">Used Codes</h6>
                            <p class="card-text display-6 mb-0"><?php echo $stats['used_codes']; ?></p>
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
                            <h6 class="card-title text-muted mb-1">Unused Codes</h6>
                            <p class="card-text display-6 mb-0"><?php echo $stats['unused_codes']; ?></p>
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
                <button type="submit" name="delete_codes" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete the selected voting codes?')">
                    <i class="bi bi-trash"></i> Delete Selected
                </button>
            </div>
        </div>
        <div class="card-body">
            <form method="post">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th width="40"><input type="checkbox" class="form-check-input select-all"></th>
                                <th>Code</th>
                                <th>Election</th>
                                <th>Status</th>
                                <th>Created At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $codes_result->fetch(PDO::FETCH_ASSOC)): ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_codes[]" value="<?php echo $row['id']; ?>" class="form-check-input code-checkbox"></td>
                                    <td><code class="bg-light px-2 py-1 rounded"><?php echo htmlspecialchars($row['code']); ?></code></td>
                                    <td><?php echo htmlspecialchars($row['election_title']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $row['is_used'] ? 'success' : 'warning'; ?>">
                                            <?php echo $row['is_used'] ? 'Used' : 'Unused'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y H:i', strtotime($row['created_at'])); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
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
                            <?php 
                            $elections_result->execute();
                            while ($election = $elections_result->fetch(PDO::FETCH_ASSOC)): 
                            ?>
                                <option value="<?php echo $election['id']; ?>">
                                    <?php echo htmlspecialchars($election['title']); ?>
                                </option>
                            <?php endwhile; ?>
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
</script>

<?php require_once "includes/footer.php"; ?> 