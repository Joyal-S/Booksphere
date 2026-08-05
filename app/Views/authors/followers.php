<?php

declare(strict_types=1);

/**
 * authors/followers.php
 *
 * The FOLLOWERS page (Phase 9.2): the people following one author,
 * newest first, resolved through the shared FollowService
 * (findFollowersOf joins the author_follows rows with the users so
 * no per-row query runs).
 *
 * Follows are PRIVATE: the author page only ever shows the COUNT;
 * this page lists the follower names for the signed-in reader (the
 * fine gate runs in the controller). The visitor's own follow state
 * rides along so the header can keep the Follow / Following button
 * in the same state as the author page.
 *
 * Available variables (from AuthorController::followers):
 *     $author    - the author row (id, name)
 *     $followers - the follower rows (user_id, full_name, created_at)
 *     $following - whether the session user follows the author
 *
 * The list is snapshotted into $people BEFORE the follow-button
 * component is included: the component runs in the same scope and
 * reassigns generic names ($followers, $following, $authorId ...), so
 * the rows must survive it under a name it never touches.
 */

$author    = $author ?? [];
$following = (bool) ($following ?? false);

// The snapshot: the component below overwrites $followers with the
// numeric count, so the rows live on as $people from here on.
$people = (array) ($followers ?? []);

$authorId   = (int) ($author['id'] ?? 0);
$authorName = (string) ($author['name'] ?? 'this author');
$count      = count($people);

?>
<div class="page-intro d-flex flex-wrap align-items-end justify-content-between gap-3">
    <div>
        <p class="eyebrow">Followers</p>
        <h1><?= e($authorName) ?></h1>
        <p class="lead">
            <?= $count ?> person<?= $count === 1 ? '' : 's' ?> follow<?= $count === 1 ? 's' : '' ?> this author.
        </p>
    </div>
    <?php $follow = [
        'author_id'  => $authorId,
        'author'     => $authorName,
        'followed'   => $following,
        'followers'  => $count,
        'show_count' => false,
    ]; ?>
    <?php require root_path('app/Views/components/follow-button.php'); ?>
</div>

<div class="card-base mt-4">
    <?php if ($people === []): ?>
        <div class="p-4 text-center text-muted">
            No one follows this author yet.
        </div>
    <?php else: ?>
        <ul class="list-unstyled follow-people mb-0">
            <?php foreach ($people as $person): ?>
                <li class="follow-person">
                    <span class="follow-person-avatar" aria-hidden="true">
                        <?= e(mb_strtoupper(mb_substr((string) ($person['full_name'] ?? '?'), 0, 1))) ?>
                    </span>
                    <div class="min-w-0">
                        <a href="/reviews/user/<?= (int) ($person['user_id'] ?? 0) ?>" class="text-decoration-none fw-semibold">
                            <?= e($person['full_name'] ?? 'A reader') ?>
                        </a>
                        <span class="d-block text-muted small">
                            following since <?= e(date('M j, Y', strtotime((string) ($person['created_at'] ?? 'now')))) ?>
                        </span>
                    </div>
                    <a class="ms-auto follow-person-link" href="/reviews/user/<?= (int) ($person['user_id'] ?? 0) ?>">
                        View activity <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>