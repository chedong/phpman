/* phpMan copy-button script */
/* Extracted from phpMan.php for external caching and XHTML validity */
(function () {
    var blocks = document.querySelectorAll('#content-wrap pre code');
    if (!blocks.length) return;

    blocks.forEach(function (code) {
        var pre = code.parentElement;
        var wrapper = document.createElement('div');
        wrapper.className = 'code-block';

        // Wrap <pre> in .code-block div
        pre.insertBefore(wrapper, code);
        wrapper.appendChild(code);

        // Create copy button
        var btn = document.createElement('button');
        btn.className = 'copy-btn';
        btn.textContent = '📋 Copy';
        btn.title = 'Copy code to clipboard';

        btn.onclick = function () {
            navigator.clipboard.writeText(code.textContent).then(function () {
                btn.textContent = '✓ Copied!';
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
