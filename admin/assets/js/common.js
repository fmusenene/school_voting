document.addEventListener('DOMContentLoaded', function() {
    // Handle sidebar toggle
    const sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
            document.getElementById('mainContent').classList.toggle('expanded');
            document.querySelector('.navbar').classList.toggle('expanded');
        });
    }

    // Handle select all checkbox
    const selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            const checkboxes = document.getElementsByName('election_ids[]');
            for (let checkbox of checkboxes) {
                checkbox.checked = this.checked;
            }
        });
    }
}); 