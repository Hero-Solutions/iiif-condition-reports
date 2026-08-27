const activateReportTab = (editor, target) => {
    const selectedTab = editor.querySelector(`[data-report-tab="${CSS.escape(target)}"]`);

    if (!selectedTab) {
        return;
    }

    editor.querySelectorAll('[data-report-tab]').forEach((button) => {
        const active = button === selectedTab;
        button.classList.toggle('active', active);
        button.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    editor.querySelectorAll('[data-report-tab-panel]').forEach((panel) => {
        const active = panel.dataset.reportTabPanel === target;
        panel.classList.toggle('active', active);
        panel.hidden = !active;
    });

    editor.querySelectorAll('[data-report-save-actions]').forEach((actions) => {
        actions.hidden = ['actors', 'photos', 'documents', 'damage'].includes(target);
    });
};

document.addEventListener('click', (event) => {
    const tab = event.target.closest('[data-report-tab], [data-report-tab-link]');

    if (!tab) {
        return;
    }

    const editor = tab.closest('.report-editor');

    if (!editor) {
        return;
    }

    const target = tab.dataset.reportTab || tab.dataset.reportTabLink;

    if (!target) {
        return;
    }

    activateReportTab(editor, target);

    if (tab.hasAttribute('data-report-tab-link')) {
        editor.querySelector(`[data-report-tab-panel="${CSS.escape(target)}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
});

document.addEventListener('DOMContentLoaded', () => {
    const target = new URLSearchParams(window.location.search).get('tab');

    if (!target) {
        return;
    }

    document.querySelectorAll('.report-editor').forEach((editor) => activateReportTab(editor, target));
});
