<?php

declare(strict_types=1);

/**
 * pages/settings.php
 *
 * The real SETTINGS page (Phase 9.5) - the successor of the "coming
 * soon" placeholder. Its first section is Email notifications: five
 * per-user switches a reader can flip without touching their in-app
 * notification preferences (those live in the Notification Center).
 *
 * Structure:
 *
 *     1. Intro  - the eyebrow + title + lead
 *     2. Email  - the preferences card: five labeled toggle rows, a
 *                 Save button and an aria-live status region. The
 *                 form posts to /settings/email-preferences and works
 *                 without JavaScript; settings.js upgrades the submit
 *                 to a fetch + in-place status
 *     3. Banner - when EMAIL_ENABLED=false the card shows a quiet
 *                 notice that delivery is off - the module is
 *                 optional and the app must keep working unconfigured
 *
 * Available variables (from SettingsController::show()):
 *     $preferences  - the five keys -> 0/1
 *     $emailEnabled - whether the email pipeline is switched on
 */

$preferences  = $preferences ?? [];
$emailEnabled = (bool) ($emailEnabled ?? false);

/** The five toggles with their copy. */
$toggles = [
    'follow' => [
        'label'   => 'Author follows & new releases',
        'help'    => 'A confirmation when you start following an author, and when a followed author publishes a new book.',
        'icon'    => 'fa-user-plus',
    ],
    'review' => [
        'label'   => 'Review appreciated',
        'help'    => 'When someone finds your review helpful.',
        'icon'    => 'fa-thumbs-up',
    ],
    'reply' => [
        'label'   => 'Replies to your reviews',
        'help'    => 'When someone replies to a review you wrote.',
        'icon'    => 'fa-comment',
    ],
    'recommendations' => [
        'label'   => 'Personal recommendations',
        'help'    => 'When a fresh set of picks is ready for you.',
        'icon'    => 'fa-wand-magic-sparkles',
    ],
    'newsletter' => [
        'label'   => 'Newsletter',
        'help'    => 'Periodic digests with new releases and community highlights (coming soon).',
        'icon'    => 'fa-envelope-open-text',
    ],
];

?>
<!-- 1. Intro -->
<section class="page-intro" data-animate>
    <p class="eyebrow">Settings</p>
    <h1>Account preferences</h1>
    <p class="lead">Choose how BookSphere talks to you — in the app and in your inbox.</p>
</section>

<!-- 2. Email notifications -->
<section class="card-base" data-animate style="max-width: 720px;">
    <div class="d-flex align-items-start gap-3 mb-2">
        <div class="settings-section-icon" aria-hidden="true">
            <i class="fa-solid fa-envelope-open-text"></i>
        </div>
        <div>
            <h2 class="h5 mb-1">Email notifications</h2>
            <p class="text-muted mb-0" style="font-size: 0.9rem;">
                These switches control <strong>emails</strong>. Your in-app notification
                preferences are separate and live in the
                <a href="/notifications/center">Notification Center</a>.
            </p>
        </div>
    </div>

    <?php if (!$emailEnabled): ?>
        <div class="settings-banner" role="status">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            <span>Email delivery is currently disabled on this server. Your choices are
            saved and will apply the moment it is turned on.</span>
        </div>
    <?php endif; ?>

    <form method="post" action="/settings/email-preferences" id="emailPrefsForm" data-settings-form>
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

        <div class="settings-toggles" id="emailToggles">
            <?php foreach ($toggles as $key => $toggle): ?>
                <div class="settings-toggle-row">
                    <span class="settings-toggle-icon" aria-hidden="true">
                        <i class="fa-solid <?= e($toggle['icon']) ?>"></i>
                    </span>
                    <label class="form-check form-switch settings-toggle-label" for="email-<?= e($key) ?>">
                        <input
                            class="form-check-input settings-toggle-input"
                            type="checkbox"
                            role="switch"
                            id="email-<?= e($key) ?>"
                            name="<?= e($key) ?>"
                            value="1"
                            data-email-toggle
                            <?= (int) ($preferences[$key] ?? 1) === 1 ? 'checked' : '' ?>
                        >
                        <span class="settings-toggle-copy">
                            <span class="settings-toggle-title"><?= e($toggle['label']) ?></span>
                            <span class="settings-toggle-help"><?= e($toggle['help']) ?></span>
                        </span>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="d-flex align-items-center gap-3 mt-4 pt-3 border-top" style="border-color: var(--border) !important;">
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-floppy-disk me-2" aria-hidden="true"></i>Save preferences
            </button>
            <span class="settings-status" data-settings-status role="status" aria-live="polite"></span>
        </div>
    </form>
</section>