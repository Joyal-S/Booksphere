<?php

declare(strict_types=1);

/**
 * LandingTest — CLI test suite for the public cover page
 *
 * Verifies the integrated cover page (pages/landing inside the bare
 * layouts/landing shell) without a database:
 *
 *     1. Structure  - the landing layout shell, skip link, one <h1>,
 *                     semantic <footer>, no inline style attributes
 *     2. Content    - hero headline (gradient line), the eight feature
 *                     cards, the ten stack badges, the project facts,
 *                     the target-user chips, the theme previews and
 *                     the footer
 *     3. Behaviour  - the theme toggle pair carries aria-pressed and
 *                     the previews container starts on the dark theme
 *
 * Run from the project root:
 *
 *     php tests/LandingTest.php
 *
 * How it works:
 *     - Renders the view through View::render with the landing layout
 *       inside an output buffer (the same technique the controller
 *       tests use for the wired page renders).
 *     - Every check prints PASS/FAIL; the summary line doubles as
 *       the cover-page checklist.
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Response;

$checks = 0;
$failed = 0;

/**
 * Run one check and print PASS/FAIL.
 *
 * @param bool        $ok     Whether the check passed
 * @param string      $label  Human-readable description of the check
 */
$check = function (bool $ok, string $label) use (&$checks, &$failed): void {
    $checks++;
    if (!$ok) {
        $failed++;
    }
    printf("  %s  %s\n", $ok ? 'PASS' : 'FAIL', $label);
};

// ---------------------------------------------------------------------
// 1. RENDER: the cover page through its own layout
// ---------------------------------------------------------------------

ob_start();
Response::view('pages.landing', [
    'title' => 'BookSphere — Discover, Review, Recommend',
], 200, 'layouts.landing');
$html = (string) ob_get_clean();

$check(str_starts_with($html, '<!doctype html>'), 'The landing layout emits a full HTML document');
$check(str_contains($html, '<html lang="en"'), 'The document declares lang="en"');
$check(str_contains($html, 'landing-page'), 'The landing page body shell renders');
$check(str_contains($html, 'class="landing-skip"'), 'A skip-to-content link is present');
$check(str_contains($html, 'css/landing.css'), 'The landing stylesheet is linked');
$check(str_contains($html, 'js/landing.js'), 'The landing script is loaded');

// ---------------------------------------------------------------------
// 2. CONTENT
// ---------------------------------------------------------------------

$check(substr_count($html, '<h1') === 1, 'Exactly one <h1> on the page (a11y)');
$check(str_contains($html, 'Intelligent') && str_contains($html, 'Book Discovery'), 'The hero headline renders');
$check(str_contains($html, 'landing-title-gradient'), 'The gradient headline line renders');
$check(str_contains($html, 'Discover &nbsp;&bull;&nbsp; Review'), 'The tagline renders');
$check(substr_count($html, '<li class="landing-feature">') === 8, 'All eight Platform Features cards render');
$check(str_contains($html, 'Analytics Dashboard'), 'The Analytics Dashboard feature renders');
$check(str_contains($html, 'Smart Book Discovery'), 'The Smart Book Discovery feature renders');
$check(substr_count($html, '<li class="landing-stack-tone--') === 10, 'All ten Technology Stack badges render');
$check(str_contains($html, 'Google Books API'), 'The Google Books API badge renders');
$check(str_contains($html, 'MCA Major Project'), 'The project facts render the type');
$check(str_contains($html, 'PHP MVC') && str_contains($html, 'SQLite'), 'The architecture facts render');
$check(str_contains($html, 'Target Users') && str_contains($html, 'Book Enthusiasts'), 'The target-user chips render');
$check(str_contains($html, 'Theme Support'), 'The theme section heading renders');
$check(substr_count($html, 'data-theme-toggle') === 2, 'The theme toggle has both buttons');
$check(str_contains($html, 'aria-pressed="true"'), 'The active (dark) toggle announces its state');
$check(str_contains($html, 'data-theme="dark"'), 'The previews start on the dark theme');
$check(str_contains($html, '<footer'), 'The page ends with a semantic <footer>');
$check(str_contains($html, 'Figma Cover'), 'The footer status label renders');
$check(str_contains($html, 'Master of Computer Applications'), 'The footer accreditation renders');

// ---------------------------------------------------------------------
// 3. HYGIENE
// ---------------------------------------------------------------------

$check(preg_match('/\sstyle="/', $html) === 0, 'No inline style attributes anywhere');
$check(str_contains($html, 'Log in') && str_contains($html, 'Get started'), 'The auth entry buttons render');
$check(substr_count($html, '<h2') === 1, 'One <h2> section heading, followed by <h3>s (h1 > h2 > h3)');
$check(substr_count($html, '<h3') >= 2, 'Section cards use <h3> headings');

// ---------------------------------------------------------------------
// RESULT
// ---------------------------------------------------------------------

echo "\n------------------------------------------------------------------------\n";
echo "RESULT\n";
echo "------------------------------------------------------------------------\n";
printf("  Checks: %d\n", $checks);
printf("  Failed: %d\n", $failed);

if ($failed > 0) {
    exit(1);
}

exit(0);