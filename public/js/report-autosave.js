document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-report-autosave-url]');

    if (!form || form.dataset.reportAutosaveEnabled !== '1') {
        return;
    }

    const editor = form.closest('.report-editor');
    const status = document.querySelector('[data-report-autosave-status]');
    const backLink = document.querySelector('[data-report-back-link]');
    const intervalMs = parseInt(form.dataset.reportAutosaveInterval || '120000', 10);
    const url = form.dataset.reportAutosaveUrl;

    let dirty = false;
    let saving = false;
    let queued = false;
    let manualSubmit = false;
    let currentSave = null;

    const serialize = () => new URLSearchParams(new FormData(form)).toString();

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

        const label = status.dataset.savedLabel || '';
        status.textContent = label.replace('__time__', timeLabel(savedAt || new Date()));
    };

    const markDirty = () => {
        dirty = true;
        setStatus('dirty');
    };

    const saveNow = async () => {
        if (!dirty || manualSubmit) {
            return true;
        }

        if (saving) {
            queued = true;
            return currentSave || false;
        }

        saving = true;
        queued = false;
        setStatus('saving');

        const savedPayload = serialize();

        currentSave = (async () => {
            const response = await fetch(url, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error('Autosave failed');
            }

            const json = await response.json();
            const currentPayload = serialize();
            dirty = currentPayload !== savedPayload;

            if (dirty) {
                setStatus('dirty');
            } else {
                setStatus('saved', json.saved_at ? new Date(json.saved_at) : new Date());
            }

            return true;
        })();

        try {
            return await currentSave;
        } catch (error) {
            setStatus('error');
            return false;
        } finally {
            saving = false;
            currentSave = null;

            if (queued && dirty && !manualSubmit) {
                queued = false;
                await saveNow();
            }
        }
    };

    const saveOnUnload = () => {
        if (!dirty || manualSubmit) {
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

    if (backLink) {
        backLink.addEventListener('click', async (event) => {
            if (!dirty) {
                return;
            }

            event.preventDefault();
            await saveNow();
            window.location.href = backLink.href;
        });
    }

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
