/**
 * charts.js
 *
 * The renderer of the Charts & Reports layer (Phase 12.5).
 *
 * It mounts every <canvas class="chart-canvas"> that sits next to a
 * <script type="application/json" data-chart-config> (the inline
 * config is emitted by the chart-card component through
 * ChartPresenter). Chart.js 4.4.3 is already a page dependency
 * (scripts.php), so this module adds no library of its own.
 *
 * Design decisions:
 *
 *     - Tokens, not colors: every dataset carries a Design TOKEN
 *       ('primary', 'info', 'success', 'warning', 'danger',
 *       'secondary'). The real color is read from the CSS custom
 *       properties of the ACTIVE theme at render time - and a
 *       MutationObserver re-renders every chart when <html>
 *       flips data-bs-theme, so light and dark mode always match
 *       the page.
 *     - Accessibility: canvases carry role="img" + aria-label and
 *       every chart card ALSO renders the numbers as a text summary
 *       (server-side), so nothing depends on the picture.
 *     - Respect prefers-reduced-motion: charts only animate when
 *       the visitor did not ask to reduce motion.
 *     - One registry per canvas: a re-render destroys the previous
 *       Chart instance first, so duplicate charts can never stack.
 */
(function () {
    'use strict';

    var instances = new Map();

    function token(name, fallback) {
        var value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return value !== '' ? value : fallback;
    }

    function palette() {
        return {
            primary:   token('--primary', '#5b4bdb'),
            info:      token('--info', '#0e7490'),
            success:   token('--success', '#16803c'),
            warning:   token('--warning', '#a55c00'),
            danger:    token('--danger', '#c43131'),
            secondary: token('--muted', '#64748b'),
            border:    token('--border', '#e5e9f2'),
            grid:      token('--border', '#e5e9f2'),
            text:      token('--body-color', '#172033'),
        };
    }

    function soft(hex, alpha) {
        var clean = String(hex).trim().replace('#', '');
        if (clean.length === 3) {
            clean = clean.charAt(0) + clean.charAt(0) + clean.charAt(1) + clean.charAt(1) + clean.charAt(2) + clean.charAt(2);
        }
        var n = parseInt(clean, 16);
        if (isNaN(n)) {
            return 'rgba(91, 76, 203, ' + alpha + ')';
        }
        return 'rgba(' + (n >> 16 & 255) + ', ' + (n >> 8 & 255) + ', ' + (n & 255) + ', ' + alpha + ')';
    }

    function resolve(config) {
        var colors = palette();
        var datasets = [];

        (config.sets || []).forEach(function (set) {
            var toneMap = {
                primary: colors.primary, info: colors.info, success: colors.success,
                warning: colors.warning, danger: colors.danger, secondary: colors.secondary,
            };
            var solid = toneMap[set.tone] || colors.primary;
            var values = set.values || [];

            if (config.type === 'doughnut') {
                datasets.push({
                    label: set.label || '',
                    data: values,
                    backgroundColor: [colors.primary, colors.info, colors.success, colors.warning, colors.danger, colors.secondary]
                        .slice(0, Math.max(values.length, 1)),
                    borderColor: colors.border,
                    borderWidth: 2,
                });
                return;
            }

            if (config.type === 'line') {
                datasets.push({
                    label: set.label,
                    data: values,
                    borderColor: solid,
                    backgroundColor: soft(solid, 0.12),
                    fill: true,
                    tension: 0.3,
                    pointRadius: 2,
                    pointBackgroundColor: solid,
                });
                return;
            }

            datasets.push({
                label: set.label,
                data: values,
                backgroundColor: solid,
                borderRadius: 4,
                maxBarThickness: 30,
            });
        });

        return datasets;
    }

    function options(config, colors) {
        var reduce = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var isDoughnut = config.type === 'doughnut';
        var horizontal = config.type === 'hbar';
        var manySets = (config.sets || []).length > 1;

        var o = {
            responsive: true,
            maintainAspectRatio: false,
            animation: reduce ? false : { duration: 250, easing: 'easeOutQuart' },
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: {
                    display: isDoughnut || manySets,
                    position: 'bottom',
                    labels: { color: colors.text, boxWidth: 10, boxHeight: 10, usePointStyle: true },
                },
                tooltip: {
                    backgroundColor: token('--surface', '#ffffff'),
                    titleColor: colors.text,
                    bodyColor: colors.text,
                    borderColor: colors.border,
                    borderWidth: 1,
                    padding: 10,
                    cornerRadius: 8,
                    displayColors: true,
                },
            },
        };

        if (!isDoughnut) {
            var categoryAxis = horizontal ? 'y' : 'x';
            var valueAxis = horizontal ? 'x' : 'y';

            o.scales = {};
            o.scales[categoryAxis] = {
                grid: { color: colors.border, drawOnChartArea: false },
                border: { color: colors.border },
                ticks: { color: colors.text, maxRotation: 0 },
            };
            o.scales[valueAxis] = {
                beginAtZero: true,
                grid: { color: colors.border },
                border: { color: colors.border },
                ticks: { color: colors.text },
            };
        }

        return o;
    }

    function mount(canvas) {
        if (typeof Chart === 'undefined') {
            return;
        }

        var wrapper = canvas.closest('.chart-frame');
        if (!wrapper) {
            return;
        }

        var script = wrapper.querySelector('script[data-chart-config]');
        if (!script) {
            return;
        }

        var config;
        try {
            config = JSON.parse(script.textContent || '{}');
        } catch (e) {
            canvas.setAttribute('aria-hidden', 'true');
            return;
        }

        if (!config || !Array.isArray(config.sets) || config.labels.length === 0) {
            canvas.setAttribute('aria-hidden', 'true');
            return;
        }

        if (instances.has(canvas)) {
            instances.get(canvas).destroy();
            instances.delete(canvas);
        }

        var colors = palette();
        var chartConfig = {
            type: config.type === 'doughnut' ? 'doughnut'
                : config.type === 'line' ? 'line' : 'bar',
            data: { labels: config.labels, datasets: resolve(config) },
            options: options(config, colors),
        };

        if (config.type === 'hbar') {
            chartConfig.options.indexAxis = 'y';
        }

        instances.set(canvas, new Chart(canvas.getContext('2d'), chartConfig));
    }

    function mountAll(scope) {
        var canvases = (scope || document).querySelectorAll('canvas.chart-canvas');
        canvases.forEach(function (canvas) {
            mount(canvas);
        });
    }

    function reTheme() {
        instances.forEach(function () {
            mountAll(document);
        });
    }

    function init() {
        mountAll(document);

        var observer = new MutationObserver(function () {
            reTheme();
        });
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });

        window.BookSphere = window.BookSphere || {};
        window.BookSphere.charts = {
            mountAll: mountAll,
            reTheme: reTheme,
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();