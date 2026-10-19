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
    const annotation = { setSelected() {}, setAnnotations() {}, setStyle() {} };
    const controller = new Workbench(workbench, viewer, annotation, {}, [], { damageCases: cases }, { key: 'first' });
    controller.damageCustomButton = element();
    controller.renderSources = () => {};
    controller.bindControls();
    controller.ensureCalls = [];
    controller.ensureDamageCase = async function(label, color, strokeWidth) {
        this.ensureCalls.push({ label, color, strokeWidth });
        const found = this.findDamageCaseByLabel(label);
        if (found) {
            found.color = color;
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
// Every thumbnail action waits for drawing/erasing to finish.
{
    const controller = setup();
    const actions = [element(), element()];
    controller.sourceList.querySelectorAll = () => actions;
    controller.syncDrawingActions();
    assert.ok(actions.every(button => button.disabled === false));
    controller.mode = 'draw';
    controller.syncDrawingActions();
    assert.ok(actions.every(button => button.disabled === true), 'Finish drawing before changing photos.');
    controller.mode = null;
    controller.eraserBusy = true;
    controller.syncDrawingActions();
    assert.ok(actions.every(button => button.disabled === true), 'Wait for annotation changes to finish.');
    controller.eraserBusy = false;
    controller.switchSource({ key: 'main' });
    assert.ok(actions.every(button => button.disabled === false));
}
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
    const caseServer = new Map(controller.damageCases.map(damageCase => [damageCase.id, structuredClone(damageCase)]));
    let nextId = 10;
    controller.workbench.dataset.annotationUrl = '/fixture/annotations';
    controller.workbench.dataset.damageCaseUrl = '/fixture/damage-cases';
    controller.viewer.world = { getItemAt: () => ({ getContentSize: () => ({ x: 800, y: 800 }) }) };
    controller.workbench.dataset.savingMessage = 'Saving';
    controller.renderLegend = () => {};
    controller.renderHistory = () => {};
    controller.annotation = {
        setSelected() {}, setAnnotations() {},
        setStyle(style) { this.style = style; },
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
        if (options.method === 'PATCH') {
            assert.ok(url.startsWith('/fixture/damage-cases/'));
            assert.equal(JSON.stringify(Object.keys(options.body)), '["color"]', 'Changing color must never send a global width update.');
            const saved = caseServer.get(Number(url.split('/').at(-1)));
            Object.assign(saved, options.body);
            return structuredClone(saved);
        }
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
    return { controller, library, server, caseServer, add, begin };
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
    const otherCase = { id: 2, label: 'Ander schadegeval', color: '#3567a9', strokeWidth: 2.2 };
    drawing.damageCases.push(otherCase);
    drawing.damageCasesById.set(2, otherCase);
    const otherDrawing = { ...prior, clientId: 'other-case', damageCaseId: 2, annotation: shape('other-case') };
    drawing.setAnnotationRecord(otherDrawing);
    drawing.ensureDamageCase = Workbench.prototype.ensureDamageCase.bind(drawing);
    drawing.viewer.viewport = { imageToViewerElementCoordinates: point => point };
    drawing.selectDamageValue('Kras');
    await settle();
    assert.equal(drawing.mode, 'draw');
    assert.equal(drawing.activeStrokeWidth, 2.2);
    assert.equal(drawing.selectedColor(), '#3567a9');
    const available = controller => {
        assert.ok(controller.colorButtons.every(button => !button.disabled), 'Color controls always remain available.');
        assert.ok(controller.strokeWidthButtons.every(button => !button.disabled), 'Width controls always remain available.');
    };
    available(drawing);
    drawing.strokeWidthButtons[0].emit('click');
    assert.equal(drawing.activeStrokeWidth, 1.4);

    const persistStyleDrawing = drawing.persistAnnotation;
    let releaseDrawing;
    drawing.persistAnnotation = (...args) => new Promise(resolve => {
        releaseDrawing = async () => resolve(await persistStyleDrawing(...args));
    });
    const savingDrawing = drawing.createDrawing([[20, 20], [100, 20]]);
    available(drawing);
    drawing.strokeWidthButtons[2].emit('click');
    drawing.colorButtons[0].emit('click');
    available(drawing);
    assert.equal(drawing.activeStrokeWidth, 3.4, 'Width changes immediately, even while a line is saving.');
    await settle();
    assert.equal(styles.caseServer.get(1).color, '#d13b3b', 'Shared color is persisted.');
    assert.equal(drawing.annotation.style(prior.annotation).fill, '#d13b3b', 'Existing drawings on other images share the new color.');
    assert.equal(drawing.annotation.style(otherDrawing.annotation).fill, '#3567a9', 'Other damage cases retain their own color.');
    assert.equal(drawing.finishButton.classList.contains('has-changes'), true, 'Changing only the color of existing drawings also counts as a change.');
    assert.equal(drawing.undoButton.disabled, true, 'Color changes do not enable drawing undo without a session drawing.');
    await releaseDrawing();
    await savingDrawing;
    drawing.persistAnnotation = persistStyleDrawing;
    const thin = structuredClone(styles.server.get(drawing.sessionDrawingIds[0]));
    assert.ok(Math.abs(thin.annotation.target.selector.geometry.bounds.maxY - 21.4) < 0.001);
    assert.equal(drawing.styleForAnnotation(thin.annotation).fill, '#d13b3b');

    // The next stroke in the SAME session is thicker, without rewriting older geometry.
    await drawing.createDrawing([[20, 20], [100, 20]]);
    assert.equal(drawing.sessionDrawingIds.length, 2, 'Style changes preserve the session and undo history.');
    const thick = structuredClone(styles.server.get(drawing.sessionDrawingIds[1]));
    assert.ok(Math.abs(thick.annotation.target.selector.geometry.bounds.maxY - 23.4) < 0.001);
    assert.equal(selector(styles.server.get(thin.clientId)), selector(thin));
    assert.equal(selector(styles.server.get(prior.clientId)), selector(prior));
    assert.equal(drawing.damageCases[0].strokeWidth, 2.2, 'The legend symbol width stays unchanged.');
    available(drawing);

    // A width change during a pointer gesture applies to the following gesture only.
    drawing.screenPoint = () => [20, 20];
    drawing.imagePoint = () => [20, 20];
    drawing.drawingLayer.setPointerCapture = () => {};
    drawing.drawingLayer.hasPointerCapture = () => false;
    const pointer = { pointerId: 1, pointerType: 'mouse', button: 0, preventDefault() {} };
    drawing.strokeWidthButtons[0].emit('click');
    drawing.onPointerDown(pointer);
    drawing.strokeWidthButtons[2].emit('click');
    available(drawing);
    assert.ok(Math.abs(drawing.previewScreenHalfWidth() - 1.4) < 0.001);
    await drawing.onPointerUp(pointer);
    const dot = styles.server.get(drawing.sessionDrawingIds.at(-1));
    assert.equal(dot.annotation.target.selector.geometry.rx, 1.4, 'Save the width captured at pointerdown.');
    assert.equal(drawing.activeStrokeWidth, 3.4);
    await drawing.undoLastDrawing();
    await drawing.undoLastDrawing();
    assert.ok(styles.server.get(thick.clientId).deleted);
    assert.equal(selector(styles.server.get(thin.clientId)), selector(thin));
    available(drawing);

    drawing.colorButtons[1].emit('click');
    await settle();
    assert.equal(drawing.styleForAnnotation(prior.annotation).fill, '#3567a9');
    assert.equal(drawing.styleForAnnotation(thin.annotation).fill, '#3567a9');
    await drawing.undoLastDrawing();
    assert.equal(drawing.finishButton.classList.contains('has-changes'), false, 'Undoing all lines and restoring the initial color returns to Cancel.');
    drawing.finishDrawing();
    drawing.selectDamageValue('Kras');
    await settle();
    assert.equal(drawing.activeStrokeWidth, 3.4, 'The next session retains the last selected width.');

    // Rapid clicks are coalesced, and a slower response cannot overwrite the last choice.
    const saveColor = context.requestJson;
    let releaseColor;
    let requests = 0;
    context.requestJson = (url, options) => {
        requests += 1;
        if (requests === 1) return new Promise(resolve => {
            releaseColor = async () => resolve(await saveColor(url, options));
        });
        return saveColor(url, options);
    };
    drawing.colorButtons[0].emit('click');
    drawing.colorButtons[1].emit('click');
    drawing.strokeWidthButtons[0].emit('click');
    available(drawing);
    assert.equal(requests, 1);
    assert.equal(drawing.activeStrokeWidth, 1.4);
    await releaseColor();
    await settle();
    assert.equal(requests, 2);
    assert.equal(styles.caseServer.get(1).color, '#3567a9');
    assert.equal(drawing.selectedColor(), '#3567a9');
    assert.equal(drawing.activeDamageCase.color, '#3567a9');

    // Finishing or switching case during a color save must not restart the old case.
    requests = 0;
    drawing.colorButtons[0].emit('click');
    drawing.selectDamageValue('Ander schadegeval');
    await releaseColor();
    await settle();
    assert.equal(drawing.activeDamageCase.id, 2);
    assert.equal(drawing.activeDamageCase.color, '#3567a9');
    assert.equal(styles.caseServer.get(1).color, '#d13b3b');
    drawing.finishDrawing();
    drawing.selectDamageValue('Kras');
    await settle();
    context.requestJson = saveColor;
    drawing.colorButtons[1].emit('click');
    await settle();

    context.requestJson = async () => { throw new Error('Saving failed'); };
    drawing.colorButtons[0].emit('click');
    await settle();
    assert.equal(drawing.mode, 'draw', 'A failed color save keeps the drawing session open.');
    assert.equal(drawing.selectedColor(), '#3567a9', 'On failure the palette returns to the saved color.');
    assert.equal(drawing.activeDamageCase.color, '#3567a9');
    assert.equal(drawing.status.classList.contains('is-error'), true);
    drawing.strokeWidthButtons[2].emit('click');
    assert.equal(drawing.activeStrokeWidth, 3.4, 'Width changes do not require any server request.');
    available(drawing);
    context.requestJson = saveColor;
    drawing.colorButtons[0].emit('click');
    await settle();
    assert.equal(drawing.activeDamageCase.color, '#d13b3b');
    assert.equal(drawing.status.classList.contains('is-error'), false, 'A successful retry clears the error.');

    const creating = setup();
    const ensureCase = creating.ensureDamageCase.bind(creating);
    let releaseCase;
    creating.ensureDamageCase = (label, color, width) => new Promise(resolve => {
        releaseCase = async () => {
            creating.ensureDamageCase = ensureCase;
            resolve(await ensureCase(label, color, width));
        };
    });
    select.damage(creating);
    select.color(creating);
    creating.colorButtons[0].emit('click');
    creating.strokeWidthButtons[2].emit('click');
    available(creating);
    await releaseCase();
    await settle();
    assert.equal(creating.mode, 'draw');
    assert.equal(creating.activeDamageCase.color, '#d13b3b', 'Choices made during case creation are applied after it finishes.');
    assert.equal(creating.activeStrokeWidth, 3.4);

    const failedLine = fixture();
    failedLine.controller.selectDamageValue('Kras');
    await settle();
    const persistLine = failedLine.controller.persistAnnotation;
    failedLine.controller.persistAnnotation = async () => { throw new Error('Drawing failed'); };
    await assert.rejects(failedLine.controller.createDrawing([[20, 20]]), /Drawing failed/);
    available(failedLine.controller);
    failedLine.controller.persistAnnotation = persistLine;
    await failedLine.controller.createDrawing([[20, 20]]);
    available(failedLine.controller);
    await failedLine.controller.undoLastDrawing();
    available(failedLine.controller);

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
    console.log('PASS: per-stroke widths, unchanged earlier geometry, shared color updates, rapid choices and save failures; hover cursor visibility, position, size and zoom; erase/undo persistence, full deletion, split fragments, lost responses, multiple targets, repeated undo, no-op strokes, retry after failure, busy guards and mode controls.');
})().catch(error => { console.error(error); process.exitCode = 1; });
