<?php

declare(strict_types=1);

/**
 * pages/landing.php
 *
 * The public cover page of BookSphere: the "cover" design converted
 * into MVC views and rendered inside layouts/landing.php (a bare,
 * dark, full-bleed shell - no app header/sidebar/footer).
 *
 * Structure of the page (1:1 with the approved Figma cover):
 *     1. Hero      - logo row, tagline headline, illustration
 *     2. Divider
 *     3. Features  - eight (8) Platform Features cards
 *     4. Tech      - Technology Stack badges + Project Information
 *     5. Theme     - the light/dark design-system preview toggle
 *     6. Footer
 *
 * The decorative illustration lives in pages/partials/landing-illustration.php.
 * All static copy is inline below (this is presentation data, so the
 * controller stays thin); the theme previews are driven by js/landing.js.
 */

// Presentation data for the repeated blocks (kept inline - the copy
// is static marketing text, not user content).
$landingUserFeatures = [
    ['icon' => '📚', 'title' => 'Smart Book Discovery', 'desc' => 'Surface hidden gems through behavior-aware, personalized search.'],
    ['icon' => '⭐', 'title' => 'Reviews & Ratings', 'desc' => 'Community-driven feedback with nuanced multi-criteria scoring.'],
    ['icon' => '🤖', 'title' => 'Intelligent Recommendations', 'desc' => 'AI-powered picks tailored to your unique reading history.'],
    ['icon' => '❤️', 'title' => 'Personal Library', 'desc' => 'Organize shelves, reading lists, and wish lists in one place.'],
    ['icon' => '👤', 'title' => 'Follow Authors', 'desc' => 'Track new releases and news from your favorite writers.'],
    ['icon' => '🔍', 'title' => 'Advanced Search', 'desc' => 'Filter by genre, era, language, mood, and audience.'],
    ['icon' => '📥', 'title' => 'Google Books Integration', 'desc' => 'Millions of titles instantly available via the Google Books API.'],
    ['icon' => '📊', 'title' => 'Analytics Dashboard', 'desc' => 'Visualize reading habits, streaks, and genre trends over time.'],
];

$platformStack = [
    ['icon' => '🐘', 'name' => 'PHP', 'version' => '8.2', 'tone' => 'php'],
    ['icon' => '🗄️', 'name' => 'SQLite', 'version' => '3', 'tone' => 'sqlite'],
    ['icon' => '🅱️', 'name' => 'Bootstrap', 'version' => '5', 'tone' => 'bootstrap'],
    ['icon' => '⚡', 'name' => 'JavaScript', 'version' => 'ES2023', 'tone' => 'javascript'],
    ['icon' => '🔶', 'name' => 'HTML5', 'version' => null, 'tone' => 'html'],
    ['icon' => '🎨', 'name' => 'CSS3', 'version' => null, 'tone' => 'css'],
    ['icon' => '🏗️', 'name' => 'MVC', 'version' => null, 'tone' => 'mvc'],
    ['icon' => '🟢', 'name' => 'GSAP', 'version' => '3', 'tone' => 'gsap'],
    ['icon' => '📚', 'name' => 'Google Books API', 'version' => 'v1', 'tone' => 'gbooks'],
    ['icon' => '✦', 'name' => 'Lucide Icons', 'version' => null, 'tone' => 'lucide'],
];

$projectFacts = [
    ['label' => 'Project', 'value' => 'BookSphere'],
    ['label' => 'Type', 'value' => 'MCA Major Project'],
    ['label' => 'Architecture', 'value' => 'PHP MVC'],
    ['label' => 'Database', 'value' => 'SQLite'],
    ['label' => 'UI Framework', 'value' => 'Bootstrap 5'],
];

$targetAudience = ['Readers', 'Students', 'Researchers', 'Book Enthusiasts'];

?>
<!-- ── HERO ── -->
<section class="landing-hero">
    <div class="container">
        <div class="landing-hero-grid">

            <!-- Left: text -->
            <div>
                <nav class="landing-navbar" aria-label="Primary">
                    <a class="landing-logo" href="/login">
                        <span class="landing-logo-icon" aria-hidden="true">📖</span>
                        <span class="landing-logo-name">BookSphere</span>
                    </a>
                    <div class="landing-auth">
                        <a class="btn btn-sm landing-btn landing-btn--ghost rounded-pill" href="/login">Log in</a>
                        <a class="btn btn-sm landing-btn landing-btn--solid rounded-pill" href="/register">Get started</a>
                    </div>
                </nav>

                <span class="landing-eyebrow">Intelligent Platform</span>
                <h1 class="landing-title">
                    <span class="landing-title-line">Intelligent</span>
                    <span class="landing-title-gradient">Book Discovery</span>
                    <span class="landing-title-line">&amp; Recommendation</span>
                </h1>

                <p class="landing-tagline">Discover &nbsp;&bull;&nbsp; Review &nbsp;&bull;&nbsp; Recommend</p>

                <p class="landing-lead">
                    A modern platform that helps readers discover books, organize personal libraries,
                    explore author communities, write reviews, and receive intelligent book recommendations.
                </p>
            </div>

            <!-- Right: illustration -->
            <?php require root_path('app/Views/pages/partials/landing-illustration.php'); ?>

        </div>
    </div>
</section>

<!-- ── DIVIDER ── -->
<div class="container">
    <div class="landing-divider" role="presentation"></div>
</div>

<!-- ── FEATURES ── -->
<section class="landing-features" aria-labelledby="landing-features-title">
    <div class="container">
        <span class="landing-eyebrow">Platform Features</span>
        <h2 class="landing-section-title" id="landing-features-title">Everything a reader needs</h2>

        <ul class="landing-feature-grid list-unstyled">
            <?php foreach ($landingUserFeatures as $feature): ?>
                <li class="landing-feature">
                    <span class="landing-feature-icon" aria-hidden="true"><?= $feature['icon'] ?></span>
                    <h3 class="landing-feature-title"><?= e($feature['title']) ?></h3>
                    <p class="landing-feature-desc"><?= e($feature['desc']) ?></p>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>

<!-- ── TECH + INFO ── -->
<section class="landing-tech">
    <div class="container">
        <div class="landing-tech-grid">

            <!-- Technology Stack -->
            <div class="landing-panel">
                <span class="landing-eyebrow">Built With</span>
                <h3 class="landing-panel-title">Technology Stack</h3>
                <ul class="landing-stack list-unstyled">
                    <?php foreach ($platformStack as $tech): ?>
                        <li class="landing-stack-tone--<?= e($tech['tone']) ?>">
                            <span class="landing-stack-icon" aria-hidden="true"><?= $tech['icon'] ?></span>
                            <span class="landing-stack-name"><?= e($tech['name']) ?></span>
                            <?php if ($tech['version'] !== null): ?>
                                <span class="landing-stack-version"><?= e($tech['version']) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Project Information -->
            <div class="landing-panel">
                <span class="landing-eyebrow">About This Project</span>
                <h3 class="landing-panel-title">Project Information</h3>
                <dl class="landing-facts">
                    <?php foreach ($projectFacts as $fact): ?>
                        <div class="landing-fact">
                            <dt><?= e($fact['label']) ?></dt>
                            <dd><?= e($fact['value']) ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
                <div class="landing-note">
                    <span class="landing-note-label">Purpose</span>
                    <p class="landing-note-text">Intelligent Book Discovery &amp; Recommendation Platform</p>
                </div>
                <div class="landing-note landing-note--targets">
                    <span class="landing-note-label">Target Users</span>
                    <div class="landing-chips">
                        <?php foreach ($targetAudience as $audience): ?>
                            <span class="landing-chip"><?= e($audience) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- ── THEME SUPPORT ── -->
<section class="landing-theme">
    <div class="container">
        <div class="landing-theme-panel">
            <div class="landing-theme-copy">
                <span class="landing-eyebrow">Design System</span>
                <h3 class="landing-theme-title">Theme Support</h3>
                <p class="landing-theme-desc">
                    BookSphere ships with fully tokenized light and dark themes, ensuring consistent,
                    accessible presentation across every screen and device.
                </p>

                <div class="landing-theme-toggle" role="group" aria-label="Preview theme">
                    <button type="button" class="landing-theme-btn landing-theme-btn--active" data-theme-toggle="light" aria-pressed="false">
                        ☀️ Light
                    </button>
                    <button type="button" class="landing-theme-btn" data-theme-toggle="dark" aria-pressed="true">
                        🌙 Dark
                    </button>
                </div>
            </div>

            <div class="landing-previews" id="theme-preview" data-theme="dark" aria-hidden="true">
                <!-- Light preview -->
                <div class="landing-preview">
                    <div class="landing-preview-window landing-preview-window--light">
                        <div class="landing-preview-header landing-preview-header--light">
                            <span class="landing-preview-dot"></span>
                            <span class="landing-preview-bar-flex"></span>
                        </div>
                        <div class="landing-preview-body">
                            <div class="landing-preview-line landing-preview-line--a landing-preview-line--light"></div>
                            <div class="landing-preview-line landing-preview-line--b landing-preview-line--light"></div>
                            <div class="landing-preview-cards">
                                <span class="landing-preview-card landing-preview-card--light"></span>
                                <span class="landing-preview-card landing-preview-card--light"></span>
                                <span class="landing-preview-card landing-preview-card--light"></span>
                            </div>
                            <div class="landing-preview-btn landing-preview-btn--light"></div>
                        </div>
                        <p class="landing-preview-caption landing-preview-caption--light">Light Mode</p>
                    </div>
                </div>
                <!-- Dark preview -->
                <div class="landing-preview">
                    <div class="landing-preview-window landing-preview-window--dark">
                        <div class="landing-preview-header landing-preview-header--dark">
                            <span class="landing-preview-dot"></span>
                            <span class="landing-preview-bar-flex"></span>
                        </div>
                        <div class="landing-preview-body">
                            <div class="landing-preview-line landing-preview-line--a landing-preview-line--dark"></div>
                            <div class="landing-preview-line landing-preview-line--b landing-preview-line--dark"></div>
                            <div class="landing-preview-cards">
                                <span class="landing-preview-card landing-preview-card--dark"></span>
                                <span class="landing-preview-card landing-preview-card--dark"></span>
                                <span class="landing-preview-card landing-preview-card--dark"></span>
                            </div>
                            <div class="landing-preview-btn landing-preview-btn--dark"></div>
                        </div>
                        <p class="landing-preview-caption landing-preview-caption--dark">Dark Mode</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── FOOTER ── -->
<footer class="landing-footer">
    <div class="container landing-footer-inner">
        <div class="landing-footer-brand">
            <div class="landing-logo landing-logo--footer">
                <span class="landing-logo-icon" aria-hidden="true">📖</span>
                <span class="landing-logo-name">BookSphere</span>
                <span class="landing-footer-version">v1.0</span>
            </div>
            <p class="landing-footer-tagline">Intelligent Book Discovery &amp; Recommendation Platform</p>
        </div>
        <div class="landing-footer-center">
            <p>Master of Computer Applications</p>
            <span>Academic Project &middot; Design System v1.0</span>
        </div>
        <div class="landing-footer-status">
            <span class="landing-status-dot" aria-hidden="true"></span>
            <span>Figma Cover &middot; 2025</span>
        </div>
    </div>
</footer>