(function () {
    const root = document.documentElement;

    const collapseButton = document.querySelector('[data-sidebar-collapse]');

    if (collapseButton) {
        collapseButton.addEventListener('click', () => {
            const collapsed = root.classList.toggle('sidebar-collapsed');

            try {
                localStorage.setItem('crSidebar', collapsed ? 'collapsed' : 'expanded');
            } catch (error) {
            }
        });
    }

    const openButton = document.querySelector('[data-sidebar-open]');
    const overlay = document.querySelector('[data-sidebar-overlay]');

    const closeSidebar = () => {
        root.classList.remove('sidebar-open');

        if (overlay) {
            overlay.hidden = true;
        }
    };

    if (openButton) {
        openButton.addEventListener('click', () => {
            root.classList.add('sidebar-open');

            if (overlay) {
                overlay.hidden = false;
            }
        });
    }

    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeSidebar();
        }
    });

    document.querySelectorAll('.sidebar-nav a').forEach((link) => {
        link.addEventListener('click', closeSidebar);
    });

    document.addEventListener('click', (event) => {
        const row = event.target.closest('[data-row-href]');

        if (!row || event.target.closest('a, button, input, select, textarea, form, label')) {
            return;
        }

        window.location.href = row.dataset.rowHref;
    });

    document.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-toggle-target]');

        if (!toggle) {
            return;
        }

        const target = document.getElementById(toggle.dataset.toggleTarget);

        if (!target) {
            return;
        }

        target.hidden = !target.hidden;
        toggle.setAttribute('aria-expanded', target.hidden ? 'false' : 'true');

        if (!target.hidden) {
            const focusable = target.querySelector('input, select, textarea');

            if (focusable) {
                focusable.focus();
            }
        }
    });

    document.querySelectorAll('.flash').forEach((flash) => {
        const dismiss = () => {
            flash.classList.add('is-leaving');
            window.setTimeout(() => flash.remove(), 300);
        };

        flash.addEventListener('click', dismiss);
        window.setTimeout(dismiss, 5000);
    });
})();
