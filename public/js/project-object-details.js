document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-object-details]').forEach((details) => {
        const tabs = Array.from(details.querySelectorAll('[data-object-detail-tab]'));
        const panels = Array.from(details.querySelectorAll('[data-object-detail-panel]'));

        if (tabs.length === 0 || panels.length === 0) {
            return;
        }

        const activateTab = (tabName) => {
            tabs.forEach((tab) => {
                const active = tab.dataset.objectDetailTab === tabName;
                tab.classList.toggle('active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            });

            panels.forEach((panel) => {
                panel.hidden = panel.dataset.objectDetailPanel !== tabName;
            });
        };

        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                activateTab(tab.dataset.objectDetailTab || 'reports');
            });
        });

        activateTab('reports');
    });

    document.querySelectorAll('[data-object-details-toggle]').forEach((button) => {
        const details = document.getElementById(button.dataset.objectDetailsToggle || '');

        if (!details) {
            return;
        }

        button.addEventListener('click', () => {
            const isOpen = !details.hidden;
            details.hidden = isOpen;
            button.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        });
    });
});
