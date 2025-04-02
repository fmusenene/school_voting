<?php
session_start();
require_once "../config/database.php";
require_once "includes/header.php";

// Get all elections for the dropdown
$elections_sql = "SELECT * FROM elections ORDER BY title";
$elections = $conn->query($elections_sql)->fetchAll(PDO::FETCH_ASSOC);

// Get selected election ID from URL
$selected_election_id = isset($_GET['election_id']) ? (int)$_GET['election_id'] : null;

// Get positions with their candidates and votes
$positions_sql = "SELECT p.*, e.title as election_title,
    (SELECT COUNT(*) FROM votes v WHERE v.candidate_id IN (SELECT id FROM candidates WHERE position_id = p.id)) as total_votes
    FROM positions p
    INNER JOIN elections e ON p.election_id = e.id
    WHERE 1=1";

if ($selected_election_id) {
    $positions_sql .= " AND p.election_id = ?";
}

$positions_sql .= " ORDER BY e.title, p.title";

$stmt = $conn->prepare($positions_sql);
if ($selected_election_id) {
    $stmt->execute([$selected_election_id]);
} else {
    $stmt->execute();
}
$positions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get voting statistics
$stats_sql = "SELECT 
    COUNT(DISTINCT vc.id) as total_codes,
    COUNT(DISTINCT CASE WHEN vc.is_used = 1 THEN vc.id END) as used_codes,
    COUNT(DISTINCT v.id) as total_votes
FROM voting_codes vc
LEFT JOIN votes v ON vc.id = v.voting_code_id
WHERE 1=1";

if ($selected_election_id) {
    $stats_sql .= " AND vc.election_id = ?";
    $stats_stmt = $conn->prepare($stats_sql);
    $stats_stmt->execute([$selected_election_id]);
} else {
    $stats_stmt = $conn->prepare($stats_sql);
    $stats_stmt->execute();
}
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Add percentage calculations
$used_codes_percentage = $stats['total_codes'] > 0 ? 
    round(($stats['used_codes'] / $stats['total_codes']) * 100, 1) : 0;

$votes_per_code = $stats['used_codes'] > 0 ? 
    round($stats['total_votes'] / $stats['used_codes'], 1) : 0;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Election Results</h1>
    <div class="d-flex align-items-center">
        <select class="form-select me-2" id="electionSelect" onchange="filterResults(this.value)">
            <option value="">All Elections</option>
            <?php foreach ($elections as $election): ?>
                <option value="<?php echo $election['id']; ?>" <?php echo $selected_election_id == $election['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($election['title']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <!-- Total Voting Codes Card -->
    <div class="col-xl-4 col-md-6 mb-4">
        <div class="card border-left-primary shadow h-100 py-2 stats-card">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                            Total Voting Codes</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                            <?php echo number_format($stats['total_codes']); ?>
                        </div>
                        <div class="text-xs text-muted mt-1">
                            <?php echo $used_codes_percentage; ?>% used
                        </div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-qr-code fs-2 text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Used Codes Card -->
    <div class="col-xl-4 col-md-6 mb-4">
        <div class="card border-left-success shadow h-100 py-2 stats-card">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                            Used Codes</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                            <?php echo number_format($stats['used_codes']); ?>
                        </div>
                        <div class="text-xs text-muted mt-1">
                            <?php echo $votes_per_code; ?> votes per code avg.
                        </div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-check-circle fs-2 text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Total Votes Card -->
    <div class="col-xl-4 col-md-6 mb-4">
        <div class="card border-left-warning shadow h-100 py-2 stats-card">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                            Total Votes Cast</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                            <?php echo number_format($stats['total_votes']); ?>
                        </div>
                        <div class="text-xs text-muted mt-1">
                            across <?php echo count($positions); ?> position(s)
                        </div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-bar-chart fs-2 text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (empty($positions)): ?>
    <div class="text-center text-muted py-5">
        <i class="bi bi-calendar-x fs-2"></i>
        <p class="mt-2">No election results found</p>
    </div>
<?php else: ?>
    <?php
    // Group positions by election
    $elections = [];
    foreach ($positions as $position) {
        if (!isset($elections[$position['election_id']])) {
            $elections[$position['election_id']] = [
                'title' => $position['election_title'],
                'positions' => []
            ];
        }
        $elections[$position['election_id']]['positions'][] = $position;
    }
    ?>

    <?php foreach ($elections as $election): ?>
        <div class="results-card mb-4">
            <div class="results-header">
                <h3><?php echo htmlspecialchars($election['title']); ?></h3>
            </div>
            <div class="results-body">
                <?php foreach ($election['positions'] as $position): ?>
                    <div class="position-section">
                        <div class="position-header">
                            <h2 class="position-title"><?php echo htmlspecialchars($position['title']); ?></h2>
                            <div class="total-votes">Total Votes: <?php echo $position['total_votes']; ?></div>
                        </div>
                        <?php
                        // Get candidates for this position
                        $candidates_sql = "SELECT c.*, 
                            (SELECT COUNT(*) FROM votes WHERE candidate_id = c.id) as vote_count
                            FROM candidates c 
                            WHERE c.position_id = ? 
                            ORDER BY vote_count DESC";
                        $stmt = $conn->prepare($candidates_sql);
                        $stmt->execute([$position['id']]);
                        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        // Calculate total votes for this position
                        $total_votes = array_sum(array_column($candidates, 'vote_count'));

                        // Get winner(s)
                        $max_votes = $total_votes > 0 ? max(array_column($candidates, 'vote_count')) : 0;
                        ?>

                        <?php if (count($candidates) > 0): ?>
                            <ul class="candidates-list">
                                <?php 
                                // Get winner(s)
                                $winners = array_filter($candidates, function($c) use ($max_votes) {
                                    return $c['vote_count'] == $max_votes;
                                });
                                ?>
                                <?php foreach ($candidates as $candidate): 
                                    $is_winner = $candidate['vote_count'] == $max_votes;
                                ?>
                                    <li class="candidate-item <?php echo $is_winner ? 'winner' : ''; ?>">
                                        <?php if (!empty($candidate['photo'])): ?>
                                            <img src="../<?php echo htmlspecialchars($candidate['photo']); ?>" 
                                                 alt="<?php echo htmlspecialchars($candidate['name']); ?>"
                                                 class="candidate-photo">
                                        <?php else: ?>
                                            <div class="candidate-photo bg-secondary text-white d-flex align-items-center justify-content-center">
                                                <i class="bi bi-person"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div class="candidate-info">
                                            <h3 class="candidate-name">
                                                <?php echo htmlspecialchars($candidate['name']); ?>
                                                <?php if ($is_winner): ?>
                                                    <span class="winner-badge">Winner</span>
                                                <?php endif; ?>
                                            </h3>
                                            <div class="vote-info">
                                                <div class="vote-count"><?php echo $candidate['vote_count']; ?> votes</div>
                                                <?php if ($total_votes > 0): ?>
                                                    <div class="vote-percentage">(<?php echo round(($candidate['vote_count'] / $total_votes) * 100, 1); ?>%)</div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>

                            <?php if ($total_votes > 0): ?>
                                <?php foreach ($winners as $winner): ?>
                                    <div class="winner-announcement">
                                        <i class="bi bi-trophy-fill me-2"></i>
                                        Winner: <?php echo htmlspecialchars($winner['name']); ?> 
                                        with <?php echo $winner['vote_count']; ?> votes 
                                        (<?php echo round(($winner['vote_count'] / $total_votes) * 100, 1); ?>% of total votes)
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="no-candidates">
                                <i class="bi bi-people"></i>
                                <p>No candidates registered for this position</p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
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

/* Candidate card styles */
.candidate-card {
    transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
}

.candidate-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.candidate-card.winner {
    background-color: #e8f5e9 !important;
    border: 1px solid #4caf50;
}

.progress {
    background-color: #e9ecef;
    border-radius: 4px;
    overflow: hidden;
}

.progress-bar {
    transition: width 0.6s ease;
}

.badge {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
}

.bi-trophy-fill {
    color: #ffd700;
}

.position-section {
    background: white;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
    overflow: hidden;
}

.position-header {
    padding: 1rem;
    border-bottom: 1px solid #e9ecef;
    background: #f8f9fa;
}

.position-title {
    font-size: 1.25rem;
    margin: 0;
    color: #333;
}

.total-votes {
    color: #6c757d;
    font-size: 0.9rem;
    margin-top: 0.25rem;
}

.candidates-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.candidate-item {
    display: flex;
    align-items: center;
    padding: 1rem;
    border-bottom: 1px solid #e9ecef;
    transition: background-color 0.2s;
}

.candidate-item:last-child {
    border-bottom: none;
}

.candidate-item:hover {
    background-color: #f8f9fa;
}

.candidate-item.winner {
    background-color: #e3fcef;
    border-left: 4px solid #00a854;
}

.candidate-photo {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    margin-right: 1rem;
    object-fit: cover;
}

.candidate-info {
    flex: 1;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.candidate-name {
    font-size: 1rem;
    color: #333;
    margin: 0;
    display: flex;
    align-items: center;
}

.candidate-name .winner-badge {
    margin-left: 0.5rem;
    background: #e3fcef;
    color: #00a854;
    padding: 0.25rem 0.75rem;
    border-radius: 50px;
    font-size: 0.75rem;
}

.vote-info {
    text-align: right;
    color: #0066ff;
    font-weight: 500;
}

.vote-count {
    font-size: 1rem;
    margin: 0;
}

.vote-percentage {
    color: #6c757d;
    font-size: 0.875rem;
    margin: 0;
}

.no-candidates {
    text-align: center;
    padding: 2rem;
    color: #6c757d;
}

.no-candidates i {
    font-size: 2rem;
    margin-bottom: 0.5rem;
}

.winner-announcement {
    text-align: center;
    padding: 1.5rem;
    margin-top: 1rem;
    background: #e3fcef;
    border-radius: 8px;
    color: #00a854;
    font-weight: 500;
    animation: fadeIn 0.5s ease-out;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>

<script>
function filterResults(electionId) {
    if (electionId) {
        window.location.href = `results.php?election_id=${electionId}`;
    } else {
        window.location.href = 'results.php';
    }
}
</script>

<?php require_once "includes/footer.php"; ?> 