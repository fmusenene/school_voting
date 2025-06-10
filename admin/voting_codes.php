<?php
session_start();
require_once "../config/database.php";
// NOTE: Includes header.php *after* data fetching and PHP logic
// require_once "includes/header.php"; // Moved down

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: /school_voting/admin/login.php");
    exit();
}

// Function to generate unique voting codes
function generateVotingCode() {
    global $conn;
    $attempts = 0;
    $max_attempts = 100; // Prevent infinite loop
    do {
        $code = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $check_sql  = "SELECT code FROM voting_codes WHERE code = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param('s', $code);
        $check_stmt->execute();
        $check_stmt->store_result();
        $exists = $check_stmt->num_rows > 0;
        $check_stmt->close();
        $attempts++;
    } while ($exists && $attempts < $max_attempts);
    if ($attempts >= $max_attempts) {
        error_log("Unable to generate unique code after $max_attempts attempts");
        return false;
    }
    return $code;
}

$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']); // Clear flash messages

// Handle form submission (delete/create) — unchanged
// … [your existing POST handlers here] …

// --- Data Fetching ---
$fetch_error   = null;
$elections     = [];
$voting_codes  = [];
$stats         = ['total_codes'=>0,'used_codes'=>0,'unused_codes'=>0];
$total_records = $total_pages = $start_record = $end_record = 0;

try {
    // Elections dropdown
    $res = $conn->query("SELECT id, title FROM elections ORDER BY title ASC");
    $elections = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $res && $res->free();

    // Filters & Pagination settings — unchanged
    // … [your existing filter + pagination code here] …

    // Fetch codes
    // … [your existing voting_codes_sql + fetch_all here] …

    // Stats
    // … [your existing stats SQL + fetch_assoc here] …

} catch (Exception $e) {
    error_log("Voting Codes Page Error: ".$e->getMessage());
    $fetch_error = "Could not load voting codes data due to a database issue.";
}

require_once "includes/header.php";
$nonce = $_SESSION['csp_nonce'] ?? '';
?>
<style nonce="<?php echo htmlspecialchars($nonce,ENT_QUOTES); ?>">
/* ... your existing CSS ... */

/* --- NEW: Toggle Buttons --- */
.view-toggle {
  margin-bottom: 1rem;
}
.view-toggle .btn {
  margin-right: .5rem;
}
/* Hide sections by default */
#tableView { display: none; }
#cardView  { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px,1fr)); gap: 1rem; }
/* Print: force 6 per row */
@media print {
  #tableView { display: none !important; }
  #cardView  {
    display: grid !important;
    grid-template-columns: repeat(6, 1fr) !important;
    gap: .5rem !important;
  }
  .card { page-break-inside: avoid; }
}
</style>

<div class="container-fluid">
  <!-- Alerts -->
  <?php if($success):?><div class="alert alert-success"><?php echo htmlspecialchars($success);?></div><?php endif;?>
  <?php if($error  ):?><div class="alert alert-danger"><?php echo htmlspecialchars($error);?></div><?php endif;?>
  <?php if($fetch_error):?><div class="alert alert-danger"><?php echo htmlspecialchars($fetch_error);?></div><?php endif;?>

  <!-- Stats Cards (unchanged) -->
  <div class="row g-3 mb-4">
    <!-- … your existing 3 stat cards … -->
  </div>

  <!-- Controls + View Toggle -->
  <div class="card controls-card mb-4">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between">
      <div class="d-flex gap-2">
        <!-- Existing filters -->
        <select id="electionFilter" class="form-select form-select-sm">…</select>
        <div class="input-group input-group-sm">…</div>
        <select id="itemsPerPageSelect" class="form-select form-select-sm">…</select>
      </div>
      <div class="d-flex align-items-center gap-2">
        <!-- View toggle -->
        <div class="view-toggle">
          <button id="showCardsBtn" class="btn btn-outline-primary btn-sm">Card View</button>
          <button id="showTableBtn" class="btn btn-outline-primary btn-sm">Table View</button>
        </div>
        <!-- Generate & Export buttons (unchanged) -->
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#generateCodesModal">Generate</button>
        <button class="btn btn-sm btn-outline-secondary" onclick="printCodes()" title="Print"><i class="bi bi-printer"></i></button>
        <div class="dropdown">
          <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-download"></i></button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><button class="dropdown-item" onclick="exportToPDF()"><i class="bi bi-file-pdf me-2"></i>PDF</button></li>
            <li><button class="dropdown-item" onclick="exportToExcel()"><i class="bi bi-file-excel me-2"></i>Excel</button></li>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <!-- CARD GRID VIEW -->
  <div id="cardView">
    <?php foreach($voting_codes as $code): ?>
      <div class="card p-2 text-center">
        <div class="mb-2"><code><?php echo htmlspecialchars($code['code']);?></code></div>
        <?php if($code['is_used']): ?>
          <span class="badge bg-danger">Used</span>
        <?php else: ?>
          <span class="badge bg-success">Available</span>
        <?php endif;?>
      </div>
    <?php endforeach;?>
    <?php if(empty($voting_codes)): ?>
      <div class="text-center text-muted py-5 col-12">No codes to display.</div>
    <?php endif;?>
  </div>

  <!-- TABLE VIEW (unchanged) -->
  <div id="tableView">
    <div class="card table-card">
      <div class="card-header"><i class="bi bi-table me-2"></i>Voting Codes List</div>
      <div class="card-body p-0">
        <form method="post" id="codesForm">
          <div class="table-responsive">
            <table class="table table-hover align-middle voting-codes-table">
              <thead class="table-light">…</thead>
              <tbody>
                <?php if(empty($voting_codes)): ?>
                  <tr class="no-codes"><td colspan="6">No voting codes generated yet.</td></tr>
                <?php else: foreach($voting_codes as $code): ?>
                  <tr>
                    <td><input type="checkbox" …></td>
                    <td><code><?php echo htmlspecialchars($code['code']);?></code></td>
                    <td><?php echo htmlspecialchars($code['election_title']?:'N/A');?></td>
                    <td>
                      <?php if($code['is_used']):?>
                        <span class="badge bg-danger">Used</span>
                      <?php else:?>
                        <span class="badge bg-info">Available</span>
                      <?php endif;?>
                    </td>
                    <td><?php echo $code['is_used'] && $code['used_at']
                      ? date("M j, Y H:i",strtotime($code['used_at']))
                      : '--';?></td>
                    <td>
                      <button class="btn btn-outline-danger btn-sm delete-code-btn" …><i class="bi bi-trash3-fill"></i></button>
                    </td>
                  </tr>
                <?php endforeach; endif;?>
              </tbody>
            </table>
          </div>
          <?php if(count($voting_codes)): ?>
            <div class="card-footer bg-light d-flex justify-content-between align-items-center">
              <button type="submit" name="delete_codes" class="btn btn-danger btn-sm" disabled id="deleteSelectedBtn">
                <i class="bi bi-trash-fill me-1"></i>Delete Selected (<span id="selectedCount">0</span>)
              </button>
              <div class="pagination-info">Showing <?php echo $start_record;?>–<?php echo $end_record;?> of <?php echo number_format($total_records);?></div>
            </div>
          <?php endif;?>
        </form>
        <!-- pagination nav unchanged -->
      </div>
    </div>
  </div>

</div>

<!-- modals (generate/delete) unchanged -->
<?php require_once "includes/footer.php"; ?>

<script nonce="<?php echo htmlspecialchars($nonce,ENT_QUOTES); ?>">
document.addEventListener('DOMContentLoaded',function(){
  const cardView = document.getElementById('cardView'),
        tableView= document.getElementById('tableView'),
        btnCards = document.getElementById('showCardsBtn'),
        btnTable = document.getElementById('showTableBtn');
  // Default = cards
  cardView.style.display = 'grid';
  tableView.style.display= 'none';
  btnCards.classList.add('active');

  btnCards.addEventListener('click', ()=>{
    cardView.style.display='grid';
    tableView.style.display='none';
    btnCards.classList.add('active');
    btnTable.classList.remove('active');
  });
  btnTable.addEventListener('click', ()=>{
    cardView.style.display='none';
    tableView.style.display='block';
    btnTable.classList.add('active');
    btnCards.classList.remove('active');
  });

  // reuse your existing JS handlers for checkboxes, delete, print, export...
  // …
});
</script>
