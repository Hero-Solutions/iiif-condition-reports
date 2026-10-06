document.addEventListener('DOMContentLoaded', () => {
    const forms = new Set();
    const initialized = new WeakSet();
    const normalize = (value) => value
        .replace(',', '.')
        .replace(/\.$/, '')
        .replace(/^\./, '0.');

    const initializeInput = (input) => {
        if (initialized.has(input)) return;
        initialized.add(input);
        const allowed = input.dataset.numericInput === 'decimal' ? /^\d*(?:[.,]\d*)?$/ : /^\d*$/;
        let previousValue = input.value;
        let previousStart = 0;

        const accepts = (text) => allowed.test(
            input.value.slice(0, input.selectionStart) + text + input.value.slice(input.selectionEnd),
        );

        input.addEventListener('beforeinput', (event) => {
            previousValue = input.value;
            previousStart = input.selectionStart;

            if (event.data !== null && !accepts(event.data)) {
                event.preventDefault();
            }
        });

        input.addEventListener('paste', (event) => {
            const text = event.clipboardData?.getData('text');

            if (text !== undefined && !accepts(text)) {
                event.preventDefault();
            }
        });

        // Also handle input methods that do not provide a cancellable beforeinput event.
        input.addEventListener('input', () => {
            if (allowed.test(input.value)) {
                previousValue = input.value;
                previousStart = input.selectionStart;
            } else {
                input.value = previousValue;
                input.setSelectionRange(previousStart, previousStart);
            }
        });

        input.addEventListener('blur', () => {
            const value = normalize(input.value);

            if (value !== input.value) {
                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });

        const form = input.form;
        if (form && !forms.has(form)) {
            forms.add(form);
            // Manual save and autosave both receive the same decimal notation.
            form.addEventListener('formdata', (event) => {
                form.querySelectorAll('[data-numeric-input]').forEach((field) => {
                    if (!field.disabled && event.formData.has(field.name)) {
                        event.formData.set(field.name, normalize(field.value));
                    }
                });
            });
        }
    };

    const initialize = (root) => root.querySelectorAll('[data-numeric-input]').forEach(initializeInput);
    initialize(document);
    document.addEventListener('report-fields-added', (event) => initialize(event.target));
});
