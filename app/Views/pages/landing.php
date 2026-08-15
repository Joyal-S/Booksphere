<?php

declare(strict_types=1);

/**
 * pages/landing.php
 *
 * The public cover page of BookSphere: the "cover" design converted
 * into MVC views and rendered inside layouts/landing.php.
 */

$svgIcons = [
    'logo' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
    'compass' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>',
    'star' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
    'sparkles' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/><path d="M5 3v4"/><path d="M19 17v4"/><path d="M3 5h4"/><path d="M17 19h4"/></svg>',
    'bookmark' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/></svg>',
    'user-pen' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><path d="M16 11l2 2 4-4"/></svg>',
    'search' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',
    'cloud-download' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="M12 12v9"/><path d="m8 17 4 4 4-4"/></svg>',
    'chart' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
];

$landingUserFeatures = [
    [
        'svg'   => $svgIcons['compass'],
        'title' => 'Smart Book Discovery',
        'desc'  => 'Surface hidden gems through multi-criteria catalogue search and category browsing.'
    ],
    [
        'svg'   => $svgIcons['star'],
        'title' => 'Reviews & Ratings',
        'desc'  => 'Community-driven feedback with detailed star ratings, reviews, and approval workflows.'
    ],
    [
        'svg'   => $svgIcons['sparkles'],
        'title' => 'Intelligent Recommendations',
        'desc'  => 'Personalized picks based on weighted category affinity, author history, and reader ratings.'
    ],
    [
        'svg'   => $svgIcons['bookmark'],
        'title' => 'Personal Library',
        'desc'  => 'Organize custom reading shelves, track reading progress, and maintain personal wishlists.'
    ],
    [
        'svg'   => $svgIcons['user-pen'],
        'title' => 'Follow Authors',
        'desc'  => 'Follow author profiles, track release catalogues, and read community reviews.'
    ],
    [
        'svg'   => $svgIcons['search'],
        'title' => 'Advanced Search',
        'desc'  => 'Filter books by category, author, publisher, language, publication year, and status.'
    ],
    [
        'svg'   => $svgIcons['cloud-download'],
        'title' => 'Google Books Integration',
        'desc'  => 'Search and import external metadata dynamically via the official Google Books API.'
    ],
    [
        'svg'   => $svgIcons['chart'],
        'title' => 'Analytics Dashboard',
        'desc'  => 'Visualize reading statistics, active reading days, and printable analytics reports.'
    ],
];

$platformWorkflow = [
    [
        'step'  => '01',
        'title' => 'Discover',
        'desc'  => 'Find books through structured catalogue search, category filters, and curated indexes.',
        'svg'   => $svgIcons['search']
    ],
    [
        'step'  => '02',
        'title' => 'Evaluate',
        'desc'  => 'Explore verified community ratings, in-depth reviews, and detailed author metadata.',
        'svg'   => $svgIcons['star']
    ],
    [
        'step'  => '03',
        'title' => 'Organize',
        'desc'  => 'Manage personal reading shelves (Finished, Currently Reading, Want to Read, On Hold, Dropped).',
        'svg'   => $svgIcons['bookmark']
    ],
    [
        'step'  => '04',
        'title' => 'Recommend',
        'desc'  => 'Receive personalized book suggestions derived from weighted category and author affinity models.',
        'svg'   => $svgIcons['sparkles']
    ],
];

$platformStackCategories = [
    'Frontend' => [
        ['name' => 'HTML5', 'version' => null, 'tone' => 'html', 'icon' => 'fa-brands fa-html5'],
        ['name' => 'CSS3', 'version' => null, 'tone' => 'css', 'icon' => 'fa-brands fa-css3-alt'],
        ['name' => 'Bootstrap', 'version' => '5.3', 'tone' => 'bootstrap', 'icon' => 'fa-brands fa-bootstrap'],
        ['name' => 'JavaScript', 'version' => 'ES2023', 'tone' => 'javascript', 'icon' => 'fa-brands fa-js'],
    ],
    'Backend' => [
        ['name' => 'PHP', 'version' => '8.2', 'tone' => 'php', 'icon' => 'fa-brands fa-php'],
        ['name' => 'MVC Architecture', 'version' => null, 'tone' => 'mvc', 'icon' => 'fa-solid fa-diagram-project'],
    ],
    'Database' => [
        ['name' => 'SQLite', 'version' => '3', 'tone' => 'sqlite', 'icon' => 'fa-solid fa-database'],
    ],
    'Integrations' => [
        ['name' => 'Google Books API', 'version' => 'v1', 'tone' => 'gbooks', 'icon' => 'fa-solid fa-cloud-arrow-down'],
    ],
    'UI & Interaction' => [
        ['name' => 'GSAP', 'version' => '3.12', 'tone' => 'gsap', 'icon' => 'fa-solid fa-play'],
        ['name' => 'Lucide Icons', 'version' => '1.0', 'tone' => 'lucide', 'icon' => 'fa-solid fa-icons'],
    ],
];

$projectFacts = [
    ['label' => 'Project Name', 'value' => 'BookSphere'],
    ['label' => 'Project Type', 'value' => 'MCA Major Project'],
    ['label' => 'Architecture', 'value' => 'Custom PHP MVC Framework'],
    ['label' => 'Database Engine', 'value' => 'SQLite 3 Relational DB'],
    ['label' => 'UI Framework', 'value' => 'Bootstrap 5 + Custom Tokens'],
    ['label' => 'Recommendation Model', 'value' => 'Weighted Category & Author Affinity'],
];

$targetAudience = ['Readers', 'Students', 'Researchers', 'Book Enthusiasts'];

?>
<section class="landing-hero">

    <!-- Full-width header -->
    <header class="landing-header">
        <div class="container">
            <nav class="landing-navbar" aria-label="Primary">
                <a class="landing-logo" href="/login">
                    <span class="landing-logo-icon" aria-hidden="true">
                        <?= $svgIcons['logo'] ?>
                    </span>
                    <span class="landing-logo-name">BookSphere</span>
                </a>
                <div class="landing-auth">
                    <a class="btn btn-sm landing-btn landing-btn--ghost rounded-pill" href="/login">Log in</a>
                    <a class="btn btn-sm landing-btn landing-btn--solid rounded-pill" href="/register">Get started</a>
                </div>
            </nav>
        </div>
    </header>

    <div class="container">
        <div class="landing-hero-grid">

            <!-- Left: text -->
            <div class="landing-hero-text">
                <span class="landing-eyebrow">Academic Major Project</span>
                <h1 class="landing-title">
                    <span class="landing-title-line">Intelligent</span>
                    <span class="landing-title-gradient">Book Discovery</span>
                    <span class="landing-title-line">&amp; Recommendation</span>
                </h1>

                <p class="landing-tagline">Discover &nbsp;&bull;&nbsp; Evaluate &nbsp;&bull;&nbsp; Organize &nbsp;&bull;&nbsp; Recommend</p>

                <p class="landing-lead">
                    A modern software platform for intelligent book discovery, structured library management,
                    author community tracking, community reviews, and personalized recommendations.
                </p>
                <div class="landing-hero-actions">
                    <a class="btn landing-btn landing-btn--solid rounded-pill px-4 py-2" href="/register">Explore Platform <i class="fa-solid fa-arrow-right ms-2"></i></a>
                    <a class="btn landing-btn landing-btn--ghost rounded-pill px-4 py-2" href="/login">Sign In</a>
                </div>
            </div>

            <!-- Right: illustration -->
            <div class="landing-hero-illus-wrap">
                <?php require root_path('app/Views/pages/partials/landing-illustration.php'); ?>
            </div>

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
                    <div class="landing-feature-icon-tile" aria-hidden="true">
                        <?= $feature['svg'] ?>
                    </div>
                    <h3 class="landing-feature-title"><?= e($feature['title']) ?></h3>
                    <p class="landing-feature-desc"><?= e($feature['desc']) ?></p>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>

<!-- ── HOW BOOKSPHERE WORKS ── -->
<section class="landing-workflow-section" aria-labelledby="landing-workflow-title">
    <div class="container">
        <span class="landing-eyebrow">Platform Workflow</span>
        <h2 class="landing-section-title" id="landing-workflow-title">How BookSphere Works</h2>

        <div class="landing-workflow-grid">
            <?php foreach ($platformWorkflow as $step): ?>
                <div class="landing-workflow-card">
                    <div class="landing-workflow-header">
                        <span class="landing-workflow-step"><?= e($step['step']) ?></span>
                        <span class="landing-workflow-icon" aria-hidden="true"><?= $step['svg'] ?></span>
                    </div>
                    <h3 class="landing-workflow-title"><?= e($step['title']) ?></h3>
                    <p class="landing-workflow-desc"><?= e($step['desc']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
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

                <div class="landing-stack-groups">
                    <?php foreach ($platformStackCategories as $categoryName => $stackList): ?>
                        <div class="landing-stack-group">
                            <span class="landing-stack-cat-title"><?= e($categoryName) ?></span>
                            <ul class="landing-stack list-unstyled">
                                <?php foreach ($stackList as $tech): ?>
                                    <li class="landing-stack-tone--<?= e($tech['tone']) ?>">
                                        <span class="landing-stack-name"><?= e($tech['name']) ?></span>
                                        <?php if ($tech['version'] !== null): ?>
                                            <span class="landing-stack-version"><?= e($tech['version']) ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Project Information -->
            <div class="landing-panel">
                <span class="landing-eyebrow">Academic Architecture</span>
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
                    <p class="landing-note-text">MCA Major Project — Intelligent Book Discovery &amp; Recommendation Platform</p>
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
                        Light
                    </button>
                    <button type="button" class="landing-theme-btn" data-theme-toggle="dark" aria-pressed="true">
                        Dark
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
                <span class="landing-logo-icon" aria-hidden="true"><?= $svgIcons['logo'] ?></span>
                <span class="landing-logo-name">BookSphere</span>
                <span class="landing-footer-version">v1.0</span>
            </div>
            <p class="landing-footer-tagline">Intelligent Book Discovery &amp; Recommendation Platform</p>
        </div>
        <div class="landing-footer-center">
            <p>© 2026 BookSphere · MCA Major Project</p>
            <span>Academic Project &middot; Custom PHP MVC Architecture</span>
        </div>
        <div class="landing-footer-status">
            <span class="landing-status-dot" aria-hidden="true"></span>
            <span>Design System &middot; 2026</span>
        </div>
    </div>
</footer>