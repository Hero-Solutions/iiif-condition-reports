document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('.object-edit-form');

    if (!form || document.body.hasAttribute('data-read-only')) {
        return;
    }

    const saveButton = form.querySelector('button[type="submit"]');
    const normalize = (value) => value.replace(/\r\n?/g, '\n').trim();
    const fields = Array.from(form.querySelectorAll('input, select, textarea')).filter((field) =>
        !field.disabled && (field.name || field.getAttribute('role') === 'combobox')
        && !field.name.endsWith('[_token]'),
    );
    const customSelects = new Map(fields
        .filter((field) => field.dataset.smartSelectCustomInput)
        .map((field) => [field, form.querySelector(`select[data-smart-select-key="${field.dataset.smartSelectCustomInput}"]`)]));

    const value = (field, initial = false) => {
        if (field.type === 'file') {
            return initial ? '' : (field.files.length > 0 ? 'selected' : '');
        }

        // A hidden custom value is only submitted as content when its custom option is selected.
        const customSelect = customSelects.get(field);
        if (customSelect && customSelect.value !== customSelect.dataset.smartSelectCustomValue) {
            return '';
        }

        if (initial && field.tagName === 'SELECT') {
            return Array.from(field.options).find((option) => option.defaultSelected)?.value || '';
        }

        return normalize(initial && field.name ? field.defaultValue : field.value);
    };
    const savedValues = fields.map((field) => value(field, true));
    const updateSaveButton = () => {
        const values = fields.map((field) => value(field));
        const changed = form.dataset.unsaved === '1' || values.some((current, index) => current !== savedValues[index]);
        saveButton.disabled = !changed || (form.dataset.newObject === '1' && values.every((current) => current === ''));
    };

    // Click also catches image-source buttons which clear other fields programmatically.
    ['input', 'change', 'click', 'keyup'].forEach((event) => form.addEventListener(event, updateSaveButton));
    form.addEventListener('reset', () => window.setTimeout(updateSaveButton));
    form.addEventListener('submit', (event) => {
        updateSaveButton();
        if (saveButton.disabled) {
            event.preventDefault();
        }
    });
    window.addEventListener('pageshow', updateSaveButton);
    updateSaveButton();
});
