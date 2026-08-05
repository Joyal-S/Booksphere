/**
 * landing.js
 *
 * The public cover page (layouts/landing.php). The only behaviour is
 * the light/dark theme preview: one data-theme attribute on the
 * previews container drives every state in css/landing.css, so the
 * script never juggles classes - and each toggle button keeps its
 * aria-pressed state in sync for assistive technology.
 *
 * Loaded after the markup, so no DOMContentLoaded wrapper is needed.
 */
(function () {
    'use strict';

    var root = document.getElementById('theme-preview');
    var buttons = Array.prototype.slice.call(
        document.querySelectorAll('[data-theme-toggle]')
    );

    if (!root || buttons.length === 0) {
        return;
    }

    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            var mode = button.getAttribute('data-theme-toggle');

            root.setAttribute('data-theme', mode);

            buttons.forEach(function (other) {
                var active = other === button;
                other.classList.toggle('landing-theme-btn--active', active);
                other.setAttribute('aria-pressed', String(active));
            });
        });
    });
})();