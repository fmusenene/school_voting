<?php declare(strict_types=1); // Enforce strict types

/**
 * Live Election Results Display Page
 *
 * Shows results for the latest active election in an auto-playing slideshow format,
 * designed for large screen displays. Fetches data periodically via AJAX.
 */

// --- Initialization & Config ---
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Error Reporting (Development vs Production)
// Dev settings:
error_reporting(E_ALL);
ini_set('display_errors', '1');
// Production settings:
// error_reporting(0);
// ini_set('display_errors', '0');
// ini_set('log_errors', '1'); // Log errors instead of displaying

// Set timezone consistently
date_default_timezone_set('Africa/Nairobi'); // EAT

// --- Generate CSP nonce ---
// Needs to be done before any output that uses it (style, script)
if (empty($_SESSION['csp_nonce'])) { $_SESSION['csp_nonce'] = base64_encode(random_bytes(16)); }
$nonce = htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8');

// Set page title
$pageTitle = "Live Election Results";

// No database operations needed on initial page load for this version,
// everything will be fetched via AJAX by the JavaScript below.

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="300"> <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">

    <?php /* Consider adding CSP meta tag here if not done via HTTP headers
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'nonce-<?php echo $nonce; ?>'; style-src 'self' 'nonce-<?php echo $nonce; ?>' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; img-src 'self' data:; connect-src 'self';">
    */ ?>

    <style nonce="<?php echo $nonce; ?>">
        :root {
            --primary-color: #0A4FAD; --primary-rgb: 10, 79, 173; --text-dark: #1a202c; --text-muted: #6c757d;
            --bg-light: #f4f7fc; --bg-white: #ffffff; --border-color: #e2e8f0;
            --winner-bg: #d1fae5; --winner-text: #065f46; --winner-border: #6ee7b7;
            --progress-bg: #e9ecef; --progress-bar-bg: #2563eb; --progress-bar-gradient: linear-gradient(90deg, rgba(37,99,235,1) 0%, rgba(59,130,246,1) 100%);
            --font-main: 'Roboto', sans-serif; --font-heading: 'Poppins', sans-serif;
            --shadow: 0 8px 25px rgba(0, 0, 0, 0.08); --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.06);
            --border-radius: 0.5rem; --border-radius-lg: 0.75rem;
            /* CSS Variable for slide interval duration (set by JS) */
            --slide-interval-duration: 8s;
            --slide-transition-duration: 0.7s;
        }
        html, body { height: 100%; margin: 0; overflow: hidden; font-family: var(--font-main); background-color: var(--bg-light); }
        .fullscreen-wrapper { height: 100%; width: 100%; display: flex; flex-direction: column; background-color: var(--bg-white); }

        /* Header */
        .live-header { padding: 1.25rem 2.5rem; background: var(--primary-color); color: white; text-align: center; box-shadow: 0 3px 6px rgba(0,0,0,.1); flex-shrink: 0; border-bottom: 3px solid hsl(217, 70%, 48%); }
        .live-header h1 { font-family: var(--font-heading); margin: 0; font-size: clamp(1.6rem, 3.5vw, 2.2rem); font-weight: 700; letter-spacing: 0.5px; }
        #electionTitle { display: inline-block; }

        /* Slideshow Container */
        .slideshow-container { flex-grow: 1; position: relative; overflow: hidden; padding: clamp(2rem, 5vw, 4rem); }
        .slide {
            position: absolute; inset: 0; padding: clamp(1.5rem, 4vw, 3rem); box-sizing: border-box;
            opacity: 0; transform: scale(0.95);
            transition: opacity var(--slide-transition-duration) ease-in-out, transform var(--slide-transition-duration) ease-in-out;
            visibility: hidden; display: flex; flex-direction: column; overflow-y: auto;
        }
        .slide.active { opacity: 1; transform: scale(1); z-index: 1; visibility: visible; }
        .slide.exiting { transition-duration: calc(var(--slide-transition-duration) * 0.8); opacity: 0; transform: scale(1.02); z-index: 0; visibility: hidden; } /* Subtle scale up on exit */

        /* Position Title within Slide */
        .slide-position-title { font-family: var(--font-heading); font-size: clamp(2rem, 5vw, 3rem); font-weight: 700; color: var(--primary-color); margin-bottom: 2.5rem; text-align: center; padding-bottom: 1rem; border-bottom: 3px solid rgba(var(--primary-rgb), 0.15); }

        /* Candidate Display Grid */
        .candidates-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 2rem; width: 100%; margin: 0 auto; max-width: 1600px; }
        .candidate-result-card { background-color: var(--bg-white); border: 1px solid var(--border-color); border-radius: var(--border-radius-lg); display: flex; flex-direction: column; box-shadow: var(--shadow-sm); padding: 1.5rem; transition: transform 0.2s ease, box-shadow 0.2s ease; border-left: 5px solid transparent; }
        .candidate-result-card:hover { transform: translateY(-5px); box-shadow: var(--shadow); }
        .candidate-result-card.is-winner { border-left-color: var(--winner-text); background-color: #f8fefc; }

        .candidate-result-info { display: flex; align-items: center; margin-bottom: 1rem; }
        .candidate-result-photo { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; margin-right: 1.5rem; border: 3px solid var(--border-color); flex-shrink: 0; background-color: #eee; box-shadow: var(--shadow-sm); }
        .candidate-result-details { flex-grow: 1; }
        .candidate-result-name { font-size: 1.5rem; font-weight: 700; color: var(--text-dark); margin: 0 0 0.3rem 0; display: flex; align-items: center; flex-wrap: wrap; gap: 0.5rem; }
        .candidate-result-votes { font-size: 2.2rem; font-weight: 700; color: var(--primary-color); line-height: 1.1; }
        .candidate-result-percentage { font-size: 1.1rem; color: var(--text-muted); margin-left: .75rem; font-weight: 500;}
        .candidate-winner-badge { font-size: .85rem; font-weight: 700; padding: .35em .9em; background-color: var(--winner-bg); color: var(--winner-text); border: 1px solid var(--winner-border); border-radius: 50px; white-space: nowrap; }
        .candidate-progress { height: 16px; background-color: var(--progress-bg); border-radius: 8px; overflow: hidden; margin-top: 0.75rem; }
        .candidate-progress-bar { height: 100%; background: var(--progress-bar-gradient); transition: width 0.6s cubic-bezier(0.25, 1, 0.5, 1); text-align: right; padding-right: 8px; color: white; font-size: 0.75rem; line-height: 16px; font-weight: 600; white-space: nowrap;}
        .candidate-progress-bar::after { content: attr(aria-valuenow) '%'; display: block; }

        /* Footer / Status Bar */
        .live-footer { padding: 0.75rem 1.5rem; background-color: blue; color: rgba(255,255,255,0.8); font-size: 0.9rem; flex-shrink: 0; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .footer-item { flex-shrink: 0; }
        #lastUpdated { font-size: 0.85rem; opacity: 0.8; }
        #slideIndicator { font-weight: 600; text-align: center; }
        #connectionStatus .badge { font-size: 0.85rem; font-weight: 600; padding: 0.4em 0.7em;}

        /* Slide Progress Bar Styles */
        .slide-progress-container { flex-grow: 1; height: 6px; background-color: rgba(255, 255, 255, 0.2); border-radius: 3px; overflow: hidden; min-width: 100px; order: 2; /* Order in flex */ }
        .slide-progress-bar { height: 100%; width: 0%; background-color: var(--bg-light); border-radius: 3px; transition: width 0.1s linear; /* Smooth reset */ }
        .slide-progress-bar.animate-progress { animation: slideProgress var(--slide-interval-duration, 8s) linear forwards; }
        @keyframes slideProgress { from { width: 0%; } to { width: 100%; } }

         /* Error/Loading State Overlay */
        .status-overlay { position: absolute; inset: 0; background-color: rgba(255,255,255,0.9); display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; z-index: 10; transition: opacity 0.3s ease-out; opacity: 1; visibility: visible;}
        .status-overlay.hidden { opacity: 0; pointer-events: none; visibility: hidden; }
        .status-overlay .spinner-border { width: 4.5rem; height: 4.5rem; color: var(--primary-color); border-width: .3em; }
        .status-overlay p { font-size: 1.3rem; color: var(--text-dark); margin-top: 1.5rem; font-weight: 600;}

        /* Fullscreen Button */
        #fullscreenBtn { position: fixed; bottom: 15px; right: 15px; z-index: 100; background-color: rgba(255,255,255,0.8); border: 1px solid var(--border-color); backdrop-filter: blur(2px); padding: 0.4rem 0.7rem; }
        #fullscreenBtn i { font-size: 1.2rem; }

        /* Responsive Adjustments */
         @media (max-width: 768px) {
             .live-footer { justify-content: center; text-align: center; }
             .footer-item { width: 100%; } /* Stack footer items */
             .slide-progress-container { order: -1; margin-bottom: 0.5rem; } /* Move progress top */
             .candidates-grid { grid-template-columns: 1fr; }
             .candidate-result-info { flex-direction: column; align-items: flex-start; }
             .candidate-result-photo { margin-right: 0; margin-bottom: 0.75rem; width: 80px; height: 80px;}
             .candidate-winner-badge { margin-left: 0.5rem;}
        }
         @media (max-width: 576px) {
             .live-header { padding: 0.8rem 1rem; } .live-header h1 { font-size: 1.4rem; }
             .slideshow-container, .slide { padding: 1rem; } .slide-position-title { font-size: 1.6rem; margin-bottom: 1.5rem;}
         }
    </style>
</head>
<body>
    <div class="fullscreen-wrapper">
        <header class="live-header">
            <h1>Live Results: <span id="electionTitle">Loading...</span></h1>
        </header>

        <main class="slideshow-container" id="slideshowContainer">
            <div class="status-overlay" id="statusOverlay">
                <div class="spinner-border" role="status"> <span class="visually-hidden">Loading...</span> </div>
                <p id="statusText">Loading initial results...</p>
            </div>
        </main>

        <footer class="live-footer">
            <div id="lastUpdated" class="footer-item">Updated: --:--:--</div>
            <div class="slide-progress-container footer-item">
                <div class="slide-progress-bar" id="slideProgressBar"></div>
            </div>
            <div id="slideIndicator" class="footer-item">Position 0 / 0</div>
            <div id="connectionStatus" class="footer-item" title="Connection Status"><span class="badge bg-warning text-dark">Connecting...</span></div>
        </footer>
    </div>

    <button id="fullscreenBtn" class="btn btn-light btn-sm shadow-sm" title="Toggle Fullscreen">
        <i class="bi bi-arrows-fullscreen fs-5"></i>
    </button>

    <script nonce="<?php echo $nonce; ?>" src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

    <script nonce="<?php echo $nonce; ?>">
        document.addEventListener('DOMContentLoaded', () => {

            // --- DOM References ---
            const slideshowContainer = document.getElementById('slideshowContainer');
            const electionTitleEl = document.getElementById('electionTitle');
            const slideIndicatorEl = document.getElementById('slideIndicator');
            const lastUpdatedEl = document.getElementById('lastUpdated');
            const statusOverlay = document.getElementById('statusOverlay');
            const statusText = document.getElementById('statusText');
            const fullscreenBtn = document.getElementById('fullscreenBtn');
            const connectionStatusEl = document.getElementById('connectionStatus');
            const progressBar = document.getElementById('slideProgressBar'); // Get progress bar

            // --- Configuration ---
            const AJAX_ENDPOINT = 'live_results_ajax.php'; // Ensure this path is correct
            const SLIDE_INTERVAL_MS = 8000; // 8 seconds per position
            const REFRESH_INTERVAL_MS = 60000; // Refresh data every 60 seconds
            const RETRY_DELAY_MS = 20000;

            // --- State ---
            let currentPositionIndex = -1;
            let positionsData = [];
            let slideshowIntervalId = null;
            let refreshIntervalId = null;
            let lastUpdateTime = null;

            // --- Set CSS Variable for Animation Duration ---
            const SLIDE_INTERVAL_SECONDS = SLIDE_INTERVAL_MS / 1000;
            document.documentElement.style.setProperty('--slide-interval-duration', `${SLIDE_INTERVAL_SECONDS}s`);

            // --- Helper Functions ---
            function escapeHtml(unsafe) { const div = document.createElement('div'); div.textContent = unsafe ?? ''; return div.innerHTML; }
            function numberFormat(number) { return new Intl.NumberFormat().format(number); }
            function updateConnectionStatus(isLive, message = '') { if (!connectionStatusEl) return; connectionStatusEl.innerHTML = isLive ? '<span class="badge bg-success">Live</span>' : `<span class="badge bg-danger" title="${escapeHtml(message)}">Offline</span>`; }
            function updateStatus(message, isLoading = false) { if (!statusOverlay || !statusText) return; statusOverlay.classList.remove('hidden'); statusText.textContent = message; const spinner = statusOverlay.querySelector('.spinner-border'); if (spinner) spinner.style.display = isLoading ? 'block' : 'none'; if (!isLoading) updateConnectionStatus(false, message); }
            function hideStatus() { if (statusOverlay) statusOverlay.classList.add('hidden'); }
            function getModalInstance(id) { const el = document.getElementById(id); return el ? bootstrap.Modal.getOrCreateInstance(el) : null; };


            // --- Helper: Restart Progress Bar Animation ---
            function restartProgressAnimation() {
                if (!progressBar) return;
                // console.log("Restarting progress animation");
                progressBar.classList.remove('animate-progress'); // Remove class
                progressBar.style.width = '0%'; // Explicitly reset width
                // Force reflow/repaint - essential for restarting CSS animation
                void progressBar.offsetWidth;
                // Re-add class to start animation from 0%
                progressBar.classList.add('animate-progress');
            }

            // --- Slide Rendering ---
            function renderSlide(positionIndex) {
                if (!slideshowContainer || !positionsData[positionIndex]) return null;

                const position = positionsData[positionIndex];
                let candidatesHtml = '';

                if (position.candidates && position.candidates.length > 0) {
                     position.candidates.forEach(candidate => {
                          // Construct image path relative to THIS file (live.php)
                          const photoPath = candidate.photo ? `../${escapeHtml(candidate.photo.replace(/^\//, ''))}` : 'assets/images/default-avatar.png';
                          const winnerBadge = candidate.is_winner && position.total_votes > 0 ? '<span class="candidate-winner-badge">WINNER</span>' : '';
                          const percentage = candidate.percentage ?? 0;
                          const voteText = candidate.vote_count === 1 ? 'vote' : 'votes';
                          // Ensure progress bar has a minimum width if votes > 0 but percentage is tiny
                          const progressBarWidth = (percentage < 1 && candidate.vote_count > 0) ? 1 : percentage;

                          candidatesHtml += `
                               <div class="candidate-result-card fade-in ${candidate.is_winner && position.total_votes > 0 ? 'is-winner' : ''}">
                                   <div class="row g-3 align-items-center">
                                       <div class="col-4 col-lg-3 text-center flex-shrink-0"> <img src="${photoPath}" alt="${escapeHtml(candidate.name)}" class="candidate-result-photo img-fluid" onerror="this.onerror=null; this.src='assets/images/default-avatar.png';">
                                       </div>
                                       <div class="col-8 col-lg-9"> <div class="candidate-result-details">
                                                 <h4 class="candidate-result-name mb-1">${escapeHtml(candidate.name)} ${winnerBadge}</h4>
                                                 <div class="d-flex align-items-baseline mb-2 flex-wrap">
                                                      <span class="candidate-result-votes">${numberFormat(candidate.vote_count)} ${voteText}</span>
                                                      ${position.total_votes > 0 ? `<span class="candidate-result-percentage">(${percentage}%)</span>` : ''}
                                                 </div>
                                                 ${position.total_votes > 0 ? `
                                                 <div class="candidate-progress progress" style="height: 16px;" title="${percentage}%">
                                                      <div class="candidate-progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: ${progressBarWidth}%" aria-valuenow="${percentage}" aria-valuemin="0" aria-valuemax="100">
                                                           ${percentage >= 15 ? percentage+'%' : ''} {/* Only show % if bar is wide enough */}
                                                      </div>
                                                 </div>` : ''}
                                            </div>
                                       </div>
                                   </div>
                               </div>`;
                     });
                } else { candidatesHtml = '<p class="text-center text-muted mt-4 fs-5"><i>No candidates found for this position.</i></p>'; }

                 const slideDiv = document.createElement('div');
                 slideDiv.className = 'slide';
                 slideDiv.id = `slide-pos-${position.id}`;
                 slideDiv.setAttribute('aria-hidden', 'true');
                 slideDiv.innerHTML = `
                    <h2 class="slide-position-title">${escapeHtml(position.title)}</h2>
                    <div class="candidates-grid">${candidatesHtml}</div>
                    <p class="text-center text-muted mt-4 fw-bold fs-5">Total Votes: ${numberFormat(position.total_votes)}</p>
                 `;
                 return slideDiv;
            }

            // --- Slideshow Control ---
            function showSlide(slideIndex) {
                 console.log(`Attempting to show slide index: ${slideIndex}`);
                 if (slideIndex < 0 || slideIndex >= positionsData.length || !slideshowContainer) return;

                 const newSlideId = `slide-pos-${positionsData[slideIndex].id}`;
                 const newSlide = document.getElementById(newSlideId);
                 const currentActive = slideshowContainer.querySelector('.slide.active');

                 if (!newSlide) { console.error(`Slide element with ID ${newSlideId} not found!`); return; }
                 if (currentActive === newSlide && !currentActive.classList.contains('exiting')) { console.log("Slide already active."); return; } // Prevent re-activating instantly

                 // Mark current for exit
                 if (currentActive) {
                     currentActive.classList.remove('active');
                     currentActive.classList.add('exiting');
                     currentActive.setAttribute('aria-hidden', 'true');
                 }

                 // Activate new slide
                 requestAnimationFrame(() => { // Use rAF for smoother transitions
                      newSlide.classList.remove('exiting'); // Clean up if it was exiting previously
                      newSlide.classList.add('active');
                      newSlide.setAttribute('aria-hidden', 'false');
                      // Restart the progress bar AFTER the new slide starts becoming visible
                      restartProgressAnimation();
                 });

                 // Clean up exiting class from OLD slide after transition duration
                 if (currentActive) {
                      setTimeout(() => {
                           currentActive?.classList.remove('exiting');
                       }, SLIDE_INTERVAL_MS * 0.8); // Cleanup slightly before next transition
                 }

                 // Update indicator text
                 if (slideIndicatorEl) slideIndicatorEl.textContent = `Position ${slideIndex + 1} / ${positionsData.length}`;
             }


            async function showNextOrRefresh() {
                 console.log("showNextOrRefresh called - Current Index:", currentPositionIndex);
                 if (positionsData.length === 0) {
                      console.log("No positions data, calling fetchResults...");
                      stopSlideshow(); await fetchResults(); return;
                 }

                 currentPositionIndex++;
                 console.log(`Index incremented to: ${currentPositionIndex}`);

                 if (currentPositionIndex >= positionsData.length) {
                      console.log("End of slides reached. Triggering data refresh...");
                      currentPositionIndex = -1; // Reset index, fetch will handle showing slide 0
                      stopSlideshow(); // Stop interval while fetching
                      await fetchResults(); // Fetch new data (starts slideshow again on success)
                 } else {
                      console.log(`Showing slide for index ${currentPositionIndex}`);
                      showSlide(currentPositionIndex); // Show the next slide
                 }
             }

            function startSlideshow() {
                stopSlideshow();
                if (positionsData.length > 0) {
                     console.log(`Starting slideshow interval (${SLIDE_INTERVAL_MS}ms)`);
                     // Show first slide immediately if needed (e.g., after refresh)
                     if (currentPositionIndex === -1) {
                          currentPositionIndex = 0;
                          showSlide(currentPositionIndex); // This now calls restartProgressAnimation
                     } else {
                         // If resuming, restart animation for the current slide
                         restartProgressAnimation();
                     }
                    slideshowIntervalId = setInterval(showNextOrRefresh, SLIDE_INTERVAL_MS);
                } else { console.log("No position data, slideshow not started."); }
            }
            function stopSlideshow() { if (slideshowIntervalId) { console.log("Stopping slideshow interval."); clearInterval(slideshowIntervalId); slideshowIntervalId = null; if(progressBar) progressBar.classList.remove('animate-progress'); /* Stop animation */ } }
            function startRefreshTimer() { stopRefreshTimer(); console.log(`Starting refresh timer (${REFRESH_INTERVAL_MS}ms)`); refreshIntervalId = setInterval(fetchResults, REFRESH_INTERVAL_MS); }
            function stopRefreshTimer() { if (refreshIntervalId) { console.log("Stopping refresh timer."); clearInterval(refreshIntervalId); refreshIntervalId = null; } }


            // --- Fetch Results from Server ---
            async function fetchResults() {
                console.log("Fetching results...");
                stopSlideshow(); // Pause slideshow during fetch
                updateStatus('Fetching latest results...', true);

                try {
                    const response = await fetch(AJAX_ENDPOINT, { method: 'POST', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'} });
                    const result = await response.json();
                    if (!response.ok || !result.success) throw new Error(result.message || `HTTP error ${response.status}`);

                    console.log("Results fetched successfully.");
                    updateConnectionStatus(true);

                    const data = result.data;
                    if (data?.election) {
                        if (electionTitleEl) electionTitleEl.textContent = data.election.title || 'Election Results';
                        positionsData = data.positions || [];
                        lastUpdateTime = new Date();
                        if(lastUpdatedEl) lastUpdatedEl.textContent = `Updated: ${lastUpdateTime.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}`;

                        // --- Render Slides ---
                        slideshowContainer.innerHTML = ''; // Clear previous slides AND overlay
                        if (positionsData.length > 0) {
                             positionsData.forEach((pos, index) => { const slideElement = renderSlide(index); if(slideElement) slideshowContainer.appendChild(slideElement); });
                             // Append overlay *after* slides if needed, or manage visibility
                             // slideshowContainer.appendChild(statusOverlay); // Re-append if needed
                             hideStatus(); // Hide overlay only if successful
                             currentPositionIndex = -1; // Reset index for the new data cycle
                             startSlideshow(); // Start the slideshow which will show slide 0
                        } else { updateStatus('No positions or candidates found for the active election.', false); stopSlideshow(); }
                    } else { updateStatus('No active election found.', false); positionsData = []; stopSlideshow();}

                } catch (error) {
                    console.error("Fetch results error:", error);
                    updateStatus(`Error loading results: ${error.message}. Retrying...`, false);
                    updateConnectionStatus(false);
                    stopSlideshow();
                    // Schedule a retry
                    setTimeout(fetchResults, RETRY_DELAY_MS);
                }
            }

            // --- Fullscreen ---
            function toggleFullScreen() { if (!document.fullscreenElement) { document.documentElement.requestFullscreen().catch(err => console.error(`Fullscreen error: ${err.message} (${err.name})`)); } else { if (document.exitFullscreen) { document.exitFullscreen(); } } }
            fullscreenBtn?.addEventListener('click', toggleFullScreen);
            document.addEventListener('fullscreenchange', () => { const icon = fullscreenBtn?.querySelector('i'); if (icon) { icon.className = document.fullscreenElement ? 'bi bi-fullscreen-exit fs-5' : 'bi bi-arrows-fullscreen fs-5'; } });
            // Attempt fullscreen on load? Consider adding a button instead.
            // setTimeout(toggleFullScreen, 2000); // Attempt after 2s delay


            // --- Initial Load ---
            fetchResults(); // Fetch data immediately on load
            startRefreshTimer(); // Start the periodic data refresh

        }); // End DOMContentLoaded
    </script>
</body>
</html>
<?php
// Final connection close
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $conn->close();
}
?>