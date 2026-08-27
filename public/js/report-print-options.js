document.addEventListener('change', (event) => {
    const toggle = event.target.closest('[data-print-section-toggle]');

    if (!toggle) {
        return;
    }

    document.querySelectorAll(`[data-print-section="${CSS.escape(toggle.value)}"]`).forEach((section) => {
        section.classList.toggle('print-excluded', !toggle.checked);
    });
});

document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-report-print]')) {
        return;
    }

    window.print();
});
