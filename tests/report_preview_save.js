// Isolated navigation/autosave tests: fake requests only; no app startup or network.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function node(properties = {}) {
    return {
        ...properties, listeners: {}, attributes: new Map(),
        classList: { add() {}, remove() {} },
        addEventListener(name, callback) { this.listeners[name] = callback; },
        setAttribute(name, value) { this.attributes.set(name, value); },
        removeAttribute(name) { this.attributes.delete(name); },
    };
}

function setup() {
    const fields = new Map([
        ['description', 'Recent description'],
        ['report_data[number_of_parts]', '2'],
        ['report_data[measurement_parts][2][height_with_frame]', '12.5'],
    ]);
    const version = { value: '1' };
    const editor = {};
    const form = node({
        dataset: { reportAutosaveEnabled: '1', reportAutosaveUrl: '/fixture/autosave' },
        closest: () => editor, querySelector: () => version,
    });
    const preview = node({ href: '/fixture/preview' });
    const back = node({ href: '/fixture/back' });
    const status = node({ dataset: { savingLabel: 'Saving', failedLabel: 'Failed', conflictLabel: 'Conflict' } });
    const document = node({ documentElement: { lang: 'nl' }, querySelector: selector => ({
        '[data-report-autosave-url]': form,
        '[data-report-preview-link]': preview,
        '[data-report-back-link]': back,
        '[data-report-autosave-status]': status,
    })[selector] || null });
    const window = node({ location: { href: '/fixture/edit' }, setInterval() {} });
    const requests = [];
    class FormData extends Map {
        constructor() { super(fields); this.set('version', version.value); }
    }
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../public/js/report-autosave.js'), 'utf8'), {
        document, window, FormData, URLSearchParams,
        fetch: (url, options) => {
            assert.equal(url, '/fixture/autosave');
            assert.equal(options.method, 'POST');
            return new Promise((resolve, reject) => requests.push({ body: options.body, resolve, reject }));
        },
    });
    document.listeners.DOMContentLoaded();
    const click = (link = preview) => {
        const event = { prevented: false, preventDefault() { this.prevented = true; } };
        return { event, done: link.listeners.click(event) };
    };
    const respond = (index, statusCode = 200) => requests[index].resolve({
        status: statusCode, ok: statusCode === 200,
        json: async () => ({ saved: true, version: index + 2 }),
    });
    return { fields, form, preview, back, status, window, requests, editor, click, respond };
}

const settle = () => new Promise(resolve => setImmediate(resolve));
(async () => {
    const clean = setup();
    const direct = clean.click();
    await direct.done;
    assert.equal(direct.event.prevented, false, 'An unchanged report can open its saved preview directly.');
    assert.equal(clean.requests.length, 0);

    const current = setup();
    current.form.listeners.input();
    const navigation = current.click();
    assert.equal(navigation.event.prevented, true);
    assert.equal(current.window.location.href, '/fixture/edit', 'Preview must wait for the server.');
    assert.equal(current.requests[0].body.get('description'), 'Recent description');
    assert.equal(current.requests[0].body.get('report_data[measurement_parts][2][height_with_frame]'), '12.5');
    await current.click().done;
    assert.equal(current.requests.length, 1, 'Double clicking must not duplicate the save.');
    current.respond(0);
    await navigation.done;
    assert.equal(current.window.location.href, '/fixture/preview');
    assert.equal(current.preview.attributes.has('aria-busy'), false);

    const concurrent = setup();
    concurrent.form.listeners.input();
    const automatic = concurrent.editor.reportAutosave.saveNow();
    concurrent.fields.set('description', 'Typed during the automatic save');
    concurrent.form.listeners.input();
    const waiting = concurrent.click();
    assert.equal(concurrent.requests.length, 1, 'Navigation must share the pending save.');
    concurrent.respond(0);
    await automatic;
    await settle();
    assert.equal(concurrent.requests.length, 2);
    assert.equal(concurrent.requests[1].body.get('description'), 'Typed during the automatic save');
    assert.equal(concurrent.requests[1].body.get('version'), '2', 'The follow-up save must use the new version.');
    assert.equal(concurrent.window.location.href, '/fixture/edit');
    concurrent.fields.set('description', 'One more edit while waiting');
    concurrent.form.listeners.input();
    concurrent.respond(1);
    await settle();
    assert.equal(concurrent.requests[2].body.get('description'), 'One more edit while waiting');
    concurrent.respond(2);
    await waiting.done;
    assert.equal(concurrent.window.location.href, '/fixture/preview');

    const failedFollowup = setup();
    failedFollowup.form.listeners.input();
    const firstSave = failedFollowup.editor.reportAutosave.saveNow();
    failedFollowup.fields.set('description', 'Unsaved follow-up');
    failedFollowup.form.listeners.input();
    const failedNavigation = failedFollowup.click();
    failedFollowup.respond(0);
    await firstSave;
    await settle();
    failedFollowup.respond(1, 500);
    await failedNavigation.done;
    assert.equal(failedFollowup.window.location.href, '/fixture/edit', 'A successful first save must not conceal a failed follow-up.');
    assert.equal(failedFollowup.status.textContent, 'Failed');
    assert.equal(failedFollowup.requests.length, 2, 'A failed save must not cause an automatic retry loop.');
    const retry = failedFollowup.click();
    failedFollowup.respond(2);
    await retry.done;
    assert.equal(failedFollowup.window.location.href, '/fixture/preview');

    const conflict = setup();
    conflict.form.listeners.input();
    const conflictingNavigation = conflict.click();
    conflict.respond(0, 409);
    await conflictingNavigation.done;
    assert.equal(conflict.window.location.href, '/fixture/edit');
    assert.equal(conflict.status.textContent, 'Conflict');
    await conflict.click().done;
    assert.equal(conflict.requests.length, 1, 'A conflict must not be silently overwritten.');

    const network = setup();
    network.form.listeners.input();
    const back = network.click(network.back);
    network.requests[0].reject(new Error('Fixture failure'));
    await back.done;
    assert.equal(network.window.location.href, '/fixture/edit', 'Back must also keep unsaved changes on failure.');
    assert.equal(network.status.textContent, 'Failed');

    const template = fs.readFileSync(path.join(__dirname, '../templates/reports/form.html.twig'), 'utf8');
    assert.match(template, /<a[^>]+data-report-preview-link>/, 'Preview must be connected to the save-before-navigation handler.');
    console.log('PASS: preview waits for all fields, pending saves and newer edits; double click, retry, failure, conflict and back navigation.');
})().catch(error => { console.error(error); process.exitCode = 1; });
