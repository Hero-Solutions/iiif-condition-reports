document.addEventListener('DOMContentLoaded', () => {
    const forms = new Set();
    const normalize = (value) => value
        .replace(',', '.')
        .replace(/\.$/, '')
        .replace(/^\./, '0.');

    document.querySelectorAll('[data-numeric-input]').forEach((input) => {
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

        if (input.form) {
            forms.add(input.form);
        }
    });

    // Manual save and autosave both receive the same decimal notation.
    forms.forEach((form) => {
        form.addEventListener('formdata', (event) => {
            form.querySelectorAll('[data-numeric-input]').forEach((input) => {
                if (!input.disabled && event.formData.has(input.name)) {
                    event.formData.set(input.name, normalize(input.value));
                }
            });
        });
    });
});
