/**
 * rating.js
 *
 * The REUSABLE rating behaviour of BookSphere (Phase 7.3), loaded
 * on every page through partials/scripts.php. It enhances every
 * [data-star-input] widget rendered by the StarRatingComponent:
 *
 *     1. HOVER        - moving the pointer over a star lights up
 *                       that star and every star before it
 *     2. SELECTION    - clicking a star immediately stores the
 *                       value (hidden input), re-renders the stars
 *                       and updates the live preview ("You selected
 *                       ★★★★☆ 4 Stars") with no page reload
 *     3. KEYBOARD     - the group is a WAI-ARIA radiogroup: one
 *                       Tab stop, Arrow keys / Home / End move the
 *                       selection, Space / Enter select (roving
 *                       tabindex, the classic radio pattern)
 *     4. VALIDATION   - submitting the form without a rating shows
 *                       a friendly inline message and focuses the
 *                       stars; the backend still re-validates
 *     5. ANIMATION    - a subtle GSAP "pop" on selection and
 *                       animated distribution bars, both skipped
 *                       when prefers-reduced-motion is set
 *
 * No jQuery: plain modern JavaScript (Event delegation, classList,
 * dataset), identical in style to app.js.
 */

(() => {
    'use strict';

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------- Star input widgets ---------- */

    document.querySelectorAll('[data-star-input]').forEach((widget) => {
        const max = parseInt(widget.dataset.max || '5', 10) || 5;
        const stars = Array.from(widget.querySelectorAll('[data-star]'));
        const hidden = widget.querySelector('[data-star-value]');
        const preview = widget.querySelector('[data-star-preview]');
        const error = widget.querySelector('[data-star-error]');

        let value = parseInt(hidden?.value || '0', 10) || 0;
        let hovered = 0;

        /* The five filled / empty glyphs a state needs. */
        const glyphs = (filled) =>
            Array.from({ length: max }, (_, i) => (i < filled ? '★' : '☆')).join('');

        const renderStars = () => {
            stars.forEach((star, index) => {
                const isSelected = value >= index + 1;
                star.classList.toggle('is-selected', isSelected);
                star.classList.toggle('is-hovered', hovered > 0 && index < hovered);
                star.setAttribute('aria-checked', isSelected ? 'true' : 'false');
                const icon = star.querySelector('i');
                icon?.classList.toggle('fa-solid', isSelected);
                icon?.classList.toggle('fa-regular', !isSelected);
            });
        };

        const renderPreview = () => {
            if (preview === null) {
                return;
            }
            if (value > 0) {
                preview.textContent = `You selected ${glyphs(value)} ${value} Star${value === 1 ? '' : 's'}`;
                preview.classList.add('has-rating');
            } else {
                preview.textContent = 'Select a rating to continue';
                preview.classList.remove('has-rating');
            }
        };

        const renderError = (message) => {
            if (error === null) {
                return;
            }
            error.hidden = message === null;
            if (message !== null) {
                error.textContent = message;
            }
        };

        const setValue = (next, animate = true) => {
            value = Math.max(0, Math.min(max, next));
            if (hidden) {
                hidden.value = value > 0 ? String(value) : '';
            }
            renderStars();
            renderPreview();
            renderError(null);

            if (animate && value > 0 && window.gsap && !reduceMotion) {
                const chosen = stars[value - 1];
                gsap.fromTo(
                    chosen,
                    { scale: 1.35 },
                    { scale: 1, duration: 0.28, ease: 'back.out(2)', clearProps: 'transform' },
                );
            }
        };

        /* Roving tabindex: exactly one radio is in the tab order. */
        const focusStar = (index) => {
            stars.forEach((star, i) => {
                star.tabIndex = i === index ? 0 : -1;
            });
            stars[index]?.focus();
        };

        /* The currently "active" radio for arrow navigation. */
        const activeIndex = () => {
            const focused = stars.findIndex((star) => star === document.activeElement);
            return focused >= 0 ? focused : Math.max(0, value - 1);
        };

        /* ---------- Hover ---------- */
        stars.forEach((star, index) => {
            star.addEventListener('pointerenter', () => {
                hovered = index + 1;
                renderStars();
            });
        });

        widget.addEventListener('pointerleave', () => {
            hovered = 0;
            renderStars();
        });

        /* ---------- Click selection ---------- */
        widget.addEventListener('click', (event) => {
            const star = event.target.closest('[data-star]');
            if (star === null) {
                return;
            }
            const next = parseInt(star.dataset.star || '0', 10) || 0;
            setValue(next === value ? next : next, true);
            focusStar(next - 1);
        });

        /* ---------- Keyboard (radiogroup pattern) ---------- */
        widget.addEventListener('keydown', (event) => {
            const index = activeIndex();
            let next = null;

            switch (event.key) {
                case 'ArrowRight':
                case 'ArrowUp':
                    next = Math.min(max - 1, index + 1);
                    break;
                case 'ArrowLeft':
                case 'ArrowDown':
                    next = Math.max(0, index - 1);
                    break;
                case 'Home':
                    next = 0;
                    break;
                case 'End':
                    next = max - 1;
                    break;
                case ' ':
                case 'Enter':
                    event.preventDefault();
                    setValue(index + 1, true);
                    return;
                default:
                    return;
            }

            event.preventDefault();
            focusStar(next);
            setValue(next + 1, false);
        });

        /* ---------- Form validation ---------- */
        const form = widget.closest('form');
        form?.addEventListener('submit', (event) => {
            if (value > 0 || hidden?.value !== '') {
                return;
            }
            event.preventDefault();
            renderError('Please choose a rating between 1 and 5 stars.');
            focusStar(0);
        });

        /* Initial state. */
        renderStars();
        renderPreview();
    });

    /* ---------- Rating distribution bars ---------- */

    const bars = Array.from(document.querySelectorAll('.rating-dist-bar .progress-bar[data-bar-percent]'));

    if (bars.length > 0) {
        const paint = () => {
            bars.forEach((bar) => {
                const percent = parseFloat(bar.dataset.barPercent || '0');
                if (window.gsap && !reduceMotion) {
                    gsap.fromTo(
                        bar,
                        { width: '0%' },
                        { width: `${percent}%`, duration: 0.8, ease: 'power2.out', delay: 0.15 },
                    );
                } else {
                    bar.style.width = `${percent}%`;
                }
            });
        };

        /* Paint when the bars scroll into view (cheap, no scroll
           listener chatter: one IntersectionObserver). */
        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver(
                (entries, self) => {
                    if (entries.some((entry) => entry.isIntersecting)) {
                        paint();
                        self.disconnect();
                    }
                },
                { threshold: 0.2 },
            );
            bars[0].closest('.rating-distribution') !== null &&
                observer.observe(bars[0].closest('.rating-distribution'));
        } else {
            paint();
        }
    }
})();
