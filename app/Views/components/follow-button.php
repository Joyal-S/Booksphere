<?php

declare(strict_types=1);

/**
 * components/follow-button.php
 *
 * The FOLLOW / UNFOLLOW control (Phase 9.2), shared by the author
 * page and the module's list pages. Following is a WRITE, so the
 * control is a small form - POST /authors/{id}/follow (or, when the
 * visitor already follows, the same path with _method=DELETE) - with
 * the CSRF token inside, exactly like every other data change of the
 * app.
 *
 * follow.js progressively enhances it: it intercepts the submit with
 * a fetch (X-Requested-With: fetch), the controller answers JSON and
 * the control repaints in place (the button state, the label and the
 * follower count). Without JavaScript the plain form posts, the
 * controller redirects back with a flash - both paths always agree
 * because the server is the single source of truth.
 *
 * Included from a view that sets $follow first:
 *
 *     $follow = [
 *         'author_id'  => 1,                 // required
 *         'author'     => 'Harper Lee',      // the no-JS flash message
 *         'followed'   => true,              // state for the session user
 *         'followers'  => 12,                // the count next to the button
 *         'show_count' => true,              // false hides the count link
 *         'compact'    => true,              // icon-only, for card rows
 *     ];
 */

$follow      = array_merge([
    'author_id'  => 0,
    'author'     => '',
    'followed'   => false,
    'followers'  => 0,
    'show_count' => true,
    'compact'    => false,
], $follow ?? []);

$authorId   = (int) $follow['author_id'];
$authorName = (string) $follow['author'];
$following  = (bool) $follow['followed'];
$followers  = (int) $follow['followers'];
$showCount  = (bool) $follow['show_count'];
$compact    = (bool) $follow['compact'];

// The two button states (the form always posts to the same path; the
// _method=DELETE input flips with the state). The label and icon live
// inside a <span> so follow.js can swap them without rebuilding.
$buttonClass = $following ? 'btn-following' : 'btn-follow';
$buttonIcon  = $following ? 'fa-circle-check' : 'fa-square-plus';
$buttonLabel = $following ? 'Following' : 'Follow';

?>
<span class="follow-control<?= $compact ? ' follow-control--compact' : '' ?>" data-follow-control data-author-id="<?= $authorId ?>">
    <form method="post" action="/authors/<?= $authorId ?>/follow" class="follow-form" data-follow-form data-current="<?= $following ? '1' : '0' ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <?php if ($following): ?>
            <input type="hidden" name="_method" value="DELETE" data-follow-method>
        <?php endif; ?>
        <button type="submit" class="btn <?= e($buttonClass) ?>" data-follow-button data-author-name="<?= e($authorName) ?>" aria-pressed="<?= $following ? 'true' : 'false' ?>" aria-label="<?= $following ? 'Unfollow ' . e($authorName) : 'Follow ' . e($authorName) ?>">
            <i class="fa-solid <?= e($buttonIcon) ?>" data-follow-icon aria-hidden="true"></i>
            <span data-follow-label><?= e($buttonLabel) ?></span>
        </button>
        <span class="visually-hidden" data-follow-status aria-live="polite"></span>
    </form>

    <?php if ($showCount): ?>
        <a class="follow-count" href="/authors/<?= $authorId ?>/followers" aria-label="<?= $followers ?> follower<?= $followers === 1 ? '' : 's' ?>">
            <i class="fa-solid fa-users" aria-hidden="true"></i>
            <span data-follow-count><?= $followers ?></span>
        </a>
    <?php endif; ?>
</span>
