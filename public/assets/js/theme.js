// Loaded in <head>, before the stylesheets, so the page paints in the right
// theme the first time. A file, not inline: the CSP allows scripts from this
// origin only.
(function () {
    var theme = null;

    try {
        theme = window.localStorage.getItem('lpanel.theme');
    } catch (e) {
        // Storage blocked: follow the system.
    }

    if (theme !== 'light' && theme !== 'dark') {
        theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    document.documentElement.setAttribute('data-bs-theme', theme);
})();
