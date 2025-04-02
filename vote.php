<?php
session_start();
require_once "config/database.php";

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in
if (!isset($_SESSION['voting_code'])) {
    header("Location: index.php");
    exit();
}

$error = '';
$success = '';

// Get election details
$election_id = $_SESSION['election_id'];
$stmt = $conn->prepare("SELECT * FROM elections WHERE id = ? AND status = 'active'");
$stmt->execute([$election_id]);
$election = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$election) {
    // If election is not active, clear session and redirect
    session_destroy();
    header("Location: index.php?error=expired");
    exit();
}

// Check if user has already voted
$stmt = $conn->prepare("SELECT is_used FROM voting_codes WHERE code = ?");
$stmt->execute([$_SESSION['voting_code']]);
$code_status = $stmt->fetch(PDO::FETCH_ASSOC);

if ($code_status['is_used']) {
    // If code is already used, clear session and redirect
    session_destroy();
    header("Location: index.php?error=already_voted");
    exit();
}

// Get positions and candidates
$stmt = $conn->prepare("
    SELECT p.*, c.id as candidate_id, c.name as candidate_name, c.photo as candidate_photo
    FROM positions p
    LEFT JOIN candidates c ON p.id = c.position_id
    WHERE p.election_id = ?
    ORDER BY p.id, c.name
");
$stmt->execute([$election_id]);
$positions = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!isset($positions[$row['id']])) {
        $positions[$row['id']] = [
            'id' => $row['id'],
            'title' => strtolower($row['title']),
            'candidates' => []
        ];
    }
    if ($row['candidate_id']) {
        $positions[$row['id']]['candidates'][] = [
            'id' => $row['candidate_id'],
            'name' => $row['candidate_name'],
            'photo' => $row['candidate_photo']
        ];
    }
}

if (empty($positions)) {
    $error = "No positions available for voting.";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $conn->beginTransaction();

        // Mark voting code as used
        $stmt = $conn->prepare("UPDATE voting_codes SET is_used = 1 WHERE code = ?");
        $stmt->execute([$_SESSION['voting_code']]);

        // Record votes
        $stmt = $conn->prepare("INSERT INTO votes (candidate_id, voting_code_id) VALUES (?, ?)");
        
        foreach ($_POST['votes'] as $position_id => $candidate_id) {
            if (!empty($candidate_id)) {
                $stmt->execute([$candidate_id, $_SESSION['voting_code_id']]);
            }
        }

        $conn->commit();
        
        // Clear session
        session_destroy();
        
        // Return success response for AJAX
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit();
        }
        
        // Fallback for non-AJAX
        header("Location: index.php");
        exit();
    } catch (Exception $e) {
        $conn->rollBack();
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit();
        }
        $error = "An error occurred while processing your vote. Please try again.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vote - <?php echo htmlspecialchars($election['title']); ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f8f9fa;
            margin: 0;
            padding: 20px;
        }
        .main-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .election-title {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
        }
        .logo-container {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 40px;
            margin-bottom: 20px;
        }
        .logo {
            width: 120px;
            height: 120px;
            object-fit: contain;
        }
        .divider {
            width: 2px;
            height: 120px;
            background: #ddd;
        }
        .election-title h2 {
            color: #333;
            font-size: 24px;
            margin: 20px 0 10px;
        }
        .election-title p {
            color: #666;
            margin-top: 5px;
        }
        .position-section {
            margin-bottom: 30px;
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .position-title {
            font-size: 16px;
            color: #333;
            margin-bottom: 20px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        .candidates-list {
            padding: 10px;
        }
        .candidate-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.3s ease;
            cursor: pointer;
            background: white;
            margin-bottom: 15px;
            width: 100%;
        }
        .candidate-card:hover {
            border-color: #28a745;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .candidate-card.selected {
            border-color: #28a745;
            background-color: #f8fff8;
        }
        .candidate-option {
            display: flex;
            align-items: center;
            padding: 15px;
            width: 100%;
        }
        .candidate-photo {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 15px;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .candidate-info {
            flex-grow: 1;
        }
        .candidate-name {
            font-size: 16px;
            color: #333;
            margin-bottom: 5px;
        }
        .candidate-details {
            font-size: 14px;
            color: #666;
        }
        .submit-button {
            background: #0d6efd;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            display: block;
            margin: 30px auto;
            transition: background-color 0.3s;
        }
        .submit-button:hover {
            background: #0b5ed7;
        }
        input[type="radio"] {
            margin-right: 10px;
        }
        /* Success Modal Styles */
        .success-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .success-content {
            background: white;
            padding: 40px;
            border-radius: 10px;
            text-align: center;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .success-icon {
            color: #28a745;
            font-size: 48px;
            margin-bottom: 20px;
        }
        .success-title {
            font-size: 24px;
            color: #333;
            margin-bottom: 15px;
        }
        .success-message {
            color: #666;
            margin-bottom: 0;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <!-- Add Success Modal -->
    <div class="success-modal" id="successModal">
        <div class="success-content">
            <div class="success-icon">✓</div>
            <h2 class="success-title">Thank You for Voting!</h2>
            <p class="success-message">Your vote has been successfully recorded. Thank you for participating in this election.</p>
        </div>
    </div>

    <div class="main-container">
        <div class="election-title">
            <div class="logo-container">
                <img src="assets/images/electoral-commission-logo.png" alt="Electoral Commission" class="logo">
                <div class="divider"></div>
                <img src="assets/images/gombe-ss-logo.png" alt="Gombe S.S." class="logo">
            </div>
            <h2><?php echo htmlspecialchars($election['title']); ?></h2>
            <p>Select your preferred candidates</p>
        </div>

        <form method="post" id="votingForm">
            <?php foreach ($positions as $position): ?>
                <div class="position-section">
                    <h3 class="position-title"><?php echo htmlspecialchars($position['title']); ?></h3>
                    <div class="candidates-list">
                        <?php foreach ($position['candidates'] as $candidate): ?>
                            <div class="candidate-card" onclick="selectCandidate(this)">
                                <label class="candidate-option">
                                    <input type="radio" name="votes[<?php echo $position['id']; ?>]" 
                                           value="<?php echo $candidate['id']; ?>" required>
                                    <?php if ($candidate['photo']): ?>
                                        <img src="<?php echo htmlspecialchars($candidate['photo']); ?>" 
                                             alt="" class="candidate-photo">
                                    <?php else: ?>
                                        <img src="assets/images/default-avatar.png" 
                                             alt="" class="candidate-photo">
                                    <?php endif; ?>
                                    <div class="candidate-info">
                                        <div class="candidate-name"><?php echo htmlspecialchars($candidate['name']); ?></div>
                                        <div class="candidate-details"><?php echo substr(md5($candidate['id']), 0, 8); ?></div>
                                    </div>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <button type="submit" class="submit-button">Submit Vote</button>
        </form>
    </div>

    <script>
        function selectCandidate(card) {
            // Remove selected class from all cards in this position section
            const positionSection = card.closest('.position-section');
            positionSection.querySelectorAll('.candidate-card').forEach(c => {
                c.classList.remove('selected');
            });
            
            // Add selected class to clicked card
            card.classList.add('selected');
            
            // Check the radio button
            const radio = card.querySelector('input[type="radio"]');
            radio.checked = true;
        }

        // Handle form submission with success message
        document.getElementById('votingForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            // Validate form
            const positions = document.querySelectorAll('.position-section');
            let isValid = true;
            
            positions.forEach(position => {
                const selected = position.querySelector('input[type="radio"]:checked');
                if (!selected) {
                    isValid = false;
                    position.style.border = '1px solid red';
                } else {
                    position.style.border = 'none';
                }
            });
            
            if (!isValid) {
                alert('Please select a candidate for each position.');
                return;
            }

            try {
                // Submit the form data
                const formData = new FormData(this);
                const response = await fetch('vote.php', {
                    method: 'POST',
                    body: formData
                });

                if (response.ok) {
                    // Show success modal
                    const modal = document.getElementById('successModal');
                    modal.style.display = 'flex';

                    // Wait 3 seconds and redirect
                    setTimeout(() => {
                        window.location.href = 'index.php';
                    }, 3000);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred while submitting your vote. Please try again.');
            }
        });
    </script>
</body>
</html> 