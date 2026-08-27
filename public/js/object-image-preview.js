document.addEventListener('DOMContentLoaded', () => {
    const preview = document.querySelector('[data-object-image-preview]');

    if (!preview) {
        return;
    }

    const manifestInput = document.querySelector('[data-object-manifest-url]');
    const manifestLoadButton = document.querySelector('[data-object-manifest-load]');
    const urlInput = document.querySelector('[data-object-image-url]');
    const loadButton = document.querySelector('[data-object-image-load]');
    const fileInput = document.querySelector('input[type="file"][name$="[imageUpload]"]');
    const previewImage = preview.querySelector('[data-object-image-preview-image]');
    const previewStatus = preview.querySelector('[data-object-image-preview-status]');
    const initialPreviewUrl = preview.dataset.initialUrl || '';
    let localPreviewUrl = null;
    let manifestRequest = 0;

    if (!manifestInput || !manifestLoadButton || !urlInput || !loadButton || !fileInput || !previewImage || !previewStatus) {
        return;
    }

    const updateLoadButtons = () => {
        manifestLoadButton.disabled = manifestInput.value.trim() === '';
        loadButton.disabled = urlInput.value.trim() === '';
    };

    const releaseLocalPreview = () => {
        if (localPreviewUrl) {
            URL.revokeObjectURL(localPreviewUrl);
            localPreviewUrl = null;
        }
    };

    const hidePreview = () => {
        releaseLocalPreview();
        preview.hidden = true;
        previewImage.hidden = true;
        previewImage.removeAttribute('src');
        previewStatus.hidden = true;
        previewStatus.classList.remove('error');
        previewStatus.textContent = '';
    };

    const showLoading = () => {
        preview.hidden = false;
        previewImage.hidden = true;
        previewStatus.hidden = false;
        previewStatus.classList.remove('error');
        previewStatus.textContent = preview.dataset.loadingMessage || '';
    };

    const showError = (message) => {
        preview.hidden = false;
        previewImage.hidden = true;
        previewStatus.hidden = false;
        previewStatus.classList.add('error');
        previewStatus.textContent = message;
    };

    const showPreview = (url, isLocalFile = false) => {
        releaseLocalPreview();

        if (isLocalFile) {
            localPreviewUrl = url;
        }

        showLoading();

        previewImage.onload = () => {
            previewImage.hidden = false;
            previewStatus.hidden = true;
        };
        previewImage.onerror = () => {
            showError(preview.dataset.errorMessage || '');
        };
        previewImage.src = url;
    };

    manifestLoadButton.addEventListener('click', async () => {
        const manifestUrl = manifestInput.value.trim();

        if (!manifestUrl || !manifestInput.reportValidity()) {
            return;
        }

        urlInput.value = '';
        fileInput.value = '';
        updateLoadButtons();
        const request = ++manifestRequest;
        releaseLocalPreview();
        showLoading();

        try {
            const response = await fetch(manifestUrl, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const imageUrl = extractIiifPreview(await response.json());

            if (!imageUrl) {
                throw new Error('No preview image found.');
            }

            if (request === manifestRequest) {
                showPreview(imageUrl);
            }
        } catch (error) {
            if (request === manifestRequest) {
                showError(preview.dataset.manifestErrorMessage || preview.dataset.errorMessage || '');
            }
        }
    });

    loadButton.addEventListener('click', () => {
        const url = urlInput.value.trim();

        if (!url || !urlInput.reportValidity()) {
            return;
        }

        ++manifestRequest;
        manifestInput.value = '';
        fileInput.value = '';
        updateLoadButtons();
        showPreview(url);
    });

    manifestInput.addEventListener('input', () => {
        ++manifestRequest;

        if (manifestInput.value.trim() !== '') {
            urlInput.value = '';
            fileInput.value = '';
            hidePreview();
        }

        updateLoadButtons();
    });
    manifestInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            manifestLoadButton.click();
        }
    });

    urlInput.addEventListener('input', () => {
        ++manifestRequest;

        if (urlInput.value.trim() !== '') {
            manifestInput.value = '';
            fileInput.value = '';
            hidePreview();
        }

        updateLoadButtons();
    });
    urlInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            loadButton.click();
        }
    });

    fileInput.addEventListener('change', () => {
        ++manifestRequest;
        const file = fileInput.files?.[0];

        if (!file) {
            if (initialPreviewUrl) {
                showPreview(initialPreviewUrl);
            }

            return;
        }

        manifestInput.value = '';
        urlInput.value = '';
        updateLoadButtons();
        showPreview(URL.createObjectURL(file), true);
    });

    window.addEventListener('beforeunload', releaseLocalPreview);
    updateLoadButtons();
});

function extractIiifPreview(manifest) {
    const presentation3Canvas = first(manifest?.items);
    const presentation3Page = first(presentation3Canvas?.items);
    const presentation3Annotation = first(presentation3Page?.items);
    const presentation3Body = first(presentation3Annotation?.body);
    const presentation3Image = bodyImageUrl(presentation3Body);

    if (presentation3Image) {
        return presentation3Image;
    }

    const presentation2Sequence = first(manifest?.sequences);
    const presentation2Canvas = first(presentation2Sequence?.canvases);
    const presentation2Annotation = first(presentation2Canvas?.images);
    const presentation2Image = bodyImageUrl(presentation2Annotation?.resource);

    return presentation2Image
        || imageId(first(presentation3Canvas?.thumbnail))
        || imageId(first(presentation2Canvas?.thumbnail))
        || imageId(first(manifest?.thumbnail));
}

function bodyImageUrl(body) {
    if (!body || typeof body !== 'object') {
        return null;
    }

    const service = first(body.service || body.services);
    const serviceId = imageId(service);

    if (serviceId) {
        return `${serviceId.replace(/\/info\.json$/i, '').replace(/\/$/, '')}/full/1200,/0/default.jpg`;
    }

    return imageId(body) || imageId(first(body.thumbnail));
}

function imageId(value) {
    if (typeof value === 'string') {
        return value.trim() || null;
    }

    if (!value || typeof value !== 'object') {
        return null;
    }

    const id = value.id || value['@id'];

    return typeof id === 'string' && id.trim() !== '' ? id.trim() : null;
}

function first(value) {
    return Array.isArray(value) ? value[0] : value;
}
