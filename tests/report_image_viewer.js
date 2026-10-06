// Isolated viewer checks: fake DOM, IIIF fixtures and viewer; no network or app startup.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function element(tag = 'div', dataset = {}) {
    const classes = new Set();
    const attributes = new Map();
    const listeners = new Map();
    return {
        dataset, children: [], hidden: false, disabled: false, open: false,
        classList: {
            contains: name => classes.has(name),
            add: name => classes.add(name),
            remove: name => classes.delete(name),
        },
        matches: selector => selector === tag,
        setAttribute: (name, value) => attributes.set(name, value),
        getAttribute: name => attributes.get(name),
        querySelector: () => null,
        addEventListener(name, callback) { listeners.set(name, callback); },
        emit(name, event = {}) { return listeners.get(name)?.(event); },
        append(child) { this.children.push(child); },
        focus() { this.focused = true; },
        showModal() { this.open = true; },
        close() { this.open = false; this.emit('close'); },
    };
}

function setup({ manifest = '', info = '', custom = false, sourceKey = '', svg = null, respond } = {}) {
    const image = element('img');
    image.src = '/fixtures/image.jpg';
    image.setAttribute('src', image.src);
    if (!sourceKey) image.classList.add('report-document-image');
    const trigger = sourceKey ? element('div', { sourceKey, imageLabel: 'Damage image' }) : image;
    trigger.querySelector = selector => selector === 'img' ? image : selector === 'svg' ? svg : null;
    const controls = ['in', 'out', 'reset'].map(imageZoom => element('button', { imageZoom }));
    const close = element('button');
    const status = element();
    const title = element();
    const canvas = element();
    const dialog = element('dialog', {
        mainManifestUrl: manifest, mainInfoUrl: info, customMainImage: custom ? '1' : '0',
        mainLabel: 'Main image', imageLabel: 'Image', openLabel: 'Enlarge image',
        loadingLabel: 'Loading', errorLabel: 'Failed',
    });
    dialog.querySelectorAll = () => controls;
    dialog.querySelector = selector => ({
        '[data-image-viewer]': canvas, '[data-image-viewer-title]': title,
        '[data-image-viewer-status]': status, '[data-image-viewer-close]': close,
    })[selector];
    const document = element();
    document.documentElement = element();
    document.createElement = tag => element(tag);
    document.querySelector = selector => selector === '[data-report-image-dialog]' ? dialog : { querySelectorAll: () => [trigger] };
    const viewers = [];
    const requests = [];
    const OpenSeadragon = options => {
        const viewer = element();
        Object.assign(viewer, {
            options, opened: [], overlays: [], zooms: [],
            addHandler: viewer.addEventListener,
            open(source) { this.opened.push(source); },
            addOverlay(overlay) { this.overlays.push(overlay); },
            destroy() { this.destroyed = true; },
            world: { getItemAt: () => ({ getBounds: () => 'image-bounds' }) },
            viewport: {
                zoomBy: factor => viewer.zooms.push(factor),
                goHome: () => { viewer.home = true; },
                applyConstraints() {},
            },
        });
        viewers.push(viewer);
        return viewer;
    };
    const window = element();
    window.OpenSeadragon = OpenSeadragon;
    const context = vm.createContext({ document, window, OpenSeadragon, fetch: async url => {
        requests.push(url);
        assert.equal(url, '/fixtures/manifest.json');
        if (!respond) throw new Error('No network allowed');
        return { ok: true, json: respond };
    } });
    for (const file of ['iiif-image-sources.js', 'report-image-viewer.js']) {
        vm.runInContext(fs.readFileSync(path.join(__dirname, '../public/js', file), 'utf8'), context);
    }
    document.emit('DOMContentLoaded');
    return { trigger, image, dialog, controls, close, status, title, document, window, viewers, requests, context };
}

const settle = () => new Promise(resolve => setImmediate(resolve));
const v3 = { items: [{ items: [{ items: [{ body: [
    { id: '/fixtures/a.jpg', service: { id: '/fixtures/a', type: 'ImageService3' } },
    { id: '/fixtures/b.jpg', service: { id: '/fixtures/b', type: 'ImageService3' } },
] }] }] }] };
const v2 = { sequences: [{ canvases: [{ images: [{ resource: {
    '@id': '/fixtures/a.jpg', service: { '@id': '/fixtures/a' },
} }] }, { images: [{ resource: { '@id': '/fixtures/b.jpg' } }] }] }] };

(async () => {
    const basic = setup();
    let prevented = false;
    basic.trigger.emit('keydown', { key: ' ', preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(basic.trigger.getAttribute('role'), 'button');
    assert.equal(basic.dialog.open, true);
    assert.equal(basic.title.textContent, 'Main image');
    assert.equal(basic.viewers[0].opened[0].url, basic.image.src);
    assert.equal(basic.controls.every(button => button.disabled), true);
    basic.viewers[0].emit('open');
    basic.controls.forEach(button => button.emit('click'));
    assert.deepEqual(basic.viewers[0].zooms, [1.5, 1 / 1.5]);
    assert.equal(basic.viewers[0].home, true);
    assert.equal(basic.status.hidden, true);
    basic.close.emit('click');
    assert.equal(basic.viewers[0].destroyed, true);
    assert.equal(basic.trigger.focused, true);
    assert.equal(basic.document.documentElement.classList.contains('report-image-viewer-open'), false);
    basic.trigger.emit('click');
    assert.equal(basic.viewers.length, 2);
    basic.window.emit('beforeprint');
    assert.equal(basic.dialog.open, false);

    const svgClone = { readOnly: true };
    const annotated = setup({ manifest: '/fixtures/manifest.json', sourceKey: 'main:2',
        svg: { cloneNode: deep => { assert.equal(deep, true); return svgClone; } }, respond: () => v3 });
    annotated.trigger.emit('click');
    await settle();
    assert.equal(annotated.viewers[0].opened[0], '/fixtures/b/info.json');
    annotated.viewers[0].emit('open');
    const overlay = annotated.viewers[0].overlays[0];
    assert.equal(overlay.location, 'image-bounds');
    assert.equal(overlay.element.children[0], svgClone);
    assert.equal(overlay.element.getAttribute('aria-hidden'), 'true');
    assert.equal(annotated.title.textContent, 'Damage image');

    const parser = setup({ respond: () => v2 });
    const sources = await vm.runInContext("loadTileSources('/fixtures/manifest.json')", parser.context);
    assert.equal(sources[0], '/fixtures/a/info.json');
    assert.equal(sources[1].type, 'image');
    assert.equal(sources[1].url, '/fixtures/b.jpg');

    const info = setup({ info: '/fixtures/main/info.json' });
    info.trigger.emit('click');
    await settle();
    assert.equal(info.requests.length, 0);
    assert.equal(info.viewers[0].opened[0], '/fixtures/main/info.json');
    info.viewers[0].emit('open-failed');
    assert.equal(info.viewers[0].opened[1].url, info.image.src);
    info.viewers[0].emit('open-failed');
    assert.equal(info.viewers[0].opened.length, 2);
    assert.equal(info.status.textContent, 'Failed');
    assert.equal(info.controls.every(button => button.disabled), true);

    const custom = setup({ custom: true, info: '/fixtures/other/info.json' });
    custom.trigger.emit('click');
    assert.equal(custom.viewers[0].opened[0].url, custom.image.src);
    const photo = setup({ sourceKey: 'photo:1', manifest: '/fixtures/manifest.json' });
    photo.trigger.emit('click');
    assert.equal(photo.requests.length, 0);
    assert.equal(photo.viewers[0].opened[0].url, photo.image.src);

    const unavailable = setup({ manifest: '/fixtures/manifest.json' });
    unavailable.trigger.emit('click');
    await settle();
    assert.equal(unavailable.viewers[0].opened[0].url, unavailable.image.src);

    let resolve;
    const pending = setup({ manifest: '/fixtures/manifest.json', respond: () => new Promise(done => { resolve = done; }) });
    pending.trigger.emit('click');
    await settle();
    pending.close.emit('click');
    resolve(v3);
    await settle();
    assert.equal(pending.viewers.length, 0);
    console.log('Report image viewer: keyboard, zoom, IIIF v2/v3, annotations, fallback, close and print checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
