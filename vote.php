<?php
session_start();
require_once "config/database.php";

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if student is logged in
if (!isset($_SESSION['voting_code_id'])) {
    header("location: index.php");
    exit();
}

$error = '';
$success = '';

// Get election details
$election_sql = "SELECT * FROM elections WHERE id = ?";
$election_stmt = mysqli_prepare($conn, $election_sql);
mysqli_stmt_bind_param($election_stmt, "i", $_SESSION['election_id']);
mysqli_stmt_execute($election_stmt);
$election = mysqli_fetch_assoc(mysqli_stmt_get_result($election_stmt));

if (!$election) {
    header("location: index.php");
    exit();
}

// Check if election is still active
$current_datetime = new DateTime();
$start_datetime = new DateTime($election['start_date']);
$end_datetime = new DateTime($election['end_date']);

// Check if election is still valid
if ($election['status'] !== 'active') {
    $error = "This election is not currently active.";
} else if ($current_datetime < $start_datetime) {
    $error = "This election has not started yet.";
} else if ($current_datetime > $end_datetime) {
    // Update election status to completed if end date has passed
    $update_sql = "UPDATE elections SET status = 'completed' WHERE id = " . $election['id'];
    mysqli_query($conn, $update_sql);
    $error = "This election has ended.";
}

// If there's an error, redirect to index with appropriate message
if ($error) {
    session_destroy();
    header("location: index.php?error=expired");
    exit();
}

// Get positions and candidates
$positions_sql = "SELECT * FROM positions WHERE election_id = ? ORDER BY title";
$positions_stmt = mysqli_prepare($conn, $positions_sql);
mysqli_stmt_bind_param($positions_stmt, "i", $_SESSION['election_id']);
mysqli_stmt_execute($positions_stmt);
$positions_result = mysqli_stmt_get_result($positions_stmt);

$positions = [];
while ($position = mysqli_fetch_assoc($positions_result)) {
    // Get candidates for this position
    $candidates_sql = "SELECT * FROM candidates WHERE position_id = ? ORDER BY name";
    $candidates_stmt = mysqli_prepare($conn, $candidates_sql);
    mysqli_stmt_bind_param($candidates_stmt, "i", $position['id']);
    mysqli_stmt_execute($candidates_stmt);
    $position['candidates'] = mysqli_fetch_all(mysqli_stmt_get_result($candidates_stmt), MYSQLI_ASSOC);
    $positions[] = $position;
}

if (empty($positions)) {
    $error = "No positions available for voting.";
}

// Handle vote submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $success = true;
    $error = null;

    // Start transaction
    mysqli_begin_transaction($conn);

    try {
        // First, mark the voting code as used
        $update_code_sql = "UPDATE voting_codes SET is_used = 1, used_at = NOW() WHERE id = ?";
        $update_code_stmt = mysqli_prepare($conn, $update_code_sql);
        mysqli_stmt_bind_param($update_code_stmt, "i", $_SESSION['voting_code_id']);
        
        if (!mysqli_stmt_execute($update_code_stmt)) {
            throw new Exception("Error marking voting code as used: " . mysqli_error($conn));
        }

        // Insert votes for each position
        foreach ($_POST['votes'] as $position_id => $candidate_id) {
            $sql = "INSERT INTO votes (voting_code_id, election_id, position_id, candidate_id) VALUES (?, ?, ?, ?)";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "iiii", $_SESSION['voting_code_id'], $_SESSION['election_id'], $position_id, $candidate_id);
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Error recording vote: " . mysqli_error($conn));
            }
        }

        // Commit transaction
        mysqli_commit($conn);
        
        // Clear session
        session_destroy();
        
        // Return JSON response
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit();
        
    } catch (Exception $e) {
        // Rollback transaction on error
        mysqli_rollback($conn);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vote - <?php echo htmlspecialchars($election['title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .voting-container {
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .school-logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .school-logo i {
            font-size: 48px;
            color: #343a40;
        }
        .position-card {
            margin-bottom: 20px;
        }
        .candidate-option {
            padding: 15px;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
        }
        .candidate-option:hover {
            border-color: #198754;
            background-color: #f8f9fa;
        }
        .candidate-option.selected {
            border-color: #198754;
            background-color: #e8f5e9;
        }
        .candidate-image {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin-right: 20px;
            object-fit: cover;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .no-image {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin-right: 20px;
            background-color: #dee2e6;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            font-size: 32px;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .candidate-info {
            flex-grow: 1;
        }
        .candidate-name {
            font-size: 1.2rem;
            font-weight: 500;
            margin-bottom: 5px;
        }
        .candidate-description {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .modal-content {
            border: none;
            border-radius: 15px;
        }
        .modal-body {
            padding: 2rem;
        }
        .bi-check-circle-fill {
            animation: scaleIn 0.5s ease-out;
        }
        @keyframes scaleIn {
            0% {
                transform: scale(0);
                opacity: 0;
            }
            100% {
                transform: scale(1);
                opacity: 1;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="voting-container">
            <div class="school-logo">
                <i class="bi bi-building"></i>
            </div>
            <h2 class="text-center mb-4"><?php echo htmlspecialchars($election['title']); ?></h2>
            <p class="text-center mb-4"><?php echo htmlspecialchars($election['description']); ?></p>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?php echo $success; ?>
                    <div class="mt-3">
                        <a href="index.php" class="btn btn-primary">Return to Home</a>
                    </div>
                </div>
            <?php else: ?>
                <?php if (!empty($positions)): ?>
                    <form method="post" action="" id="votingForm">
                        <?php foreach ($positions as $position): ?>
                            <div class="card position-card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0"><?php echo htmlspecialchars($position['title']); ?></h5>
                                </div>
                                <div class="card-body">
                                    <?php foreach ($position['candidates'] as $candidate): ?>
                                        <div class="candidate-option" data-candidate-id="<?php echo $candidate['id']; ?>">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" 
                                                       name="votes[<?php echo $position['id']; ?>]" 
                                                       value="<?php echo $candidate['id']; ?>" 
                                                       id="candidate<?php echo $candidate['id']; ?>" required>
                                            </div>
                                            <?php if (!empty($candidate['image_path'])): ?>
                                                <img src="<?php echo htmlspecialchars($candidate['image_path']); ?>" 
                                                     alt="<?php echo htmlspecialchars($candidate['name']); ?>" 
                                                     class="candidate-image">
                                            <?php else: ?>
                                                <div class="no-image">
                                                    <i class="bi bi-person"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div class="candidate-info">
                                                <div class="candidate-name">
                                                    <?php echo htmlspecialchars($candidate['name']); ?>
                                                </div>
                                                <?php if (!empty($candidate['description'])): ?>
                                                    <div class="candidate-description">
                                                        <?php echo htmlspecialchars($candidate['description']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <div class="text-center">
                            <button type="submit" class="btn btn-primary btn-lg">Submit Vote</button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="alert alert-warning">
                        No positions are available for voting at this time.
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle form submission
        document.getElementById('votingForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Submitting...';
            
            // Submit the form
            fetch(this.action, {
                method: 'POST',
                body: new FormData(this)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success modal
                    const successModal = new bootstrap.Modal(document.getElementById('successModal'));
                    successModal.show();
                    
                    // Redirect after 2 seconds
                    setTimeout(() => {
                        window.location.href = 'index.php';
                    }, 2000);
                } else {
                    alert(data.message || 'Error submitting vote. Please try again.');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error submitting vote. Please try again.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });

        // Add visual feedback for selected candidates
        document.querySelectorAll('.candidate-option').forEach(option => {
            option.addEventListener('click', function() {
                const radio = this.querySelector('input[type="radio"]');
                if (radio) {
                    radio.checked = true;
                    this.classList.add('selected');
                    
                    // Remove selected class from other options in the same position
                    const positionCard = this.closest('.position-card');
                    positionCard.querySelectorAll('.candidate-option').forEach(otherOption => {
                        if (otherOption !== this) {
                            otherOption.classList.remove('selected');
                        }
                    });
                }
            });
        });
    </script>

    <!-- Success Modal -->
    <div class="modal fade" id="successModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center p-5">
                    <div class="mb-4">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                    </div>
                    <h4 class="mb-3">Vote Submitted Successfully!</h4>
                    <p class="text-muted">Thank you for participating in the election.</p>
                    <p class="text-muted">Redirecting to home page...</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html> 