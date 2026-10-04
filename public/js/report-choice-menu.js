document.addEventListener('DOMContentLoaded', () => {
    const compactScreen = window.matchMedia('(max-width: 900px)');

    document.querySelectorAll('[data-report-choice]').forEach((container) => {
        const list = container.querySelector('[data-report-choice-list]');
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'report-choice-toggle';
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-controls', list.id);
        toggle.innerHTML = '<span class="report-choice-current"></span><svg class="icon" aria-hidden="true"><use href="#i-down"/></svg>';
        const current = toggle.querySelector('.report-choice-current');
        container.insertBefore(toggle, list);
        container.classList.add('has-choice-menu');

        const options = () => Array.from(list.querySelectorAll('button')).filter((button) => !button.disabled && !button.hidden);
        const selected = () => list.querySelector('button.active');
        const isOpen = () => toggle.getAttribute('aria-expanded') === 'true';

        const close = (restoreFocus = false) => {
            container.classList.remove('choice-menu-open');
            toggle.setAttribute('aria-expanded', 'false');
            if (restoreFocus) toggle.focus();
        };

        const position = () => {
            const bounds = toggle.getBoundingClientRect();
            const below = window.innerHeight - bounds.bottom - 12;
            const above = bounds.top - 12;
            const openAbove = below < 200 && above > below;
            container.classList.toggle('choice-menu-above', openAbove);
            list.style.setProperty('--choice-menu-height', `${Math.max(0, Math.min(420, openAbove ? above : below))}px`);
        };

        const open = () => {
            if (!compactScreen.matches || toggle.hidden) return;
            position();
            container.classList.add('choice-menu-open');
            toggle.setAttribute('aria-expanded', 'true');
            (selected() || options()[0])?.focus();
        };

        const update = () => {
            const active = selected();
            toggle.hidden = !active;
            if (!active) {
                close();
                return;
            }
            current.replaceChildren(...Array.from(active.children, (child) => child.cloneNode(true)));
            const label = active.title || active.querySelector('span')?.textContent || active.textContent;
            toggle.title = label;
            toggle.setAttribute('aria-label', `${container.dataset.reportChoice}: ${label}`);
        };

        toggle.addEventListener('click', () => isOpen() ? close(true) : open());
        container.addEventListener('click', (event) => {
            // Image selection rebuilds the buttons before this event reaches the container.
            if (isOpen() && event.composedPath().includes(list) && event.target.closest('button')) {
                close(true);
            }
        });
        container.addEventListener('keydown', (event) => {
            if (!compactScreen.matches) return;
            if (event.key === 'Escape' && isOpen()) {
                event.preventDefault();
                event.stopPropagation();
                close(true);
                return;
            }
            if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            if (!isOpen()) {
                open();
                return;
            }
            const buttons = options();
            const index = buttons.indexOf(document.activeElement);
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1
                : (index + (event.key === 'ArrowUp' ? -1 : 1) + buttons.length) % buttons.length;
            buttons[next]?.focus();
        });
        container.addEventListener('focusout', (event) => {
            // activeElement may briefly be the document body between two option buttons.
            if (event.relatedTarget && !container.contains(event.relatedTarget)) close();
        });
        document.addEventListener('pointerdown', (event) => {
            if (!container.contains(event.target)) close();
        });
        window.addEventListener('resize', () => { if (isOpen()) position(); });
        window.addEventListener('scroll', () => { if (isOpen()) position(); }, true);
        compactScreen.addEventListener('change', () => {
            const focusWasInside = container.contains(document.activeElement);
            close();
            if (focusWasInside) (compactScreen.matches ? toggle : selected())?.focus();
        });

        new MutationObserver(update).observe(list, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class', 'hidden', 'aria-selected', 'aria-pressed'],
        });
        update();
    });
});
