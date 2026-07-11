/* Setzt das gespeicherte Design (hell/dunkel) früh, bevor die Seite rendert. */
(function () {
    try {
        var t = localStorage.getItem('tm_theme') || 'dark';
        document.documentElement.setAttribute('data-theme', t);
    } catch (e) {}
})();
