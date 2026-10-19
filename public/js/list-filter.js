(function () {
    const form = document.querySelector('[data-list-filter]');
    if (!form) return;
    const input = form.querySelector('input[name="q"]');
    const error = document.querySelector('[data-list-filter-error]');
    let results = document.querySelector('[data-list-results]');
    let timer;
    let request;

    const filter = async () => {
        request?.abort();
        const current = new AbortController();
        request = current;
        const url = new URL(form.action);
        url.search = new URLSearchParams(new FormData(form)).toString();
        error.hidden = true;
        results.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, { signal: current.signal, headers: { Accept: 'text/html' } });
            if (!response.ok) throw new Error('Filter request failed');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const replacement = page.querySelector('[data-list-results]');
            if (!replacement) throw new Error('Missing list results');
            if (current.signal.aborted) return;
            if (document.body.hasAttribute('data-read-only')) {
                replacement.querySelectorAll('form[method="post"] input, form[method="post"] button').forEach((control) => { control.disabled = true; });
            }
            results.replaceWith(replacement);
            results = replacement;
            history.replaceState(null, '', url);
        } catch (failure) {
            if (!current.signal.aborted) error.hidden = false;
        } finally {
            if (request === current) results.removeAttribute('aria-busy');
        }
    };

    input.addEventListener('input', (event) => {
        clearTimeout(timer);
        request?.abort();
        if (!event.isComposing) timer = setTimeout(filter, 350);
    });
    input.addEventListener('compositionend', () => {
        clearTimeout(timer);
        timer = setTimeout(filter, 350);
    });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        clearTimeout(timer);
        filter();
    });
})();
