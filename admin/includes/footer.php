                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle sidebar toggle
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
            document.getElementById('mainContent').classList.toggle('expanded');
            document.querySelector('.navbar').classList.toggle('expanded');
        });

        // Handle select all checkbox
        if (document.getElementById('selectAll')) {
            document.getElementById('selectAll').addEventListener('change', function() {
                const checkboxes = document.getElementsByName('election_ids[]');
                for (let checkbox of checkboxes) {
                    checkbox.checked = this.checked;
                }
            });
        }
    </script>
</body>
</html> 