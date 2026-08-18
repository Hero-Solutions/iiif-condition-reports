document.addEventListener('DOMContentLoaded', () => {
    const dispatchChange = (element) => {
        element.dispatchEvent(new Event('input', { bubbles: true }));
        element.dispatchEvent(new Event('change', { bubbles: true }));
    };

    document.querySelectorAll('select[data-smart-select]').forEach((select, index) => {
        if (select.dataset.smartSelectEnhanced === 'true') {
            return;
        }

        const form = select.closest('form');
        const customKey = select.dataset.smartSelectKey || '';
        const customValue = select.dataset.smartSelectCustomValue || '';
        const customInput = customKey && form
            ? Array.from(form.querySelectorAll('[data-smart-select-custom-input]'))
                .find((input) => input.dataset.smartSelectCustomInput === customKey) || null
            : null;
        const selectOptions = Array.from(select.options);
        const emptyOption = selectOptions.find((option) => option.value === '') || null;
        const fixedOptions = selectOptions.filter((option) => option.value !== '' && option.value !== customValue);
        const listId = `smart-select-options-${index}`;
        const wrapper = document.createElement('div');
        const control = document.createElement('div');
        const search = document.createElement('input');
        const menu = document.createElement('div');
        const customButton = document.createElement('button');

        wrapper.className = 'smart-single-select';
        control.className = 'smart-single-select-control';
        menu.className = 'smart-single-select-options';
        menu.id = listId;
        menu.hidden = true;
        menu.setAttribute('role', 'listbox');

        search.type = 'text';
        search.autocomplete = 'off';
        search.required = select.required;
        search.disabled = select.disabled;
        search.placeholder = select.dataset.smartSelectPlaceholder || emptyOption?.textContent.trim() || '';
        search.setAttribute('role', 'combobox');
        search.setAttribute('aria-autocomplete', 'list');
        search.setAttribute('aria-controls', listId);
        search.setAttribute('aria-expanded', 'false');
        search.setAttribute(
            'aria-label',
            select.getAttribute('aria-label')
                || select.labels?.[0]?.querySelector(':scope > span')?.textContent.trim()
                || select.labels?.[0]?.textContent.trim()
                || select.name,
        );

        customButton.type = 'button';
        customButton.className = 'smart-single-select-custom';
        customButton.hidden = true;
        customButton.setAttribute('role', 'option');

        const optionButtons = fixedOptions.map((option) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = option.textContent.trim();
            button.dataset.value = option.value;
            button.dataset.searchText = option.textContent.trim().toLocaleLowerCase(document.documentElement.lang || undefined);
            button.setAttribute('role', 'option');
            menu.append(button);

            return button;
        });

        if (customValue && customInput) {
            menu.append(customButton);
        }

        control.innerHTML = '<svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>';
        control.append(search);
        wrapper.append(control, menu);
        select.insertAdjacentElement('beforebegin', wrapper);
        select.classList.add('smart-single-select-native');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');
        select.dataset.smartSelectEnhanced = 'true';

        const selectedText = () => {
            if (select.value === customValue && customInput?.value.trim()) {
                return customInput.value.trim();
            }

            const selectedOption = select.selectedOptions[0];

            return selectedOption && selectedOption.value !== '' ? selectedOption.textContent.trim() : '';
        };

        const closeMenu = () => {
            menu.hidden = true;
            search.setAttribute('aria-expanded', 'false');
        };

        const refreshMenu = (showAll = false) => {
            const query = showAll
                ? ''
                : search.value.trim().toLocaleLowerCase(document.documentElement.lang || undefined);
            let visibleCount = 0;
            let exactMatch = false;

            optionButtons.forEach((button) => {
                const matches = query === '' || button.dataset.searchText.includes(query);
                const show = matches && visibleCount < 8;
                button.hidden = !show;

                if (button.dataset.searchText === query) {
                    exactMatch = true;
                }

                if (show) {
                    visibleCount += 1;
                }
            });

            if (customValue && customInput) {
                const customText = search.value.trim();
                customButton.textContent = customText;
                customButton.hidden = showAll || customText === '' || exactMatch;
            }

            const hasOptions = visibleCount > 0 || !customButton.hidden;
            menu.hidden = !hasOptions;
            search.setAttribute('aria-expanded', hasOptions ? 'true' : 'false');
        };

        const clearCustomInput = () => {
            if (customInput && customInput.value !== '') {
                customInput.value = '';
                dispatchChange(customInput);
            }
        };

        const commitOption = (option) => {
            clearCustomInput();
            select.value = option.value;
            search.value = option.textContent.trim();
            dispatchChange(select);
            closeMenu();
        };

        const commitCustom = (value) => {
            if (!customValue || !customInput || value === '') {
                return false;
            }

            customInput.value = value;
            select.value = customValue;
            search.value = value;
            dispatchChange(customInput);
            dispatchChange(select);
            closeMenu();

            return true;
        };

        const commitSearchValue = () => {
            const value = search.value.trim();

            if (value === '') {
                if (!emptyOption) {
                    search.value = selectedText();
                    closeMenu();
                    return;
                }

                clearCustomInput();
                select.value = '';
                dispatchChange(select);
                search.value = '';
                closeMenu();
                return;
            }

            const normalizedValue = value.toLocaleLowerCase(document.documentElement.lang || undefined);
            const exactOption = fixedOptions.find((option) => (
                option.textContent.trim().toLocaleLowerCase(document.documentElement.lang || undefined) === normalizedValue
            ));

            if (exactOption) {
                commitOption(exactOption);
                return;
            }

            if (!commitCustom(value)) {
                search.value = selectedText();
                closeMenu();
            }
        };

        optionButtons.forEach((button) => {
            button.addEventListener('pointerdown', (event) => event.preventDefault());
            button.addEventListener('click', () => {
                const option = fixedOptions.find((candidate) => candidate.value === button.dataset.value);

                if (option) {
                    commitOption(option);
                    search.focus();
                    closeMenu();
                }
            });
        });

        customButton.addEventListener('pointerdown', (event) => event.preventDefault());
        customButton.addEventListener('click', () => {
            if (commitCustom(search.value.trim())) {
                search.focus();
                closeMenu();
            }
        });

        search.addEventListener('pointerdown', (event) => {
            if (!menu.hidden) {
                event.preventDefault();
                closeMenu();
                search.blur();
            }
        });
        search.addEventListener('focus', () => {
            search.select();
            refreshMenu(true);
        });
        search.addEventListener('input', () => refreshMenu());
        search.addEventListener('blur', (event) => {
            if (event.relatedTarget && menu.contains(event.relatedTarget)) {
                return;
            }

            commitSearchValue();
        });
        search.addEventListener('keydown', (event) => {
            const visibleButtons = Array.from(menu.querySelectorAll('button:not([hidden])'));

            if (event.key === 'Escape') {
                event.preventDefault();
                search.value = selectedText();
                closeMenu();
                search.blur();
                return;
            }

            if (event.key === 'ArrowDown' && visibleButtons.length > 0) {
                event.preventDefault();
                visibleButtons[0].focus();
                return;
            }

            if (event.key === 'Enter') {
                event.preventDefault();

                if (visibleButtons.length > 0) {
                    visibleButtons[0].click();
                } else {
                    commitSearchValue();
                }
            }
        });

        menu.addEventListener('keydown', (event) => {
            const visibleButtons = Array.from(menu.querySelectorAll('button:not([hidden])'));
            const currentIndex = visibleButtons.indexOf(document.activeElement);

            if (event.key === 'Escape') {
                event.preventDefault();
                closeMenu();
                search.focus();
                return;
            }

            if (!['ArrowDown', 'ArrowUp'].includes(event.key)) {
                return;
            }

            event.preventDefault();
            const direction = event.key === 'ArrowDown' ? 1 : -1;
            const nextIndex = Math.min(Math.max(currentIndex + direction, 0), visibleButtons.length - 1);
            visibleButtons[nextIndex]?.focus();
        });

        document.addEventListener('pointerdown', (event) => {
            if (!wrapper.contains(event.target)) {
                if (document.activeElement === search) {
                    commitSearchValue();
                } else {
                    closeMenu();
                }
            }
        });

        form?.addEventListener('reset', () => {
            window.setTimeout(() => {
                search.value = selectedText();
                closeMenu();
            });
        });
        form?.addEventListener('submit', commitSearchValue);
        select.addEventListener('change', () => {
            search.value = selectedText();
            closeMenu();
        });

        search.value = selectedText();
    });
});
