<?php
session_start();
require_once "../config/database.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: /school_voting/admin/login.php");
    exit();
}

if (!isset($_GET['id'])) {
    $_SESSION['error'] = "No election specified for viewing results";
    header("Location: elections.php");
    exit();
}

$election_id = (int)$_GET['id'];

try {
// Get election details
    $sql = "SELECT * FROM elections WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$election_id]);
    $election = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$election) {
        $_SESSION['error'] = "Election not found";
        header("Location: elections.php");
        exit();
    }

    // Get total voters (using voting_code_id instead of voter_id)
    $sql = "SELECT COUNT(DISTINCT v.voting_code_id) as total_voters 
            FROM votes v 
            WHERE v.election_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$election_id]);
    $voters = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_voters = $voters['total_voters'];

    // Get total votes cast
    $sql = "SELECT COUNT(*) as total_votes FROM votes WHERE election_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$election_id]);
    $votes = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_votes = $votes['total_votes'];

    // Get positions with their candidates and votes
    $sql = "SELECT p.id, p.title as position_title, 
                   c.id as candidate_id, c.name as candidate_name,
                   COUNT(v.id) as vote_count
            FROM positions p
            LEFT JOIN candidates c ON c.position_id = p.id
            LEFT JOIN votes v ON v.candidate_id = c.id AND v.election_id = ?
            WHERE p.election_id = ?
            GROUP BY p.id, c.id
            ORDER BY p.title, vote_count DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$election_id, $election_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Organize results by position
    $positions = [];
    foreach ($results as $row) {
        if (!isset($positions[$row['id']])) {
            $positions[$row['id']] = [
                'title' => $row['position_title'],
                'candidates' => []
            ];
        }
        if ($row['candidate_id']) {
            $positions[$row['id']]['candidates'][] = [
                'name' => $row['candidate_name'],
                'votes' => $row['vote_count']
            ];
        }
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Error fetching results: " . $e->getMessage();
    header("Location: elections.php");
    exit();
}

require_once "includes/header.php";
?>

<style>
.page-header {
    background: linear-gradient(135deg, #6B73FF 0%, #000DFF 100%);
    color: white;
    padding: 2rem;
    border-radius: 10px;
    margin-bottom: 2rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.stats-card {
    background: white;
    border-radius: 10px;
    padding: 1.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    text-align: center;
}

.stats-number {
    font-size: 2.5rem;
    font-weight: 600;
    color: #1890ff;
    margin-bottom: 0.5rem;
}

.stats-label {
    color: #6c757d;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.position-card {
    background: white;
    border-radius: 10px;
    padding: 1.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 1.5rem;
}

.position-title {
    color: #2c3e50;
    font-size: 1.25rem;
    font-weight: 600;
    margin-bottom: 1.5rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #e9ecef;
}

.candidate-row {
    display: flex;
    align-items: center;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 0.5rem;
    background: #f8f9fa;
}

.candidate-photo {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 1rem;
}

.candidate-name {
    flex-grow: 1;
    font-weight: 500;
}

.vote-count {
    background: #e9ecef;
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-weight: 600;
    color: #2c3e50;
}

.progress {
    height: 8px;
    margin-top: 0.5rem;
}

.btn-back {
    background: #6c757d;
    color: white;
    border: none;
    padding: 0.6rem 2rem;
    border-radius: 6px;
    font-weight: 500;
    text-decoration: none;
}

.btn-back:hover {
    background: #5a6268;
    color: white;
}
</style>

<div class="container-fluid py-4">
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h1 class="fs-2 mb-0"><?php echo htmlspecialchars($election['title']); ?> Results</h1>
            <p class="mb-0 mt-2 text-white-50">
                <?php echo date('F j, Y', strtotime($election['start_date'])); ?> - 
                <?php echo date('F j, Y', strtotime($election['end_date'])); ?>
            </p>
        </div>
        <a href="elections.php" class="btn btn-back">
            <i class="bi bi-arrow-left"></i> Back to Elections
        </a>
</div>

    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="stats-card">
                <div class="stats-number"><?php echo $total_voters; ?></div>
                <div class="stats-label">Total Voters</div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="stats-card">
                <div class="stats-number"><?php echo $total_votes; ?></div>
                <div class="stats-label">Total Votes Cast</div>
        </div>
    </div>
</div>

<?php foreach ($positions as $position): ?>
        <div class="position-card">
            <h3 class="position-title"><?php echo htmlspecialchars($position['title']); ?></h3>
            
    <?php
            // Sort candidates by vote count in descending order
            usort($position['candidates'], function($a, $b) {
                return $b['votes'] - $a['votes'];
            });
            
            // Find maximum votes for percentage calculation
            $max_votes = 0;
            foreach ($position['candidates'] as $candidate) {
                $max_votes = max($max_votes, $candidate['votes']);
            }
            
            foreach ($position['candidates'] as $candidate): 
                $percentage = $max_votes > 0 ? ($candidate['votes'] / $max_votes) * 100 : 0;
            ?>
                <div class="candidate-row">
                    <div class="candidate-name"><?php echo htmlspecialchars($candidate['name']); ?></div>
                    <div class="vote-count"><?php echo $candidate['votes']; ?> votes</div>
                </div>
                <div class="progress">
                    <div class="progress-bar bg-primary" role="progressbar" 
                         style="width: <?php echo $percentage; ?>%" 
                         aria-valuenow="<?php echo $percentage; ?>" 
                         aria-valuemin="0" 
                         aria-valuemax="100"></div>
        </div>
                        <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    </div>

<?php require_once "includes/footer.php"; ?> 