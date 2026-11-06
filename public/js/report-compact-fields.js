document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('#report-edit-form');
    const customOptionPrefix = document.documentElement.dataset.customOptionPrefix || 'Anders:';

    if (!form) {
        return;
    }

    const compactGroups = Array.from(form.querySelectorAll('[data-compact-group]'));

    const notifyChange = (element) => {
        element.dispatchEvent(new Event('input', { bubbles: true }));
        element.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const initializeGroup = (group) => {
        const combobox = group.querySelector('[data-compact-combobox]');
        const search = group.querySelector('[data-compact-search]');
        const menu = group.querySelector('[data-compact-options]');
        const optionButtons = Array.from(group.querySelectorAll('[data-compact-option]'));
        const useCustom = group.querySelector('[data-use-custom]');
        const customQuery = group.querySelector('[data-custom-query]');
        const noOptions = group.querySelector('[data-no-options]');
        const customTreatmentTemplate = group.querySelector('[data-custom-treatment-template]');
        const treatmentDateField = group.matches('[data-treatment-group]') ? group.querySelector('[data-treatment-date-field]') : null;
        const treatmentDate = treatmentDateField?.querySelector('[data-treatment-date]');
        let nextCustomTreatment = 0;

        const updateTreatmentDate = () => {
            if (!treatmentDateField) {
                return;
            }

            treatmentDateField.hidden = group.querySelector('[data-treatment-happened].is-selected') === null;
        };

        if (treatmentDateField) {
            group.addEventListener('change', updateTreatmentDate);
            updateTreatmentDate();
        }

        const clearItem = (item) => {
            item.querySelectorAll('[data-option-control]').forEach((control) => {
                if (control.type === 'checkbox') {
                    control.checked = false;
                } else {
                    control.value = '';
                }

                control.disabled = true;
            });

        };

        const clearQuickItem = (item) => {
            item.classList.remove('is-selected');
            item.querySelector('[data-compact-quick-toggle]')?.setAttribute('aria-pressed', 'false');
            clearItem(item);
        };

        group.querySelectorAll('[data-compact-quick-item]').forEach((item) => {
            if (item.closest('[data-compact-group]') !== group) {
                return;
            }

            const toggle = item.querySelector('[data-compact-quick-toggle]');
            if (!toggle) {
                return;
            }

            toggle.addEventListener('click', () => {
                const selected = !item.classList.contains('is-selected');
                const selectionGroup = item.dataset.selectionGroup || '';

                if (selected && selectionGroup !== '') {
                    group.querySelectorAll('[data-compact-quick-item].is-selected').forEach((candidate) => {
                        if (candidate === item || candidate.dataset.selectionGroup !== selectionGroup) {
                            return;
                        }

                        clearQuickItem(candidate);
                        notifyChange(candidate);
                    });
                }

                item.classList.toggle('is-selected', selected);
                toggle.setAttribute('aria-pressed', selected ? 'true' : 'false');

                if (selected) {
                    item.querySelectorAll('[data-option-control]').forEach((control) => {
                        control.disabled = false;

                        if (control.type === 'checkbox') {
                            control.checked = true;
                        }
                    });
                } else {
                    clearItem(item);
                }

                if (
                    treatmentDate
                    && treatmentDate.value !== ''
                    && group.querySelector('[data-treatment-happened].is-selected') === null
                ) {
                    treatmentDate.value = '';
                    notifyChange(treatmentDate);
                }

                notifyChange(item);
            });
        });

        group.querySelectorAll('[data-remove-compact-item]').forEach((button) => {
            if (button.closest('[data-compact-group]') !== group) {
                return;
            }

            button.addEventListener('click', () => {
                const item = button.closest('[data-compact-item]');

                if (!item) {
                    return;
                }

                if (item.matches('[data-custom-treatment]')) {
                    item.remove();
                    notifyChange(form);
                    return;
                }

                if (item.matches('[data-treatment-group]')) {
                    item.querySelectorAll('[data-compact-quick-item].is-selected').forEach(clearQuickItem);
                    item.querySelectorAll('input:not([type="hidden"]):not([type="checkbox"])').forEach((input) => {
                        input.value = '';
                    });
                    item.disabled = true;
                } else {
                    clearItem(item);
                }

                item.hidden = true;
                notifyChange(item);
            });
        });

        if (!combobox || !search || !menu || !useCustom || !customQuery || !noOptions) {
            return;
        }

        const itemForName = (name) => {
            return Array.from(group.querySelectorAll('[data-compact-item]'))
                .find((item) => item.dataset.compactItem === name) || null;
        };

        const closeMenu = () => {
            menu.hidden = true;
            search.setAttribute('aria-expanded', 'false');
        };

        const refreshMenu = () => {
            const query = search.value.trim().toLocaleLowerCase(document.documentElement.lang || undefined);
            let visibleCount = 0;
            let exactMatch = false;

            optionButtons.forEach((button) => {
                const item = itemForName(button.dataset.compactOption || '');
                const available = item !== null && item.hidden;
                const searchText = (button.dataset.searchText || '').trim();
                const optionLabel = (button.dataset.optionLabel || '').trim();
                const matches = query === '' || searchText.includes(query);

                if (optionLabel === query) {
                    exactMatch = true;
                }

                const show = available && matches && visibleCount < 8;
                button.hidden = !show;

                if (show) {
                    visibleCount += 1;
                }
            });

            const customItem = Array.from(group.querySelectorAll('[data-open-custom]'))
                .find((item) => item.hidden) || null;
            const canUseCustom = query !== '' && !exactMatch && (customItem !== null || customTreatmentTemplate !== null);

            useCustom.hidden = !canUseCustom;
            customQuery.textContent = `${customOptionPrefix} ${search.value.trim()}`;
            noOptions.hidden = query === '' || visibleCount > 0 || canUseCustom;

            return visibleCount > 0 || canUseCustom || query !== '';
        };

        const openMenu = () => {
            const hasContent = refreshMenu();
            menu.hidden = !hasContent;
            search.setAttribute('aria-expanded', hasContent ? 'true' : 'false');
        };

        const activateItem = (item, customValue = '') => {
            item.hidden = false;

            if (item.matches('[data-treatment-group]')) {
                item.disabled = false;
            } else {
                item.querySelectorAll('[data-option-control]').forEach((control) => {
                    control.disabled = false;

                    if (control.matches('[data-presence-control]')) {
                        control.value = '1';
                    }

                    if (control.type === 'checkbox') {
                        control.checked = true;
                    }
                });
            }

            const customControl = item.querySelector('[data-custom-value]');
            const itemLabel = item.querySelector('[data-item-label]');

            if (customControl && customValue !== '') {
                customControl.value = customValue;
            }

            if (itemLabel && customValue !== '') {
                itemLabel.textContent = customValue;
            }

            search.value = '';
            closeMenu();
            notifyChange(item);

            const focusedElement = document.activeElement;

            if (focusedElement instanceof HTMLElement && combobox.contains(focusedElement)) {
                focusedElement.blur();
            }
        };

        optionButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const item = itemForName(button.dataset.compactOption || '');

                if (item) {
                    activateItem(item);
                }
            });
        });

        useCustom.addEventListener('click', () => {
            if (customTreatmentTemplate) {
                const customName = search.value.trim();
                if (customName === '') {
                    return;
                }

                while (itemForName('custom-treatment-' + nextCustomTreatment)) {
                    nextCustomTreatment += 1;
                }

                const index = nextCustomTreatment++;
                const item = customTreatmentTemplate.content.firstElementChild.cloneNode(true);
                item.dataset.compactItem = 'custom-treatment-' + index;
                item.dataset.compactGroup = item.dataset.compactItem;
                item.querySelectorAll('[name]').forEach((input) => {
                    input.name = input.name.replace('[__index__]', '[' + index + ']');
                });
                group.querySelector('[data-selected-list]').append(item);
                initializeGroup(item);
                activateItem(item, customName);
                return;
            }

            const item = Array.from(group.querySelectorAll('[data-open-custom]'))
                .find((candidate) => candidate.hidden) || null;

            if (item) {
                activateItem(item, search.value.trim());
            }
        });

        search.addEventListener('pointerdown', (event) => {
            if (!menu.hidden) {
                event.preventDefault();
                closeMenu();
                search.blur();
                return;
            }

            if (document.activeElement === search) {
                openMenu();
            }
        });

        search.addEventListener('focus', openMenu);
        search.addEventListener('input', openMenu);
        search.addEventListener('keydown', (event) => {
            const visibleOptions = Array.from(menu.querySelectorAll('button:not([hidden])'));

            if (event.key === 'Escape') {
                closeMenu();
                return;
            }

            if (event.key === 'ArrowDown' && visibleOptions.length > 0) {
                event.preventDefault();
                visibleOptions[0].focus();
                return;
            }

            if (event.key === 'Enter' && visibleOptions.length > 0) {
                event.preventDefault();
                visibleOptions[0].click();
            }
        });

        menu.addEventListener('keydown', (event) => {
            const visibleOptions = Array.from(menu.querySelectorAll('button:not([hidden])'));
            const currentIndex = visibleOptions.indexOf(document.activeElement);

            if (event.key === 'Escape') {
                closeMenu();
                search.focus();
                return;
            }

            if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
                return;
            }

            event.preventDefault();
            const direction = event.key === 'ArrowDown' ? 1 : -1;
            const nextIndex = Math.min(Math.max(currentIndex + direction, 0), visibleOptions.length - 1);
            visibleOptions[nextIndex]?.focus();
        });

        document.addEventListener('pointerdown', (event) => {
            if (!combobox.contains(event.target)) {
                closeMenu();
            }
        });
    };

    compactGroups.forEach(initializeGroup);

    const dependentGroups = Array.from(form.querySelectorAll('[data-compact-requires]'), (group) => ({
        group,
        controls: group.dataset.compactRequires.split(' ').map((name) => form.elements.namedItem('report_data[' + name + ']')),
    }));
    if (dependentGroups.length > 0) {
        const updateDependentGroups = () => {
            dependentGroups.forEach(({ group, controls }) => {
                const visible = controls.every((control) => control?.checked && !control.matches(':disabled'));
                group.hidden = !visible;
                group.disabled = !visible;
            });
        };
        form.addEventListener('change', updateDependentGroups);
        updateDependentGroups();
    }

    form.querySelectorAll('[data-conditional-block]').forEach((block) => {
        const content = block.querySelector('[data-conditional-content]');
        const choices = Array.from(block.querySelectorAll('[data-presence-choice]'));

        if (!content || choices.length === 0) {
            return;
        }

        choices.forEach((choice) => {
            choice.addEventListener('click', () => {
                const deselect = choice.classList.contains('is-selected');

                choices.forEach((candidate) => {
                    const selected = candidate === choice && !deselect;
                    const control = candidate.querySelector('[data-presence-control]');

                    candidate.classList.toggle('is-selected', selected);
                    candidate.setAttribute('aria-pressed', selected ? 'true' : 'false');

                    if (control) {
                        control.checked = selected;
                    }
                });

                content.hidden = !block.querySelector('[data-presence-choice="present"].is-selected');
                notifyChange(choice);
            });
        });
    });

});
