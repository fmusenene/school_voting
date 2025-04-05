<?php
session_start();
require_once "../config/database.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: /school_voting/admin/login.php");
    exit();
}

// Get election ID from URL
$election_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get election details
$election_sql = "SELECT * FROM elections WHERE id = ?";
$stmt = $conn->prepare($election_sql);
$stmt->execute([$election_id]);
$election = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$election) {
    $_SESSION['error'] = "Election not found.";
    header("Location: elections.php");
    exit();
}

require_once "includes/header.php";
?>

<style>
.results-card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 1.5rem;
    overflow: hidden;
}

.results-header {
    background: #f8f9fa;
    padding: 1rem;
    border-bottom: 1px solid #dee2e6;
}

.results-header h3 {
    margin: 0;
    color: #2c3e50;
    font-size: 1.2rem;
}

.results-body {
    padding: 1.5rem;
}

.candidate-card {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
    transition: transform 0.2s;
}

.candidate-card:hover {
    transform: translateY(-2px);
}

.candidate-info {
    display: flex;
    align-items: center;
    margin-bottom: 0.5rem;
}

.candidate-photo {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 1rem;
}

.candidate-name {
    font-size: 1.1rem;
    font-weight: 500;
    color: #2c3e50;
    margin: 0;
}

.candidate-votes {
    font-size: 1.5rem;
    font-weight: 600;
    color: #1890ff;
    margin: 0.5rem 0;
}

.progress {
    height: 8px;
    background-color: #e9ecef;
    border-radius: 4px;
    overflow: hidden;
}

.progress-bar {
    background-color: #1890ff;
    transition: width 0.3s ease;
}

.winner-badge {
    background: #e3fcef;
    color: #00a854;
    padding: 0.25rem 0.75rem;
    border-radius: 50px;
    font-size: 0.85rem;
    font-weight: 500;
    margin-left: 0.5rem;
}

.back-button {
    background: #f8f9fa;
    color: #2c3e50;
    border: none;
    padding: 0.5rem 1rem;
    border-radius: 5px;
    font-weight: 500;
    transition: background 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    margin-bottom: 1rem;
}

.back-button:hover {
    background: #e9ecef;
    color: #2c3e50;
}

.back-button i {
    margin-right: 0.5rem;
}
</style>

<div class="container-fluid py-4">
    <a href="elections.php" class="back-button">
        <i class="bi bi-arrow-left"></i> Back to Elections
    </a>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0 text-gray-800"><?php echo htmlspecialchars($election['title']); ?> Results</h1>
        <div>
            <span class="badge bg-<?php echo $election['status'] === 'active' ? 'success' : ($election['status'] === 'pending' ? 'warning' : 'secondary'); ?>">
                <?php echo ucfirst($election['status']); ?>
            </span>
        </div>
    </div>

    <?php
    // Get positions for this election
    $positions_sql = "SELECT * FROM positions WHERE election_id = ? ORDER BY title";
    $stmt = $conn->prepare($positions_sql);
    $stmt->execute([$election_id]);
    $positions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($positions as $position) {
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
        $max_votes = max(array_column($candidates, 'vote_count'));
        $winners = array_filter($candidates, function($c) use ($max_votes) {
            return $c['vote_count'] == $max_votes;
        });
        ?>
        
        <div class="results-card">
            <div class="results-header">
                <h3><?php echo htmlspecialchars($position['title']); ?></h3>
            </div>
            <div class="results-body">
                <?php if (count($candidates) > 0): ?>
                    <?php foreach ($candidates as $candidate): ?>
                        <div class="candidate-card">
                            <div class="candidate-info">
                                <?php if (!empty($candidate['photo'])): ?>
                                    <img src="../<?php echo htmlspecialchars($candidate['photo']); ?>" 
                                         alt="<?php echo htmlspecialchars($candidate['name']); ?>"
                                         class="candidate-photo">
                                <?php else: ?>
                                    <div class="candidate-photo bg-secondary text-white d-flex align-items-center justify-content-center">
                                        <i class="bi bi-person"></i>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <h4 class="candidate-name">
                                        <?php echo htmlspecialchars($candidate['name']); ?>
                                        <?php if (in_array($candidate, $winners)): ?>
                                            <span class="winner-badge">Winner</span>
                                        <?php endif; ?>
                                    </h4>
                                    <div class="candidate-votes">
                                        <?php echo $candidate['vote_count']; ?> votes
                                        <?php if ($total_votes > 0): ?>
                                            (<?php echo round(($candidate['vote_count'] / $total_votes) * 100); ?>%)
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($total_votes > 0): ?>
                                        <div class="progress">
                                            <div class="progress-bar" 
                                                 role="progressbar" 
                                                 style="width: <?php echo ($candidate['vote_count'] / $total_votes) * 100; ?>%">
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-people fs-2"></i>
                        <p class="mt-2">No candidates registered for this position</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php } ?>

    <?php if (empty($positions)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-calendar-x fs-2"></i>
            <p class="mt-2">No positions found for this election</p>
        </div>
    <?php endif; ?>
</div>

<?php require_once "includes/footer.php"; ?> 