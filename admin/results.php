<?php
require_once "includes/header.php";

// Get all elections
$elections_sql = "SELECT * FROM elections ORDER BY created_at DESC";
$elections_result = $conn->query($elections_sql);

// Get selected election or default to the most recent one
$selected_election_id = isset($_GET['election_id']) ? (int)$_GET['election_id'] : null;
if (!$selected_election_id) {
    $first_election = $elections_result->fetch(PDO::FETCH_ASSOC);
    $selected_election_id = $first_election ? $first_election['id'] : null;
}

// Get positions for the selected election
$positions_sql = "SELECT * FROM positions WHERE election_id = ? ORDER BY title";
$positions_stmt = $conn->prepare($positions_sql);
$positions_stmt->execute([$selected_election_id]);
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
$stats_stmt->execute([$selected_election_id]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Election Results</h2>
    <div class="d-flex gap-2">
        <select class="form-select" id="electionSelect" onchange="window.location.href='results.php?election_id=' + this.value">
            <?php 
            $elections_result->execute();
            while ($election = $elections_result->fetch(PDO::FETCH_ASSOC)): 
            ?>
                <option value="<?php echo $election['id']; ?>" <?php echo $election['id'] == $selected_election_id ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($election['title']); ?>
                </option>
            <?php endwhile; ?>
        </select>
    </div>
</div>

<?php if ($selected_election_id): ?>
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
        // Get votes for this position
        $votes_sql = "SELECT c.name, COUNT(v.id) as vote_count 
                      FROM candidates c 
                      LEFT JOIN votes v ON c.id = v.candidate_id 
                      WHERE c.position_id = ? 
                      GROUP BY c.id 
                      ORDER BY vote_count DESC";
        $votes_stmt = $conn->prepare($votes_sql);
        $votes_stmt->execute([$position['id']]);
        $votes = $votes_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate total votes for this position
        $total_votes = array_sum(array_column($votes, 'vote_count'));
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
                            <?php foreach ($votes as $vote): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($vote['name']); ?></td>
                                    <td><?php echo $vote['vote_count']; ?></td>
                                    <td>
                                        <?php 
                                        $percentage = $total_votes > 0 ? 
                                            round(($vote['vote_count'] / $total_votes) * 100, 1) : 0;
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
<?php else: ?>
    <div class="alert alert-info">
        No elections found. Please create an election first.
    </div>
<?php endif; ?>

<?php require_once "includes/footer.php"; ?> 