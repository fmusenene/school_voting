<?php
session_start();
require_once "../config/database.php";
require_once "includes/header.php";

// Get all elections
$elections_sql = "SELECT * FROM elections ORDER BY created_at DESC";
$elections_result = $conn->query($elections_sql);
$elections = $elections_result->fetchAll(PDO::FETCH_ASSOC);

// Get selected election or default to the most recent one
$selected_election_id = isset($_GET['election_id']) ? (int)$_GET['election_id'] : null;
if (!$selected_election_id && !empty($elections)) {
    $selected_election_id = $elections[0]['id'];
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
            <?php foreach ($elections as $election): ?>
                <option value="<?php echo $election['id']; ?>" <?php echo $election['id'] == $selected_election_id ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($election['title']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<?php if ($selected_election_id): ?>
    <!-- Voting Statistics -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Total Voting Codes</h5>
                    <p class="card-text display-4"><?php echo $stats['total_codes']; ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Used Codes</h5>
                    <p class="card-text display-4"><?php echo $stats['used_codes']; ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Total Votes</h5>
                    <p class="card-text display-4"><?php echo $stats['total_votes']; ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Results by Position -->
    <?php foreach ($positions as $position): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h4><?php echo htmlspecialchars($position['title']); ?></h4>
            </div>
            <div class="card-body">
                <?php
                // Get votes for this position
                $votes_sql = "SELECT c.name, COUNT(v.id) as vote_count
                             FROM candidates c
                             LEFT JOIN votes v ON c.id = v.candidate_id
                             WHERE c.position_id = ?
                             GROUP BY c.id, c.name
                             ORDER BY vote_count DESC";
                $votes_stmt = $conn->prepare($votes_sql);
                $votes_stmt->execute([$position['id']]);
                $votes = $votes_stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                
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
                                        $percentage = $stats['total_votes'] > 0 
                                            ? round(($vote['vote_count'] / $stats['total_votes']) * 100, 2) 
                                            : 0;
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

<style>
/* Card hover effects */
.card {
    transition: all 0.3s ease-in-out;
    cursor: pointer;
}

.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
}

/* Stats card specific hover effects */
.stats-card:hover .text-gray-300 {
    transform: scale(1.1);
    transition: transform 0.3s ease-in-out;
}

.stats-card:hover .text-primary {
    color: #2e59d9 !important;
}

.stats-card:hover .text-success {
    color: #169b6b !important;
}

.stats-card:hover .text-warning {
    color: #d4a106 !important;
}

/* Election card specific hover effects */
.election-card:hover .card-header {
    background-color: #f8f9fc;
}

.election-card:hover .position-card {
    transform: translateX(5px);
    transition: transform 0.3s ease-in-out;
}

.election-card:hover .candidate-card {
    transform: translateX(5px);
    transition: transform 0.3s ease-in-out;
}

/* Progress bar hover effect */
.progress {
    transition: all 0.3s ease-in-out;
}

.progress:hover {
    height: 1.5rem;
}

/* Vote count hover effect */
.vote-count {
    transition: all 0.3s ease-in-out;
}

.candidate-card:hover .vote-count {
    transform: scale(1.1);
    color: #4e73df;
}

/* Winner badge hover effect */
.winner-badge {
    transition: all 0.3s ease-in-out;
}

.candidate-card:hover .winner-badge {
    transform: scale(1.1);
}

/* Card border colors */
.border-left-primary {
    border-left: 4px solid #4e73df !important;
}
.border-left-success {
    border-left: 4px solid #1cc88a !important;
}
.border-left-warning {
    border-left: 4px solid #f6c23e !important;
}

/* Text colors */
.text-gray-300 {
    color: #dddfeb !important;
}
.text-gray-800 {
    color: #5a5c69 !important;
}
</style>

<?php require_once "includes/footer.php"; ?> 