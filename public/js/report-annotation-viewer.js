document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('report-annotation-viewer');

    if (!container) {
        return;
    }

    const initWhenVisible = () => {
        if (!container.closest('[hidden]')) {
            initAnnotationViewer(container);
        }
    };

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-report-tab="damage"]')) {
            window.setTimeout(initWhenVisible, 0);
        }
    });

    initWhenVisible();
});

async function initAnnotationViewer(container) {
    if (container.dataset.initialized === '1') {
        return;
    }

    container.dataset.initialized = '1';
    container.textContent = '';

    const manifestUrl = container.dataset.manifestUrl || '';

    try {
        const tileSources = await loadTileSources(manifestUrl);

        if (tileSources.length === 0) {
            throw new Error('No IIIF image service found.');
        }

        const viewer = OpenSeadragon({
            id: container.id,
            prefixUrl: '/images/',
            tileSources,
            sequenceMode: tileSources.length > 1,
        });

        const annotation = OpenSeadragon.Annotorious(viewer, {
            locale: 'auto',
            styleColor: 'red',
            styleFill: '',
            styleClass: 'condition-red',
        });

        window.reportAnnotationViewer = viewer;
        window.reportAnnotation = annotation;
    } catch (error) {
        container.dataset.initialized = '0';
        container.textContent = container.dataset.errorMessage || error.message;
    }
}

async function loadTileSources(manifestUrl) {
    if (manifestUrl.endsWith('/info.json')) {
        return [manifestUrl];
    }

    const response = await fetch(manifestUrl, {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
    });

    if (!response.ok) {
        throw new Error(`IIIF manifest kon niet geladen worden (${response.status}).`);
    }

    return extractTileSources(await response.json(), manifestUrl);
}

function extractTileSources(manifest, manifestUrl) {
    const tileSources = [
        ...extractPresentation3TileSources(manifest),
        ...extractPresentation2TileSources(manifest),
    ];

    return tileSources.length > 0 ? tileSources : [manifestUrl];
}

function extractPresentation3TileSources(manifest) {
    return asArray(manifest.items)
        .flatMap((canvas) => asArray(canvas.items))
        .flatMap((page) => asArray(page.items))
        .flatMap((annotation) => asArray(annotation.body))
        .map(bodyToTileSource)
        .filter(Boolean);
}

function extractPresentation2TileSources(manifest) {
    return asArray(manifest.sequences)
        .flatMap((sequence) => asArray(sequence.canvases))
        .flatMap((canvas) => asArray(canvas.images))
        .map((annotation) => annotation.resource)
        .map(bodyToTileSource)
        .filter(Boolean);
}

function bodyToTileSource(body) {
    if (!body || typeof body !== 'object') {
        return null;
    }

    const service = asArray(body.service || body.services)
        .map(serviceToTileSource)
        .find(Boolean);

    if (service) {
        return service;
    }

    const imageUrl = stringValue(body.id || body['@id']);

    return imageUrl ? { type: 'image', url: imageUrl } : null;
}

function serviceToTileSource(service) {
    if (!service || typeof service !== 'object') {
        return null;
    }

    const serviceId = stringValue(service.id || service['@id']);

    if (!serviceId) {
        return null;
    }

    return serviceId.endsWith('/info.json') ? serviceId : `${serviceId.replace(/\/$/, '')}/info.json`;
}

function asArray(value) {
    if (Array.isArray(value)) {
        return value;
    }

    return value ? [value] : [];
}

function stringValue(value) {
    return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}
