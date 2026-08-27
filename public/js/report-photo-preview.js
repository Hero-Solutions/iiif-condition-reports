(() => {
    document.addEventListener('DOMContentLoaded', () => {
        for (const form of document.querySelectorAll('[data-report-photo-preview-form]')) {
            initializePhotoPreview(form);
        }
    });

    function initializePhotoPreview(form) {
        const kind = form.dataset.previewKind;
        const fileInput = form.querySelector('[data-report-photo-file]');
        const urlInput = form.querySelector('[data-report-photo-url]');
        const loadButton = form.querySelector('[data-report-photo-load]');
        const submitButton = form.querySelector('[data-report-photo-submit]');
        const preview = form.querySelector('[data-report-photo-preview]');
        const images = form.querySelector('[data-report-photo-preview-images]');
        const status = form.querySelector('[data-report-photo-preview-status]');
        let objectUrls = [];
        let requestNumber = 0;

        if (!submitButton || !preview || !images || !status) {
            return;
        }

        const releaseObjectUrls = () => {
            for (const url of objectUrls) {
                URL.revokeObjectURL(url);
            }

            objectUrls = [];
        };

        const clearPreview = () => {
            releaseObjectUrls();
            preview.hidden = true;
            images.textContent = '';
            status.hidden = true;
            status.classList.remove('error');
            status.textContent = '';
            submitButton.disabled = true;
        };

        const showStatus = (message, error = false) => {
            preview.hidden = false;
            images.textContent = '';
            status.hidden = false;
            status.classList.toggle('error', error);
            status.textContent = message;
            submitButton.disabled = true;
        };

        const showImages = async (urls, currentRequest) => {
            preview.hidden = false;
            images.textContent = '';
            status.hidden = true;
            status.classList.remove('error');

            try {
                await Promise.all(urls.map((url) => appendPreviewImage(images, url)));

                if (currentRequest === requestNumber) {
                    submitButton.disabled = false;
                }
            } catch (error) {
                if (currentRequest === requestNumber) {
                    showStatus(form.dataset.previewError || '', true);
                }
            }
        };

        if (kind === 'files' && fileInput) {
            fileInput.addEventListener('change', () => {
                const currentRequest = ++requestNumber;
                clearPreview();
                const files = [...(fileInput.files || [])];

                if (files.length === 0) {
                    return;
                }

                objectUrls = files.map((file) => URL.createObjectURL(file));
                showImages(objectUrls, currentRequest);
            });
        }

        if ((kind === 'url' || kind === 'iiif') && urlInput && loadButton) {
            const updateLoadButton = () => {
                loadButton.disabled = urlInput.value.trim() === '';
            };

            urlInput.addEventListener('input', () => {
                ++requestNumber;
                clearPreview();
                updateLoadButton();
            });
            urlInput.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    loadButton.click();
                }
            });

            loadButton.addEventListener('click', async () => {
                const url = urlInput.value.trim();

                if (!url || !urlInput.reportValidity()) {
                    return;
                }

                const currentRequest = ++requestNumber;
                showStatus(form.dataset.previewLoading || '');

                try {
                    const previewUrl = kind === 'iiif' ? await loadIiifPreview(url) : url;

                    if (currentRequest === requestNumber) {
                        await showImages([previewUrl], currentRequest);
                    }
                } catch (error) {
                    if (currentRequest === requestNumber) {
                        showStatus(form.dataset.previewError || '', true);
                    }
                }
            });

            updateLoadButton();
        }

        form.addEventListener('submit', (event) => {
            if (submitButton.disabled) {
                event.preventDefault();
            }
        });
        window.addEventListener('beforeunload', releaseObjectUrls);
    }

    function appendPreviewImage(container, url) {
        return new Promise((resolve, reject) => {
            const image = document.createElement('img');
            image.alt = '';
            image.onload = () => resolve();
            image.onerror = () => reject(new Error('Preview could not be loaded.'));
            image.src = url;
            container.append(image);
        });
    }

    async function loadIiifPreview(url) {
        const response = await fetch(url, {
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

        return imageUrl;
    }

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

        const serviceId = imageId(first(body.service || body.services));

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
})();
