document.addEventListener('click', (event) => {
    const tab = event.target.closest('[data-report-tab]');

    if (!tab) {
        return;
    }

    const editor = tab.closest('.report-editor');

    if (!editor) {
        return;
    }

    const target = tab.dataset.reportTab;

    editor.querySelectorAll('[data-report-tab]').forEach((button) => {
        const active = button === tab;
        button.classList.toggle('active', active);
        button.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    editor.querySelectorAll('[data-report-tab-panel]').forEach((panel) => {
        const active = panel.dataset.reportTabPanel === target;
        panel.classList.toggle('active', active);
        panel.hidden = !active;
    });

    editor.querySelectorAll('[data-report-save-actions]').forEach((actions) => {
        actions.hidden = target === 'actors';
    });
});
