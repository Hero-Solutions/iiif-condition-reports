document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('#report-edit-form');
    const finalizeButton = document.querySelector('[data-report-finalize-action]');

    if (!form || !finalizeButton) {
        return;
    }

    const refresh = () => {
        const type = form.elements.namedItem('type')?.value || '';
        const customType = form.elements.namedItem('custom_type')?.value.trim() || '';

        finalizeButton.hidden = type === '' || (type === 'other' && customType === '');
    };

    form.addEventListener('input', refresh);
    form.addEventListener('change', refresh);
    refresh();
});
