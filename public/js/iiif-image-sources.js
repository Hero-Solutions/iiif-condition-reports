async function loadTileSources(manifestUrl) {
    if (/\/info\.json(?:\?|$)/.test(manifestUrl)) {
        return [manifestUrl];
    }

    const response = await fetch(manifestUrl, {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
    });

    if (!response.ok) {
        throw new Error(`IIIF manifest kon niet geladen worden (${response.status}).`);
    }

    const manifest = await response.json();

    return [
        ...extractPresentation3TileSources(manifest),
        ...extractPresentation2TileSources(manifest),
    ];
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
    const serviceId = typeof service === 'string'
        ? stringValue(service)
        : stringValue(service?.id || service?.['@id']);

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

