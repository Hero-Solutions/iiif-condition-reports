// Isolated dynamic-form checks. Fake DOM only; no application startup or network.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class Element {
    constructor(tag = 'div', dataset = {}) {
        this.tagName = tag;
        this.dataset = dataset;
        this.children = [];
        this.listeners = new Map();
        this.value = '';
        this.disabled = false;
        this.selectionStart = this.selectionEnd = 0;
    }
    append(child) {
        child.remove();
        this.children.push(child);
        child.parentElement = this;
    }
    remove() {
        if (this.parentElement) {
            this.parentElement.children = this.parentElement.children.filter(child => child !== this);
            this.parentElement = null;
        }
    }
    get form() { return this.tagName === 'form' ? this : this.parentElement?.form; }
    querySelectorAll(selector) {
        return this.children.flatMap(child => [
            ...(selector === '[data-numeric-input]' && child.dataset.numericInput ? [child] : []),
            ...child.querySelectorAll(selector),
        ]);
    }
    addEventListener(name, callback) {
        const callbacks = this.listeners.get(name) || [];
        callbacks.push(callback);
        this.listeners.set(name, callbacks);
    }
    dispatchEvent(event) {
        event.target ||= this;
        for (const callback of this.listeners.get(event.type) || []) callback(event);
        if (event.bubbles && this.parentElement) this.parentElement.dispatchEvent(event);
        return !event.defaultPrevented;
    }
    setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; }
}

class Event {
    constructor(type, options = {}) { Object.assign(this, { type, defaultPrevented: false }, options); }
    preventDefault() { this.defaultPrevented = true; }
}

const input = (name, mode = 'decimal') => Object.assign(new Element('input', { numericInput: mode }), { name });
const makePart = number => {
    const part = new Element('section', { measurementPart: String(number) });
    for (const field of ['height_with_frame', 'height_without_frame']) {
        part.append(input(`report_data[measurement_parts][${number}][${field}]`));
    }
    return part;
};

function setup(total = '0', saved = []) {
    const document = new Element('document');
    const form = new Element('form');
    const count = input('report_data[number_of_parts]', 'integer');
    count.value = total;
    const whole = input('report_data[height_full]');
    whole.value = '100';
    const container = new Element();
    document.append(form);
    form.append(count);
    form.append(whole);
    form.append(container);
    for (const number of saved) container.append(makePart(number));
    const template = { innerHTML: '<section data-measurement-part="__part__"></section>' };
    document.querySelector = selector => ({
        '[data-measurement-part-count]': count,
        '[data-measurement-parts]': container,
        '[data-measurement-part-template]': template,
    })[selector];
    document.createElement = tag => {
        assert.equal(tag, 'template');
        return { set innerHTML(html) {
            const number = Number(html.match(/data-measurement-part="(\d+)"/)[1]);
            this.content = { firstElementChild: makePart(number) };
        } };
    };
    const context = vm.createContext({ document, Event, fetch: () => { throw new Error('No network allowed'); } });
    for (const file of ['numeric-inputs.js', 'report-measurements.js']) {
        vm.runInContext(fs.readFileSync(path.join(__dirname, '../public/js', file), 'utf8'), context);
    }
    document.dispatchEvent(new Event('DOMContentLoaded'));
    const changeCount = value => {
        count.value = value;
        count.dispatchEvent(new Event('input', { bubbles: true }));
    };
    const payload = () => {
        const fields = new Map(form.querySelectorAll('[data-numeric-input]').map(field => [field.name, field.value]));
        form.dispatchEvent(new Event('formdata', { formData: fields }));
        return fields;
    };
    return { form, count, whole, container, changeCount, payload };
}

const fixture = setup();
assert.equal(fixture.container.children.length, 0);
fixture.changeCount('3');
assert.deepEqual(fixture.container.children.map(part => part.dataset.measurementPart), ['1', '2', '3']);
assert.equal(fixture.whole.value, '100', 'Whole-work measurements must not be rebuilt.');
const third = fixture.container.children[2];
const field = third.children[0];
field.value = '12,5';
field.dispatchEvent(new Event('input', { bubbles: true }));
const text = new Event('beforeinput', { data: 'cm' });
field.dispatchEvent(text);
assert.equal(text.defaultPrevented, true, 'Dynamically added fields must reject letters.');
const paste = new Event('paste', { clipboardData: { getData: () => '3kg' } });
field.dispatchEvent(paste);
assert.equal(paste.defaultPrevented, true, 'Pasting units must be rejected.');
field.value = 'broken';
field.dispatchEvent(new Event('input'));
assert.equal(field.value, '12,5', 'Non-cancellable input must restore the last numeric value.');
assert.equal(fixture.payload().get(field.name), '12.5', 'Autosave must receive normalized decimals.');

fixture.changeCount('1');
assert.equal(fixture.container.children.length, 1);
assert.equal(fixture.payload().has(field.name), false, 'Removed parts must be absent from the saved payload.');
fixture.changeCount('3');
assert.equal(fixture.container.children[2], third, 'Raising the count must restore detached fields.');
assert.equal(field.value, '12,5');
assert.equal(field.listeners.get('beforeinput').length, 1, 'Restoring fields must not duplicate numeric listeners.');
assert.equal(fixture.form.listeners.get('formdata').length, 1, 'Normalization should be bound once per form.');
field.dispatchEvent(new Event('blur'));
assert.equal(field.value, '12.5');
fixture.changeCount('');
assert.equal(fixture.container.children.length, 0);
fixture.changeCount('3');
assert.equal(fixture.container.children[2].children[0].value, '12.5', 'Clearing and retyping the count must preserve values.');
fixture.changeCount('3.5');
assert.equal(fixture.count.value, '3', 'The part count must remain an integer.');
assert.equal(fixture.container.children.length, 3);

const sparse = setup('3', [1, 3]);
assert.deepEqual(sparse.container.children.map(part => part.dataset.measurementPart), ['1', '2', '3'], 'Fill gaps in saved parts in numerical order.');
assert.equal(new Set(sparse.payload().keys()).size, 8, 'Each part needs independent field names.');
console.log('PASS: dynamic part count, preserved values, independent fields, removal from payload, numeric validation and decimal normalization.');
