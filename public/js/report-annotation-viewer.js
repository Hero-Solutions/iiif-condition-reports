const ANNOTATION_COLORS = [
    '#d13b3b',
    '#2f8a57',
    '#3567a9',
    '#95613b',
    '#7650a4',
    '#d2ad00',
];
const ANNOTATION_REFERENCE_SIZE = 800;

document.addEventListener('DOMContentLoaded', () => {
    const workbench = document.querySelector('[data-report-annotation-workbench]');

    if (!workbench) {
        return;
    }

    const initWhenVisible = () => {
        if (!workbench.closest('[hidden]')) {
            initAnnotationViewer(workbench);
        }
    };

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-report-tab="damage"]')) {
            window.setTimeout(initWhenVisible, 0);
        }
    });

    initWhenVisible();
});

async function initAnnotationViewer(workbench) {
    if (workbench.dataset.initialized === '1') {
        return;
    }

    workbench.dataset.initialized = '1';

    try {
        if (!window.OpenSeadragon || !window.AnnotoriousOSD?.createOSDAnnotator) {
            throw new Error('Annotorious kon niet geladen worden.');
        }

        const [state, sources] = await Promise.all([
            requestJson(workbench.dataset.stateUrl),
            resolveAnnotationSources(workbench),
        ]);

        if (sources.length === 0) {
            throw new Error('Geen bruikbare afbeelding gevonden.');
        }

        const initialSource = sources.find((source) => source.key === workbench.dataset.initialSource) || sources[0];

        const viewer = OpenSeadragon({
            id: 'report-annotation-viewer',
            prefixUrl: '/vendor/openseadragon/images/',
            tileSources: initialSource.tileSource,
            showSequenceControl: false,
            gestureSettingsMouse: {
                clickToZoom: false,
                dblClickToZoom: false,
                dblClickDragToZoom: false,
            },
            gestureSettingsTouch: {
                clickToZoom: false,
                dblClickToZoom: false,
                dblClickDragToZoom: false,
            },
            gestureSettingsPen: {
                clickToZoom: false,
                dblClickToZoom: false,
                dblClickDragToZoom: false,
            },
            gestureSettingsUnknown: {
                clickToZoom: false,
                dblClickToZoom: false,
                dblClickDragToZoom: false,
            },
        });

        await waitForViewerOpen(viewer);

        const annotation = window.AnnotoriousOSD.createOSDAnnotator(viewer, {
            autoSave: true,
            drawingEnabled: false,
            multiSelect: true,
        });

        window.AnnotoriousTools?.mountPlugin(annotation);

        let booleanOperations = null;

        try {
            const module = await import('/vendor/annotorious-boolean-operations/index.js?v=0.4.0-2');
            booleanOperations = {
                ...module.mountPlugin(annotation),
                subtractAnnotations: module.subtractAnnotations,
            };
        } catch (error) {
            console.warn('Annotorious boolean operations could not be loaded.', error);
        }

        const controller = new ReportAnnotationWorkbench(
            workbench,
            viewer,
            annotation,
            booleanOperations,
            sources,
            state,
            initialSource,
        );
        controller.init();

        window.reportAnnotationViewer = viewer;
        window.reportAnnotation = annotation;
        window.reportAnnotationWorkbench = controller;
    } catch (error) {
        workbench.dataset.initialized = '0';
        const viewer = workbench.querySelector('#report-annotation-viewer');

        if (viewer) {
            viewer.textContent = workbench.dataset.errorMessage || error.message;
        }

        console.error(error);
    }
}

class ReportAnnotationWorkbench {
    constructor(workbench, viewer, annotation, booleanOperations, sources, state, initialSource) {
        this.workbench = workbench;
        this.viewer = viewer;
        this.annotation = annotation;
        this.booleanOperations = booleanOperations;
        this.sources = sources;
        this.currentSource = initialSource;
        this.damageCases = state.damageCases || [];
        this.history = state.history || [];
        this.annotationRecords = new Map();
        this.damageCasesById = new Map();
        this.annotationCaseIds = new Map();
        this.suppressEvents = false;
        this.mode = null;
        this.activeDamageCase = null;
        this.selectedAnnotationId = null;
        this.selectedAnnotation = null;
        this.sessionDrawingIds = [];
        this.pointerState = null;
        this.touchPoints = new Map();
        this.touchGesture = null;
        this.statusTimer = null;
        this.draggedLegendRow = null;
        this.draggedLegendPreviousOrder = null;

        this.damageInput = workbench.querySelector('[data-annotation-damage-input]');
        this.damageCombobox = workbench.querySelector('[data-annotation-damage-combobox]');
        this.damageMenu = workbench.querySelector('[data-annotation-damage-suggestions]');
        this.baseDamageSuggestions = [...this.damageMenu.querySelectorAll('[data-annotation-damage-option]')]
            .map((option) => option.dataset.value);
        this.damageOptionButtons = [];
        this.damageCustomButton = null;
        this.colorButtons = [...workbench.querySelectorAll('[data-annotation-color]')];
        this.strokeWidthButtons = [...workbench.querySelectorAll('[data-annotation-stroke-width]')];
        this.strokeWidthLock = workbench.querySelector('[data-annotation-width-lock]');
        this.eraserWidthButtons = [...workbench.querySelectorAll('[data-annotation-eraser-width]')];
        this.eraserWidths = workbench.querySelector('[data-annotation-eraser-widths]');
        this.startButton = workbench.querySelector('[data-annotation-start]');
        this.finishButton = workbench.querySelector('[data-annotation-finish]');
        this.undoButton = workbench.querySelector('[data-annotation-undo]');
        this.eraserButton = workbench.querySelector('[data-annotation-eraser]');
        this.deleteButton = workbench.querySelector('[data-annotation-delete]');
        this.status = workbench.querySelector('[data-annotation-status]');
        this.sourceList = workbench.querySelector('[data-annotation-source-list]');
        this.drawingLayer = workbench.querySelector('[data-annotation-drawing-layer]');
        this.preview = workbench.querySelector('[data-annotation-preview]');
        this.previewDot = workbench.querySelector('[data-annotation-preview-dot]');
        this.eraserCursor = workbench.querySelector('[data-annotation-eraser-cursor]');
        this.legend = workbench.querySelector('[data-annotation-legend]');
        this.emptyLegend = workbench.querySelector('[data-annotation-empty-legend]');
        this.historyContainer = workbench.querySelector('[data-annotation-history]');

        for (const damageCase of this.damageCases) {
            this.damageCasesById.set(damageCase.id, damageCase);
        }

        for (const record of state.annotations || []) {
            this.setAnnotationRecord(record);
        }
    }

    init() {
        this.annotation.setStyle((annotation) => this.styleForAnnotation(annotation));
        this.bindControls();
        this.bindAnnotationEvents();
        this.renderSources();
        this.renderSuggestions();
        this.renderLegend();
        this.renderHistory();
        this.loadCurrentSourceAnnotations();
    }

    bindControls() {
        this.startButton.addEventListener('click', () => this.startDrawing());
        this.finishButton.addEventListener('click', () => this.finishDrawing());
        this.undoButton.addEventListener('click', () => {
            this.undoLastDrawing().catch((error) => this.showSaveError(error));
        });
        this.eraserButton.addEventListener('click', () => this.startEraser());
        this.deleteButton.addEventListener('click', () => {
            this.deleteSelectedDrawing().catch((error) => this.showSaveError(error));
        });
        this.deleteButton.addEventListener('pointerdown', (event) => event.stopPropagation());

        for (const eventName of ['animation', 'animation-finish', 'resize']) {
            this.viewer.addHandler(eventName, () => {
                this.updateDeleteButtonPosition();

                if (this.mode === 'erase' && !this.eraserCursor.hidden) {
                    this.eraserCursor.setAttribute('r', String(this.eraserScreenHalfWidth()));
                }
            });
        }

        this.bindDamageCombobox();

        for (const button of this.colorButtons) {
            button.addEventListener('click', () => this.selectColor(button.dataset.annotationColor));
        }

        for (const button of this.strokeWidthButtons) {
            button.addEventListener('click', () => {
                this.selectStrokeWidth(Number(button.dataset.annotationStrokeWidth));
                this.persistSelectedStrokeWidth().catch((error) => this.showSaveError(error));
            });
        }

        for (const button of this.eraserWidthButtons) {
            button.addEventListener('click', () => {
                this.selectEraserWidth(Number(button.dataset.annotationEraserWidth));
                this.updatePreview();

                if (!this.eraserCursor.hidden) {
                    this.eraserCursor.setAttribute('r', String(this.eraserScreenHalfWidth()));
                }
            });
        }

        this.drawingLayer.addEventListener('pointerdown', (event) => this.onPointerDown(event));
        this.drawingLayer.addEventListener('pointermove', (event) => this.onPointerMove(event));
        this.drawingLayer.addEventListener('pointerup', (event) => this.onPointerUp(event));
        this.drawingLayer.addEventListener('pointercancel', (event) => this.onPointerCancel(event));
        this.drawingLayer.addEventListener('wheel', (event) => this.onDrawingLayerWheel(event), { passive: false });
        this.drawingLayer.addEventListener('pointerleave', () => {
            if (!this.pointerState) {
                this.eraserCursor.hidden = true;
            }
        });
    }

    bindAnnotationEvents() {
        this.annotation.on('selectionChanged', (annotations) => {
            if (this.suppressEvents || this.mode) {
                return;
            }

            this.selectedAnnotationId = annotations[0]?.id || null;
            const hasSelection = Boolean(this.selectedAnnotationId && this.annotationRecords.has(this.selectedAnnotationId));
            this.selectedAnnotation = hasSelection ? annotations[0] : null;
            this.deleteButton.disabled = !hasSelection;
            this.deleteButton.hidden = !hasSelection;

            if (hasSelection) {
                window.requestAnimationFrame(() => this.updateDeleteButtonPosition());
            }
        });

        this.annotation.on('updateAnnotation', (updated) => {
            if (!this.suppressEvents) {
                if (updated.id === this.selectedAnnotationId) {
                    this.selectedAnnotation = updated;
                    this.updateDeleteButtonPosition();
                }

                this.saveExistingAnnotation(updated).catch((error) => this.showSaveError(error));
            }
        });

        this.annotation.on('deleteAnnotation', (deleted) => {
            if (!this.suppressEvents) {
                this.deleteRecordAfterLibraryDelete(deleted.id).catch((error) => this.showSaveError(error));
            }
        });
    }

    renderSources() {
        this.sourceList.textContent = '';
        this.sourceList.hidden = false;

        for (const source of this.sources) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `report-annotation-source-button${source.key === this.currentSource.key ? ' active' : ''}`;
            button.dataset.sourceKey = source.key;
            button.setAttribute('aria-pressed', source.key === this.currentSource.key ? 'true' : 'false');
            button.title = source.label;

            if (source.thumbnailUrl) {
                const image = document.createElement('img');
                image.src = source.thumbnailUrl;
                image.alt = '';
                button.append(image);
            }

            const label = document.createElement('span');
            label.textContent = source.label;
            button.append(label);
            button.addEventListener('click', () => this.switchSource(source));
            this.sourceList.append(button);
        }
    }

    switchSource(source) {
        if (source.key === this.currentSource.key) {
            return;
        }

        this.finishDrawing();
        this.currentSource = source;
        this.selectedAnnotationId = null;
        this.selectedAnnotation = null;
        this.deleteButton.disabled = true;
        this.deleteButton.hidden = true;
        this.eraserButton.disabled = false;
        this.renderSources();

        this.suppressEvents = true;
        this.annotation.setSelected();
        this.annotation.setAnnotations([]);
        window.setTimeout(() => {
            this.suppressEvents = false;
        }, 0);

        this.viewer.addOnceHandler('open', () => this.loadCurrentSourceAnnotations());
        this.viewer.open(source.tileSource);
    }

    loadCurrentSourceAnnotations() {
        const annotations = [...this.annotationRecords.values()]
            .filter((record) => record.sourceKey === this.currentSource.key && !record.deleted)
            .map((record) => record.annotation);

        this.suppressEvents = true;
        this.annotation.setAnnotations(annotations);
        this.annotation.setStyle((annotation) => this.styleForAnnotation(annotation));
        window.setTimeout(() => {
            this.suppressEvents = false;
        }, 0);
    }

    renderSuggestions() {
        const suggestions = [];
        const normalized = new Set();

        for (const label of [...this.baseDamageSuggestions, ...this.damageCases.map((damageCase) => damageCase.label)]) {
            const key = label.trim().toLocaleLowerCase(document.documentElement.lang || undefined);

            if (key && !normalized.has(key)) {
                normalized.add(key);
                suggestions.push(label.trim());
            }
        }

        this.damageMenu.textContent = '';
        this.damageOptionButtons = suggestions.map((label) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = label;
            button.dataset.value = label;
            button.dataset.searchText = label.toLocaleLowerCase(document.documentElement.lang || undefined);
            button.setAttribute('role', 'option');
            this.damageMenu.append(button);

            return button;
        });

        this.damageCustomButton = document.createElement('button');
        this.damageCustomButton.type = 'button';
        this.damageCustomButton.className = 'smart-single-select-custom';
        this.damageCustomButton.dataset.customDamageValue = '1';
        this.damageCustomButton.setAttribute('role', 'option');
        this.damageCustomButton.hidden = true;
        this.damageMenu.append(this.damageCustomButton);
        this.closeDamageMenu();
    }

    bindDamageCombobox() {
        this.damageInput.addEventListener('pointerdown', (event) => {
            if (!this.damageMenu.hidden) {
                event.preventDefault();
                this.closeDamageMenu();
                this.damageInput.blur();
            }
        });
        this.damageInput.addEventListener('focus', () => {
            this.damageInput.select();
            this.refreshDamageMenu(true);
        });
        this.damageInput.addEventListener('input', () => {
            this.refreshDamageMenu();
            this.syncStrokeWidthAvailability();
        });
        this.damageInput.addEventListener('change', () => this.syncDamageStyle());
        this.damageInput.addEventListener('blur', (event) => {
            if (!event.relatedTarget || !this.damageMenu.contains(event.relatedTarget)) {
                this.closeDamageMenu();
            }
        });
        this.damageInput.addEventListener('keydown', (event) => {
            const visibleButtons = this.visibleDamageButtons();

            if (event.key === 'Escape') {
                event.preventDefault();
                this.closeDamageMenu();
                this.damageInput.blur();
            } else if (event.key === 'ArrowDown' && visibleButtons.length > 0) {
                event.preventDefault();
                visibleButtons[0].focus();
            } else if (event.key === 'Enter' && visibleButtons.length > 0) {
                event.preventDefault();
                visibleButtons[0].click();
            }
        });

        this.damageMenu.addEventListener('pointerdown', (event) => {
            if (event.target.closest('button')) {
                event.preventDefault();
            }
        });
        this.damageMenu.addEventListener('click', (event) => {
            const button = event.target.closest('button');

            if (!button) {
                return;
            }

            const value = button.dataset.customDamageValue
                ? this.damageInput.value.trim()
                : button.dataset.value;

            if (value) {
                this.selectDamageValue(value);
            }
        });
        this.damageMenu.addEventListener('keydown', (event) => {
            const visibleButtons = this.visibleDamageButtons();
            const currentIndex = visibleButtons.indexOf(document.activeElement);

            if (event.key === 'Escape') {
                event.preventDefault();
                this.closeDamageMenu();
                this.damageInput.focus();
            } else if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
                event.preventDefault();
                const direction = event.key === 'ArrowDown' ? 1 : -1;
                const nextIndex = Math.min(Math.max(currentIndex + direction, 0), visibleButtons.length - 1);
                visibleButtons[nextIndex]?.focus();
            }
        });

        document.addEventListener('pointerdown', (event) => {
            if (!this.damageCombobox.contains(event.target)) {
                this.closeDamageMenu();
            }
        });
    }

    refreshDamageMenu(showAll = false) {
        const query = showAll
            ? ''
            : this.damageInput.value.trim().toLocaleLowerCase(document.documentElement.lang || undefined);
        let visibleCount = 0;
        let exactMatch = false;

        for (const button of this.damageOptionButtons) {
            const matches = query === '' || button.dataset.searchText.includes(query);
            const show = matches && visibleCount < 8;
            button.hidden = !show;

            if (button.dataset.searchText === query) {
                exactMatch = true;
            }

            if (show) {
                visibleCount += 1;
            }
        }

        const customValue = this.damageInput.value.trim();
        this.damageCustomButton.textContent = customValue;
        this.damageCustomButton.hidden = showAll || customValue === '' || exactMatch;
        const hasOptions = visibleCount > 0 || !this.damageCustomButton.hidden;
        this.damageMenu.hidden = !hasOptions;
        this.damageInput.setAttribute('aria-expanded', hasOptions ? 'true' : 'false');
    }

    closeDamageMenu() {
        this.damageMenu.hidden = true;
        this.damageInput.setAttribute('aria-expanded', 'false');
    }

    visibleDamageButtons() {
        return [...this.damageMenu.querySelectorAll('button:not([hidden])')];
    }

    selectDamageValue(value) {
        const activeLabel = this.activeDamageCase?.label || '';
        const switchesCase = activeLabel.localeCompare(value, undefined, { sensitivity: 'accent' }) !== 0;

        if (this.mode === 'erase' || (this.mode === 'draw' && switchesCase)) {
            this.finishDrawing();
        }

        this.damageInput.value = value;
        this.syncDamageStyle();
        this.damageInput.focus();
        this.closeDamageMenu();
    }

    syncDamageStyle() {
        const damageCase = this.findDamageCaseByLabel(this.damageInput.value);

        if (damageCase) {
            this.selectColor(damageCase.color);
            this.selectStrokeWidth(damageCase.strokeWidth || 2.2);
        }

        this.syncStrokeWidthAvailability();
    }

    syncStrokeWidthAvailability() {
        const damageCase = this.findDamageCaseByLabel(this.damageInput.value);
        const locked = Boolean(damageCase && [...this.annotationRecords.values()].some(
            (record) => !record.deleted && record.damageCaseId === damageCase.id,
        ));

        for (const button of this.strokeWidthButtons) {
            button.disabled = Boolean(this.mode) || locked;
        }

        this.strokeWidthLock.hidden = !locked;
    }

    async startDrawing() {
        const label = this.damageInput.value.trim();

        if (!label) {
            this.setStatus(this.workbench.dataset.selectDamageMessage, true);
            this.damageInput.focus();
            return;
        }

        this.startButton.disabled = true;

        try {
            this.activeDamageCase = await this.ensureDamageCase(
                label,
                this.selectedColor(),
                this.selectedStrokeWidth(),
            );
            this.damageInput.value = this.activeDamageCase.label;
            this.selectColor(this.activeDamageCase.color);
            this.selectStrokeWidth(this.activeDamageCase.strokeWidth || 2.2);
            this.sessionDrawingIds = [];
            this.setMode('draw');
        } catch (error) {
            this.showSaveError(error);
        } finally {
            this.startButton.disabled = false;
        }
    }

    async ensureDamageCase(label, color, strokeWidth) {
        const existing = this.findDamageCaseByLabel(label);

        if (existing) {
            return existing;
        }

        const damageCase = await requestJson(this.workbench.dataset.damageCaseUrl, {
            method: 'POST',
            csrfToken: this.workbench.dataset.csrfToken,
            body: {
                clientId: createClientId('damage-case'),
                label,
                color,
                strokeWidth,
            },
        });

        this.damageCases.push(damageCase);
        this.damageCasesById.set(damageCase.id, damageCase);
        this.renderSuggestions();

        return damageCase;
    }

    setMode(mode) {
        this.mode = mode;
        this.drawingLayer.classList.add('active');
        this.drawingLayer.classList.toggle('eraser', mode === 'erase');
        this.drawingLayer.style.setProperty('--annotation-preview-color', this.activeDamageCase?.color || '#d13b3b');
        this.eraserWidths.hidden = mode !== 'erase';
        this.eraserCursor.hidden = true;
        this.viewer.setMouseNavEnabled(false);
        this.annotation.setSelected();
        this.selectedAnnotationId = null;
        this.selectedAnnotation = null;
        this.startButton.hidden = true;
        this.finishButton.hidden = false;
        this.undoButton.hidden = mode !== 'draw';
        this.undoButton.disabled = this.sessionDrawingIds.length === 0;
        this.eraserButton.disabled = false;
        this.eraserButton.classList.toggle('active', mode === 'erase');
        this.eraserButton.setAttribute('aria-pressed', mode === 'erase' ? 'true' : 'false');
        this.deleteButton.disabled = true;
        this.deleteButton.hidden = true;
        this.damageInput.disabled = false;
        this.colorButtons.forEach((button) => {
            button.disabled = true;
        });
        this.strokeWidthButtons.forEach((button) => {
            button.disabled = true;
        });
        this.restoreModeStatus();
    }

    finishDrawing() {
        if (!this.mode) {
            return;
        }

        this.mode = null;
        this.activeDamageCase = null;
        this.resetPointer();
        this.touchPoints.clear();
        this.touchGesture = null;
        this.drawingLayer.classList.remove('active', 'eraser');
        this.eraserWidths.hidden = true;
        this.eraserCursor.hidden = true;
        this.viewer.setMouseNavEnabled(true);
        this.startButton.hidden = false;
        this.finishButton.hidden = true;
        this.undoButton.hidden = true;
        this.eraserButton.disabled = false;
        this.eraserButton.classList.remove('active');
        this.eraserButton.setAttribute('aria-pressed', 'false');
        this.deleteButton.hidden = true;
        this.damageInput.disabled = false;
        this.damageInput.value = '';
        this.damageInput.blur();
        this.colorButtons.forEach((button) => {
            button.disabled = false;
        });
        this.strokeWidthButtons.forEach((button) => {
            button.disabled = false;
        });
        this.syncStrokeWidthAvailability();
        this.setStatus('');
    }

    startEraser() {
        if (!this.booleanOperations) {
            this.setStatus(this.workbench.dataset.eraserUnavailableMessage, true);
            return;
        }

        if (this.mode === 'erase') {
            if (this.activeDamageCase) {
                this.setMode('draw');
            } else {
                this.finishDrawing();
            }

            return;
        }

        const hasDrawings = [...this.annotationRecords.values()].some(
            (record) => !record.deleted && record.sourceKey === this.currentSource.key,
        );

        if (!hasDrawings) {
            this.setStatus(this.workbench.dataset.selectEraserMessage, true);
            return;
        }

        this.setMode('erase');
    }

    onPointerDown(event) {
        if (!this.mode || event.button > 0) {
            return;
        }

        event.preventDefault();
        this.drawingLayer.setPointerCapture(event.pointerId);
        const screenPoint = this.screenPoint(event);

        if (event.pointerType === 'touch') {
            this.touchPoints.set(event.pointerId, screenPoint);

            if (this.touchPoints.size >= 2) {
                this.resetPointer();
                this.touchGesture = this.currentTouchGesture();
                this.eraserCursor.hidden = true;
                return;
            }
        }

        if (this.mode === 'erase') {
            this.updateEraserCursor(screenPoint);
        }

        this.pointerState = {
            pointerId: event.pointerId,
            screenPoints: [screenPoint],
            imagePoints: [this.imagePoint(event)],
        };
        this.updatePreview();
    }

    onDrawingLayerWheel(event) {
        if (!this.mode) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        if (this.pointerState || event.deltaY === 0) {
            return;
        }

        const bounds = this.viewer.container.getBoundingClientRect();
        const pixel = new OpenSeadragon.Point(
            event.clientX - bounds.left,
            event.clientY - bounds.top,
        );
        const focalPoint = this.viewer.viewport.pointFromPixel(pixel);
        const zoomFactor = event.deltaY < 0 ? 1.2 : (1 / 1.2);
        this.viewer.viewport.zoomBy(zoomFactor, focalPoint);
        this.viewer.viewport.applyConstraints();

        if (this.mode === 'erase' && !this.eraserCursor.hidden) {
            this.eraserCursor.setAttribute('r', String(this.eraserScreenHalfWidth()));
        }
    }

    onPointerMove(event) {
        if (event.pointerType === 'touch' && this.touchPoints.has(event.pointerId)) {
            this.touchPoints.set(event.pointerId, this.screenPoint(event));

            if (this.touchGesture && this.touchPoints.size >= 2) {
                event.preventDefault();
                this.updateTouchGesture();
                return;
            }
        }

        if (this.mode === 'erase') {
            this.updateEraserCursor(this.screenPoint(event));
        }

        if (!this.pointerState || this.pointerState.pointerId !== event.pointerId) {
            if (this.drawingLayer.hasPointerCapture(event.pointerId)) {
                this.drawingLayer.releasePointerCapture(event.pointerId);
            }

            return;
        }

        event.preventDefault();
        const screenPoint = this.screenPoint(event);
        const previous = this.pointerState.screenPoints.at(-1);

        if (pointDistance(previous, screenPoint) < 2) {
            return;
        }

        this.pointerState.screenPoints.push(screenPoint);
        this.pointerState.imagePoints.push(this.imagePoint(event));
        this.updatePreview();
    }

    async onPointerUp(event) {
        const endingTouchGesture = event.pointerType === 'touch' && Boolean(this.touchGesture);

        if (event.pointerType === 'touch') {
            this.touchPoints.delete(event.pointerId);

            if (this.touchPoints.size < 2) {
                this.touchGesture = null;
            }
        }

        if (endingTouchGesture) {
            event.preventDefault();
            this.resetPointer();

            if (this.drawingLayer.hasPointerCapture(event.pointerId)) {
                this.drawingLayer.releasePointerCapture(event.pointerId);
            }

            return;
        }

        if (!this.pointerState || this.pointerState.pointerId !== event.pointerId) {
            return;
        }

        event.preventDefault();

        if (this.drawingLayer.hasPointerCapture(event.pointerId)) {
            this.drawingLayer.releasePointerCapture(event.pointerId);
        }

        const points = this.pointerState.imagePoints;
        this.resetPointer();

        try {
            if (this.mode === 'draw') {
                await this.createDrawing(points);
            } else if (this.mode === 'erase') {
                await this.eraseDrawing(points);
            }
        } catch (error) {
            this.showSaveError(error);
        }
    }

    onPointerCancel(event) {
        if (event.pointerType === 'touch') {
            this.touchPoints.delete(event.pointerId);

            if (this.touchPoints.size < 2) {
                this.touchGesture = null;
            }
        }

        if (this.pointerState?.pointerId === event.pointerId || this.touchGesture) {
            this.resetPointer();
        }

        if (this.drawingLayer.hasPointerCapture(event.pointerId)) {
            this.drawingLayer.releasePointerCapture(event.pointerId);
        }
    }

    currentTouchGesture() {
        const points = [...this.touchPoints.values()].slice(0, 2);

        if (points.length < 2) {
            return null;
        }

        return {
            midpoint: [
                (points[0][0] + points[1][0]) / 2,
                (points[0][1] + points[1][1]) / 2,
            ],
            distance: Math.max(1, pointDistance(points[0], points[1])),
        };
    }

    updateTouchGesture() {
        const current = this.currentTouchGesture();

        if (!current || !this.touchGesture) {
            return;
        }

        const previous = this.touchGesture;
        const pixelDelta = new OpenSeadragon.Point(
            previous.midpoint[0] - current.midpoint[0],
            previous.midpoint[1] - current.midpoint[1],
        );
        const panDelta = this.viewer.viewport.deltaPointsFromPixels(pixelDelta, true);
        this.viewer.viewport.panBy(panDelta);
        const focalPoint = this.viewer.viewport.pointFromPixel(
            new OpenSeadragon.Point(current.midpoint[0], current.midpoint[1]),
        );
        const zoomFactor = Math.max(0.8, Math.min(1.25, current.distance / previous.distance));
        this.viewer.viewport.zoomBy(zoomFactor, focalPoint);
        this.viewer.viewport.applyConstraints();
        this.touchGesture = current;
    }

    resetPointer() {
        this.pointerState = null;
        this.preview.setAttribute('points', '');
        this.previewDot.hidden = true;
    }

    updatePreview() {
        const points = this.pointerState?.screenPoints || [];

        if (points.length === 0) {
            this.preview.setAttribute('points', '');
            this.previewDot.hidden = true;
            return;
        }

        const halfWidth = this.previewScreenHalfWidth();

        if (points.length === 1 || pathLength(points) < halfWidth) {
            this.preview.setAttribute('points', '');
            this.previewDot.hidden = false;
            this.previewDot.setAttribute('cx', String(points[0][0]));
            this.previewDot.setAttribute('cy', String(points[0][1]));
            this.previewDot.setAttribute('r', String(halfWidth));
            return;
        }

        this.previewDot.hidden = true;
        this.preview.setAttribute(
            'points',
            strokeToPolygon(points, halfWidth).map((point) => `${point[0]},${point[1]}`).join(' '),
        );
    }

    previewScreenHalfWidth() {
        const referenceWidth = this.mode === 'erase'
            ? this.selectedEraserWidth()
            : (this.activeDamageCase?.strokeWidth || this.selectedStrokeWidth());

        return this.screenWidthForImageWidth(this.imageWidthForReferencePixels(referenceWidth));
    }

    eraserScreenHalfWidth() {
        return this.screenWidthForImageWidth(
            this.imageWidthForReferencePixels(this.selectedEraserWidth()),
        );
    }

    updateEraserCursor(point) {
        this.eraserCursor.hidden = false;
        this.eraserCursor.setAttribute('cx', String(point[0]));
        this.eraserCursor.setAttribute('cy', String(point[1]));
        this.eraserCursor.setAttribute('r', String(this.eraserScreenHalfWidth()));
    }

    screenPoint(event) {
        const bounds = this.drawingLayer.getBoundingClientRect();

        return [event.clientX - bounds.left, event.clientY - bounds.top];
    }

    imagePoint(event) {
        const bounds = this.viewer.container.getBoundingClientRect();
        const point = this.viewer.viewport.viewerElementToImageCoordinates(
            new OpenSeadragon.Point(event.clientX - bounds.left, event.clientY - bounds.top),
        );

        return [point.x, point.y];
    }

    imageWidthForReferencePixels(pixels) {
        const contentSize = this.viewer.world.getItemAt(0)?.getContentSize();

        if (!contentSize) {
            return pixels;
        }

        return Math.max(0.5, (Math.max(contentSize.x, contentSize.y) / ANNOTATION_REFERENCE_SIZE) * pixels);
    }

    screenWidthForImageWidth(imageWidth) {
        const contentSize = this.viewer.world.getItemAt(0)?.getContentSize();

        if (!contentSize) {
            return imageWidth;
        }

        const start = this.viewer.viewport.imageToViewerElementCoordinates(
            new OpenSeadragon.Point(contentSize.x / 2, contentSize.y / 2),
        );
        const end = this.viewer.viewport.imageToViewerElementCoordinates(
            new OpenSeadragon.Point((contentSize.x / 2) + imageWidth, contentSize.y / 2),
        );

        return Math.max(1, Math.abs(end.x - start.x));
    }

    async createDrawing(points) {
        if (!this.activeDamageCase || points.length === 0) {
            return;
        }

        const annotation = createBrushAnnotation(
            points,
            this.imageWidthForReferencePixels(this.activeDamageCase.strokeWidth || 2.2),
        );
        const contentSize = this.viewer.world.getItemAt(0)?.getContentSize();

        if (contentSize) {
            annotation.target.sourceDimensions = {
                width: contentSize.x,
                height: contentSize.y,
            };
        }

        const record = {
            id: null,
            clientId: annotation.id,
            damageCaseId: this.activeDamageCase.id,
            sourceKey: this.currentSource.key,
            reportImageId: this.currentSource.reportImageId,
            annotation,
            deleted: false,
        };

        this.setAnnotationRecord(record);
        this.suppressEvents = true;
        this.annotation.addAnnotation(annotation);
        this.suppressEvents = false;
        this.renderLegend();
        this.setStatus(this.workbench.dataset.savingMessage);

        try {
            const saved = await this.persistAnnotation(record, annotation);
            this.setAnnotationRecord(saved);
            this.sessionDrawingIds.push(annotation.id);
            this.undoButton.disabled = false;
            this.addHistoryFromRecord(saved);
            this.renderHistory();
            this.restoreModeStatus();
        } catch (error) {
            this.suppressEvents = true;
            this.annotation.removeAnnotation(annotation.id);
            this.suppressEvents = false;
            this.annotationRecords.delete(annotation.id);
            this.annotationCaseIds.delete(annotation.id);
            this.sessionDrawingIds = this.sessionDrawingIds.filter((id) => id !== annotation.id);
            this.renderLegend();
            throw error;
        }
    }

    async saveExistingAnnotation(annotation) {
        const record = this.annotationRecords.get(annotation.id);

        if (!record) {
            return;
        }

        this.setStatus(this.workbench.dataset.savingMessage);
        const saved = await this.persistAnnotation(record, annotation);
        this.setAnnotationRecord(saved);
        this.flashSavedStatus();
    }

    persistAnnotation(record, annotation) {
        return requestJson(this.workbench.dataset.annotationUrl, {
            method: 'POST',
            csrfToken: this.workbench.dataset.csrfToken,
            body: {
                damageCaseId: record.damageCaseId,
                sourceKey: record.sourceKey,
                reportImageId: record.reportImageId,
                annotation,
            },
        });
    }

    async undoLastDrawing() {
        const clientId = this.sessionDrawingIds.pop();

        if (!clientId) {
            return;
        }

        await this.deleteAnnotationRecord(clientId);
        this.undoButton.disabled = this.sessionDrawingIds.length === 0;
    }

    updateDeleteButtonPosition() {
        if (!this.selectedAnnotationId || this.deleteButton.hidden) {
            return;
        }

        const annotation = this.selectedAnnotation
            || this.annotationRecords.get(this.selectedAnnotationId)?.annotation;
        const bounds = selectorBounds(annotation?.target?.selector);

        if (!bounds) {
            this.deleteButton.classList.add('is-out-of-view');
            return;
        }

        const topLeft = this.viewer.viewport.imageToViewerElementCoordinates(
            new OpenSeadragon.Point(bounds.minX, bounds.minY),
        );
        const bottomRight = this.viewer.viewport.imageToViewerElementCoordinates(
            new OpenSeadragon.Point(bounds.maxX, bounds.maxY),
        );
        const shell = this.deleteButton.parentElement;
        const shellWidth = shell.clientWidth;
        const shellHeight = shell.clientHeight;

        if (
            bottomRight.x < 0
            || topLeft.x > shellWidth
            || bottomRight.y < 0
            || topLeft.y > shellHeight
        ) {
            this.deleteButton.classList.add('is-out-of-view');
            return;
        }

        this.deleteButton.classList.remove('is-out-of-view');
        const buttonWidth = this.deleteButton.offsetWidth || 40;
        const buttonHeight = this.deleteButton.offsetHeight || 40;
        let left = bottomRight.x + 10;

        if (left + buttonWidth > shellWidth - 6) {
            left = topLeft.x - buttonWidth - 10;
        }

        left = Math.max(6, Math.min(shellWidth - buttonWidth - 6, left));
        const top = Math.max(
            (buttonHeight / 2) + 6,
            Math.min(shellHeight - (buttonHeight / 2) - 6, (topLeft.y + bottomRight.y) / 2),
        );
        this.deleteButton.style.left = `${left}px`;
        this.deleteButton.style.top = `${top}px`;
    }

    async deleteSelectedDrawing() {
        if (!this.selectedAnnotationId) {
            return;
        }

        await this.deleteAnnotationRecord(this.selectedAnnotationId);
        this.selectedAnnotationId = null;
        this.selectedAnnotation = null;
        this.deleteButton.disabled = true;
        this.deleteButton.hidden = true;
        this.eraserButton.disabled = false;
    }

    async deleteAnnotationRecord(clientId) {
        const record = this.annotationRecords.get(clientId);

        if (!record) {
            return;
        }

        if (record.id) {
            await requestJson(`${this.workbench.dataset.annotationUrl}/${record.id}`, {
                method: 'DELETE',
                csrfToken: this.workbench.dataset.csrfToken,
            });
        }

        this.suppressEvents = true;
        this.annotation.removeAnnotation(clientId);
        this.suppressEvents = false;
        this.removeAnnotationRecord(clientId);
    }

    async deleteRecordAfterLibraryDelete(clientId) {
        const record = this.annotationRecords.get(clientId);

        if (!record) {
            return;
        }

        if (record.id) {
            await requestJson(`${this.workbench.dataset.annotationUrl}/${record.id}`, {
                method: 'DELETE',
                csrfToken: this.workbench.dataset.csrfToken,
            });
        }

        this.removeAnnotationRecord(clientId);
    }

    removeAnnotationRecord(clientId) {
        const record = this.annotationRecords.get(clientId);

        if (record) {
            const historyItem = this.history.find((item) => item.id === record.id);

            if (historyItem) {
                historyItem.deleted = true;
                historyItem.updatedAt = new Date().toISOString();
            }
        }

        this.annotationRecords.delete(clientId);
        this.annotationCaseIds.delete(clientId);
        this.sessionDrawingIds = this.sessionDrawingIds.filter((id) => id !== clientId);
        this.syncStrokeWidthAvailability();
        this.renderLegend();
        this.renderHistory();
    }

    async eraseDrawing(points) {
        if (points.length === 0 || !this.booleanOperations) {
            return;
        }

        const halfWidth = this.imageWidthForReferencePixels(this.selectedEraserWidth());
        const eraserProbe = createBrushAnnotation(points, halfWidth, 'eraser-probe');
        const targets = [...this.annotationRecords.values()]
            .filter((record) => !record.deleted && record.sourceKey === this.currentSource.key)
            .map((record) => ({
                record,
                annotation: this.annotation.getAnnotationById(record.clientId),
            }))
            .filter(({ annotation }) => annotation && selectorsIntersect(
                annotation.target.selector,
                eraserProbe.target.selector,
            ));

        if (targets.length === 0) {
            this.setStatus(this.workbench.dataset.selectEraserMessage, true);
            return;
        }

        this.suppressEvents = true;

        try {
            for (const { record, annotation: original } of targets) {
                const target = this.annotation.getAnnotationById(original.id);

                if (!target) {
                    continue;
                }

                const eraser = createBrushAnnotation(points, halfWidth, 'eraser');
                const updated = this.booleanOperations.subtractAnnotations(target, eraser);

                if (updated) {
                    await this.saveErasedAnnotationComponents(record, updated);
                } else {
                    await this.deleteFullyErasedAnnotation(record, target.id);
                }
            }
        } finally {
            this.annotation.setSelected();
            this.suppressEvents = false;
            this.restoreModeStatus();
        }
    }

    async saveErasedAnnotationComponents(record, updated) {
        const [primary, ...separated] = splitAnnotationComponents(updated);
        this.annotation.updateAnnotation(primary);
        await this.saveExistingAnnotation(primary);

        for (const annotation of separated) {
            const separatedRecord = {
                id: null,
                clientId: annotation.id,
                damageCaseId: record.damageCaseId,
                sourceKey: record.sourceKey,
                reportImageId: record.reportImageId,
                annotation,
                deleted: false,
            };

            this.setAnnotationRecord(separatedRecord);
            this.annotation.addAnnotation(annotation);

            try {
                const saved = await this.persistAnnotation(separatedRecord, annotation);
                this.setAnnotationRecord(saved);
                this.addHistoryFromRecord(saved);
            } catch (error) {
                this.annotation.removeAnnotation(annotation.id);
                this.annotationRecords.delete(annotation.id);
                this.annotationCaseIds.delete(annotation.id);
                throw error;
            }
        }

        this.renderLegend();
        this.renderHistory();
    }

    async deleteFullyErasedAnnotation(record, clientId) {
        if (record.id) {
            await requestJson(`${this.workbench.dataset.annotationUrl}/${record.id}`, {
                method: 'DELETE',
                csrfToken: this.workbench.dataset.csrfToken,
            });
        }

        if (this.annotation.getAnnotationById(clientId)) {
            this.annotation.removeAnnotation(clientId);
        }

        this.removeAnnotationRecord(clientId);
    }

    setAnnotationRecord(record) {
        this.annotationRecords.set(record.clientId, record);
        this.annotationCaseIds.set(record.clientId, record.damageCaseId);
    }

    styleForAnnotation(annotation) {
        const damageCase = this.damageCasesById.get(this.annotationCaseIds.get(annotation.id));
        const color = damageCase?.color || '#d13b3b';

        return {
            fill: color,
            fillOpacity: 0.78,
            stroke: color,
            strokeWidth: 1.25,
        };
    }

    renderLegend() {
        this.legend.textContent = '';
        this.legend.dataset.annotationLegendLoaded = 'true';
        const counts = new Map();

        for (const record of this.annotationRecords.values()) {
            if (!record.deleted) {
                counts.set(record.damageCaseId, (counts.get(record.damageCaseId) || 0) + 1);
            }
        }

        const visibleCases = this.damageCases.filter((damageCase) => (counts.get(damageCase.id) || 0) > 0);
        this.emptyLegend.hidden = visibleCases.length > 0;

        for (const damageCase of visibleCases) {
            const row = document.createElement('div');
            row.className = 'report-annotation-legend-row';
            row.dataset.damageCaseId = String(damageCase.id);
            row.style.setProperty('--annotation-color', damageCase.color);

            const drag = this.iconButton('i-menu', this.workbench.dataset.moveLegendLabel);
            drag.classList.add('report-annotation-legend-drag');
            drag.draggable = true;
            drag.addEventListener('pointerdown', (event) => this.startLegendDrag(event, row));
            drag.addEventListener('dragstart', (event) => this.startNativeLegendDrag(event, row));
            drag.addEventListener('dragend', () => this.finishNativeLegendDrag());
            drag.addEventListener('keydown', (event) => this.moveLegendRowByKeyboard(event, row));
            row.addEventListener('dragover', (event) => this.moveNativeLegendDrag(event, row));
            row.addEventListener('drop', (event) => event.preventDefault());
            row.append(drag);

            const symbol = createLegendSymbol(
                damageCase.legendGeometry,
                damageCase.color,
                damageCase.strokeWidth || 2.2,
            );
            row.append(symbol);

            const copy = document.createElement('button');
            copy.type = 'button';
            copy.className = 'report-annotation-legend-copy button-plain';
            const title = document.createElement('strong');
            title.textContent = damageCase.label;
            const note = document.createElement('span');
            note.className = 'report-annotation-legend-note';
            note.textContent = damageCase.legendNote || '';
            note.hidden = !damageCase.legendNote;
            const count = document.createElement('small');
            const number = counts.get(damageCase.id) || 0;
            count.textContent = number === 1
                ? this.workbench.dataset.drawingCountOneLabel
                : this.workbench.dataset.drawingCountManyLabel.replace('__COUNT__', String(number));
            copy.append(title, note, count);
            copy.addEventListener('click', () => this.selectDamageValue(damageCase.label));
            row.append(copy);

            const actions = document.createElement('div');
            actions.className = 'report-annotation-legend-actions';
            const edit = this.iconButton('i-pencil', this.workbench.dataset.legendEditLabel);
            edit.addEventListener('click', () => this.openLegendEditor(row, damageCase));
            const remove = this.iconButton('i-trash', this.workbench.dataset.deleteCaseLabel, true);
            remove.addEventListener('click', () => {
                this.deleteDamageCase(damageCase).catch((error) => this.showSaveError(error));
            });
            actions.append(edit, remove);
            row.append(actions);
            this.legend.append(row);
        }
    }

    startLegendDrag(event, row) {
        if (event.pointerType === 'mouse' || event.button > 0 || row.classList.contains('editing')) {
            return;
        }

        event.preventDefault();
        const handle = event.currentTarget;
        const pointerId = event.pointerId;
        const previousOrder = this.damageCases.map((damageCase) => damageCase.id);
        let moved = false;

        const move = (moveEvent) => {
            if (moveEvent.pointerId !== pointerId) {
                return;
            }

            const target = document.elementFromPoint(moveEvent.clientX, moveEvent.clientY)
                ?.closest('.report-annotation-legend-row');

            if (!target || target === row || target.parentElement !== this.legend) {
                return;
            }

            const bounds = target.getBoundingClientRect();

            if (moveEvent.clientY < bounds.top + (bounds.height / 2)) {
                target.before(row);
            } else {
                target.after(row);
            }

            moved = true;
        };

        const finish = (finishEvent) => {
            if (finishEvent.pointerId !== pointerId) {
                return;
            }

            handle.removeEventListener('pointermove', move);
            handle.removeEventListener('pointerup', finish);
            handle.removeEventListener('pointercancel', finish);

            if (handle.hasPointerCapture(pointerId)) {
                handle.releasePointerCapture(pointerId);
            }

            row.classList.remove('dragging');
            this.legend.classList.remove('reordering');

            if (moved) {
                this.persistLegendOrder(previousOrder).catch((error) => this.showSaveError(error));
            }
        };

        row.classList.add('dragging');
        this.legend.classList.add('reordering');
        handle.setPointerCapture(pointerId);
        handle.addEventListener('pointermove', move);
        handle.addEventListener('pointerup', finish);
        handle.addEventListener('pointercancel', finish);
    }

    startNativeLegendDrag(event, row) {
        if (row.classList.contains('editing')) {
            event.preventDefault();
            return;
        }

        this.draggedLegendRow = row;
        this.draggedLegendPreviousOrder = this.damageCases.map((damageCase) => damageCase.id);
        row.classList.add('dragging');
        this.legend.classList.add('reordering');

        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', row.dataset.damageCaseId || '');
        }
    }

    moveNativeLegendDrag(event, targetRow) {
        const draggedRow = this.draggedLegendRow;

        if (!draggedRow || draggedRow === targetRow) {
            return;
        }

        event.preventDefault();

        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = 'move';
        }

        const bounds = targetRow.getBoundingClientRect();

        if (event.clientY < bounds.top + (bounds.height / 2)) {
            targetRow.before(draggedRow);
        } else {
            targetRow.after(draggedRow);
        }
    }

    finishNativeLegendDrag() {
        if (!this.draggedLegendRow || !this.draggedLegendPreviousOrder) {
            return;
        }

        const row = this.draggedLegendRow;
        const previousOrder = this.draggedLegendPreviousOrder;
        this.draggedLegendRow = null;
        this.draggedLegendPreviousOrder = null;
        row.classList.remove('dragging');
        this.legend.classList.remove('reordering');
        this.persistLegendOrder(previousOrder).catch((error) => this.showSaveError(error));
    }

    moveLegendRowByKeyboard(event, row) {
        if (!['ArrowUp', 'ArrowDown'].includes(event.key)) {
            return;
        }

        const target = event.key === 'ArrowUp' ? row.previousElementSibling : row.nextElementSibling;

        if (!target) {
            return;
        }

        event.preventDefault();
        const previousOrder = this.damageCases.map((damageCase) => damageCase.id);

        if (event.key === 'ArrowUp') {
            target.before(row);
        } else {
            target.after(row);
        }

        this.persistLegendOrder(previousOrder).catch((error) => this.showSaveError(error));
    }

    async persistLegendOrder(previousOrder) {
        const visibleIds = [...this.legend.querySelectorAll('.report-annotation-legend-row')]
            .map((row) => Number(row.dataset.damageCaseId));
        const visibleIdSet = new Set(visibleIds);
        const orderedVisible = visibleIds.map((id) => this.damageCasesById.get(id)).filter(Boolean);
        let visibleIndex = 0;
        const previousSortOrders = new Map(
            this.damageCases.map((damageCase) => [damageCase.id, damageCase.sortOrder]),
        );

        this.damageCases = this.damageCases.map((damageCase) => (
            visibleIdSet.has(damageCase.id) ? orderedVisible[visibleIndex++] : damageCase
        ));

        const changedCases = this.damageCases.filter((damageCase, index) => {
            damageCase.sortOrder = index;

            return previousSortOrders.get(damageCase.id) !== index;
        });

        if (changedCases.length === 0) {
            return;
        }

        this.setStatus(this.workbench.dataset.savingMessage);

        try {
            const updatedCases = await Promise.all(changedCases.map((damageCase) => requestJson(
                `${this.workbench.dataset.damageCaseUrl}/${damageCase.id}`,
                {
                    method: 'PATCH',
                    csrfToken: this.workbench.dataset.csrfToken,
                    body: { sortOrder: damageCase.sortOrder },
                },
            )));

            for (const updated of updatedCases) {
                const damageCase = this.damageCasesById.get(updated.id);

                if (damageCase) {
                    Object.assign(damageCase, updated);
                }
            }

            this.renderLegend();
            this.flashSavedStatus();
        } catch (error) {
            const casesById = new Map(this.damageCases.map((damageCase) => [damageCase.id, damageCase]));
            this.damageCases = previousOrder.map((id) => casesById.get(id)).filter(Boolean);

            for (const damageCase of this.damageCases) {
                damageCase.sortOrder = previousSortOrders.get(damageCase.id) ?? 0;
            }

            this.renderLegend();
            throw error;
        }
    }

    openLegendEditor(row, damageCase) {
        if (row.classList.contains('editing')) {
            return;
        }

        row.classList.add('editing');
        const currentSymbol = row.querySelector('.report-annotation-legend-symbol');
        const currentCopy = row.querySelector('.report-annotation-legend-copy');
        const actions = row.querySelector('.report-annotation-legend-actions');

        const sketch = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        sketch.setAttribute('viewBox', '0 0 100 34');
        sketch.setAttribute('preserveAspectRatio', 'none');
        sketch.classList.add('report-annotation-legend-symbol', 'report-annotation-legend-sketch');
        let selectedColor = damageCase.color;
        let strokes = normalizeLegendGeometry(damageCase.legendGeometry).map((stroke) => [...stroke]);
        let currentStroke = null;
        const drawSketch = () => renderLegendGeometry(
            sketch,
            strokes,
            selectedColor,
            damageCase.strokeWidth || 2.2,
        );
        drawSketch();
        currentSymbol.replaceWith(sketch);

        const editor = document.createElement('div');
        editor.className = 'report-annotation-legend-editor';
        const title = document.createElement('strong');
        title.textContent = damageCase.label;
        const note = document.createElement('input');
        note.type = 'text';
        note.value = damageCase.legendNote || '';
        note.maxLength = 2000;
        note.placeholder = this.workbench.dataset.legendNotePlaceholder;
        editor.append(title, note);

        const palette = document.createElement('div');
        palette.className = 'report-annotation-color-list';

        for (const color of ANNOTATION_COLORS) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `report-annotation-color${color === selectedColor ? ' active' : ''}`;
            button.style.setProperty('--annotation-color', color);
            button.addEventListener('click', () => {
                selectedColor = color;
                palette.querySelectorAll('button').forEach((item) => item.classList.toggle('active', item === button));
                drawSketch();
            });
            palette.append(button);
        }

        editor.append(palette);
        currentCopy.replaceWith(editor);

        sketch.addEventListener('pointerdown', (event) => {
            event.preventDefault();
            sketch.setPointerCapture(event.pointerId);
            currentStroke = [legendPoint(sketch, event)];
            strokes.push(currentStroke);
            drawSketch();
        });
        sketch.addEventListener('pointermove', (event) => {
            if (!currentStroke) {
                return;
            }

            event.preventDefault();
            const point = legendPoint(sketch, event);

            if (pointDistance(currentStroke.at(-1), point) >= 1) {
                currentStroke.push(point);
                drawSketch();
            }
        });
        const finishStroke = (event) => {
            if (currentStroke && sketch.hasPointerCapture(event.pointerId)) {
                sketch.releasePointerCapture(event.pointerId);
            }

            currentStroke = null;
        };
        sketch.addEventListener('pointerup', finishStroke);
        sketch.addEventListener('pointercancel', () => {
            currentStroke = null;
        });

        actions.textContent = '';
        actions.classList.add('editing');
        const clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'button secondary';
        clear.textContent = this.workbench.dataset.legendClearLabel;
        clear.addEventListener('click', () => {
            strokes = [];
            drawSketch();
        });
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'button secondary';
        cancel.textContent = this.workbench.dataset.legendCancelLabel;
        cancel.addEventListener('click', () => this.renderLegend());
        const save = document.createElement('button');
        save.type = 'button';
        save.textContent = this.workbench.dataset.legendSaveLabel;
        save.addEventListener('click', async () => {
            save.disabled = true;

            try {
                const updated = await requestJson(`${this.workbench.dataset.damageCaseUrl}/${damageCase.id}`, {
                    method: 'PATCH',
                    csrfToken: this.workbench.dataset.csrfToken,
                    body: {
                        color: selectedColor,
                        legendGeometry: strokes,
                        legendNote: note.value.trim(),
                    },
                });
                Object.assign(damageCase, updated);
                this.damageCasesById.set(damageCase.id, damageCase);
                this.annotation.setStyle((annotation) => this.styleForAnnotation(annotation));
                this.renderSuggestions();
                this.renderLegend();
            } catch (error) {
                this.showSaveError(error);
                save.disabled = false;
            }
        });
        actions.append(clear, cancel, save);
        note.focus();
        note.select();
    }

    async deleteDamageCase(damageCase) {
        if (!window.confirm(this.workbench.dataset.confirmDeleteCase)) {
            return;
        }

        await requestJson(`${this.workbench.dataset.damageCaseUrl}/${damageCase.id}`, {
            method: 'DELETE',
            csrfToken: this.workbench.dataset.csrfToken,
        });

        this.suppressEvents = true;

        for (const record of [...this.annotationRecords.values()]) {
            if (record.damageCaseId === damageCase.id) {
                if (this.annotation.getAnnotationById(record.clientId)) {
                    this.annotation.removeAnnotation(record.clientId);
                }

                this.annotationRecords.delete(record.clientId);
                this.annotationCaseIds.delete(record.clientId);
                const historyItem = this.history.find((item) => item.id === record.id);

                if (historyItem) {
                    historyItem.deleted = true;
                    historyItem.updatedAt = new Date().toISOString();
                }
            }
        }

        this.suppressEvents = false;
        this.damageCases = this.damageCases.filter((item) => item.id !== damageCase.id);
        this.damageCasesById.delete(damageCase.id);
        this.renderSuggestions();
        this.renderLegend();
        this.renderHistory();
    }

    renderHistory() {
        this.historyContainer.textContent = '';
        const list = document.createElement('div');
        list.className = 'report-annotation-history-list';
        const formatter = new Intl.DateTimeFormat(document.documentElement.lang || 'nl', {
            dateStyle: 'short',
            timeStyle: 'short',
        });

        for (const item of [...this.history].sort((a, b) => String(b.createdAt).localeCompare(String(a.createdAt)))) {
            const line = document.createElement('p');
            line.className = 'report-annotation-history-item';
            const created = formatter.format(new Date(item.createdAt));
            const added = this.workbench.dataset.addedByLabel
                .replace('__USER__', item.createdBy || '—')
                .replace('__DATE__', created);
            let text = `${item.damageCase} — ${added}`;

            if (item.deleted) {
                const deleted = formatter.format(new Date(item.updatedAt));
                text += ` · ${this.workbench.dataset.deletedOnLabel.replace('__DATE__', deleted)}`;
            }

            line.textContent = text;
            list.append(line);
        }

        this.historyContainer.append(list);
    }

    addHistoryFromRecord(record) {
        if (this.history.some((item) => item.id === record.id)) {
            return;
        }

        this.history.unshift({
            id: record.id,
            damageCase: this.damageCasesById.get(record.damageCaseId)?.label || '',
            deleted: false,
            createdAt: record.createdAt || new Date().toISOString(),
            updatedAt: record.updatedAt || record.createdAt || new Date().toISOString(),
            createdBy: record.createdBy || '',
        });
    }

    iconButton(icon, label, danger = false) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `compact-icon-button${danger ? ' danger' : ''}`;
        button.title = label;
        button.setAttribute('aria-label', label);
        button.innerHTML = `<svg class="icon" aria-hidden="true"><use href="#${icon}"/></svg>`;

        return button;
    }

    findDamageCaseByLabel(label) {
        const normalized = label.trim().toLocaleLowerCase();

        return this.damageCases.find((damageCase) => damageCase.label.toLocaleLowerCase() === normalized) || null;
    }

    selectedColor() {
        return this.colorButtons.find((button) => button.classList.contains('active'))?.dataset.annotationColor
            || ANNOTATION_COLORS[0];
    }

    selectColor(color) {
        for (const button of this.colorButtons) {
            const selected = button.dataset.annotationColor === color;
            button.classList.toggle('active', selected);
            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
        }
    }

    selectedStrokeWidth() {
        return Number(
            this.strokeWidthButtons.find((button) => button.classList.contains('active'))
                ?.dataset.annotationStrokeWidth,
        ) || 2.2;
    }

    selectStrokeWidth(strokeWidth) {
        for (const button of this.strokeWidthButtons) {
            const selected = Number(button.dataset.annotationStrokeWidth) === Number(strokeWidth);
            button.classList.toggle('active', selected);
            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
        }
    }

    selectedEraserWidth() {
        return Number(
            this.eraserWidthButtons.find((button) => button.classList.contains('active'))
                ?.dataset.annotationEraserWidth,
        ) || 7;
    }

    selectEraserWidth(eraserWidth) {
        for (const button of this.eraserWidthButtons) {
            const selected = Number(button.dataset.annotationEraserWidth) === Number(eraserWidth);
            button.classList.toggle('active', selected);
            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
        }
    }

    async persistSelectedStrokeWidth() {
        const damageCase = this.findDamageCaseByLabel(this.damageInput.value);
        const strokeWidth = this.selectedStrokeWidth();

        if (damageCase && [...this.annotationRecords.values()].some(
            (record) => !record.deleted && record.damageCaseId === damageCase.id,
        )) {
            this.selectStrokeWidth(damageCase.strokeWidth || 2.2);
            this.syncStrokeWidthAvailability();
            return;
        }

        if (!damageCase || Number(damageCase.strokeWidth) === strokeWidth) {
            return;
        }

        const previousStrokeWidth = damageCase.strokeWidth || 2.2;
        damageCase.strokeWidth = strokeWidth;
        this.renderLegend();

        try {
            const updated = await requestJson(`${this.workbench.dataset.damageCaseUrl}/${damageCase.id}`, {
                method: 'PATCH',
                csrfToken: this.workbench.dataset.csrfToken,
                body: { strokeWidth },
            });
            Object.assign(damageCase, updated);
            this.damageCasesById.set(damageCase.id, damageCase);
            this.renderLegend();
        } catch (error) {
            damageCase.strokeWidth = previousStrokeWidth;
            this.selectStrokeWidth(previousStrokeWidth);
            this.renderLegend();
            throw error;
        }
    }

    setStatus(message, error = false) {
        window.clearTimeout(this.statusTimer);
        this.status.textContent = message || '';
        this.status.classList.toggle('is-error', error);
        this.status.classList.toggle('is-mode', Boolean(this.mode) && !error);
        this.status.classList.toggle('is-eraser', this.mode === 'erase' && !error);
    }

    restoreModeStatus() {
        if (this.mode === 'draw') {
            this.setStatus(this.workbench.dataset.drawingActiveMessage);
        } else if (this.mode === 'erase') {
            this.setStatus(this.workbench.dataset.eraserActiveMessage);
        } else {
            this.setStatus('');
        }
    }

    flashSavedStatus() {
        if (this.mode) {
            this.restoreModeStatus();
            return;
        }

        this.setStatus(this.workbench.dataset.savedMessage);
        this.statusTimer = window.setTimeout(() => this.setStatus(''), 1400);
    }

    showSaveError(error) {
        console.error(error);
        this.setStatus(this.workbench.dataset.saveErrorMessage, true);
    }
}

function createBrushAnnotation(points, halfWidth, prefix = 'annotation') {
    const id = createClientId(prefix);
    let selector;

    if (points.length === 1 || pathLength(points) < halfWidth) {
        const [cx, cy] = points[0];
        selector = {
            type: 'ELLIPSE',
            geometry: {
                cx,
                cy,
                rx: halfWidth,
                ry: halfWidth,
                bounds: {
                    minX: cx - halfWidth,
                    minY: cy - halfWidth,
                    maxX: cx + halfWidth,
                    maxY: cy + halfWidth,
                },
            },
        };
    } else {
        const polygon = strokeToPolygon(points, halfWidth);
        selector = {
            type: 'POLYGON',
            geometry: {
                points: polygon,
                bounds: boundsForPoints(polygon),
            },
        };
    }

    return {
        id,
        bodies: [],
        target: {
            annotation: id,
            selector,
        },
    };
}

function strokeToPolygon(points, halfWidth) {
    if (points.length < 2) {
        return circlePolygon(points[0], halfWidth);
    }

    const left = [];
    const right = [];

    for (let index = 0; index < points.length; index += 1) {
        const previous = points[Math.max(0, index - 1)];
        const next = points[Math.min(points.length - 1, index + 1)];
        const dx = next[0] - previous[0];
        const dy = next[1] - previous[1];
        const length = Math.hypot(dx, dy) || 1;
        const normalX = (-dy / length) * halfWidth;
        const normalY = (dx / length) * halfWidth;
        left.push([points[index][0] + normalX, points[index][1] + normalY]);
        right.push([points[index][0] - normalX, points[index][1] - normalY]);
    }

    const start = points[0];
    const end = points.at(-1);
    const startRightAngle = Math.atan2(right[0][1] - start[1], right[0][0] - start[0]);
    const endLeftAngle = Math.atan2(left.at(-1)[1] - end[1], left.at(-1)[0] - end[0]);
    const endCap = arcPoints(end, halfWidth, endLeftAngle, endLeftAngle - Math.PI, 10);
    const reversedRight = [...right].reverse().slice(1);
    const startCap = arcPoints(start, halfWidth, startRightAngle, startRightAngle - Math.PI, 10);
    const polygon = [...left, ...endCap, ...reversedRight, ...startCap];

    return [...polygon, polygon[0]];
}

function arcPoints(center, radius, startAngle, endAngle, segments) {
    const points = [];

    for (let index = 1; index <= segments; index += 1) {
        const angle = startAngle + ((endAngle - startAngle) * (index / segments));
        points.push([
            center[0] + (Math.cos(angle) * radius),
            center[1] + (Math.sin(angle) * radius),
        ]);
    }

    return points;
}

function circlePolygon(center, radius, segments = 24) {
    const points = [];

    for (let index = 0; index < segments; index += 1) {
        const angle = (index / segments) * Math.PI * 2;
        points.push([
            center[0] + (Math.cos(angle) * radius),
            center[1] + (Math.sin(angle) * radius),
        ]);
    }

    return [...points, points[0]];
}

function boundsForPoints(points) {
    const xs = points.map((point) => point[0]);
    const ys = points.map((point) => point[1]);

    return {
        minX: Math.min(...xs),
        minY: Math.min(...ys),
        maxX: Math.max(...xs),
        maxY: Math.max(...ys),
    };
}

function pathLength(points) {
    let length = 0;

    for (let index = 1; index < points.length; index += 1) {
        length += pointDistance(points[index - 1], points[index]);
    }

    return length;
}

function pointDistance(first, second) {
    return Math.hypot(second[0] - first[0], second[1] - first[1]);
}

function selectorsIntersect(first, second) {
    if (!boundsOverlap(first?.geometry?.bounds, second?.geometry?.bounds)) {
        return false;
    }

    const firstRings = selectorRings(first);
    const secondRings = selectorRings(second);

    if (firstRings.some((firstRing) => secondRings.some((secondRing) => ringsIntersect(firstRing, secondRing)))) {
        return true;
    }

    return selectorRings(first, true).some((ring) => ring.some((point) => selectorContainsPoint(second, point)))
        || selectorRings(second, true).some((ring) => ring.some((point) => selectorContainsPoint(first, point)));
}

function selectorRings(selector, outerOnly = false) {
    const geometry = selector?.geometry;

    if (!geometry) {
        return [];
    }

    if (selector.type === 'ELLIPSE') {
        const ring = [];

        for (let index = 0; index < 64; index += 1) {
            const angle = (index / 64) * Math.PI * 2;
            ring.push([
                geometry.cx + geometry.rx * Math.cos(angle),
                geometry.cy + geometry.ry * Math.sin(angle),
            ]);
        }

        return [ring];
    }

    if (selector.type === 'POLYGON') {
        return [geometry.points || []];
    }

    if (selector.type === 'MULTIPOLYGON') {
        return (geometry.polygons || []).flatMap((polygon) => {
            const rings = polygon.rings || [];

            return outerOnly ? (rings[0] ? [rings[0].points] : []) : rings.map((ring) => ring.points);
        });
    }

    return [];
}

function selectorBounds(selector) {
    const bounds = selector?.geometry?.bounds;

    if (
        bounds
        && ['minX', 'minY', 'maxX', 'maxY'].every((key) => Number.isFinite(Number(bounds[key])))
    ) {
        return {
            minX: Number(bounds.minX),
            minY: Number(bounds.minY),
            maxX: Number(bounds.maxX),
            maxY: Number(bounds.maxY),
        };
    }

    const points = selectorRings(selector).flat();

    return points.length > 0 ? boundsForPoints(points) : null;
}

function splitAnnotationComponents(annotation) {
    const selector = annotation?.target?.selector;
    const polygons = selector?.type === 'MULTIPOLYGON'
        ? (selector.geometry?.polygons || [])
        : [];

    if (polygons.length < 2) {
        return [annotation];
    }

    return polygons.map((polygon, index) => {
        const id = index === 0 ? annotation.id : createClientId('annotation');

        return {
            ...annotation,
            id,
            target: {
                ...annotation.target,
                annotation: id,
                selector: selectorForPolygon(polygon),
            },
        };
    });
}

function selectorForPolygon(polygon) {
    const rings = polygon.rings || [];
    const outerPoints = rings[0]?.points || [];
    const bounds = polygon.bounds || (outerPoints.length > 0 ? boundsForPoints(outerPoints) : null);

    if (rings.length <= 1) {
        return {
            type: 'POLYGON',
            geometry: {
                points: outerPoints,
                bounds,
            },
        };
    }

    return {
        type: 'MULTIPOLYGON',
        geometry: {
            polygons: [{ ...polygon, bounds }],
            bounds,
        },
    };
}

function boundsOverlap(first, second) {
    return !first || !second || !(
        first.maxX < second.minX
        || first.minX > second.maxX
        || first.maxY < second.minY
        || first.minY > second.maxY
    );
}

function ringsIntersect(first, second) {
    for (let firstIndex = 0; firstIndex < first.length; firstIndex += 1) {
        const firstStart = first[firstIndex];
        const firstEnd = first[(firstIndex + 1) % first.length];

        for (let secondIndex = 0; secondIndex < second.length; secondIndex += 1) {
            const secondStart = second[secondIndex];
            const secondEnd = second[(secondIndex + 1) % second.length];

            if (segmentsIntersect(firstStart, firstEnd, secondStart, secondEnd)) {
                return true;
            }
        }
    }

    return false;
}

function segmentsIntersect(firstStart, firstEnd, secondStart, secondEnd) {
    if (
        Math.max(firstStart[0], firstEnd[0]) < Math.min(secondStart[0], secondEnd[0])
        || Math.min(firstStart[0], firstEnd[0]) > Math.max(secondStart[0], secondEnd[0])
        || Math.max(firstStart[1], firstEnd[1]) < Math.min(secondStart[1], secondEnd[1])
        || Math.min(firstStart[1], firstEnd[1]) > Math.max(secondStart[1], secondEnd[1])
    ) {
        return false;
    }

    const firstSide = crossProduct(firstStart, firstEnd, secondStart);
    const secondSide = crossProduct(firstStart, firstEnd, secondEnd);
    const thirdSide = crossProduct(secondStart, secondEnd, firstStart);
    const fourthSide = crossProduct(secondStart, secondEnd, firstEnd);

    return ((firstSide <= 0 && secondSide >= 0) || (firstSide >= 0 && secondSide <= 0))
        && ((thirdSide <= 0 && fourthSide >= 0) || (thirdSide >= 0 && fourthSide <= 0));
}

function crossProduct(start, end, point) {
    return (end[0] - start[0]) * (point[1] - start[1])
        - (end[1] - start[1]) * (point[0] - start[0]);
}

function selectorContainsPoint(selector, point) {
    const geometry = selector?.geometry;

    if (!geometry || !pointWithinBounds(point, geometry.bounds)) {
        return false;
    }

    if (selector.type === 'ELLIPSE') {
        const x = (point[0] - geometry.cx) / geometry.rx;
        const y = (point[1] - geometry.cy) / geometry.ry;

        return (x * x) + (y * y) <= 1;
    }

    if (selector.type === 'POLYGON') {
        return pointInRing(point, geometry.points);
    }

    if (selector.type === 'MULTIPOLYGON') {
        return geometry.polygons.some((polygon) => {
            const [outerRing, ...holes] = polygon.rings || [];

            return outerRing
                && pointInRing(point, outerRing.points)
                && !holes.some((ring) => pointInRing(point, ring.points));
        });
    }

    return false;
}

function pointWithinBounds(point, bounds) {
    return !bounds || (
        point[0] >= bounds.minX
        && point[0] <= bounds.maxX
        && point[1] >= bounds.minY
        && point[1] <= bounds.maxY
    );
}

function pointInRing(point, ring) {
    if (!Array.isArray(ring) || ring.length < 3) {
        return false;
    }

    let inside = false;

    for (let index = 0, previous = ring.length - 1; index < ring.length; previous = index, index += 1) {
        const [x, y] = ring[index];
        const [previousX, previousY] = ring[previous];
        const crosses = (y > point[1]) !== (previousY > point[1]);

        if (crosses && point[0] < ((previousX - x) * (point[1] - y)) / (previousY - y) + x) {
            inside = !inside;
        }
    }

    return inside;
}

function createLegendSymbol(geometry, color, strokeWidth) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 100 34');
    svg.setAttribute('preserveAspectRatio', 'none');
    svg.classList.add('report-annotation-legend-symbol');
    renderLegendGeometry(svg, geometry, color, strokeWidth);

    return svg;
}

function renderLegendGeometry(svg, geometry, color, strokeWidth) {
    svg.style.setProperty('--annotation-color', color);
    const normalized = normalizeLegendGeometry(geometry);
    const displayWidth = Math.max(1.5, Math.min(6, Number(strokeWidth || 2.2) * 1.35));

    if (normalized.length === 0) {
        svg.textContent = '';
        const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        line.setAttribute('x1', '8');
        line.setAttribute('y1', '17');
        line.setAttribute('x2', '92');
        line.setAttribute('y2', '17');
        line.setAttribute('stroke', color);
        line.setAttribute('stroke-width', String(displayWidth));
        svg.append(line);
    } else {
        renderLegendStrokes(
            svg,
            normalized.map((stroke) => stroke.map(([x, y]) => [x, (y / 100) * 34])),
            color,
            displayWidth,
        );
    }
}

function renderLegendStrokes(svg, strokes, color, strokeWidth) {
    svg.textContent = '';

    for (const stroke of strokes) {
        if (stroke.length === 1) {
            const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
            circle.setAttribute('cx', String(stroke[0][0]));
            circle.setAttribute('cy', String(stroke[0][1]));
            circle.setAttribute('r', String(Math.max(1, strokeWidth / 2)));
            circle.setAttribute('fill', color);
            svg.append(circle);
            continue;
        }

        const polyline = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
        polyline.setAttribute('points', stroke.map((point) => `${point[0]},${point[1]}`).join(' '));
        polyline.setAttribute('fill', 'none');
        polyline.setAttribute('stroke', color);
        polyline.setAttribute('stroke-linecap', 'round');
        polyline.setAttribute('stroke-linejoin', 'round');
        polyline.setAttribute('stroke-width', String(strokeWidth));
        svg.append(polyline);
    }
}

function normalizeLegendGeometry(geometry) {
    if (!Array.isArray(geometry) || geometry.length === 0) {
        return [];
    }

    if (Array.isArray(geometry[0]) && Number.isFinite(Number(geometry[0][0]))) {
        return [geometry];
    }

    return geometry.filter((stroke) => Array.isArray(stroke));
}

function legendPoint(svg, event) {
    const bounds = svg.getBoundingClientRect();

    return [
        Math.max(0, Math.min(100, ((event.clientX - bounds.left) / bounds.width) * 100)),
        Math.max(0, Math.min(100, ((event.clientY - bounds.top) / bounds.height) * 100)),
    ];
}

function createClientId(prefix) {
    const random = window.crypto?.randomUUID
        ? window.crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(36).slice(2)}`;

    return `${prefix}-${random}`;
}

async function resolveAnnotationSources(workbench) {
    const sources = [];

    for (const element of workbench.querySelectorAll('[data-annotation-source]')) {
        const manifestUrl = element.dataset.manifestUrl || '';
        const imageUrl = element.dataset.imageUrl || '';

        if (!manifestUrl && !imageUrl) {
            continue;
        }

        let tileSources = [];

        if (manifestUrl) {
            try {
                tileSources = await loadTileSources(manifestUrl);
            } catch (error) {
                console.warn('IIIF manifest could not be loaded.', error);
            }
        }

        if (tileSources.length === 0 && imageUrl) {
            tileSources = [{ type: 'image', url: imageUrl }];
        }

        tileSources.forEach((tileSource, index) => {
            const multiple = tileSources.length > 1;
            sources.push({
                key: multiple ? `${element.dataset.sourceKey}:${index + 1}` : element.dataset.sourceKey,
                label: multiple ? `${element.dataset.sourceLabel} ${index + 1}` : element.dataset.sourceLabel,
                reportImageId: element.dataset.reportImageId ? Number(element.dataset.reportImageId) : null,
                thumbnailUrl: element.dataset.thumbnailUrl || '',
                tileSource,
            });
        });
    }

    return sources;
}

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

function waitForViewerOpen(viewer) {
    if (viewer.world.getItemCount() > 0) {
        return Promise.resolve();
    }

    return new Promise((resolve, reject) => {
        viewer.addOnceHandler('open', resolve);
        viewer.addOnceHandler('open-failed', (event) => reject(event.message || new Error('Afbeelding kon niet worden geladen.')));
    });
}

async function requestJson(url, options = {}) {
    const headers = {
        Accept: 'application/json',
        ...(options.headers || {}),
    };

    if (options.csrfToken) {
        headers['X-CSRF-TOKEN'] = options.csrfToken;
    }

    const requestOptions = {
        method: options.method || 'GET',
        headers,
    };

    if (options.body !== undefined) {
        headers['Content-Type'] = 'application/json';
        requestOptions.body = JSON.stringify(options.body);
    }

    const response = await fetch(url, requestOptions);

    if (!response.ok) {
        throw new Error(`Request failed (${response.status}).`);
    }

    return response.json();
}
