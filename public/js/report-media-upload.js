document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-report-media-dialog]').forEach((dialog) => {
        document.querySelectorAll(`[data-report-media-open="${dialog.id}"]`).forEach((button) => {
            button.addEventListener('click', () => dialog.showModal());
        });
        dialog.querySelector('[data-report-media-close]').addEventListener('click', () => dialog.close());
    });

    document.querySelectorAll('[data-report-document-delete], [data-report-image-delete]').forEach((form) => {
        let submitting = false;
        form.addEventListener('submit', async (event) => {
            if (event.defaultPrevented) return;
            event.preventDefault();
            if (submitting) return;
            submitting = true;
            try {
                const saved = await form.closest('.report-editor')?.reportAutosave?.saveNow();
                if (saved !== false) form.submit();
            } finally {
                submitting = false;
            }
        });
    });

    document.querySelectorAll('[data-report-media-form]').forEach((form) => {
        const editor = form.closest('.report-editor');
        const fields = form.id ? document.querySelector(`[data-report-media-fields="${form.id}"]`) || form : form;
        const fileInput = fields.querySelector('[data-report-media-file]');
        const urlInput = fields.querySelector('input[type="url"]');
        const fileName = fields.querySelector('[data-report-file-name]');
        const status = fields.querySelector('[data-report-media-status]');
        const retry = fields.querySelector('[data-report-media-retry]');
        const buttons = fields.querySelectorAll('button[type="submit"]');
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
