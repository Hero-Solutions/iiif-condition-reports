// Isolated drawing style and eraser regression checks. No server, environment files or network.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
function element(dataset = {}) {
    const classes = new Set();
    const listeners = new Map();
    const attributes = new Map();
    return {
        dataset, value: '', hidden: false, disabled: false, textContent: '',
        classList: {
            contains: value => classes.has(value),
            add: (...values) => values.forEach(value => classes.add(value)),
            remove: (...values) => values.forEach(value => classes.delete(value)),
            toggle(value, enabled) { if (enabled) classes.add(value); else classes.delete(value); },
        },
        style: { setProperty() {} },
        setAttribute(name, value) { attributes.set(name, String(value)); },
        getAttribute: name => attributes.get(name) ?? null,
        hasAttribute: name => attributes.has(name),
        removeAttribute(name) { attributes.delete(name); },
        addEventListener(name, callback) {
            const handlers = listeners.get(name) || [];
            handlers.push(callback);
            listeners.set(name, handlers);
        },
        emit(name, event = {}) { for (const callback of listeners.get(name) || []) callback(event); },
        focus() {}, blur() {}, select() {}, contains: () => false,
        querySelectorAll: () => [],
    };
}
const context = vm.createContext({
    document: { addEventListener() {}, documentElement: { lang: 'nl', dataset: {} } },
    window: { clearTimeout, setTimeout },
    structuredClone,
    OpenSeadragon: { Point: class { constructor(x, y) { this.x = x; this.y = y; } } },
    console: { error() {} },
    fetch: () => { throw new Error('No network allowed in this fixture'); },
});
vm.runInContext(fs.readFileSync(path.join(__dirname, '../public/js/report-annotation-viewer.js'), 'utf8'), context);
const Workbench = vm.runInContext('ReportAnnotationWorkbench', context);
function setup(cases = []) {
    const nodes = new Map();
    const colors = ['#d13b3b', '#3567a9'].map(color => element({ annotationColor: color }));
    const widths = ['1.4', '2.2', '3.4'].map(width => element({ annotationStrokeWidth: width }));
    const eraserWidths = ['4', '7', '11'].map(width => element({ annotationEraserWidth: width }));
    const workbench = {
        classList: element().classList,
        dataset: { drawingActiveMessage: 'Draw', eraserActiveMessage: 'Erase', saveErrorMessage: 'Error' },
        querySelector(selector) {
            assert.notEqual(selector, '[data-annotation-start]');
            assert.notEqual(selector, '[data-annotation-width-lock]');
            if (!nodes.has(selector)) {
                const node = element();
                if (['[data-annotation-preview-dot]', '[data-annotation-eraser-cursor]'].includes(selector)) {
                    // SVG circles have a hidden attribute, without HTMLElement.hidden reflection.
                    delete node.hidden;
                    node.setAttribute('hidden', '');
                }
                nodes.set(selector, node);
            }
            return nodes.get(selector);
        },
        querySelectorAll: selector => ({
            '[data-annotation-color]': colors,
            '[data-annotation-stroke-width]': widths,
            '[data-annotation-eraser-width]': eraserWidths,
        })[selector] || [],
    };
    const viewerEvents = element();
    const viewer = {
        addHandler: viewerEvents.addEventListener, emit: viewerEvents.emit,
        addOnceHandler() {}, open() {}, setMouseNavEnabled(value) { this.navigation = value; },
    };
    const annotation = { setSelected() {}, setAnnotations() {} };
    const controller = new Workbench(workbench, viewer, annotation, {}, [], { damageCases: cases }, { key: 'first' });
    controller.damageCustomButton = element();
    controller.renderSources = () => {};
    controller.bindControls();
    controller.ensureCalls = [];
    controller.ensureDamageCase = async function(label, color, strokeWidth) {
        this.ensureCalls.push({ label, color, strokeWidth });
        const found = this.findDamageCaseByLabel(label);
        if (found) {
            if (!this.hasDamageDrawings(found)) Object.assign(found, { color, strokeWidth });
            return found;
        }
        const damageCase = { id: this.damageCases.length + 1, label, color, strokeWidth };
        this.damageCases.push(damageCase);
        this.damageCasesById.set(damageCase.id, damageCase);
        return damageCase;
    };
    controller.syncDrawingActions();
    return controller;
}
const settle = () => new Promise(resolve => setImmediate(resolve));
const select = {
    damage: c => c.selectDamageValue('Kras'),
    color: c => c.colorButtons[1].emit('click'),
    width: c => c.strokeWidthButtons[2].emit('click'),
};

function shape(id, left = 0, right = 100) {
    return { id, bodies: [], target: { annotation: id, selector: {
        type: 'POLYGON', geometry: {
            points: [[left, 0], [right, 0], [right, 100], [left, 100]],
            bounds: { minX: left, minY: 0, maxX: right, maxY: 100 },
        },
    } } };
}
function fixture() {
    const controller = setup([{ id: 1, label: 'Kras', color: '#3567a9', strokeWidth: 2.2 }]);
    const library = new Map();
    const server = new Map();
    let nextId = 10;
    controller.workbench.dataset.annotationUrl = '/fixture/annotations';
    controller.viewer.world = { getItemAt: () => ({ getContentSize: () => ({ x: 800, y: 800 }) }) };
    controller.workbench.dataset.savingMessage = 'Saving';
    controller.renderLegend = () => {};
    controller.renderHistory = () => {};
    controller.annotation = {
        setSelected() {}, setAnnotations() {},
        getAnnotationById: id => library.get(id),
        addAnnotation: annotation => library.set(annotation.id, structuredClone(annotation)),
        updateAnnotation: annotation => library.set(annotation.id, structuredClone(annotation)),
        removeAnnotation: id => library.delete(id),
    };
    controller.persistAnnotation = async (record, annotation) => {
        const previous = server.get(record.clientId);
        const saved = {
            ...structuredClone(record), id: previous?.id || nextId++,
            deleted: false, annotation: structuredClone(annotation),
            createdAt: previous?.createdAt || '2026-09-25T12:00:00+00:00',
            updatedAt: '2026-09-25T13:00:00+00:00',
        };
        server.set(saved.clientId, structuredClone(saved));
        return saved;
    };
    context.requestJson = async (url, options) => {
        assert.equal(options.method, 'DELETE');
        assert.ok(url.startsWith('/fixture/annotations/'));
        const id = Number(url.split('/').at(-1));
        const saved = [...server.values()].find(record => record.id === id);
        assert.ok(saved);
        saved.deleted = true;
        return { deleted: true };
    };
    function add(id, sourceKey = 'first') {
        const record = {
            id: nextId++, clientId: id, damageCaseId: 1,
            sourceKey, reportImageId: null, deleted: false,
            annotation: shape(id), createdAt: '2026-09-25T12:00:00+00:00',
        };
        controller.setAnnotationRecord(structuredClone(record));
        controller.annotation.addAnnotation(record.annotation);
        server.set(id, structuredClone(record));
        controller.addHistoryFromRecord(record);
        return structuredClone(record);
    }
    function begin(result) {
        controller.booleanOperations = { subtractAnnotations: target => typeof result === 'function' ? result(target) : structuredClone(result) };
        controller.startEraser();
        assert.equal(controller.mode, 'erase');
        assert.equal(controller.workbench.classList.contains('is-erasing'), true);
        assert.equal(controller.drawingControls.inert, true);
        assert.equal(controller.eraserButton.style.visibility, 'hidden');
        assert.equal(controller.eraserButton.disabled, true);
        assert.equal(controller.finishButton.style.visibility, '');
        assert.equal(controller.strokeWidths.inert, true);
        assert.equal(controller.eraserWidths.inert, false);
        assert.equal(controller.damageInput.disabled, true);
    }
    return { controller, library, server, add, begin };
}
const points = [[30, 30], [35, 35]];
const selector = record => JSON.stringify(record.annotation.target.selector);
(async () => {
    for (const order of [
        ['damage', 'color', 'width'], ['damage', 'width', 'color'],
        ['color', 'damage', 'width'], ['color', 'width', 'damage'],
        ['width', 'damage', 'color'], ['width', 'color', 'damage'],
    ]) {
        const controller = setup();
        const chosen = new Set();
        assert.equal(controller.selectedStrokeWidth(), 2.2, 'The first session defaults to medium.');
        for (const step of order) {
            select[step](controller);
            chosen.add(step);
            await settle();
            assert.equal(controller.mode, chosen.has('damage') && chosen.has('color') ? 'draw' : null);
        }
        assert.equal(controller.activeStrokeWidth, 3.4);
        assert.ok(controller.colorButtons.every(button => !button.disabled), 'Color stays editable before the first line.');
        assert.ok(controller.strokeWidthButtons.every(button => !button.disabled), 'Width stays editable before the first line.');
        controller.finishDrawing();
        assert.equal(controller.activeStrokeWidth, null);
        assert.equal(controller.selectedStrokeWidth(), 3.4, 'Finishing retains the last chosen width.');
        assert.ok(controller.colorButtons.every(button => !button.disabled));
        assert.ok(controller.strokeWidthButtons.every(button => !button.disabled));
    }

    const styles = fixture();
    const drawing = styles.controller;
    const prior = styles.add('prior', 'another-image');
    drawing.ensureDamageCase = Workbench.prototype.ensureDamageCase.bind(drawing);
    drawing.viewer.viewport = { imageToViewerElementCoordinates: point => point };
    drawing.selectDamageValue('Kras');
    await settle();
    assert.equal(drawing.mode, 'draw', 'An existing case starts with the selected default width.');
    assert.equal(drawing.activeStrokeWidth, 2.2);
    assert.equal(drawing.selectedColor(), '#3567a9');
    assert.ok(drawing.colorButtons.every(button => button.disabled), 'Drawings on any image lock the shared color.');
    assert.ok(drawing.strokeWidthButtons.every(button => !button.disabled));
    drawing.colorButtons[0].emit('click');
    assert.equal(drawing.selectedColor(), '#3567a9', 'A disabled color cannot change.');
    drawing.strokeWidthButtons[0].emit('click');
    assert.ok(drawing.strokeWidthButtons.every(button => button.disabled), 'Choices lock while drawing starts.');
    await settle();
    assert.equal(drawing.mode, 'draw');
    assert.equal(drawing.activeStrokeWidth, 1.4);
    assert.ok(Math.abs(drawing.previewScreenHalfWidth() - 1.4) < 0.001, 'Preview uses the session width.');

    drawing.screenPoint = () => [20, 20];
    drawing.imagePoint = () => [20, 20];
    drawing.drawingLayer.setPointerCapture = () => {};
    drawing.drawingLayer.hasPointerCapture = () => false;
    const pointer = { pointerId: 1, pointerType: 'mouse', button: 0, preventDefault() {} };
    drawing.onPointerDown(pointer);
    assert.ok(drawing.strokeWidthButtons.every(button => button.disabled), 'A stroke in progress locks the width.');
    drawing.onPointerCancel(pointer);
    assert.ok(drawing.strokeWidthButtons.every(button => !button.disabled), 'A cancelled pointer gesture unlocks it again.');

    const persistStyleDrawing = drawing.persistAnnotation;
    let releaseDrawing;
    drawing.persistAnnotation = (...args) => new Promise(resolve => {
        releaseDrawing = async () => resolve(await persistStyleDrawing(...args));
    });
    const savingDrawing = drawing.createDrawing([[20, 20], [100, 20]]);
    assert.ok(drawing.strokeWidthButtons.every(button => button.disabled), 'Pending lines also lock the style.');
    drawing.strokeWidthButtons[2].emit('click');
    drawing.colorButtons[0].emit('click');
    assert.equal(drawing.selectedStrokeWidth(), 1.4);
    assert.equal(drawing.selectedColor(), '#3567a9');
    await releaseDrawing();
    await savingDrawing;
    drawing.persistAnnotation = persistStyleDrawing;
    const thin = structuredClone(styles.server.get(drawing.sessionDrawingIds[0]));
    assert.ok(Math.abs(thin.annotation.target.selector.geometry.bounds.maxY - 21.4) < 0.001);
    drawing.finishDrawing();
    drawing.selectDamageValue('Kras');
    await settle();
    assert.equal(drawing.mode, 'draw');
    assert.equal(drawing.selectedStrokeWidth(), 1.4, 'The next session reuses the last chosen width.');
    assert.equal(drawing.activeStrokeWidth, 1.4);
    assert.ok(drawing.colorButtons.every(button => button.disabled));
    drawing.strokeWidthButtons[2].emit('click');
    await settle();
    assert.equal(drawing.activeStrokeWidth, 3.4);
    assert.ok(Math.abs(drawing.previewScreenHalfWidth() - 3.4) < 0.001);
    await drawing.createDrawing([[20, 20], [100, 20]]);
    const thick = structuredClone(styles.server.get(drawing.sessionDrawingIds[0]));
    assert.ok(Math.abs(thick.annotation.target.selector.geometry.bounds.maxY - 23.4) < 0.001);
    assert.equal(selector(styles.server.get(thin.clientId)), selector(thin), 'Earlier lines retain their geometry.');
    assert.equal(selector(styles.server.get(prior.clientId)), selector(prior));
    assert.equal(drawing.damageCases[0].strokeWidth, 2.2, 'The legend symbol width stays unchanged.');
    assert.equal(drawing.styleForAnnotation(thin.annotation).fill, drawing.styleForAnnotation(thick.annotation).fill);
    drawing.damageCases[0].color = '#d13b3b'; // Same shared update as saving a legend color.
    assert.equal(drawing.styleForAnnotation(thin.annotation).fill, '#d13b3b');
    assert.equal(drawing.styleForAnnotation(thick.annotation).fill, '#d13b3b');
    await drawing.undoLastDrawing();
    assert.ok(styles.server.get(thick.clientId).deleted);
    assert.equal(selector(styles.server.get(thin.clientId)), selector(thin));
    assert.ok(drawing.strokeWidthButtons.every(button => !button.disabled), 'Undoing every session line unlocks the width.');
    assert.ok(drawing.colorButtons.every(button => button.disabled), 'Older case lines still lock the shared color.');
    drawing.finishDrawing();
    drawing.selectDamageValue('Nieuw schadegeval');
    assert.ok(drawing.colorButtons.every(button => !button.disabled), 'A different, new case can choose its color.');

    const emptyFixture = fixture();
    const empty = emptyFixture.controller;
    empty.ensureDamageCase = Workbench.prototype.ensureDamageCase.bind(empty);
    empty.workbench.dataset.damageCaseUrl = '/fixture/damage-cases';
    const requests = [];
    context.requestJson = async (url, options) => {
        assert.equal(url, '/fixture/damage-cases/1');
        assert.equal(options.method, 'PATCH');
        requests.push(options.body);
        return { ...empty.damageCases[0], ...options.body };
    };
    empty.selectDamageValue('Kras');
    await settle();
    assert.ok(empty.colorButtons.every(button => !button.disabled), 'An empty case can still change its color.');
    empty.colorButtons[0].emit('click');
    await settle();
    assert.equal(empty.activeDamageCase.color, '#d13b3b', 'Color changes after drawing mode starts but before a line.');
    empty.strokeWidthButtons[0].emit('click');
    await settle();
    assert.equal(JSON.stringify(requests), JSON.stringify([
        { color: '#d13b3b', strokeWidth: 2.2 }, { color: '#d13b3b', strokeWidth: 1.4 },
    ]));
    assert.equal(empty.activeDamageCase.color, '#d13b3b');
    assert.equal(empty.activeStrokeWidth, 1.4);
    empty.finishDrawing();
    empty.selectDamageValue('Kras');
    await settle();
    context.requestJson = async () => { throw new Error('Saving failed'); };
    empty.strokeWidthButtons[2].emit('click');
    await settle();
    assert.equal(empty.mode, null, 'A failed request never starts drawing.');
    assert.ok(empty.colorButtons.every(button => !button.disabled));
    assert.ok(empty.strokeWidthButtons.every(button => !button.disabled));
    assert.equal(empty.damageCases[0].strokeWidth, 1.4, 'Failure preserves the saved style.');

    const failedLine = fixture();
    failedLine.controller.selectDamageValue('Kras');
    await settle();
    const persistLine = failedLine.controller.persistAnnotation;
    failedLine.controller.persistAnnotation = async () => { throw new Error('Drawing failed'); };
    await assert.rejects(failedLine.controller.createDrawing([[20, 20]]), /Drawing failed/);
    assert.ok(failedLine.controller.colorButtons.every(button => !button.disabled));
    assert.ok(failedLine.controller.strokeWidthButtons.every(button => !button.disabled));
    failedLine.controller.persistAnnotation = persistLine;
    await failedLine.controller.createDrawing([[20, 20]]);
    assert.ok(failedLine.controller.colorButtons.every(button => button.disabled));
    assert.ok(failedLine.controller.strokeWidthButtons.every(button => button.disabled));
    await failedLine.controller.undoLastDrawing();
    assert.ok(failedLine.controller.colorButtons.every(button => !button.disabled), 'An empty case can change color again after undo.');
    assert.ok(failedLine.controller.strokeWidthButtons.every(button => !button.disabled));

    const hover = fixture();
    hover.add('hover');
    const c = hover.controller;
    const beforeHover = JSON.stringify([...hover.server.values()]);
    const mouse = { pointerType: 'mouse', pointerId: 1, clientX: 90, clientY: 120 };
    let zoom = 1;
    c.viewer.viewport = { imageToViewerElementCoordinates: point => ({ x: point.x * zoom, y: point.y * zoom }) };
    c.drawingLayer.getBoundingClientRect = () => ({ left: 40, top: 60 });
    c.drawingLayer.hasPointerCapture = () => false;
    c.drawingLayer.emit('pointerenter', mouse);
    assert.equal(c.eraserCursor.hasAttribute('hidden'), true, 'No eraser cursor outside eraser mode.');
    hover.begin(null);
    c.drawingLayer.emit('pointerenter', mouse);
    assert.equal(c.eraserCursor.hasAttribute('hidden'), false, 'Entering the image shows the SVG circle before any click.');
    assert.equal(c.eraserCursor.getAttribute('cx'), '50');
    assert.equal(c.eraserCursor.getAttribute('cy'), '60');
    assert.equal(c.eraserCursor.getAttribute('r'), '7');
    c.drawingLayer.emit('pointermove', { ...mouse, clientX: 100, clientY: 140 });
    assert.equal(c.eraserCursor.getAttribute('cx'), '60');
    assert.equal(c.eraserCursor.getAttribute('cy'), '80');
    c.eraserWidthButtons[2].emit('click');
    assert.equal(c.eraserCursor.getAttribute('r'), '11', 'Cursor follows the selected eraser size.');
    zoom = 2;
    c.viewer.emit('animation');
    assert.equal(c.eraserCursor.getAttribute('r'), '22', 'Cursor follows image zoom.');
    assert.equal(c.pointerState, null);
    assert.equal(c.eraserUndoStack.length, 0);
    assert.equal(JSON.stringify([...hover.server.values()]), beforeHover, 'Hovering never changes annotations.');
    c.drawingLayer.emit('pointerleave');
    assert.equal(c.eraserCursor.hasAttribute('hidden'), true);
    c.drawingLayer.emit('pointerenter', mouse);
    assert.equal(c.eraserCursor.hasAttribute('hidden'), false);
    c.setMode('draw');
    c.drawingLayer.emit('pointermove', mouse);
    assert.equal(c.eraserCursor.hasAttribute('hidden'), true, 'Drawing never shows the eraser cursor.');
    c.setMode('erase');
    c.drawingLayer.emit('pointerenter', { ...mouse, pointerType: 'touch' });
    assert.equal(c.eraserCursor.hasAttribute('hidden'), true, 'Touch entry waits for actual contact.');
    c.drawingLayer.emit('pointerenter', mouse);
    c.pointerState = { screenPoints: [[60, 80]] };
    c.updatePreview();
    assert.equal(c.previewDot.hasAttribute('hidden'), false);
    c.finishDrawing();
    assert.equal(c.eraserCursor.hasAttribute('hidden'), true);
    assert.equal(c.previewDot.hasAttribute('hidden'), true);

    let f = fixture();
    let original = f.add('original');
    f.begin(shape('original', 0, 45));
    await f.controller.eraseDrawing(points);
    assert.equal(f.controller.eraserUndoStack.length, 1);
    assert.notEqual(selector(f.server.get('original')), selector(original));
    assert.equal(f.controller.undoButton.disabled, false);
    await f.controller.undoLastErasure();
    assert.equal(selector(f.server.get('original')), selector(original));
    assert.equal(selector(f.controller.annotationRecords.get('original')), selector(original));
    assert.equal(f.controller.eraserUndoStack.length, 0);
    assert.equal(f.controller.undoButton.disabled, true);

    f = fixture();
    original = f.add('whole');
    f.begin(null);
    await f.controller.eraseDrawing(points);
    assert.equal(f.library.size, 0);
    assert.equal(f.server.get('whole').deleted, true);
    assert.equal(f.controller.undoButton.disabled, false);
    await f.controller.undoLastErasure();
    assert.equal(f.library.size, 1);
    assert.equal(f.server.get('whole').deleted, false);
    assert.equal(f.controller.history.find(item => item.id === original.id).deleted, false);
    assert.equal(selector(f.server.get('whole')), selector(original));

    function split(target) {
        const polygons = [shape(target.id, 0, 40), shape(target.id, 60, 100)].map(part => ({
            rings: [{ points: part.target.selector.geometry.points }],
            bounds: part.target.selector.geometry.bounds,
        }));
        return { ...target, target: { ...target.target, selector: {
            type: 'MULTIPOLYGON', geometry: { polygons, bounds: target.target.selector.geometry.bounds },
        } } };
    }
    for (const responseLost of [false, true]) {
        f = fixture();
        original = f.add('split');
        f.begin(split);
        if (responseLost) {
            const persist = f.controller.persistAnnotation;
            let lost = false;
            f.controller.persistAnnotation = async (record, annotation) => {
                const saved = await persist(record, annotation);
                if (record.clientId !== 'split' && !lost) { lost = true; throw new Error('Response lost'); }
                return saved;
            };
            await assert.rejects(f.controller.eraseDrawing(points), /Response lost/);
        } else {
            await f.controller.eraseDrawing(points);
            assert.equal(f.library.size, 2);
        }
        assert.equal(f.controller.eraserUndoStack.length, 1);
        assert.equal(f.controller.eraserUndoStack[0].created.length, 1);
        await f.controller.undoLastErasure();
        assert.equal([...f.server.values()].filter(record => !record.deleted).length, 1);
        assert.equal(f.library.size, 1);
        assert.equal(selector(f.server.get('split')), selector(original));
    }

    f = fixture();
    original = f.add('first');
    const second = f.add('second');
    const otherImage = f.add('other', 'second');
    f.begin(target => shape(target.id, 0, 45));
    await f.controller.eraseDrawing(points);
    assert.equal(f.controller.eraserUndoStack.length, 1, 'A gesture affecting multiple annotations is one undo step.');
    assert.equal(f.controller.eraserUndoStack[0].originals.length, 2);
    await f.controller.undoLastErasure();
    for (const record of [original, second, otherImage]) assert.equal(selector(f.server.get(record.clientId)), selector(record));

    f = fixture();
    original = f.add('multiple');
    const results = [shape('multiple', 0, 75), shape('multiple', 0, 50)];
    f.begin(() => results.shift());
    await f.controller.eraseDrawing(points);
    await f.controller.eraseDrawing(points);
    assert.equal(f.controller.eraserUndoStack.length, 2);
    await f.controller.undoLastErasure();
    assert.equal(selector(f.server.get('multiple')), JSON.stringify(shape('multiple', 0, 75).target.selector));
    await f.controller.undoLastErasure();
    assert.equal(selector(f.server.get('multiple')), selector(original));

    f = fixture();
    f.add('no-change');
    f.begin(target => structuredClone(target));
    await f.controller.eraseDrawing(points);
    assert.equal(f.controller.eraserUndoStack.length, 0);
    await f.controller.eraseDrawing([[500, 500]]);
    assert.equal(f.controller.eraserUndoStack.length, 0);

    f = fixture();
    original = f.add('retry');
    f.begin(shape('retry', 0, 45));
    const persist = f.controller.persistAnnotation;
    f.controller.persistAnnotation = async () => { throw new Error('Saving failed'); };
    await assert.rejects(f.controller.eraseDrawing(points), /Saving failed/);
    assert.equal(f.controller.eraserUndoStack.length, 1);
    await assert.rejects(f.controller.undoLastErasure(), /Saving failed/);
    assert.equal(f.controller.eraserUndoStack.length, 1, 'Failed undo must remain retryable.');
    assert.equal(f.controller.eraserBusy, false);
    f.controller.persistAnnotation = persist;
    await f.controller.undoLastErasure();
    assert.equal(selector(f.server.get('retry')), selector(original));

    f = fixture();
    f.add('pending');
    f.begin(shape('pending', 0, 45));
    const save = f.controller.persistAnnotation;
    let release;
    f.controller.persistAnnotation = (...args) => new Promise(resolve => { release = async () => resolve(await save(...args)); });
    const pending = f.controller.eraseDrawing(points);
    assert.equal(f.controller.eraserBusy, true);
    assert.equal(f.controller.finishButton.disabled, true);
    assert.equal(f.controller.undoButton.disabled, true);
    assert.equal(f.controller.legend.inert, true);
    assert.equal(f.controller.sourceList.inert, true);
    f.controller.finishDrawing();
    f.controller.switchSource({ key: 'second' });
    await f.controller.undoLastErasure();
    await f.controller.eraseDrawing(points);
    assert.equal(f.controller.currentSource.key, 'first');
    assert.equal(f.controller.mode, 'erase');
    await release();
    await pending;
    assert.equal(f.controller.eraserBusy, false);
    assert.equal(f.controller.eraserUndoStack.length, 1);
    f.controller.finishDrawing();
    assert.equal(f.controller.eraserUndoStack.length, 0);
    assert.equal(f.controller.workbench.classList.contains('is-erasing'), false);
    assert.equal(f.controller.drawingControls.inert, false);
    assert.equal(f.controller.eraserButton.style.visibility, '');
    assert.equal(f.controller.eraserButton.disabled, false);
    assert.equal(f.controller.finishButton.style.visibility, 'hidden');
    assert.equal(f.controller.strokeWidths.inert, false);
    assert.equal(f.controller.eraserWidths.inert, true);
    assert.equal(f.controller.legend.inert, false);
    console.log('PASS: per-session widths, unchanged earlier geometry, shared color locks, cancelled sessions and style save failures; hover cursor visibility, position, size and zoom; erase/undo persistence, full deletion, split fragments, lost responses, multiple targets, repeated undo, no-op strokes, retry after failure, busy guards and mode controls.');
})().catch(error => { console.error(error); process.exitCode = 1; });
