<?php
session_start();
require_once "../config/database.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: /school_voting/admin/login.php");
    exit();
}

require_once "includes/header.php";

// Get elections statistics
$elections_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
FROM elections";
$elections_result = $conn->query($elections_sql)->fetch(PDO::FETCH_ASSOC);

// Get total positions
$positions_sql = "SELECT COUNT(*) as total FROM positions";
$positions_total = $conn->query($positions_sql)->fetch(PDO::FETCH_ASSOC)['total'];

// Get total candidates
$candidates_sql = "SELECT COUNT(*) as total FROM candidates";
$candidates_total = $conn->query($candidates_sql)->fetch(PDO::FETCH_ASSOC)['total'];

// Get total voting codes used
$codes_sql = "SELECT COUNT(*) as total FROM voting_codes WHERE is_used = 1";
$codes_used = $conn->query($codes_sql)->fetch(PDO::FETCH_ASSOC)['total'];

// Get total voting codes
$total_codes_sql = "SELECT COUNT(*) as total FROM voting_codes";
$total_codes = $conn->query($total_codes_sql)->fetch(PDO::FETCH_ASSOC)['total'];

// Get recent voting activity
$recent_votes_sql = "SELECT 
    v.created_at as timestamp,
    e.title as election_title,
    p.title as position_title,
    c.name as candidate_name,
    vc.code as voting_code
FROM votes v
JOIN candidates c ON v.candidate_id = c.id
JOIN positions p ON c.position_id = p.id
JOIN elections e ON p.election_id = e.id
JOIN voting_codes vc ON v.voting_code_id = vc.id
ORDER BY v.created_at DESC
LIMIT 10";
$recent_votes = $conn->query($recent_votes_sql)->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
.dashboard-title {
    font-size: 2rem;
    font-weight: 500;
    color: #2c3e50;
    margin-bottom: 2rem;
}

.stats-card {
    background: white;
    border-radius: 10px;
    padding: 1rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: transform 0.2s;
}

.stats-card:hover {
    transform: translateY(-5px);
}

.stats-icon {
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    margin-bottom: 0.6rem;
}

.stats-number {
    font-size: 1.8rem;
    font-weight: 600;
    margin: 0.2rem 0;
    color: #2c3e50;
    line-height: 1;
}

.stats-label {
    color: #7f8c8d;
    font-size: 0.9rem;
    margin: 0;
    margin-bottom: 0.2rem;
}

.stats-detail {
    font-size: 0.8rem;
    color: #95a5a6;
    line-height: 1.1;
}

.activity-table {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-top: 2rem;
}

.activity-table th {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    color: #2c3e50;
    font-weight: 600;
    padding: 1rem;
}

.activity-table td {
    padding: 1rem;
    vertical-align: middle;
}

.activity-table tbody tr:hover {
    background-color: #f8f9fa;
}

.status-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 50px;
    font-size: 0.85rem;
    font-weight: 500;
}

.status-active {
    background-color: #e3fcef;
    color: #00a854;
}

.status-pending {
    background-color: #fff7e6;
    color: #fa8c16;
}

.status-completed {
    background-color: #f0f0f0;
    color: #595959;
}

.voting-code {
    font-family: monospace;
    background: #f8f9fa;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    color: #2c3e50;
}

.new-election-btn {
    background: #1890ff;
    color: white;
    border: none;
    padding: 0.5rem 1rem;
    border-radius: 5px;
    font-weight: 500;
    transition: background 0.2s;
}

.new-election-btn:hover {
    background: #096dd9;
    color: white;
    text-decoration: none;
}

.bi {
    font-size: 1rem;
}
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="dashboard-title">Dashboard Overview</h1>
        <a href="create_election.php" class="new-election-btn">
            <i class="bi bi-plus-circle"></i> New Election
        </a>
    </div>

    <div class="row g-4">
        <!-- Elections Card -->
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: #e3f2fd; color: #1976d2;">
                    <i class="bi bi-calendar2-check"></i>
                </div>
                <h3 class="stats-number"><?php echo $elections_result['total']; ?></h3>
                <p class="stats-label">Elections</p>
                <div class="stats-detail">
                    <span class="text-success"><?php echo $elections_result['active']; ?> Active</span> •
                    <span class="text-warning"><?php echo $elections_result['pending']; ?> Pending</span> •
                    <span class="text-secondary"><?php echo $elections_result['completed']; ?> Completed</span>
                </div>
            </div>
        </div>

        <!-- Positions Card -->
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: #e8f5e9; color: #388e3c;">
                    <i class="bi bi-person-badge"></i>
                </div>
                <h3 class="stats-number"><?php echo $positions_total; ?></h3>
                <p class="stats-label">Positions</p>
                <div class="stats-detail">Total registered positions</div>
            </div>
        </div>

        <!-- Candidates Card -->
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: #fff3e0; color: #f57c00;">
                    <i class="bi bi-people"></i>
                </div>
                <h3 class="stats-number"><?php echo $candidates_total; ?></h3>
                <p class="stats-label">Candidates</p>
                <div class="stats-detail">Total registered candidates</div>
            </div>
        </div>

        <!-- Votes Cast Card -->
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: #fce4ec; color: #c2185b;">
                    <i class="bi bi-check2-square"></i>
                </div>
                <h3 class="stats-number"><?php echo $codes_used; ?></h3>
                <p class="stats-label">Votes Cast</p>
                <div class="stats-detail">Out of <?php echo $total_codes; ?> voting codes</div>
            </div>
        </div>
    </div>

    <!-- Recent Voting Activity -->
    <div class="activity-table table-responsive mt-4">
        <h2 class="fs-4 p-3 mb-0">Recent Voting Activity</h2>
        <table class="table mb-0">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Election</th>
                    <th>Position</th>
                    <th>Candidate</th>
                    <th>Voting Code</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_votes as $vote): ?>
                    <tr>
                        <td><?php echo date('M d, Y H:i', strtotime($vote['timestamp'])); ?></td>
                        <td><?php echo htmlspecialchars($vote['election_title']); ?></td>
                        <td><?php echo htmlspecialchars($vote['position_title']); ?></td>
                        <td><?php echo htmlspecialchars($vote['candidate_name']); ?></td>
                        <td><span class="voting-code"><?php echo htmlspecialchars($vote['voting_code']); ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once "includes/footer.php"; ?> 