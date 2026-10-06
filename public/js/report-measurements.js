document.addEventListener('DOMContentLoaded', () => {
    const count = document.querySelector('[data-measurement-part-count]');
    const container = document.querySelector('[data-measurement-parts]');
    const template = document.querySelector('[data-measurement-part-template]');
    if (!count || !container || !template) return;

    // Keep detached parts so correcting the count does not erase work in this editor.
    const parts = new Map(Array.from(container.children, part => [Number(part.dataset.measurementPart), part]));
    const update = () => {
        const total = Number(count.value || 0);
        if (!Number.isSafeInteger(total) || total < 0) return;

        for (const [number, part] of parts) {
            if (number > total) part.remove();
        }
        for (let number = 1; number <= total; number += 1) {
            let part = parts.get(number);
            if (!part) {
                const copy = document.createElement('template');
                copy.innerHTML = template.innerHTML.replaceAll('__part__', String(number));
                part = copy.content.firstElementChild;
                parts.set(number, part);
            }
            const added = part.parentElement !== container;
            container.append(part);
            if (added) {
                part.dispatchEvent(new Event('report-fields-added', { bubbles: true }));
            }
        }
    };

    count.addEventListener('input', update);
    count.addEventListener('change', update);
    update();
});
