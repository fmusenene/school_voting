// Global variables
let currentPage = 1;
let recordsPerPage = 10;
let searchTerm = '';

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    initializeEventListeners();
    loadVotingCodes();
});

// Initialize event listeners
function initializeEventListeners() {
    // Select all checkbox
    const selectAll = document.querySelector('.select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.code-checkbox').forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
    }

    // Search input
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(function() {
            searchTerm = this.value;
            currentPage = 1;
            loadVotingCodes();
        }, 300));
    }

    // Generate codes form
    const generateForm = document.getElementById('generateCodesForm');
    if (generateForm) {
        generateForm.addEventListener('submit', function(e) {
            e.preventDefault();
            generateCodes(this);
        });
    }

    // Delete buttons
    document.querySelectorAll('.delete-btn').forEach(button => {
        button.addEventListener('click', function() {
            const id = this.dataset.id;
            if (confirm('Are you sure you want to delete this voting code?')) {
                deleteVotingCode(id);
            }
        });
    });
}

// Load voting codes
function loadVotingCodes() {
    const formData = new FormData();
    formData.append('action', 'fetch_codes');
    formData.append('page', currentPage);
    formData.append('records_per_page', recordsPerPage);
    formData.append('search', searchTerm);

    fetch('voting_codes_ajax.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            renderTable(data.codes);
            renderPagination(data.total_pages);
        } else {
            showNotification(data.message || 'Error loading voting codes', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error loading voting codes', 'error');
    });
}

// Render table
function renderTable(codes) {
    const tbody = document.querySelector('table tbody');
    if (!tbody) return;

    if (!codes || codes.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" class="text-center py-4">
                    No voting codes found
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = codes.map(code => `
        <tr>
            <td>
                <input type="checkbox" class="code-checkbox" value="${code.id}">
            </td>
            <td>${escapeHtml(code.code)}</td>
            <td>${escapeHtml(code.election_title)}</td>
            <td>
                ${code.is_used ? 
                    '<span class="badge bg-success">Used</span>' : 
                    '<span class="badge bg-warning">Unused</span>'
                }
            </td>
            <td>${code.vote_count}</td>
            <td>${formatDate(code.created_at)}</td>
            <td>
                <button class="btn btn-sm btn-danger delete-btn" data-id="${code.id}">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>
    `).join('');

    // Reattach event listeners
    initializeEventListeners();
}

// Render pagination
function renderPagination(totalPages) {
    const pagination = document.querySelector('.pagination');
    if (!pagination) return;

    let html = '';
    
    // Previous button
    html += `
        <li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
            <a class="page-link" href="#" data-page="${currentPage - 1}">
                <span aria-hidden="true">&laquo;</span>
            </a>
        </li>
    `;

    // Page numbers
    for (let i = 1; i <= totalPages; i++) {
        if (
            i === 1 || // First page
            i === totalPages || // Last page
            (i >= currentPage - 1 && i <= currentPage + 1) // Pages around current page
        ) {
            html += `
                <li class="page-item ${i === currentPage ? 'active' : ''}">
                    <a class="page-link" href="#" data-page="${i}">${i}</a>
                </li>
            `;
        } else if (
            i === currentPage - 2 ||
            i === currentPage + 2
        ) {
            html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }

    // Next button
    html += `
        <li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
            <a class="page-link" href="#" data-page="${currentPage + 1}">
                <span aria-hidden="true">&raquo;</span>
            </a>
        </li>
    `;

    pagination.innerHTML = html;

    // Add click event listeners to pagination links
    pagination.querySelectorAll('.page-link').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const page = parseInt(this.dataset.page);
            if (page && page !== currentPage) {
                currentPage = page;
                loadVotingCodes();
            }
        });
    });
}

// Generate voting codes
function generateCodes(form) {
    const formData = new FormData(form);
    formData.append('action', 'create');

    fetch('voting_codes_ajax.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message || 'Voting codes generated successfully');
            bootstrap.Modal.getInstance(document.getElementById('generateCodesModal')).hide();
            loadVotingCodes();
        } else {
            showNotification(data.message || 'Error generating voting codes', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error generating voting codes', 'error');
    });
}

// Delete voting code
function deleteVotingCode(id) {
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);

    fetch('voting_codes_ajax.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Voting code deleted successfully');
            loadVotingCodes();
        } else {
            showNotification(data.message || 'Error deleting voting code', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error deleting voting code', 'error');
    });
}

// Show notification
function showNotification(message, type = 'success') {
    const notification = document.createElement('div');
    notification.className = `alert alert-${type} alert-dismissible fade show`;
    notification.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    const container = document.querySelector('.container-fluid');
    container.insertBefore(notification, container.firstChild);
    
    setTimeout(() => {
        notification.remove();
    }, 5000);
}

// Utility functions
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

function escapeHtml(unsafe) {
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleString();
} 