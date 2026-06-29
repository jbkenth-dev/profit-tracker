/**
 * Business Profit & Expense Tracker — Main JavaScript
 */

document.addEventListener('DOMContentLoaded', function () {

    // ─── Sidebar Toggle (Mobile) ──────────────────────────
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('sidebarToggle');
    const closeBtn = document.getElementById('sidebarClose');
    const backdrop = document.getElementById('sidebarBackdrop');
    const mainContent = document.getElementById('mainContent');

    function openSidebar() {
        if (window.innerWidth < 1200) {
            sidebar.classList.add('show');
            if (backdrop) backdrop.classList.add('show');
            if (mainContent) mainContent.classList.add('sidebar-open');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeSidebar() {
        sidebar.classList.remove('show');
        if (backdrop) backdrop.classList.remove('show');
        if (mainContent) mainContent.classList.remove('sidebar-open');
        document.body.style.overflow = '';
    }

    if (toggleBtn) toggleBtn.addEventListener('click', openSidebar);
    if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
    if (backdrop) backdrop.addEventListener('click', closeSidebar);

    // Close on escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sidebar.classList.contains('show')) {
            closeSidebar();
        }
    });

    // Close sidebar on window resize to desktop
    window.addEventListener('resize', function () {
        if (window.innerWidth >= 1200 && sidebar.classList.contains('show')) {
            closeSidebar();
        }
    });

    // ─── Auto-dismiss Alerts ──────────────────────────────
    document.querySelectorAll('.alert-dismissible').forEach(function (alert) {
        setTimeout(function () {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            bsAlert.close();
        }, 5000);
    });

    // ─── Tooltips ─────────────────────────────────────────
    const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    if (tooltips.length) {
        tooltips.forEach(function (el) {
            new bootstrap.Tooltip(el);
        });
    }

    // ─── Confirm Delete Generic Helper ────────────────────
    window.confirmDelete = function (message, callback) {
        Swal.fire({
            title: 'Are you sure?',
            text: message || 'This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#DC2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, delete it',
            cancelButtonText: 'Cancel',
            reverseButtons: true,
        }).then(function (result) {
            if (result.isConfirmed && typeof callback === 'function') {
                callback();
            }
        });
    };

    // ─── Flash Success Toast ──────────────────────────────
    window.showToast = function (message, type) {
        type = type || 'success';
        const iconMap = {
            success: 'success',
            error: 'error',
            warning: 'warning',
            info: 'info',
        };
        Swal.fire({
            icon: iconMap[type] || 'info',
            title: message,
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
        });
    };

    // ─── Number Formatting for Inputs ─────────────────────
    document.querySelectorAll('input[type="number"]').forEach(function (input) {
        input.addEventListener('blur', function () {
            const val = parseFloat(this.value);
            if (!isNaN(val) && this.step && this.step.indexOf('.') !== -1) {
                const decimals = this.step.split('.')[1].length;
                this.value = val.toFixed(decimals);
            }
        });
    });

    // ─── Currency Input Formatting ────────────────────────
    document.querySelectorAll('.currency-input').forEach(function (input) {
        input.addEventListener('input', function () {
            this.value = this.value.replace(/[^0-9.]/g, '');
        });
    });

    // ─── Responsive Tables: Add scroll indicator ──────────
    document.querySelectorAll('.table-responsive').forEach(function (wrapper) {
        function checkScroll() {
            if (wrapper.scrollWidth > wrapper.clientWidth) {
                wrapper.style.borderBottom = '2px solid var(--primary)';
            } else {
                wrapper.style.borderBottom = 'none';
            }
        }
        checkScroll();
        window.addEventListener('resize', checkScroll);
    });

});
