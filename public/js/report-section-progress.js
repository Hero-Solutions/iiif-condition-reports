document.addEventListener('DOMContentLoaded', () => {
    const editor = document.querySelector('.report-editor');

    if (!editor) {
        return;
    }

    const countPanel = (panel) => {
        const actorList = panel.querySelector('.actor-list');

        if (actorList) {
            return actorList.querySelectorAll('li').length;
        }

        const compactItems = Array.from(panel.querySelectorAll('[data-compact-item]'));

        if (compactItems.length > 0) {
            const visibleItems = compactItems.filter((item) => {
                const selected = !item.matches('[data-compact-quick-item]') || item.classList.contains('is-selected');

                return selected && !item.hidden && item.closest('[data-conditional-content][hidden]') === null;
            }).length;
            const presenceChoices = panel.querySelectorAll('[data-presence-choice].is-selected').length;

            return visibleItems + presenceChoices;
        }

        let filled = 0;

        panel.querySelectorAll('input, select, textarea').forEach((field) => {
            if (field.type === 'hidden' || field.disabled) {
                return;
            }

            if (field.type === 'checkbox' || field.type === 'radio') {
                if (field.checked) {
                    filled += 1;
                }

                return;
            }

            if (field.tagName === 'SELECT') {
                return;
            }

            if (field.value && field.value.trim() !== '') {
                filled += 1;
            }
        });

        return filled;
    };

    const updateCounts = () => {
        editor.querySelectorAll('[data-report-tab]').forEach((button) => {
            const badge = button.querySelector('[data-section-count]');
            const panel = editor.querySelector('[data-report-tab-panel="' + button.dataset.reportTab + '"]');

            if (!badge || !panel) {
                return;
            }

            const filled = countPanel(panel);

            badge.textContent = String(filled);
            badge.hidden = false;
            badge.classList.toggle('is-empty', filled === 0);
        });
    };

    let timer = null;

    const scheduleUpdate = () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(updateCounts, 250);
    };

    document.addEventListener('input', scheduleUpdate);
    document.addEventListener('change', scheduleUpdate);

    updateCounts();
});
