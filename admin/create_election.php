<?php
session_start();
require_once "../config/database.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: /school_voting/admin/login.php");
    exit();
}

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    
    if (empty($title)) {
        $error = "Title is required";
    } elseif (empty($start_date)) {
        $error = "Start date is required";
    } elseif (empty($end_date)) {
        $error = "End date is required";
    } elseif (strtotime($end_date) <= strtotime($start_date)) {
        $error = "End date must be after start date";
    } else {
        try {
            $sql = "INSERT INTO elections (title, description, start_date, end_date, status) 
                    VALUES (?, ?, ?, ?, 'pending')";
            $stmt = $conn->prepare($sql);
            
            if ($stmt->execute([$title, $description, $start_date, $end_date])) {
                $success = "Election created successfully!";
                // Clear form data after successful submission
                $title = $description = $start_date = $end_date = '';
            } else {
                $error = "Error creating election";
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
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

.form-card {
    background: white;
    border-radius: 10px;
    padding: 2rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.form-label {
    font-weight: 500;
    color: #2c3e50;
    margin-bottom: 0.5rem;
}

.form-control {
    border-radius: 5px;
    border: 1px solid #ced4da;
    padding: 0.6rem;
}

.form-control:focus {
    border-color: #6B73FF;
    box-shadow: 0 0 0 0.2rem rgba(107, 115, 255, 0.25);
}

.btn-primary {
    background: #6B73FF;
    border: none;
    padding: 0.8rem 2rem;
    font-weight: 500;
}

.btn-primary:hover {
    background: #5158CC;
}

.btn-secondary {
    background: #6c757d;
    border: none;
    padding: 0.8rem 2rem;
    font-weight: 500;
}

.btn-secondary:hover {
    background: #5a6268;
}
</style>

<div class="container-fluid py-4">
    <div class="page-header">
        <h1 class="fs-2 mb-0">Create New Election</h1>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>

    <div class="form-card">
        <form method="POST" action="">
            <div class="mb-3">
                <label for="title" class="form-label">Election Title</label>
                <input type="text" class="form-control" id="title" name="title" 
                       value="<?php echo isset($title) ? htmlspecialchars($title) : ''; ?>" 
                       required>
            </div>

            <div class="mb-3">
                <label for="description" class="form-label">Description</label>
                <textarea class="form-control" id="description" name="description" 
                          rows="3"><?php echo isset($description) ? htmlspecialchars($description) : ''; ?></textarea>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="start_date" class="form-label">Start Date & Time</label>
                    <input type="datetime-local" class="form-control" id="start_date" 
                           name="start_date" 
                           value="<?php echo isset($start_date) ? $start_date : ''; ?>" 
                           required>
                </div>

                <div class="col-md-6 mb-3">
                    <label for="end_date" class="form-label">End Date & Time</label>
                    <input type="datetime-local" class="form-control" id="end_date" 
                           name="end_date" 
                           value="<?php echo isset($end_date) ? $end_date : ''; ?>" 
                           required>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-2"></i>Create Election
                </button>
                <a href="index.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>
        </form>
    </div>
</div>

<?php require_once "includes/footer.php"; ?> 