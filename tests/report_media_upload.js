// Isolated upload flow: fake DOM, native submission spy, no HTTP or application startup.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function element(properties = {}) {
    const listeners = {};
    return {
        hidden: false, disabled: false, value: '', dataset: {},
        classList: { add() {}, remove() {} },
        setAttribute() {}, removeAttribute() {},
        addEventListener(event, callback) { listeners[event] = callback; },
        emit(event, properties = {}) { return listeners[event]?.({ preventDefault() {}, ...properties }); },
        ...properties,
    };
}

function uploadForm(kind, saveNow) {
    const input = element({ files: [] });
    const retry = element({ hidden: true });
    const submit = element();
    const status = element({ dataset: { uploadingLabel: 'Adding', failedLabel: 'Failed' } });
    const name = element({ hidden: true });
    const fields = {
        '[data-report-media-file]': kind === 'file' ? input : null,
        'input[type="url"]': kind === 'url' ? input : null,
        '[data-report-file-name]': name,
        '[data-report-media-status]': status,
        '[data-report-media-retry]': retry,
    };
    const form = element({
        submissions: 0,
        querySelector: selector => fields[selector],
        querySelectorAll: () => [submit, retry],
        closest: () => ({ reportAutosave: { saveNow } }),
        reportValidity: () => true,
        requestSubmit() { this.pending = this.emit('submit'); },
        submit() { this.submissions += 1; },
    });
    return { form, input, retry, submit, status, name };
}

(async () => {
    let finishSave;
    let fileSaves = 0;
    const file = uploadForm('file', () => {
        fileSaves += 1;
        return new Promise(resolve => { finishSave = resolve; });
    });
    // Inline controls are visually inside the report, but owned by a separate upload form.
    file.form.id = 'report-document-upload-basis';
    const inlineFields = { querySelector: file.form.querySelector, querySelectorAll: file.form.querySelectorAll };
    file.form.querySelector = () => null;
    file.form.querySelectorAll = () => [];
    let allowSave = false;
    const url = uploadForm('url', async () => allowSave);
    const deletion = uploadForm('delete', async () => allowSave);
    const dialogs = ['report-image-manager'].map(id => {
        const open = element();
        const close = element();
        const dialog = element({
            id,
            querySelector: () => close,
            showModal() { this.open = true; }, close() { this.open = false; },
        });
        return { dialog, open, close };
    });
    const document = {
        querySelector: selector => selector === '[data-report-media-fields="report-document-upload-basis"]' ? inlineFields : null,
        querySelectorAll(selector) {
            if (selector === '[data-report-media-dialog]') return dialogs.map(item => item.dialog);
            if (selector === '[data-report-media-form]') return [file.form, url.form];
            if (selector === '[data-report-document-delete]') return [deletion.form];
            return dialogs.filter(item => selector === `[data-report-media-open="${item.dialog.id}"]`).map(item => item.open);
        },
        addEventListener: (_, callback) => callback(),
    };
    vm.runInNewContext(fs.readFileSync(__dirname + '/../public/js/report-media-upload.js', 'utf8'), { document });

    dialogs[0].open.emit('click');
    assert.equal(dialogs[0].dialog.open, true, 'Image management still uses a dialog.');
    dialogs[0].close.emit('click');
    assert.equal(dialogs[0].dialog.open, false);

    file.input.emit('change');
    assert.equal(fileSaves, 0, 'Dismissing the file picker does not submit.');
    file.input.files = [{ name: 'front.jpg' }, { name: 'back.jpg' }];
    file.input.emit('change');
    assert.equal(fileSaves, 1, 'Selecting files starts saving immediately.');
    assert.equal(file.form.submissions, 0, 'Wait for report autosave before navigating.');
    assert.equal(file.name.textContent, 'front.jpg, back.jpg');
    await file.form.emit('submit');
    assert.equal(fileSaves, 1, 'Ignore duplicate submissions while saving.');
    finishSave(true);
    await file.form.pending;
    assert.equal(file.form.submissions, 1);
    assert.equal(file.input.disabled, false, 'Files must remain enabled for native form submission.');

    assert.equal(url.submit.disabled, true);
    url.input.value = '  ';
    url.input.emit('input');
    assert.equal(url.submit.disabled, true, 'Whitespace is not a URL.');
    await url.form.emit('submit');
    assert.equal(url.form.submissions, 0);
    url.input.value = 'https://example.invalid/photo.jpg';
    url.input.emit('input');
    assert.equal(url.submit.disabled, false);
    assert.equal(url.form.submissions, 0, 'Typing a URL waits for Load.');
    await url.form.emit('submit');
    assert.equal(url.form.submissions, 0, 'Failed autosave must not lose report edits by navigating.');
    assert.equal(url.status.textContent, 'Failed');
    assert.equal(url.retry.hidden, false);
    assert.equal(url.submit.disabled, false);
    await deletion.form.emit('submit');
    assert.equal(deletion.form.submissions, 0, 'Do not delete while report edits cannot be saved.');
    allowSave = true;
    await url.form.emit('submit');
    assert.equal(url.form.submissions, 1, 'Retry succeeds after report edits are saved.');
    await deletion.form.emit('submit', { defaultPrevented: true });
    assert.equal(deletion.form.submissions, 0, 'Respect cancelling the delete confirmation.');
    await deletion.form.emit('submit');
    assert.equal(deletion.form.submissions, 1, 'Delete after saving current report edits.');
    console.log('PASS: inline document upload, image dialog, uploads/deletion after autosave, cancelled deletes, duplicate prevention and failure/retry.');
})().catch(error => { console.error(error); process.exitCode = 1; });
