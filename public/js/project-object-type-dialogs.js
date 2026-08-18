document.addEventListener('click', (event) => {
    const openButton = event.target.closest('[data-dialog-open]');

    if (openButton) {
        const dialog = document.getElementById(openButton.dataset.dialogOpen);

        if (!dialog) {
            return;
        }

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', 'open');
        }

        return;
    }

    const closeButton = event.target.closest('[data-dialog-close]');

    if (closeButton) {
        const dialog = closeButton.closest('dialog');

        if (dialog) {
            dialog.close();
        }
    }
});
