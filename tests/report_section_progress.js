// Isolated counters: documents supplement, rather than replace, the fields in their tab.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function panel(fields, documentCount, compactItems = []) {
    return {
        querySelector: () => null,
        querySelectorAll(selector) {
            if (selector === '[data-report-document-row]') return Array(documentCount).fill({});
            if (selector === '[data-compact-item]') return compactItems;
            if (selector === 'input, select, textarea') return fields;
            return [];
        },
    };
}
function count(basis, recommendations) {
    const panels = { basis, recommendations };
    const buttons = Object.keys(panels).map(key => ({
        dataset: { reportTab: key },
        badge: { classList: { toggle() {} } },
        querySelector() { return this.badge; },
    }));
    const editor = {
        querySelector: selector => panels[selector.match(/="(.*?)"/)[1]],
        querySelectorAll: selector => selector === '[data-report-tab]' ? buttons : Object.values(panels),
    };
    vm.runInNewContext(fs.readFileSync(__dirname + '/../public/js/report-section-progress.js', 'utf8'), {
        document: {
            querySelector: () => editor,
            addEventListener: (name, callback) => { if (name === 'DOMContentLoaded') callback(); },
        },
        window: {}, MutationObserver: class { observe() {} },
    });
    return buttons.map(button => Number(button.badge.textContent));
}
const field = (value, upload = false) => ({ value, type: 'text', tagName: 'INPUT', closest: () => upload ? {} : null });
const compact = { matches: () => false, closest: () => null, hidden: false };
assert.deepEqual(count(panel([field('Reason'), field('Note')], 1), panel([], 2, [compact])), [3, 3]);
assert.deepEqual(count(panel([field('')], 1), panel([], 2)), [1, 2]);
assert.deepEqual(count(panel([field('Reason')], 0), panel([], 0, [compact])), [1, 1]);
assert.deepEqual(count(panel([], 0), panel([], 0)), [0, 0]);
assert.deepEqual(count(panel([field('General', true), field('chosen.pdf', true)], 0), panel([], 0)), [0, 0], 'Pending uploads are not filled report fields.');
console.log('PASS: fields and documents counted together, populated/empty tabs and compact recommendations.');
