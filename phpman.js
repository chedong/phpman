/* phpMan — theme-toggle + copy-button scripts v4.7 */
(function () {
    /* ── Theme toggle ──
       Storage key bumped to v2 (2026-06-25) to invalidate stale preferences
       that forced dark mode after CSS auto-switch refactor. */
    var STORAGE_KEY = 'phpman-theme-v2';

    // Restore saved preference
    var saved = localStorage.getItem(STORAGE_KEY);
    if (saved === 'light' || saved === 'dark') {
        document.documentElement.setAttribute('data-theme', saved);
    }

    // Create toggle button — icon set by updateToggleIcon() from CSS matchMedia
    var toggle = document.createElement('button');
    toggle.id = 'theme-toggle';
    toggle.title = 'Toggle light/dark mode';
    updateToggleIcon();

    toggle.onclick = function () {
        var current = document.documentElement.getAttribute('data-theme');
        // Determine what we're actually showing (account for OS default)
        var isDark = (current === 'dark') || (!current && window.matchMedia('(prefers-color-scheme: dark)').matches);
        var next = isDark ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem(STORAGE_KEY, next);
        updateToggleIcon();
    };

    // Listen for OS theme changes (only relevant when no manual override)
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
        if (!document.documentElement.getAttribute('data-theme')) {
            updateToggleIcon();
        }
    });

    function updateToggleIcon() {
        var d = document.documentElement;
        var current = d.getAttribute('data-theme');
        var isDark = (current === 'dark') || (!current && window.matchMedia('(prefers-color-scheme: dark)').matches);
        toggle.textContent = isDark ? '☀' : '☾';  // ☀ in dark (tap for light), ☾ in light (tap for dark)
    }

    document.body.appendChild(toggle);

    /* ── Copy buttons ── */
    var blocks = document.querySelectorAll('#content-wrap pre code');
    if (!blocks.length) return;

    blocks.forEach(function (code) {
        var pre = code.parentElement;
        var wrapper = document.createElement('div');
        wrapper.className = 'code-block';

        pre.insertBefore(wrapper, code);
        wrapper.appendChild(code);

        var btn = document.createElement('button');
        btn.className = 'copy-btn';
        btn.textContent = '📋 Copy';  // 📋 Copy
        btn.title = 'Copy code to clipboard';

        btn.onclick = function () {
            navigator.clipboard.writeText(code.textContent).then(function () {
                btn.textContent = '✓ Copied!';  // ✓ Copied!
                btn.classList.add('copied');
                setTimeout(function () {
                    btn.textContent = '📋 Copy';
                    btn.classList.remove('copied');
                }, 1500);
            });
        };

        wrapper.appendChild(btn);
    });
})();

/* ── Sidebar toggles (#273) ──
   These used to be inline onclick attributes. An inline event handler is covered
   by script-src, so keeping them would have forced 'unsafe-inline' back into the
   CSP and defeated the point of the nonce. Delegated from one listener instead:
   the markup only carries the id, and this file carries the behaviour. */
(function () {
    var TOGGLES = {
        'toc-toggle': 'toc-open',
        'alpha-toggle': 'alpha-open'
    };

    document.addEventListener('click', function (e) {
        var el = e.target;
        // Walk up from the click target: these headers contain <span> icons, so
        // the click rarely lands on the div itself.
        while (el && el !== document.body) {
            if (el.id && TOGGLES[el.id]) {
                document.body.classList.toggle(TOGGLES[el.id]);
                return;
            }
            el = el.parentElement;
        }
    });
})();
