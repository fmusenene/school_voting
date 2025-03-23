<?php
session_start();
require_once "../config/database.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("location: login.php");
    exit();
}

// Get all elections for dropdown
$elections_sql = "SELECT * FROM elections ORDER BY title";
$elections_result = mysqli_query($conn, $elections_sql);

// Get selected election ID from URL or default to first election
$selected_election_id = isset($_GET['election_id']) ? (int)$_GET['election_id'] : 0;
if ($selected_election_id == 0 && mysqli_num_rows($elections_result) > 0) {
    $first_election = mysqli_fetch_assoc($elections_result);
    $selected_election_id = $first_election['id'];
    mysqli_data_seek($elections_result, 0);
}

// Get election details
$election_sql = "SELECT * FROM elections WHERE id = $selected_election_id";
$election_result = mysqli_query($conn, $election_sql);
$election = mysqli_fetch_assoc($election_result);

// Get positions for the selected election
$positions_sql = "SELECT * FROM positions WHERE election_id = $selected_election_id ORDER BY title";
$positions_result = mysqli_query($conn, $positions_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Election Results - School Voting System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .sidebar {
            min-height: 100vh;
            background: #343a40;
            color: white;
        }
        .nav-link {
            color: rgba(255,255,255,.75);
        }
        .nav-link:hover {
            color: white;
        }
        .nav-link.active {
            color: white;
        }
        .result-card {
            margin-bottom: 20px;
        }
        .progress {
            height: 25px;
            margin-bottom: 10px;
        }
        .progress-bar {
            line-height: 25px;
        }
        .candidate-bar {
            height: 80px;
            background-color: #e9ecef;
            border-radius: 5px;
            margin-bottom: 10px;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            padding: 10px;
            transition: all 0.3s ease;
        }
        .candidate-bar.winner {
            background-color: #e8f5e9;
            border: 2px solid #4caf50;
        }
        .winner-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: #4caf50;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        .candidate-fill {
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            background-color: #0d6efd;
            transition: width 0.5s ease-in-out;
            opacity: 0.2;
        }
        .candidate-info {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            width: 100%;
        }
        .candidate-image {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            margin-right: 15px;
            object-fit: cover;
            border: 2px solid #fff;
        }
        .candidate-details {
            flex-grow: 1;
        }
        .candidate-name {
            font-weight: bold;
            margin-bottom: 5px;
        }
        .candidate-percentage {
            color: #0d6efd;
            font-weight: bold;
        }
        .no-image {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            margin-right: 15px;
            background-color: #dee2e6;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            font-size: 24px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 px-0 position-fixed sidebar">
                <div class="p-3">
                    <h4>School Voting</h4>
                </div>
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="elections.php">
                            <i class="bi bi-calendar-check"></i> Elections
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="positions.php">
                            <i class="bi bi-person-badge"></i> Positions
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="candidates.php">
                            <i class="bi bi-people"></i> Candidates
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="voting_codes.php">
                            <i class="bi bi-key"></i> Voting Codes
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="results.php">
                            <i class="bi bi-graph-up"></i> Results
                        </a>
                    </li>
                    <li class="nav-item mt-3">
                        <a class="nav-link" href="logout.php">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Main content -->
            <div class="col-md-9 col-lg-10 ms-auto px-4 py-3">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Election Results</h2>
                    <div class="d-flex align-items-center">
                        <form method="GET" class="d-flex">
                            <select name="election_id" class="form-select me-2" onchange="this.form.submit()">
                                <?php 
                                mysqli_data_seek($elections_result, 0);
                                while ($election_row = mysqli_fetch_assoc($elections_result)): 
                                ?>
                                    <option value="<?php echo $election_row['id']; ?>" <?php echo $election_row['id'] == $selected_election_id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($election_row['title']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </form>
                    </div>
                </div>

                <?php if ($election): ?>
                    <div class="card mb-4">
                        <div class="card-body">
                            <h3><?php echo htmlspecialchars($election['title']); ?></h3>
                            <p class="text-muted"><?php echo htmlspecialchars($election['description']); ?></p>
                            <p>
                                <strong>Status:</strong> 
                                <span class="badge bg-<?php 
                                    echo $election['status'] == 'active' ? 'success' : 
                                        ($election['status'] == 'completed' ? 'secondary' : 'warning'); 
                                ?>">
                                    <?php echo ucfirst($election['status']); ?>
                                </span>
                            </p>
                            <p>
                                <strong>Period:</strong> 
                                <?php echo date('M d, Y', strtotime($election['start_date'])); ?> to 
                                <?php echo date('M d, Y', strtotime($election['end_date'])); ?>
                            </p>
                        </div>
                    </div>

                    <?php while ($position = mysqli_fetch_assoc($positions_result)): ?>
                        <?php
                        // Get candidates and their vote counts for this position
                        $candidates_sql = "SELECT c.*, COUNT(v.id) as vote_count 
                                         FROM candidates c 
                                         LEFT JOIN votes v ON c.id = v.candidate_id 
                                         WHERE c.position_id = {$position['id']} 
                                         GROUP BY c.id 
                                         ORDER BY vote_count DESC";
                        $candidates_result = mysqli_query($conn, $candidates_sql);
                        
                        // Get total votes for this position
                        $total_votes_sql = "SELECT COUNT(DISTINCT v.voting_code_id) as total_votes 
                                          FROM votes v 
                                          WHERE v.position_id = {$position['id']}";
                        $total_votes_result = mysqli_query($conn, $total_votes_sql);
                        $total_votes = mysqli_fetch_assoc($total_votes_result)['total_votes'];
                        ?>
                        
                        <div class="card result-card">
                            <div class="card-header">
                                <h4 class="mb-0"><?php echo htmlspecialchars($position['title']); ?></h4>
                                <small class="text-muted">Total Votes: <?php echo $total_votes; ?></small>
                            </div>
                            <div class="card-body">
                                <?php 
                                $candidates = array();
                                while ($candidate = mysqli_fetch_assoc($candidates_result)) {
                                    $candidates[] = $candidate;
                                }
                                
                                // Sort candidates by vote count
                                usort($candidates, function($a, $b) {
                                    return $b['vote_count'] - $a['vote_count'];
                                });
                                
                                // Get the highest vote count
                                $highest_votes = $candidates[0]['vote_count'] ?? 0;
                                
                                foreach ($candidates as $candidate): 
                                    $percentage = $total_votes > 0 ? ($candidate['vote_count'] / $total_votes) * 100 : 0;
                                    $is_winner = $candidate['vote_count'] == $highest_votes && $highest_votes > 0;
                                ?>
                                    <div class="candidate-bar <?php echo $is_winner ? 'winner' : ''; ?>">
                                        <?php if ($is_winner): ?>
                                            <div class="winner-badge">
                                                <i class="bi bi-trophy"></i> Winner
                                            </div>
                                        <?php endif; ?>
                                        <div class="candidate-fill" style="width: <?php echo $percentage; ?>%"></div>
                                        <div class="candidate-info">
                                            <?php if (!empty($candidate['image_path'])): ?>
                                                <img src="../<?php echo htmlspecialchars($candidate['image_path']); ?>" 
                                                     alt="<?php echo htmlspecialchars($candidate['name']); ?>" 
                                                     class="candidate-image">
                                            <?php else: ?>
                                                <div class="no-image">
                                                    <i class="bi bi-person"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div class="candidate-details">
                                                <div class="candidate-name">
                                                    <?php echo htmlspecialchars($candidate['name']); ?>
                                                </div>
                                                <?php if (!empty($candidate['description'])): ?>
                                                    <div class="candidate-description text-muted">
                                                        <?php echo htmlspecialchars($candidate['description']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="candidate-percentage">
                                                <?php echo $candidate['vote_count']; ?> votes (<?php echo number_format($percentage, 1); ?>%)
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php if ($highest_votes > 0): ?>
                                    <div class="alert alert-success mt-3">
                                        <i class="bi bi-trophy-fill"></i> 
                                        <strong>Winner:</strong> <?php echo htmlspecialchars($candidates[0]['name']); ?> 
                                        with <?php echo $highest_votes; ?> votes 
                                        (<?php echo number_format(($highest_votes / $total_votes) * 100, 1); ?>% of total votes)
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="alert alert-info">
                        No election selected. Please select an election from the dropdown above.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 