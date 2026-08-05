---
name: ui-ux-design
description: Use when working on any UI, UX, design, styling, layout, responsive, accessibility, or front-end task in BookSphere — changing or creating views, components, CSS, markup, buttons, cards, forms, modals, headers, sidebars, dark mode, or page structure. Use when asked to make something "look good", "premium", "polished", "consistent", or "responsive". Provides the BookSphere design system tokens, component patterns, and UI/UX rules so changes stay consistent with the existing app.
---

# BookSphere UI/UX Design

BookSphere is a book discovery and recommendation app. Stack: custom PHP MVC
(views in `app/Views/`), Bootstrap 5.3.3, Font Awesome 6.5.2, vanilla
JavaScript (`public/assets/js/`), SQLite.

## Golden rules

1. **Use the existing design system — never invent a parallel one.** Read the
   CSS before writing new styles. Reuse existing components in
   `app/Views/components/` (stat-card, empty-state, star-rating, section-header,
   review-card, rating-badge, loading-skeleton, alert, button…) and partials in
   `app/Views/partials/` (head, header, sidebar, footer, flash, form-errors).
2. **Use design tokens, not hard-coded values.** Pull colors, radii, shadows,
   and fonts from the CSS custom properties below. Never type hex values
   ad-hoc.
3. **Stability over refactoring.** Do not rewrite working views or restyle
   pages that were not asked for. Small, focused changes only.
4. **Mobile-first, responsive, accessible.** Every addition must work at
   360px wide and pass keyboard + screen-reader basics.
5. **No new dependencies.** Bootstrap, Font Awesome, Inter/Fraunces, and the
   existing JS are the whole toolkit.

## Design tokens (from `public/assets/css/app.css` `:root`)

- **Brand:** primary `#5b4bdb` (`--primary`), strong `#4536c4`
  (`--primary-strong`), soft `#eeecff` (`--primary-soft`), feeds Bootstrap via
  `--bs-primary`.
- **Neutrals:** canvas `#f8fafc` (`--canvas`), surface `#ffffff` (`--surface`),
  surface-2 `#f3f5fa`, inverse `#172033`, text `#172033`, muted `#64748b`,
  border `#e5e9f2`, border-strong `#d5dbe8`.
- **Tones:** success `#16803c`, warning `#a55c00`, danger `#c43131`,
  info `#0e7490`, star `#f59e0b` — each tone has a `-soft` background variant.
- **Radii:** `--radius-sm: 8px`, `--radius: 12px`, `--radius-lg: 16px`,
  `--radius-xl: 20px`, `--radius-full: 999px`.
- **Shadows:** `--shadow-sm`, `--shadow`, `--shadow-lg`, `--shadow-primary`.
- **Layout:** `--navbar-height: 68px`, `--sidebar-width: 264px` (collapsed 76px).
- **Fonts:** `--font-sans: "Inter"` for UI, `--font-serif: "Fraunces"` for
  stylised book covers / headings on the landing page. Headings use tight
  letter-spacing (`-0.02em`), line-height 1.25.
- **Dark mode:** the app uses `data-bs-theme` on `<html>`. CSS has dark
  overrides near the end of `app.css` (e.g. `--bs-primary: #8b80ff`). When
  adding colors, provide both light and dark values via the existing override
  blocks — check `app.css` for where they live and follow that pattern.

## File organisation

- Views: `app/Views/<feature>/*.php` (plain PHP, escaped output via `e()`,
  reusable snippets under `components/`, `partials/`).
- CSS: `public/assets/css/` — `app.css` (global + design system),
  `auth.css`, `landing.css`, `library.css`, `rating.css`, `reviews.css`.
- JS: `public/assets/js/` mirrors the same naming; no jQuery, vanilla JS,
  use Bootstrap's JS API for interactive components.

## UI/UX rules for every change

- **Hierarchy:** one clear primary action per view; use `section-header`
  for page titles; keep information scent (links/actions visible where users
  look).
- **Buttons:** prefer existing `.btn` + `.btn-primary` with `--shadow-primary`
  on hover; secondary/ghost variants already exist — reuse, don't redefine.
- **Cards:** use `--surface`, `--radius`, `--border`, `--shadow-sm`; hover
  lift with `--shadow` and a transition. Keep vertical rhythm consistent.
- **Forms:** labels always visible (no placeholder-only), `form-errors`
  partial for validation, clear focus rings (`--bs-focus-ring-color`),
  disabled/loading states for submits.
- **Empty & loading states:** use `empty-state` and `loading-skeleton`
  components instead of raw text or spinner hacks.
- **Typography:** Inter for UI; Fraunces only for stylised display text.
  Max line length ~65–75ch for prose. Never `px`-lock fluid text; use clamp
  where needed.
- **Spacing:** prefer Bootstrap utilities and the radius/shadow tokens;
  consistent 4px-based rhythm; generous whitespace over cramming.
- **Motion:** subtle (150–250ms ease) and only where it clarifies;
  respect `prefers-reduced-motion`.
- **Accessibility:** semantic landmarks (`header`, `nav`, `main`, `footer` —
  already used in `layouts/master.php`), skip links, `aria-label` on icon-only
  controls, contrast ≥ 4.5:1 for text, keyboard-visible focus, focus trap +
  `aria-modal` for modals, error messages linked to inputs with
  `aria-describedby`.
- **Responsive:** the sidebar collapses (see `--sidebar-width-collapsed` and
  `app.js`); ensure new content has no horizontal overflow at 360px, tables
  degrade to cards or scroll containers, touch targets ≥ 44px.
- **Content:** match the existing tone; never invent features or copy not
  requested.

## Verification checklist before finishing

- [ ] Uses existing tokens/components; no duplicated styles or hard-coded hex.
- [ ] Looks right at 360px, 768px, and desktop; no overflow.
- [ ] Dark mode checked (if colors were added).
- [ ] Keyboard navigable; visible focus; screen-reader labels present.
- [ ] No new libraries; markup escaped with `e()` where user data appears.
