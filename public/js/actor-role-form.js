document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.actor-form').forEach((form) => {
        const roleSelect = form.querySelector('[data-actor-role-select]');
        const roleField = form.querySelector('[data-actor-role-field]');
        const customRoleRow = form.querySelector('[data-actor-custom-role-row]');
        const customRoleInput = customRoleRow ? customRoleRow.querySelector('input[name="custom_role"]') : null;
        const actorSelect = form.querySelector('[data-existing-actor-select]');
        const actorNameInput = form.querySelector('input[name="actor_name"]');
        const typeSelect = form.querySelector('[data-actor-type-select]');
        const newActorFields = form.querySelector('[data-new-actor-fields]');
        const submitButton = form.querySelector('[data-actor-submit]');

        if (roleSelect && customRoleRow && customRoleInput) {
            const syncCustomRole = () => {
                const actorHasValue = !actorSelect || actorSelect.value !== '';
                const isOther = actorHasValue && roleSelect.value === 'other';

                if (roleSelect.dataset.smartSelectEnhanced === 'true') {
                    customRoleRow.hidden = true;
                    customRoleInput.required = false;

                    if (!actorHasValue) {
                        customRoleInput.value = '';
                    }

                    return;
                }

                customRoleRow.hidden = !isOther;
                customRoleInput.required = isOther;

                if (!isOther) {
                    customRoleInput.value = '';
                }
            };

            roleSelect.addEventListener('change', syncCustomRole);
            syncCustomRole();
        }

        if (actorSelect && actorNameInput && typeSelect) {
            const syncNewActorFields = () => {
                const isNewActor = actorSelect.value === '__new__';

                if (newActorFields) {
                    newActorFields.hidden = !isNewActor;
                }

                actorNameInput.required = isNewActor;
                typeSelect.required = isNewActor;

                if (submitButton) {
                    submitButton.hidden = actorSelect.value === '';
                }

                if (roleField) {
                    roleField.hidden = actorSelect.value === '';
                }

                if (customRoleRow && actorSelect.value === '') {
                    customRoleRow.hidden = true;
                }

                if (roleSelect) {
                    roleSelect.dispatchEvent(new Event('change'));
                }
            };

            actorSelect.addEventListener('change', () => {
                const option = actorSelect.selectedOptions[0];

                if (option && option.value !== '' && option.value !== '__new__') {
                    actorNameInput.value = option.dataset.actorName || '';
                    typeSelect.value = option.dataset.actorType || 'organization';
                } else if (!option || option.value === '') {
                    actorNameInput.value = '';
                    typeSelect.value = 'organization';
                }

                typeSelect.dispatchEvent(new Event('change', { bubbles: true }));
                syncNewActorFields();
            });

            syncNewActorFields();
        }
    });

    document.querySelectorAll('.actor-contact-form').forEach((form) => {
        const contactSelect = form.querySelector('[data-contact-assignment-select]');
        const newContactFields = form.querySelector('[data-new-contact-fields]');
        const submitButton = form.querySelector('[data-contact-submit]');
        const contactInputs = newContactFields ? newContactFields.querySelectorAll('input') : [];
        const contactNameInput = newContactFields ? newContactFields.querySelector('input[name="contact_name"]') : null;

        if (!contactSelect || !newContactFields || !contactNameInput || !submitButton) {
            return;
        }

        const initialContactValue = contactSelect.dataset.initialValue || '';

        const syncNewContactFields = () => {
            const isNewContact = contactSelect.value === '__new__';
            newContactFields.hidden = !isNewContact;
            contactNameInput.required = isNewContact;
            submitButton.hidden = contactSelect.value === initialContactValue;

            if (!isNewContact) {
                contactInputs.forEach((input) => {
                    input.value = '';
                });
            }
        };

        contactSelect.addEventListener('change', syncNewContactFields);
        contactInputs.forEach((input) => {
            input.addEventListener('input', () => {
                submitButton.hidden = contactSelect.value !== '__new__' && contactSelect.value === initialContactValue;
            });
        });
        syncNewContactFields();
    });

    document.querySelectorAll('[data-edit-contact-select]').forEach((contactSelect) => {
        const form = contactSelect.closest('form');
        const newContactFields = form ? form.querySelector('[data-edit-new-contact-fields]') : null;
        const contactInputs = newContactFields ? newContactFields.querySelectorAll('input, textarea') : [];
        const contactNameInput = newContactFields ? newContactFields.querySelector('input[name="contact_name"]') : null;

        if (!newContactFields || !contactNameInput) {
            return;
        }

        const syncEditContactFields = () => {
            const isNewContact = contactSelect.value === '__new__';
            newContactFields.hidden = !isNewContact;
            contactNameInput.required = isNewContact;

            if (!isNewContact) {
                contactInputs.forEach((input) => {
                    input.value = '';
                });
            }
        };

        contactSelect.addEventListener('change', syncEditContactFields);
        syncEditContactFields();
    });

    document.querySelectorAll('[data-actor-assignment]').forEach((assignment) => {
        const readonly = assignment.querySelector('[data-actor-readonly]');
        const editForm = assignment.querySelector('[data-actor-edit-form]');
        const editButton = assignment.querySelector('[data-actor-edit-toggle]');
        const cancelButton = assignment.querySelector('[data-actor-edit-cancel]');

        if (!readonly || !editForm || !editButton || !cancelButton) {
            return;
        }

        editButton.addEventListener('click', () => {
            readonly.hidden = true;
            editForm.hidden = false;
            editButton.hidden = true;
        });

        cancelButton.addEventListener('click', () => {
            editForm.reset();
            const roleSelect = editForm.querySelector('[data-assignment-role-select]');

            if (roleSelect) {
                roleSelect.dispatchEvent(new Event('change'));
            }

            const contactSelect = editForm.querySelector('[data-edit-contact-select]');

            if (contactSelect) {
                contactSelect.dispatchEvent(new Event('change'));
            }

            editForm.hidden = true;
            readonly.hidden = false;
            editButton.hidden = false;
        });
    });

    document.querySelectorAll('[data-assignment-role-select]').forEach((roleSelect) => {
        const form = roleSelect.closest('form');
        const customRoleRow = form ? form.querySelector('[data-assignment-custom-role-row]') : null;
        const customRoleInput = customRoleRow ? customRoleRow.querySelector('input[name="custom_role"]') : null;

        if (!customRoleRow || !customRoleInput) {
            return;
        }

        const syncCustomRole = () => {
            const isOther = roleSelect.value === 'other';

            if (roleSelect.dataset.smartSelectEnhanced === 'true') {
                customRoleRow.hidden = true;
                customRoleInput.required = false;
                return;
            }

            customRoleRow.hidden = !isOther;
            customRoleInput.required = isOther;

            if (!isOther) {
                customRoleInput.value = '';
            }
        };

        roleSelect.addEventListener('change', syncCustomRole);
        syncCustomRole();
    });
});
