<?php declare(strict_types=1); // Enforce strict types

/**
 * Live Election Results Display Page
 *
 * Shows results for the latest active election in an auto-playing slideshow format,
 * designed for large screen displays. Fetches data periodically via AJAX.
 */

// --- Initialization & Config ---
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Dev settings:
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Set timezone consistently
date_default_timezone_set('Africa/Nairobi'); // EAT

// --- Generate CSP nonce ---
if (empty($_SESSION['csp_nonce'])) { $_SESSION['csp_nonce'] = base64_encode(random_bytes(16)); }
$nonce = htmlspecialchars($_SESSION['csp_nonce'], ENT_QUOTES, 'UTF-8');

// Set page title
$pageTitle = "Live Election Results";

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">

    <style nonce="<?php echo $nonce; ?>">
        :root {
            --primary-color: #0A4FAD; --primary-rgb: 10, 79, 173; --text-dark: #1a202c; --text-muted: #6c757d;
            --bg-light: #f4f7fc; --bg-white: #ffffff; --border-color: #e2e8f0;
            --winner-bg: #dcfce7; --winner-text: #166534; --winner-border: #4ade80;
            --progress-bg: #e9ecef; --progress-bar-bg: #2563eb; --progress-bar-gradient: linear-gradient(90deg, rgba(37,99,235,1) 0%, rgba(59,130,246,1) 100%);
            --font-main: 'Roboto', sans-serif; --font-heading: 'Poppins', sans-serif;
            --shadow: 0 8px 25px rgba(0, 0, 0, 0.08); --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.06);
            --border-radius: 0.5rem; --border-radius-lg: 0.75rem;
            /* DYNAMIC CSS Variables set by JS */
            --slide-interval-duration: 8s;
            --slide-transition-duration: 0.7s;
        }
        html, body { height: 100%; margin: 0; overflow: hidden; font-family: var(--font-main); background-color: var(--bg-light); }
        .fullscreen-wrapper { height: 100%; width: 100%; display: flex; flex-direction: column; background-color: var(--bg-white); }

        /* Header */
        .live-header { padding: 1rem 2rem; background: var(--primary-color); color: white; text-align: center; box-shadow: 0 3px 6px rgba(0,0,0,.1); flex-shrink: 0; border-bottom: 4px solid hsl(217, 85%, 43%); }
        .live-header h1 { font-family: var(--font-heading); margin: 0; font-size: clamp(1.5rem, 3.5vw, 2.1rem); font-weight: 700; }
        #electionTitle { display: inline-block; }

        /* Slideshow Container */
        .slideshow-container { flex-grow: 1; position: relative; overflow: hidden; padding: clamp(1.5rem, 4vw, 3rem); }
        .slide {
            position: absolute; inset: 0; padding: clamp(1rem, 3vw, 2.5rem); box-sizing: border-box;
            opacity: 0; transform: scale(0.98) translateY(10px);
            transition: opacity var(--slide-transition-duration) ease-in-out, transform var(--slide-transition-duration) ease-in-out;
            visibility: hidden; display: flex; flex-direction: column; overflow-y: auto;
        }
        .slide.active { opacity: 1; transform: scale(1) translateY(0); z-index: 1; visibility: visible; }
        .slide.exiting { transition-duration: calc(var(--slide-transition-duration) * 0.8); opacity: 0; transform: scale(1.01); z-index: 0; visibility: hidden; }

        /* Position Title within Slide */
        .slide-position-title { font-family: var(--font-heading); font-size: clamp(1.8rem, 4.5vw, 2.8rem); font-weight: 700; color: var(--primary-color); margin-bottom: 2rem; text-align: center; padding-bottom: 1rem; border-bottom: 3px solid rgba(var(--primary-rgb), 0.15); }

        /* Candidate Display Grid */
        .candidates-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 1.75rem; width: 100%; margin: 0 auto; max-width: 1800px; }
        
        @keyframes fadeInCard { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .candidate-result-card { 
            background-color: var(--bg-white); border: 1px solid var(--border-color); border-radius: var(--border-radius-lg); 
            display: flex; flex-direction: column; box-shadow: var(--shadow-sm); padding: 1.5rem; transition: transform 0.2s ease, box-shadow 0.2s ease; 
            border-left: 5px solid transparent;
            opacity: 0; animation: fadeInCard 0.5s ease-out forwards;
        }
        /* Staggered animation for candidate cards */
        .slide.active .candidate-result-card:nth-child(2) { animation-delay: 0.1s; }
        .slide.active .candidate-result-card:nth-child(3) { animation-delay: 0.2s; }
        .slide.active .candidate-result-card:nth-child(4) { animation-delay: 0.3s; }
        .slide.active .candidate-result-card:nth-child(n+5) { animation-delay: 0.4s; }
        
        .candidate-result-card:hover { transform: translateY(-5px); box-shadow: var(--shadow); }
        .candidate-result-card.is-winner { 
            border-left-color: var(--winner-text); background-color: var(--winner-bg); 
            box-shadow: 0 0 25px -5px rgba(var(--winner-border), 0.6);
        }

        .candidate-result-info { display: flex; align-items: center; margin-bottom: 1rem; }
        .candidate-result-photo { width: 90px; height: 90px; border-radius: 50%; object-fit: cover; margin-right: 1.5rem; border: 3px solid var(--border-color); flex-shrink: 0; background-color: #eee; box-shadow: var(--shadow-sm); }
        .candidate-result-details { flex-grow: 1; }
        .candidate-result-name { font-size: 1.4rem; font-weight: 700; color: var(--text-dark); margin: 0 0 0.3rem 0; display: flex; align-items: center; flex-wrap: wrap; gap: 0.5rem; }
        .candidate-result-votes { font-size: 2rem; font-weight: 700; color: var(--primary-color); line-height: 1.1; }
        .candidate-result-percentage { font-size: 1.1rem; color: var(--text-muted); margin-left: .75rem; font-weight: 500;}
        .candidate-winner-badge { font-size: .8rem; font-weight: 700; padding: .35em .9em; background-color: var(--winner-text); color: var(--bg-white); border-radius: 50px; white-space: nowrap; }
        .candidate-progress { height: 16px; background-color: var(--progress-bg); border-radius: 8px; overflow: hidden; margin-top: 0.75rem; }
        .candidate-progress-bar { height: 100%; background: var(--progress-bar-gradient); transition: width 0.6s cubic-bezier(0.25, 1, 0.5, 1); text-align: right; padding-right: 8px; color: white; font-size: 0.75rem; line-height: 16px; font-weight: 600; white-space: nowrap;}

        /* Footer / Status Bar */
        .live-footer { padding: 0.75rem 1.5rem; background-color: var(--primary-color); color: rgba(255,255,255,0.85); font-size: 0.9rem; flex-shrink: 0; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .footer-item { flex-shrink: 0; }
        #lastUpdated { font-size: 0.85rem; opacity: 0.8; }
        #slideIndicator { font-weight: 600; text-align: center; }
        #connectionStatus .badge { font-size: 0.85rem; font-weight: 600; padding: 0.4em 0.7em;}

        /* Slide Progress Bar */
        .slide-progress-container { flex-grow: 1; height: 6px; background-color: rgba(255, 255, 255, 0.2); border-radius: 3px; overflow: hidden; min-width: 100px; order: 2; }
        .slide-progress-bar { height: 100%; width: 0%; background-color: var(--bg-light); border-radius: 3px; transition: width 0.1s linear; }
        .slide-progress-bar.animate-progress { animation: slideProgress var(--slide-interval-duration) linear forwards; }
        @keyframes slideProgress { from { width: 0%; } to { width: 100%; } }

        /* Status Overlay */
        .status-overlay { position: absolute; inset: 0; background-color: rgba(255,255,255,0.95); display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; z-index: 10; transition: opacity 0.3s ease-out; opacity: 1; visibility: visible;}
        .status-overlay.hidden { opacity: 0; pointer-events: none; visibility: hidden; }
        .status-overlay .spinner-border { width: 4.5rem; height: 4.5rem; color: var(--primary-color); border-width: .3em; }
        .status-overlay p { font-size: 1.3rem; color: var(--text-dark); margin-top: 1.5rem; font-weight: 600;}

        /* Fullscreen Button */
        #fullscreenBtn { position: fixed; bottom: 15px; right: 15px; z-index: 100; background-color: rgba(255,255,255,0.8); border: 1px solid var(--border-color); backdrop-filter: blur(2px); padding: 0.4rem 0.7rem; }
        #fullscreenBtn i { font-size: 1.2rem; }

        /* Responsive */
        @media (max-width: 768px) {
            .live-footer { justify-content: center; text-align: center; }
            .footer-item { width: 100%; }
            .slide-progress-container { order: -1; margin-bottom: 0.5rem; }
            .candidates-grid { grid-template-columns: 1fr; }
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

    <script nonce="<?php echo $nonce; ?>">
        document.addEventListener('DOMContentLoaded', () => {

            // --- DOM References ---
            const dom = {
                slideshowContainer: document.getElementById('slideshowContainer'),
                electionTitle: document.getElementById('electionTitle'),
                slideIndicator: document.getElementById('slideIndicator'),
                lastUpdated: document.getElementById('lastUpdated'),
                statusOverlay: document.getElementById('statusOverlay'),
                statusText: document.getElementById('statusText'),
                fullscreenBtn: document.getElementById('fullscreenBtn'),
                connectionStatus: document.getElementById('connectionStatus'),
                progressBar: document.getElementById('slideProgressBar')
            };

            // --- Configuration ---
            const CONFIG = {
                AJAX_ENDPOINT: 'live_results_ajax.php',
                RETRY_DELAY_MS: 15000,
                BASE_SLIDE_DURATION_MS: 2000,
                PER_CANDIDATE_DURATION_MS: 1000 
            };

            // --- State ---
            let state = {
                currentPositionIndex: 0,
                positionsData: [],
                slideshowTimeoutId: null,
            };

            // --- Helper Functions ---
            const helpers = {
                escapeHtml: (unsafe) => { const div = document.createElement('div'); div.textContent = unsafe ?? ''; return div.innerHTML; },
                numberFormat: (number) => new Intl.NumberFormat().format(number),
                updateConnectionStatus: (isLive, message = '') => { if (!dom.connectionStatus) return; dom.connectionStatus.innerHTML = isLive ? '<span class="badge bg-success">Live</span>' : `<span class="badge bg-danger" title="${helpers.escapeHtml(message)}">Offline</span>`; },
                updateStatus: (message, isLoading = false) => { if (!dom.statusOverlay || !dom.statusText) return; dom.statusOverlay.classList.remove('hidden'); dom.statusText.textContent = message; const spinner = dom.statusOverlay.querySelector('.spinner-border'); if (spinner) spinner.style.display = isLoading ? 'block' : 'none'; if (!isLoading) helpers.updateConnectionStatus(false, message); },
                hideStatus: () => { if (dom.statusOverlay) dom.statusOverlay.classList.add('hidden'); },
                calculateDynamicInterval: (candidateCount) => {
                    if (candidateCount === 0) return CONFIG.BASE_SLIDE_DURATION_MS;
                    // Ensure there's a reasonable max time per slide
                    const calculatedTime = CONFIG.BASE_SLIDE_DURATION_MS + (candidateCount * CONFIG.PER_CANDIDATE_DURATION_MS);
                    return Math.min(calculatedTime, 20000); // Cap slide time at 20 seconds
                },
                restartProgressAnimation: (durationMs) => {
                    if (!dom.progressBar) return;
                    const durationSec = durationMs / 1000;
                    document.documentElement.style.setProperty('--slide-interval-duration', `${durationSec}s`);
                    dom.progressBar.classList.remove('animate-progress');
                    void dom.progressBar.offsetWidth; // Force reflow
                    dom.progressBar.classList.add('animate-progress');
                }
            };
            
            // --- Slide Rendering ---
            function renderSlide(positionIndex) {
                if (!dom.slideshowContainer || !state.positionsData[positionIndex]) return null;
                const position = state.positionsData[positionIndex];
                let candidatesHtml = '';

                if (position.candidates && position.candidates.length > 0) {
                    position.candidates.forEach(candidate => {
                        const photoPath = candidate.photo ? `../${helpers.escapeHtml(candidate.photo.replace(/^\//, ''))}` : 'assets/images/default-avatar.png';
                        const winnerBadge = candidate.is_winner && position.total_votes > 0 ? '<span class="candidate-winner-badge"><i class="bi bi-check-circle-fill"></i> WINNER</span>' : '';
                        const percentage = candidate.percentage ?? 0;
                        const voteText = candidate.vote_count === 1 ? 'vote' : 'votes';
                        const progressBarWidth = (percentage < 1 && candidate.vote_count > 0) ? 1 : percentage;

                        candidatesHtml += `
                            <div class="candidate-result-card ${candidate.is_winner && position.total_votes > 0 ? 'is-winner' : ''}">
                                <div class="candidate-result-info">
                                    <img src="${photoPath}" alt="${helpers.escapeHtml(candidate.name)}" class="candidate-result-photo" onerror="this.onerror=null; this.src='assets/images/default-avatar.png';">
                                    <div class="candidate-result-details">
                                        <h4 class="candidate-result-name">${helpers.escapeHtml(candidate.name)} ${winnerBadge}</h4>
                                        <div class="d-flex align-items-baseline flex-wrap">
                                            <span class="candidate-result-votes">${helpers.numberFormat(candidate.vote_count)} ${voteText}</span>
                                            ${position.total_votes > 0 ? `<span class="candidate-result-percentage">(${percentage}%)</span>` : ''}
                                        </div>
                                    </div>
                                </div>
                                ${position.total_votes > 0 ? `
                                <div class="candidate-progress progress" style="height: 16px;" title="${percentage}%">
                                    <div class="candidate-progress-bar" role="progressbar" style="width: ${progressBarWidth}%" aria-valuenow="${percentage}" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>` : ''}
                            </div>`;
                    });
                } else { candidatesHtml = '<p class="text-center text-muted mt-4 fs-5"><i>No candidates found for this position.</i></p>'; }

                const slideDiv = document.createElement('div');
                slideDiv.className = 'slide';
                slideDiv.id = `slide-pos-${position.id}`;
                slideDiv.setAttribute('aria-hidden', 'true');
                slideDiv.innerHTML = `
                    <h2 class="slide-position-title">${helpers.escapeHtml(position.title)}</h2>
                    <div class="candidates-grid">${candidatesHtml}</div>
                    <p class="text-center text-muted mt-auto pt-3 fw-bold fs-5">Total Votes: ${helpers.numberFormat(position.total_votes)}</p>
                `;
                return slideDiv;
            }

            // --- Slideshow Control ---
            function showSlide(slideIndex) {
                if (slideIndex < 0 || slideIndex >= state.positionsData.length || !dom.slideshowContainer) return;
                const newSlideId = `slide-pos-${state.positionsData[slideIndex].id}`;
                const newSlide = document.getElementById(newSlideId);
                const currentActive = dom.slideshowContainer.querySelector('.slide.active');
                if (!newSlide || (currentActive === newSlide && !currentActive.classList.contains('exiting'))) return;

                if (currentActive) {
                    currentActive.classList.remove('active');
                    currentActive.classList.add('exiting');
                    setTimeout(() => currentActive?.classList.remove('exiting'), 800);
                }
                
                newSlide.classList.remove('exiting');
                newSlide.classList.add('active');
                newSlide.setAttribute('aria-hidden', 'false');

                if (dom.slideIndicator) dom.slideIndicator.textContent = `Position ${slideIndex + 1} / ${state.positionsData.length}`;
            }

            function stopSlideshow() { 
                if (state.slideshowTimeoutId) clearTimeout(state.slideshowTimeoutId);
                state.slideshowTimeoutId = null;
                if (dom.progressBar) dom.progressBar.classList.remove('animate-progress');
            }

            /**
             * This is the main loop for the slideshow. It's a self-calling function
             * that uses setTimeout to create a cycle.
             */
            function runSlideshowCycle() {
                // First, check if the cycle is over.
                if (state.currentPositionIndex >= state.positionsData.length) {
                    console.log("Full slideshow cycle complete. Fetching new results.");
                    fetchResults(); // The cycle is over, so fetch new data.
                    return; // End this chain of timeouts.
                }

                // --- Handle the current slide ---
                showSlide(state.currentPositionIndex);
                
                const currentPosition = state.positionsData[state.currentPositionIndex];
                const candidateCount = currentPosition.candidates?.length ?? 0;
                const dynamicInterval = helpers.calculateDynamicInterval(candidateCount);
                
                helpers.restartProgressAnimation(dynamicInterval);
                
                // --- Prepare for the next slide ---
                state.currentPositionIndex++;
                
                // Schedule the next call to this function after the current slide's duration.
                state.slideshowTimeoutId = setTimeout(runSlideshowCycle, dynamicInterval);
            }

            // --- Data Fetching ---
            async function fetchResults() {
                console.log("Fetching results...");
                stopSlideshow(); // Always stop any previous cycle before fetching.
                helpers.updateStatus('Fetching latest results...', true);

                try {
                    const response = await fetch(CONFIG.AJAX_ENDPOINT, { method: 'GET', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'} });
                    const result = await response.json();
                    if (!response.ok || !result.success) throw new Error(result.message || `HTTP error ${response.status}`);

                    console.log("Results fetched successfully.");
                    helpers.updateConnectionStatus(true);
                    
                    const data = result.data;
                    if (data?.election) {
                        if (dom.electionTitle) dom.electionTitle.textContent = data.election.title || 'Election Results';
                        state.positionsData = data.positions || [];
                        
                        dom.slideshowContainer.innerHTML = ''; // Clear previous content

                        if (state.positionsData.length > 0) {
                            state.positionsData.forEach((pos, index) => { 
                                const slideElement = renderSlide(index); 
                                if(slideElement) dom.slideshowContainer.appendChild(slideElement); 
                            });
                            helpers.hideStatus();
                            // Reset index and start the new slideshow cycle
                            state.currentPositionIndex = 0;
                            runSlideshowCycle();
                        } else { 
                            helpers.updateStatus('No positions or candidates found for the active election.', false); 
                        }
                    } else { 
                        helpers.updateStatus('No active election found.', false); 
                        state.positionsData = []; 
                    }

                } catch (error) {
                    console.error("Fetch results error:", error);
                    helpers.updateStatus(`Error loading results: ${error.message}. Retrying...`, false);
                    // Schedule a retry after a delay.
                    setTimeout(fetchResults, CONFIG.RETRY_DELAY_MS);
                }
            }

            // --- Fullscreen Toggle ---
            function toggleFullScreen() { if (!document.fullscreenElement) document.documentElement.requestFullscreen().catch(err => console.error(err)); else if (document.exitFullscreen) document.exitFullscreen(); }
            dom.fullscreenBtn?.addEventListener('click', toggleFullScreen);
            document.addEventListener('fullscreenchange', () => { const icon = dom.fullscreenBtn?.querySelector('i'); if (icon) icon.className = document.fullscreenElement ? 'bi bi-fullscreen-exit fs-5' : 'bi bi-arrows-fullscreen fs-5'; });

            // --- Initial Load ---
            fetchResults();

        }); // End DOMContentLoaded
    </script>
</body>
</html>