document.addEventListener('DOMContentLoaded', () => {
    if (document.body.hasAttribute('data-read-only')) {
        return;
    }

    const normalize = (value) => value.replace(/\r\n?/g, '\n').trim();

    document.querySelectorAll('.project-object-context-form').forEach((form) => {
        const fields = Array.from(form.querySelectorAll('[name="room"], [name="notes"]'));
        const saveButton = form.querySelector('button[type="submit"]');
        const savedValues = fields.map((field) => normalize(field.defaultValue));

        const updateSaveButton = () => {
            saveButton.disabled = !fields.some((field, index) => normalize(field.value) !== savedValues[index]);
        };

        form.addEventListener('input', updateSaveButton);
        form.addEventListener('change', updateSaveButton);
        form.addEventListener('submit', (event) => {
            updateSaveButton();

            if (saveButton.disabled) {
                event.preventDefault();
            }
        });
        window.addEventListener('pageshow', updateSaveButton);
        updateSaveButton();
    });
});
