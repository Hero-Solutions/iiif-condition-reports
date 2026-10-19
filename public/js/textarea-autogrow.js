(function () {
    const widths = new WeakMap();

    const resize = (textarea) => {
        if (!textarea.isConnected || !textarea.getClientRects().length || textarea.clientWidth === 0) return;
        const style = getComputedStyle(textarea);
        const padding = parseFloat(style.paddingTop) + parseFloat(style.paddingBottom);
        const border = parseFloat(style.borderTopWidth) + parseFloat(style.borderBottomWidth);
        textarea.style.overflowY = 'hidden';
        textarea.style.height = 'auto';
        const height = textarea.scrollHeight + (style.boxSizing === 'border-box' ? border : -padding);
        textarea.style.height = `${Math.ceil(height)}px`;
    };

    const resizeAll = () => document.querySelectorAll('textarea').forEach(resize);
    const observer = new ResizeObserver((entries) => {
        const changed = entries.filter(({ target, contentRect }) => {
            if (widths.get(target) === contentRect.width) return false;
            widths.set(target, contentRect.width);
            return contentRect.width > 0;
        });
        // Height changes also trigger this observer. Only width/visibility changes need another pass.
        if (changed.length) requestAnimationFrame(() => changed.forEach(({ target }) => resize(target)));
    });

    const eachTextarea = (root, callback) => {
        if (root.nodeType !== Node.ELEMENT_NODE && root.nodeType !== Node.DOCUMENT_NODE) return;
        if (root.matches?.('textarea')) callback(root);
        root.querySelectorAll('textarea').forEach(callback);
    };
    const register = (textarea) => {
        if (widths.has(textarea)) return;
        widths.set(textarea, null);
        observer.observe(textarea);
        resize(textarea);
    };

    eachTextarea(document, register);
    new MutationObserver((mutations) => {
        mutations.forEach(({ addedNodes, removedNodes }) => {
            removedNodes.forEach((node) => eachTextarea(node, (textarea) => {
                if (!textarea.isConnected) {
                    observer.unobserve(textarea);
                    widths.delete(textarea);
                }
            }));
            addedNodes.forEach((node) => eachTextarea(node, register));
        });
    }).observe(document.body, { childList: true, subtree: true });

    ['input', 'change'].forEach((type) => document.addEventListener(type, (event) => {
        if (event.target.matches('textarea')) resize(event.target);
    }));
    document.addEventListener('reset', () => requestAnimationFrame(resizeAll));
    window.addEventListener('pageshow', resizeAll);
    if (document.fonts) {
        document.fonts.ready.then(resizeAll);
        document.fonts.addEventListener('loadingdone', resizeAll);
    }
})();
