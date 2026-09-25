document.addEventListener('DOMContentLoaded', () => {
    const tabs = document.querySelector('[data-project-tabs]');

    if (!tabs) {
        return;
    }

    const triggers = Array.from(tabs.querySelectorAll('[data-project-tab-trigger]'));
    const panels = Array.from(document.querySelectorAll('[data-project-tab-panel]'));
    const validTabs = new Set(triggers.map((trigger) => trigger.dataset.projectTabTrigger));
    const hashTab = window.location.hash.startsWith('#project-tab-')
        ? window.location.hash.replace('#project-tab-', '')
        : '';
    const initialTab = validTabs.has(hashTab) ? hashTab : 'objects';

    const activateTab = (tab, updateHash) => {
        triggers.forEach((trigger) => {
            const active = trigger.dataset.projectTabTrigger === tab;
            trigger.classList.toggle('active', active);
            trigger.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        panels.forEach((panel) => {
            panel.hidden = panel.dataset.projectTabPanel !== tab;
        });

        if (updateHash) {
            window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}#project-tab-${tab}`);
        }
    };

    triggers.forEach((trigger) => {
        trigger.addEventListener('click', () => {
            activateTab(trigger.dataset.projectTabTrigger, true);
        });
    });

    activateTab(initialTab, false);
});
