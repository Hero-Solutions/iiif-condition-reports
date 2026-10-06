document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.querySelector('[data-report-image-dialog]');
    const report = document.querySelector('.report-document');
    if (!dialog || !report || !window.OpenSeadragon) return;

    const canvas = dialog.querySelector('[data-image-viewer]');
    const title = dialog.querySelector('[data-image-viewer-title]');
    const status = dialog.querySelector('[data-image-viewer-status]');
    const controls = Array.from(dialog.querySelectorAll('[data-image-zoom]'));
    let viewer = null;
    let opener = null;
    let requestId = 0;

    const open = async (trigger, image) => {
        const request = ++requestId;
        const sourceKey = trigger.dataset.sourceKey || '';
        const annotatedMain = /^main(?::\d+)?$/.test(sourceKey);
        const main = image.classList.contains('report-document-image');
        const imageUrl = image.currentSrc || image.src;
        let tileSource = { type: 'image', url: imageUrl };
        let manifestUrl = '';

        if (annotatedMain) {
            manifestUrl = dialog.dataset.mainManifestUrl || dialog.dataset.mainInfoUrl;
        } else if (main && dialog.dataset.customMainImage !== '1') {
            manifestUrl = dialog.dataset.mainInfoUrl || dialog.dataset.mainManifestUrl;
        }

        opener = trigger;
        title.textContent = trigger.dataset.imageLabel || (main ? dialog.dataset.mainLabel : dialog.dataset.imageLabel);
        status.textContent = dialog.dataset.loadingLabel;
        status.hidden = false;
        controls.forEach((button) => { button.disabled = true; });
        dialog.showModal();
        document.documentElement.classList.add('report-image-viewer-open');

        try {
            if (manifestUrl) {
                try {
                    const sources = await loadTileSources(manifestUrl);
                    const index = annotatedMain && sourceKey.includes(':') ? Number(sourceKey.split(':')[1]) - 1 : 0;
                    tileSource = sources[index] || tileSource;
                } catch {
                    // The existing report image remains usable if IIIF is unavailable.
                }
            }
            if (request !== requestId || !dialog.open) return;

            viewer = OpenSeadragon({
                element: canvas,
                showNavigationControl: false,
                showSequenceControl: false,
                gestureSettingsMouse: { clickToZoom: false },
            });
            const currentViewer = viewer;
            let usingImage = tileSource.type === 'image' && tileSource.url === imageUrl;

            currentViewer.addHandler('open', () => {
                if (request !== requestId) return;
                status.hidden = true;
                controls.forEach((button) => { button.disabled = false; });

                const annotations = trigger.querySelector('svg');
                if (annotations) {
                    const overlay = document.createElement('div');
                    overlay.className = 'report-image-viewer-overlay';
                    overlay.setAttribute('aria-hidden', 'true');
                    overlay.append(annotations.cloneNode(true));
                    currentViewer.addOverlay({
                        element: overlay,
                        location: currentViewer.world.getItemAt(0).getBounds(),
                        checkResize: false,
                    });
                }
            });
            currentViewer.addHandler('open-failed', () => {
                if (request !== requestId) return;
                if (!usingImage) {
                    usingImage = true;
                    currentViewer.open({ type: 'image', url: imageUrl });
                } else {
                    status.textContent = dialog.dataset.errorLabel;
                    status.hidden = false;
                }
            });
            currentViewer.open(tileSource);
        } catch {
            if (request === requestId) {
                status.textContent = dialog.dataset.errorLabel;
                status.hidden = false;
            }
        }
    };

    report.querySelectorAll('.report-document-image, .report-output-photo-grid img, .report-damage-canvas').forEach((trigger) => {
        const image = trigger.matches('img') ? trigger : trigger.querySelector('img');
        if (!image?.getAttribute('src')) return;
        trigger.classList.add('report-image-zoom-trigger');
        trigger.setAttribute('role', 'button');
        trigger.setAttribute('tabindex', '0');
        trigger.setAttribute('aria-haspopup', 'dialog');
        trigger.setAttribute('aria-label', dialog.dataset.openLabel);
        trigger.setAttribute('title', dialog.dataset.openLabel);
        trigger.addEventListener('click', () => { if (!dialog.open) open(trigger, image); });
        trigger.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                if (!dialog.open) open(trigger, image);
            }
        });
    });

    controls.forEach((button) => button.addEventListener('click', () => {
        if (!viewer || button.disabled) return;
        if (button.dataset.imageZoom === 'reset') viewer.viewport.goHome();
        else viewer.viewport.zoomBy(button.dataset.imageZoom === 'in' ? 1.5 : 1 / 1.5);
        viewer.viewport.applyConstraints();
    }));
    dialog.querySelector('[data-image-viewer-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
    dialog.addEventListener('close', () => {
        ++requestId;
        viewer?.destroy();
        viewer = null;
        document.documentElement.classList.remove('report-image-viewer-open');
        opener?.focus({ preventScroll: true });
    });
    window.addEventListener('beforeprint', () => { if (dialog.open) dialog.close(); });
});
