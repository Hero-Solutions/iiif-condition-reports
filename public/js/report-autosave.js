document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-report-autosave-url]');

    if (!form || form.dataset.reportAutosaveEnabled !== '1') {
        return;
    }

    const editor = form.closest('.report-editor');
    const status = document.querySelector('[data-report-autosave-status]');
    const navigationLinks = [
        document.querySelector('[data-report-back-link]'),
        document.querySelector('[data-report-preview-link]'),
    ].filter(Boolean);
    const intervalMs = parseInt(form.dataset.reportAutosaveInterval || '120000', 10);
    const url = form.dataset.reportAutosaveUrl;
    const version = form.querySelector('[data-report-version]');
    const title = document.querySelector('[data-report-title]');

    let dirty = false;
    let saving = false;
    let navigating = false;
    let manualSubmit = false;
    let conflicted = false;
    let currentSave = null;

    const serialize = () => new URLSearchParams(new FormData(form)).toString();
    const fingerprint = () => {
        const data = new FormData(form);
        data.delete('version');

        return new URLSearchParams(data).toString();
    };

    const timeLabel = (date) => {
        return date.toLocaleTimeString(document.documentElement.lang || undefined, {
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    const setStatus = (state, savedAt = null) => {
        if (!status) {
            return;
        }

        status.classList.remove('is-dirty', 'is-saving', 'is-error');

        if (state === 'dirty') {
            status.textContent = status.dataset.unsavedLabel || '';
            status.classList.add('is-dirty');
            return;
        }

        if (state === 'saving') {
            status.textContent = status.dataset.savingLabel || '';
            status.classList.add('is-saving');
            return;
        }

        if (state === 'error') {
            status.textContent = status.dataset.failedLabel || '';
            status.classList.add('is-error');
            return;
        }

        if (state === 'conflict') {
            status.textContent = status.dataset.conflictLabel || status.dataset.failedLabel || '';
            status.classList.add('is-error');
            return;
        }

        const label = status.dataset.savedLabel || '';
        status.textContent = label.replace('__time__', timeLabel(savedAt || new Date()));
    };

    const markDirty = () => {
        dirty = true;
        setStatus('dirty');
    };

    const saveNow = async () => {
        if (conflicted) {
            return false;
        }

        if (manualSubmit) {
            return true;
        }

        if (saving) {
            const saved = await currentSave;
            return saved ? saveNow() : false;
        }

        if (!dirty) return true;

        saving = true;
        setStatus('saving');

        const savedPayload = fingerprint();

        currentSave = (async () => {
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (response.status === 409) {
                    conflicted = true;
                    setStatus('conflict');

                    return false;
                }

                if (!response.ok) {
                    throw new Error('Autosave failed');
                }

                const json = await response.json();

                if (version && json.version) {
                    version.value = String(json.version);
                }

                const currentPayload = fingerprint();
                dirty = currentPayload !== savedPayload;

                if (dirty) {
                    setStatus('dirty');
                } else {
                    setStatus('saved', json.saved_at ? new Date(json.saved_at) : new Date());

                    if (title && typeof json.title === 'string') {
                        title.textContent = json.title;
                        document.title = json.title + ' | ' + title.dataset.appTitle;
                    }
                }

                return true;
            } catch (error) {
                setStatus('error');
                return false;
            } finally {
                saving = false;
                currentSave = null;
            }
        })();

        return currentSave;
    };

    const saveOnUnload = () => {
        if (!dirty || manualSubmit || conflicted) {
            return;
        }

        const body = new Blob([serialize()], {
            type: 'application/x-www-form-urlencoded;charset=UTF-8',
        });

        if (navigator.sendBeacon && navigator.sendBeacon(url, body)) {
            dirty = false;
            return;
        }

        fetch(url, {
            method: 'POST',
            body,
            keepalive: true,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            },
        }).catch(() => {});
    };

    form.addEventListener('input', markDirty);
    form.addEventListener('change', markDirty);
    form.addEventListener('submit', () => {
        manualSubmit = true;
    });

    document.addEventListener('click', (event) => {
        const tab = event.target.closest('[data-report-tab]');

        if (!tab || tab.classList.contains('active')) {
            return;
        }

        saveNow();
    }, true);

    navigationLinks.forEach((link) => {
        link.addEventListener('click', async (event) => {
            if (!dirty && !saving && !conflicted) {
                return;
            }

            event.preventDefault();
            if (navigating || manualSubmit) return;

            navigating = true;
            link.setAttribute('aria-busy', 'true');
            try {
                let saved;
                do {
                    saved = await saveNow();
                } while (saved && dirty && !manualSubmit);

                if (saved && !manualSubmit) window.location.href = link.href;
            } finally {
                navigating = false;
                link.removeAttribute('aria-busy');
            }
        });
    });

    window.addEventListener('pagehide', saveOnUnload);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            saveOnUnload();
        }
    });

    window.setInterval(saveNow, intervalMs);

    if (editor) {
        editor.reportAutosave = { saveNow };
    }
});
