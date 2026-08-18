document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-project-type-select], select[name$="[type]"]').forEach((typeSelect) => {
        const form = typeSelect.closest('form');

        if (!form) {
            return;
        }

        const customTypeRow = form.querySelector('[data-project-custom-type-row]');
        const customTypeInput = form.querySelector('[data-project-custom-type-input], input[name$="[customType]"]');

        if (!customTypeRow || !customTypeInput) {
            return;
        }

        const syncCustomType = () => {
            if (typeSelect.dataset.smartSelectEnhanced === 'true') {
                customTypeRow.hidden = true;
                customTypeRow.style.display = 'none';
                customTypeInput.required = false;
                return;
            }

            const isOther = typeSelect.value === 'other';
            customTypeRow.hidden = !isOther;
            customTypeRow.style.display = isOther ? '' : 'none';
            customTypeInput.required = isOther;

            if (!isOther) {
                customTypeInput.value = '';
            }
        };

        typeSelect.addEventListener('change', syncCustomType);
        syncCustomType();
    });
});
