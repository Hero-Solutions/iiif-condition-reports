document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-report-media-form]').forEach((form) => {
        const editor = form.closest('.report-editor');
        const fileInput = form.querySelector('[data-report-media-file]');
        const urlInput = form.querySelector('input[type="url"]');
        const fileName = form.querySelector('[data-report-file-name]');
        const status = form.querySelector('[data-report-media-status]');
        const retry = form.querySelector('[data-report-media-retry]');
        const buttons = form.querySelectorAll('button[type="submit"]');
        let submitting = false;

        const updateSubmitButtons = () => {
            const disabled = submitting || urlInput?.value.trim() === '';
            buttons.forEach((button) => { button.disabled = disabled; });
        };

        urlInput?.addEventListener('input', updateSubmitButtons);
        urlInput?.addEventListener('change', updateSubmitButtons);
        updateSubmitButtons();

        fileInput?.addEventListener('click', (event) => {
            if (submitting) event.preventDefault();
        });

        fileInput?.addEventListener('change', () => {
            const files = Array.from(fileInput.files || []);
            fileName.textContent = files.map((file) => file.name).join(', ');
            fileName.hidden = files.length === 0;

            if (files.length > 0) form.requestSubmit();
        });

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (submitting || urlInput?.value.trim() === '' || !form.reportValidity()) return;

            submitting = true;
            form.setAttribute('aria-busy', 'true');
            updateSubmitButtons();
            retry.hidden = true;
            status.hidden = false;
            status.classList.remove('error');
            status.textContent = status.dataset.uploadingLabel;

            try {
                if (editor?.reportAutosave && !await editor.reportAutosave.saveNow()) {
                    throw new Error('Report changes could not be saved.');
                }

                // Keep file and category inputs enabled so the native form sends them.
                form.submit();
            } catch (error) {
                submitting = false;
                form.removeAttribute('aria-busy');
                updateSubmitButtons();
                status.classList.add('error');
                status.textContent = status.dataset.failedLabel;
                retry.hidden = false;
            }
        });
    });
});
