// Isolated UI checks: local scripts, fake DOM, no application startup or network.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

(async () => {

class Element {
    constructor(tag = 'div') {
        this.tagName = tag;
        this.children = [];
        this.dataset = {};
        this.attributes = new Map();
        this.listeners = new Map();
        this.style = { setProperty: (name, value) => { this.style[name] = value; } };
        this.className = '';
        this.hidden = false;
        this.disabled = false;
        this._text = '';
        this.classList = {
            contains: name => this.className.split(' ').includes(name),
            add: (...names) => names.forEach(name => this.classList.toggle(name, true)),
            remove: (...names) => names.forEach(name => this.classList.toggle(name, false)),
            toggle: (name, on) => {
                const names = new Set(this.className.split(' ').filter(Boolean));
                if (on) names.add(name); else names.delete(name);
                this.className = [...names].join(' ');
            },
        };
    }
    set textContent(value) { this._text = String(value); this.children.forEach(child => { child.parent = null; }); this.children = []; }
    get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
    set innerHTML(value) {
        this.textContent = '';
        if (value.includes('report-choice-current')) {
            const current = new Element('span'); current.className = 'report-choice-current';
            this.append(current, new Element('svg'));
        }
    }
    append(...children) { children.forEach(child => { child.parent = this; this.children.push(child); }); }
    insertBefore(child, before) { child.parent = this; this.children.splice(this.children.indexOf(before), 0, child); }
    replaceChildren(...children) { this.textContent = ''; this.append(...children); }
    setAttribute(name, value) { this.attributes.set(name, String(value)); }
    getAttribute(name) { return this.attributes.get(name) ?? null; }
    hasAttribute(name) { return this.attributes.has(name); }
    matches(selector) {
        if (selector.includes(',')) return selector.split(',').some(part => this.matches(part.trim()));
        if (selector.startsWith('.')) return this.classList.contains(selector.slice(1));
        if (selector === 'button.active') return this.tagName === 'button' && this.classList.contains('active');
        if (selector.startsWith('[data-')) {
            const [, raw, value] = selector.match(/^\[data-([\w-]+)(?:="([^"]+)")?\]$/);
            const key = raw.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
            return key in this.dataset && (value === undefined || this.dataset[key] === value);
        }
        return this.tagName === selector;
    }
    querySelectorAll(selector) {
        return this.children.flatMap(child => [
            ...(selector.split(',').some(part => child.matches(part.trim())) ? [child] : []),
            ...child.querySelectorAll(selector),
        ]);
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    closest(selector) { return this.matches(selector) ? this : this.parent?.closest(selector) || null; }
    contains(child) { return child === this || this.children.some(node => node.contains(child)); }
    cloneNode(deep) {
        const copy = new Element(this.tagName);
        copy.className = this.className; copy.dataset = { ...this.dataset }; copy.attributes = new Map(this.attributes);
        copy._text = this._text; copy.hidden = this.hidden; copy.src = this.src; copy.title = this.title;
        if (deep) copy.append(...this.children.map(child => child.cloneNode(true)));
        return copy;
    }
    focus() { document.activeElement = this; }
    getBoundingClientRect() { return { top: 100, bottom: 144 }; }
    addEventListener(type, callback) {
        if (!this.listeners.has(type)) this.listeners.set(type, []);
        this.listeners.get(type).push(callback);
    }
    emit(type, fields = {}) {
        const route = [];
        for (let element = this; element; element = element.parent) route.push(element);
        const event = { target: this, composedPath: () => route, preventDefault() {}, stopPropagation() { this.stopped = true; }, ...fields };
        for (const element of route) {
            for (const callback of element.listeners.get(type) || []) callback(event);
            if (event.stopped) break;
        }
    }
}
const document = new Element('document');
document.createElement = tag => new Element(tag);
document.createElementNS = (_, tag) => new Element(tag);
const media = new Element();
media.matches = true;
const window = new Element();
window.matchMedia = () => media;
window.innerHeight = 800;
window.location = { search: '?tab=damage' };
const observers = [];
class MutationObserver {
    constructor(callback) { this.callback = callback; observers.push(this); }
    observe(target) { this.target = target; }
}
const flush = () => observers.forEach(observer => observer.callback());
const editor = new Element(); editor.className = 'report-editor'; document.append(editor);
function choice(label, id) {
    const root = new Element(); root.dataset.reportChoice = label;
    const list = new Element(); list.dataset.reportChoiceList = ''; list.id = id;
    root.append(list); editor.append(root);
    return { root, list };
}
const tabs = choice('Rapportvelden', 'sections');
for (const [key, label] of [['basis', 'Basis'], ['damage', 'Schadebeelden']]) {
    const button = new Element('button'); button.dataset.reportTab = key; button.className = key === 'basis' ? 'active' : '';
    const text = new Element('span'); text.textContent = label;
    const count = new Element('span'); count.textContent = '2'; count.dataset.sectionCount = ''; count.className = 'report-nav-count';
    button.append(text, count); tabs.list.append(button);
    const panel = new Element('section'); panel.dataset.reportTabPanel = key; editor.append(panel);
}
const images = choice('Afbeelding kiezen', 'images');
const context = vm.createContext({
    document, window, MutationObserver, queueMicrotask, URLSearchParams, CSS: { escape: value => value },
    fetch() { throw new Error('Network forbidden'); },
});
const load = name => vm.runInContext(fs.readFileSync(path.join(__dirname, '../public/js', name), 'utf8'), context);
load('report-choice-menu.js');
load('report-form-tabs.js');
document.emit('DOMContentLoaded');
flush();
const toggle = tabs.root.querySelector('.report-choice-toggle');
async function pointerFocus(target, trigger) {
    const previous = document.activeElement;
    // During native focusout, activeElement can still be the document body.
    document.activeElement = document;
    previous.emit('focusout', { relatedTarget: target });
    await Promise.resolve();
    assert.equal(trigger.getAttribute('aria-expanded'), 'true', 'Moving focus to another menu option must not close the menu before the click.');
    target.focus();
}
assert.equal(toggle.getAttribute('aria-expanded'), 'false');
assert.equal(toggle.querySelector('.report-choice-current').textContent, 'Schadebeelden2', 'URL tab is reflected in the compact choice.');
assert.equal(editor.querySelectorAll('[data-report-tab]').length, 2, 'Selected summary does not duplicate actionable tabs.');
assert.equal(images.root.querySelector('.report-choice-toggle').hidden, true, 'Wait for asynchronously loaded images.');
toggle.emit('click');
assert.equal(toggle.getAttribute('aria-expanded'), 'true');
assert.equal(document.activeElement, tabs.list.children[1]);
document.activeElement.emit('keydown', { key: 'ArrowUp' });
assert.equal(document.activeElement, tabs.list.children[0]);
document.activeElement.emit('keydown', { key: 'ArrowDown' });
await pointerFocus(tabs.list.children[0], toggle);
document.activeElement.emit('click');
flush();
assert.equal(toggle.getAttribute('aria-expanded'), 'false');
assert.equal(document.activeElement, toggle);
assert.equal(toggle.querySelector('.report-choice-current').textContent, 'Basis2');
assert.equal(editor.querySelector('[data-report-tab-panel="basis"]').hidden, false);
tabs.list.children[0].children[1].textContent = '9';
flush();
assert.equal(toggle.querySelector('.report-choice-current').textContent, 'Basis9', 'Live counts update the summary.');
toggle.emit('click');
document.activeElement.emit('keydown', { key: 'End' });
assert.equal(document.activeElement, tabs.list.children[1]);
document.activeElement.emit('keydown', { key: 'Escape' });
assert.equal(toggle.getAttribute('aria-expanded'), 'false');
assert.equal(document.activeElement, toggle);
toggle.emit('click');
document.emit('pointerdown');
assert.equal(toggle.getAttribute('aria-expanded'), 'false');
toggle.emit('click');
media.matches = false; media.emit('change');
assert.equal(toggle.getAttribute('aria-expanded'), 'false');
assert.equal(document.activeElement, tabs.list.children[0], 'Desktop focus returns to the real tab.');
toggle.emit('click');
assert.equal(toggle.getAttribute('aria-expanded'), 'false');
media.matches = true; media.emit('change');

// Use the real source renderer; choosing an image replaces its original button.
load('report-annotation-viewer.js');
const Workbench = vm.runInContext('ReportAnnotationWorkbench', context);
const sources = [
    { key: 'main', label: 'Hoofdafbeelding', thumbnailUrl: 'fixture-main.png' },
    { key: 'photo', label: 'Detail.png', thumbnailUrl: 'fixture-detail.png' },
];
const controller = {
    sourceList: images.list, sources, currentSource: sources[0],
    switchSource(source) { this.currentSource = source; Workbench.prototype.renderSources.call(this); },
};
Workbench.prototype.renderSources.call(controller);
flush();
const imageToggle = images.root.querySelector('.report-choice-toggle');
assert.equal(imageToggle.hidden, false);
assert.equal(imageToggle.querySelector('img').src, 'fixture-main.png');
imageToggle.emit('click');
await pointerFocus(images.list.children[1], imageToggle);
images.list.children[1].emit('click');
flush();
assert.equal(controller.currentSource.key, 'photo');
assert.equal(imageToggle.getAttribute('aria-expanded'), 'false', 'Close even after source renderer replaces the clicked button.');
assert.equal(document.activeElement, imageToggle);
assert.equal(imageToggle.querySelector('img').src, 'fixture-detail.png');
assert.equal(imageToggle.querySelector('.report-choice-current').textContent, 'Detail.png');
imageToggle.getBoundingClientRect = () => ({ top: 700, bottom: 744 });
imageToggle.emit('click');
assert.ok(images.root.classList.contains('choice-menu-above'), 'Open upwards near the viewport bottom.');
document.activeElement.emit('focusout', { relatedTarget: document });
document.activeElement = document;
await Promise.resolve();
assert.equal(imageToggle.getAttribute('aria-expanded'), 'false');
console.log('PASS: initial tab, live counts, source thumbnails and rebuilds, keyboard, pointer focus transitions, outside click, focus exit, viewport direction and desktop switching.');
})().catch(error => { console.error(error); process.exitCode = 1; });
