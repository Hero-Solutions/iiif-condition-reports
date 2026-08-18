document.addEventListener('DOMContentLoaded', () => {
    const tabs = document.querySelector('[data-project-tabs]');

    if (!tabs) {
        return;
    }

    const triggers = Array.from(tabs.querySelectorAll('[data-project-tab-trigger]'));
    const panels = Array.from(document.querySelectorAll('[data-project-tab-panel]'));
    const validTabs = new Set(triggers.map((trigger) => trigger.dataset.projectTabTrigger));
    const storageKey = `project-tab:${window.location.pathname}`;
    const hashTab = window.location.hash.startsWith('#project-tab-')
        ? window.location.hash.replace('#project-tab-', '')
        : '';
    const storedTab = window.localStorage.getItem(storageKey);
    const initialTab = validTabs.has(hashTab) ? hashTab : (validTabs.has(storedTab) ? storedTab : 'objects');

    const activateTab = (tab, updateHash) => {
        triggers.forEach((trigger) => {
            const active = trigger.dataset.projectTabTrigger === tab;
            trigger.classList.toggle('active', active);
            trigger.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        panels.forEach((panel) => {
            panel.hidden = panel.dataset.projectTabPanel !== tab;
        });

        window.localStorage.setItem(storageKey, tab);

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
