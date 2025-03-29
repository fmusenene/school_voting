<?php
require_once "includes/header.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("location: login.php");
    exit();
}

// Check if election ID is provided
if (!isset($_GET['id'])) {
    header("location: results.php");
    exit();
}

$election_id = (int)$_GET['id'];

// Get election details
$election_sql = "SELECT * FROM elections WHERE id = ?";
$election_stmt = $conn->prepare($election_sql);
$election_stmt->execute([$election_id]);
$election = $election_stmt->fetch(PDO::FETCH_ASSOC);

if (!$election) {
    header("location: results.php");
    exit();
}

// Get positions for this election
$positions_sql = "SELECT * FROM positions WHERE election_id = ? ORDER BY title";
$positions_stmt = $conn->prepare($positions_sql);
$positions_stmt->execute([$election_id]);
$positions = $positions_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get voting statistics
$stats_sql = "SELECT 
                COUNT(DISTINCT vc.id) as total_codes,
                COUNT(DISTINCT CASE WHEN vc.is_used = 1 THEN vc.id END) as used_codes,
                COUNT(DISTINCT v.id) as total_votes
              FROM voting_codes vc
              LEFT JOIN votes v ON vc.id = v.voting_code_id
              WHERE vc.election_id = ?";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->execute([$election_id]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get results for each position
$results = [];
foreach ($positions as $position) {
    $votes_sql = "SELECT c.name, COUNT(v.id) as vote_count 
                  FROM candidates c 
                  LEFT JOIN votes v ON c.id = v.candidate_id 
                  WHERE c.position_id = ? 
                  GROUP BY c.id 
                  ORDER BY vote_count DESC";
    $votes_stmt = $conn->prepare($votes_sql);
    $votes_stmt->execute([$position['id']]);
    $results[$position['id']] = $votes_stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><?php echo htmlspecialchars($election['title']); ?> - Results</h2>
    <div class="d-flex gap-2">
        <a href="results.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Back to Results
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <h5 class="card-title">Total Voting Codes</h5>
                <p class="card-text display-6"><?php echo $stats['total_codes']; ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <h5 class="card-title">Used Codes</h5>
                <p class="card-text display-6"><?php echo $stats['used_codes']; ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <h5 class="card-title">Total Votes</h5>
                <p class="card-text display-6"><?php echo $stats['total_votes']; ?></p>
            </div>
        </div>
    </div>
</div>

<?php foreach ($positions as $position): ?>
    <?php
    // Calculate total votes for this position
    $total_votes = array_sum(array_column($results[$position['id']], 'vote_count'));
    ?>
    
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><?php echo htmlspecialchars($position['title']); ?></h5>
            <small class="text-muted">Total Votes: <?php echo $total_votes; ?></small>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Candidate</th>
                            <th>Votes</th>
                            <th>Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results[$position['id']] as $result): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($result['name']); ?></td>
                                <td><?php echo $result['vote_count']; ?></td>
                                <td>
                                    <?php 
                                    $percentage = $total_votes > 0 ? 
                                        round(($result['vote_count'] / $total_votes) * 100, 1) : 0;
                                    echo $percentage . '%';
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php require_once "includes/footer.php"; ?> 