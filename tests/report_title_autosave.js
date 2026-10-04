// Isolated autosave fixture: no browser, application server or network.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

(async () => {
    const listeners = {};
    const fields = new Map([['reason', 'other'], ['custom_reason', 'Transportcontrole'], ['version', '1']]);
    const heading = { textContent: 'Initial title', dataset: { appTitle: 'Conditierapporten' } };
    const version = { value: '1' };
    const editor = {};
    const form = {
        dataset: { reportAutosaveEnabled: '1', reportAutosaveUrl: '/fixture/autosave' },
        closest: () => editor,
        querySelector: () => version,
        addEventListener: (event, callback) => { listeners[event] = callback; },
    };
    let ready;
    let finishResponse;
    const document = {
        title: 'Initial title | Conditierapporten',
        querySelector: (selector) => ({
            '[data-report-autosave-url]': form,
            '[data-report-title]': heading,
        })[selector] || null,
        addEventListener: (event, callback) => { if (event === 'DOMContentLoaded') ready = callback; },
    };
    class FakeFormData extends Map {
        constructor() { super(fields); }
    }
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../public/js/report-autosave.js'), 'utf8'), {
        document,
        window: { addEventListener() {}, setInterval() {} },
        FormData: FakeFormData,
        URLSearchParams,
        fetch: () => new Promise((resolve) => { finishResponse = resolve; }),
    });
    ready();
    listeners.input();
    const firstSave = editor.reportAutosave.saveNow();
    finishResponse({ ok: true, json: async () => ({ title: 'Transportcontrole — 25/09/2026', version: 2 }) });
    assert.equal(await firstSave, true);
    assert.equal(heading.textContent, 'Transportcontrole — 25/09/2026');
    assert.equal(document.title, 'Transportcontrole — 25/09/2026 | Conditierapporten');
    assert.equal(version.value, '2');

    listeners.change();
    const pendingSave = editor.reportAutosave.saveNow();
    fields.set('custom_reason', 'Nieuwere wijziging');
    finishResponse({ ok: true, json: async () => ({ title: 'Older response title', version: 3 }) });
    await pendingSave;
    assert.equal(heading.textContent, 'Transportcontrole — 25/09/2026', 'Stale save must not overwrite the heading.');
    const nextSave = editor.reportAutosave.saveNow();
    finishResponse({ ok: true, json: async () => ({ title: '<script>custom reason</script> — 25/09/2026', version: 4 }) });
    await nextSave;
    assert.equal(heading.textContent, '<script>custom reason</script> — 25/09/2026');
    assert.equal(Object.hasOwn(heading, 'innerHTML'), false, 'Titles must be written as text.');
    console.log('PASS: autosave updates heading and browser title; stale responses and literal text handled.');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
