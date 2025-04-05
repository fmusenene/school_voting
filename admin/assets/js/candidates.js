// Global variables
let currentPage = 1;
let totalPages = 1;
let itemsPerPage = 10;
let currentSearch = '';
let currentElection = 'all';
let currentPosition = 'all';
let currentSort = 'name_asc';
let currentSortOrder = 'asc';
let currentSortField = 'name';
let selectedCandidates = new Set();

// Event Listeners
document.addEventListener('DOMContentLoaded', function() {
    // Initialize event listeners
    initializeEventListeners();
    
    // Load initial data
    loadCandidates();
    
    // Handle session notifications
    if (typeof sessionSuccess !== 'undefined') {
        showNotification(sessionSuccess, 'success');
    }
    if (typeof sessionError !== 'undefined') {
        showNotification(sessionError, 'error');
    }
});

function initializeEventListeners() {
    // Search input
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(function() {
            currentSearch = this.value;
            currentPage = 1;
            loadCandidates();
        }, 300));
    }

    // Election filter
    const electionSelect = document.getElementById('electionSelect');
    if (electionSelect) {
        electionSelect.addEventListener('change', function() {
            currentElection = this.value;
            currentPage = 1;
            loadCandidates();
        });
    }

    // Position filter
    const positionSelect = document.getElementById('positionSelect');
    if (positionSelect) {
        positionSelect.addEventListener('change', function() {
            currentPosition = this.value;
            currentPage = 1;
            loadCandidates();
        });
    }

    // Sort buttons
    document.querySelectorAll('.sort-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const field = this.dataset.field;
            if (currentSortField === field) {
                currentSortOrder = currentSortOrder === 'asc' ? 'desc' : 'asc';
            } else {
                currentSortField = field;
                currentSortOrder = 'asc';
            }
            currentSort = `${field}_${currentSortOrder}`;
            loadCandidates();
        });
    });

    // Add candidate form
    const addCandidateForm = document.getElementById('addCandidateForm');
    if (addCandidateForm) {
        addCandidateForm.addEventListener('submit', handleAddCandidate);
    }

    // Select all checkbox
    const selectAllCheckbox = document.querySelector('.select-all');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.candidate-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
                if (this.checked) {
                    selectedCandidates.add(checkbox.value);
                } else {
                    selectedCandidates.delete(checkbox.value);
                }
            });
            updateBulkActions();
        });
    }

    // Bulk action buttons
    document.getElementById('deleteSelectedBtn').addEventListener('click', function() {
        if (selectedCandidates.size > 0) {
            showConfirmationDialog(
                'Delete Selected Candidates',
                `Are you sure you want to delete ${selectedCandidates.size} selected candidate(s)?`,
                () => deleteSelectedCandidates()
            );
        }
    });

    // Print button
    document.getElementById('printBtn').addEventListener('click', function() {
        window.print();
    });

    // Export button
    document.getElementById('exportBtn').addEventListener('click', function() {
        exportToPDF();
    });
}

// Load candidates function
function loadCandidates() {
    const candidatesContainer = document.getElementById('candidatesContainer');
    const loadingSpinner = document.getElementById('loadingSpinner');
    
    if (loadingSpinner) loadingSpinner.style.display = 'block';
    if (candidatesContainer) candidatesContainer.style.display = 'none';

    const formData = new FormData();
    formData.append('action', 'fetch');
    formData.append('page', currentPage);
    formData.append('search', currentSearch);
    formData.append('election', currentElection);
    formData.append('position', currentPosition);
    formData.append('sort', currentSort);

    fetch('candidates_ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            renderCandidates(data.candidates);
            renderPagination(data.total_pages);
        } else {
            showNotification(data.message || 'Error loading candidates', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error loading candidates', 'error');
    })
    .finally(() => {
        if (loadingSpinner) loadingSpinner.style.display = 'none';
        if (candidatesContainer) candidatesContainer.style.display = 'block';
    });
}

// Render candidates function
function renderCandidates(candidates) {
    const container = document.getElementById('candidatesContainer');
    if (!container) return;

    container.innerHTML = candidates.map(candidate => `
        <tr>
            <td>
                <input type="checkbox" class="candidate-checkbox" value="${candidate.id}">
            </td>
            <td>${candidate.name}</td>
            <td>${candidate.position_title}</td>
            <td>${candidate.election_title}</td>
            <td>${candidate.vote_count}</td>
            <td>
                <button class="btn btn-sm btn-primary edit-btn" data-id="${candidate.id}">
                    <i class="bi bi-pencil"></i>
                </button>
                <button class="btn btn-sm btn-danger delete-btn" data-id="${candidate.id}">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>
    `).join('');

    // Add event listeners to new buttons
    container.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', () => editCandidate(btn.dataset.id));
    });

    container.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', () => deleteCandidate(btn.dataset.id));
    });

    container.querySelectorAll('.candidate-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            if (this.checked) {
                selectedCandidates.add(this.value);
            } else {
                selectedCandidates.delete(this.value);
            }
            updateBulkActions();
            updateSelectAllCheckbox();
        });
    });
}

// Render pagination function
function renderPagination(totalPages) {
    const container = document.getElementById('paginationContainer');
    if (!container) return;

    let html = '';
    for (let i = 1; i <= totalPages; i++) {
        html += `
            <li class="page-item ${i === currentPage ? 'active' : ''}">
                <a class="page-link" href="#" data-page="${i}">${i}</a>
            </li>
        `;
    }

    container.innerHTML = html;

    // Add event listeners to pagination links
    container.querySelectorAll('.page-link').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            currentPage = parseInt(this.dataset.page);
            loadCandidates();
        });
    });
}

// Update bulk actions visibility
function updateBulkActions() {
    const bulkActions = document.getElementById('bulkActions');
    if (bulkActions) {
        bulkActions.style.display = selectedCandidates.size > 0 ? 'block' : 'none';
    }
}

// Update select all checkbox state
function updateSelectAllCheckbox() {
    const selectAll = document.querySelector('.select-all');
    if (!selectAll) return;

    const checkboxes = document.querySelectorAll('.candidate-checkbox');
    const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
    
    selectAll.checked = checkedCount === checkboxes.length && checkboxes.length > 0;
    selectAll.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
}

// Show notification function
function showNotification(message, type = 'success') {
    const container = document.getElementById('notificationContainer');
    if (!container) return;

    const notification = document.createElement('div');
    notification.className = `notification alert alert-${type} alert-dismissible fade show`;
    notification.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;

    container.appendChild(notification);

    // Auto dismiss after 5 seconds
    setTimeout(() => {
        notification.remove();
    }, 5000);
}

// Show confirmation dialog
function showConfirmationDialog(title, message, onConfirm) {
    const dialog = document.createElement('div');
    dialog.className = 'confirmation-dialog';
    dialog.innerHTML = `
        <div class="confirmation-content">
            <div class="confirmation-header">
                <h5>${title}</h5>
                <button type="button" class="btn-close"></button>
            </div>
            <div class="confirmation-body">
                ${message}
            </div>
            <div class="confirmation-footer">
                <button type="button" class="btn btn-secondary cancel-btn">Cancel</button>
                <button type="button" class="btn btn-danger confirm-btn">Confirm</button>
            </div>
        </div>
    `;

    document.body.appendChild(dialog);
    setTimeout(() => dialog.classList.add('show'), 10);

    // Add event listeners
    dialog.querySelector('.btn-close').addEventListener('click', () => {
        dialog.classList.remove('show');
        setTimeout(() => dialog.remove(), 300);
    });

    dialog.querySelector('.cancel-btn').addEventListener('click', () => {
        dialog.classList.remove('show');
        setTimeout(() => dialog.remove(), 300);
    });

    dialog.querySelector('.confirm-btn').addEventListener('click', () => {
        onConfirm();
        dialog.classList.remove('show');
        setTimeout(() => dialog.remove(), 300);
    });
}

// Utility function for debouncing
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func.apply(this, args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Export to PDF function
function exportToPDF() {
    const formData = new FormData();
    formData.append('action', 'export');
    formData.append('election', currentElection);
    formData.append('position', currentPosition);
    formData.append('search', currentSearch);

    fetch('candidates_ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.blob())
    .then(blob => {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'candidates.pdf';
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        a.remove();
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error exporting to PDF', 'error');
    });
} 


// Located in: admin/assets/js/candidates.js

function handleAddCandidate(event) {
    event.preventDefault(); // Prevent the default form submission (page reload)

    const form = event.target; // Or document.getElementById('addCandidateForm');
    const formData = new FormData(form);
    formData.append('action', 'add'); // Tell the backend what action to perform

    const submitButton = form.querySelector('button[type="submit"]'); // Get the submit button
    const originalButtonText = submitButton.innerHTML;
    submitButton.disabled = true;
    submitButton.innerHTML = 'Saving...'; // Optional: Provide user feedback

    fetch('candidates_ajax.php', { // Send data to the backend AJAX handler
        method: 'POST',
        body: formData
    })
    .then(response => response.json()) // Expect a JSON response from the server
    .then(data => {
        if (data.success) {
            // Success!
            showNotification(data.message || 'Candidate added successfully!', 'success'); // Show success message
            
            // Close the modal
            const modalElement = document.getElementById('addCandidateModal'); // Assuming your modal has id="addCandidateModal"
            if (modalElement) {
                const modalInstance = bootstrap.Modal.getInstance(modalElement);
                if (modalInstance) {
                    modalInstance.hide();
                }
            }
            
            form.reset(); // Clear the form
            loadCandidates(); // Refresh the candidate list
        } else {
            // Error reported by server
            showNotification(data.message || 'Error adding candidate.', 'error');
        }
    })
    .catch(error => {
        // Network or other errors
        console.error('Error:', error);
        showNotification('An unexpected error occurred. ERROR: ' + error.message, 'error'); 
    })
    .finally(() => {
        // Re-enable the button regardless of success or error
        submitButton.disabled = false;
        submitButton.innerHTML = originalButtonText;
    });
}

// Make sure the form has the ID 'addCandidateForm' and the modal has an ID like 'addCandidateModal'
// e.g., in admin/candidates.php:
// <form id="addCandidateForm"> ... </form>
// <div class="modal" id="addCandidateModal"> ... </div>

// Ensure the event listener is attached in initializeEventListeners:
// const addCandidateForm = document.getElementById('addCandidateForm');
// if (addCandidateForm) {
//     addCandidateForm.addEventListener('submit', handleAddCandidate);
// }