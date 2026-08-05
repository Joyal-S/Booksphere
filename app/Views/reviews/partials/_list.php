<?php

declare(strict_types=1);

/**
 * reviews/partials/_list.php
 *
 * The shared APPROVED REVIEWS list of the book-facing pages (the
 * book detail section and the /books/{id}/reviews page) and the
 * review pages (My Reviews, search, user page). One markup, every
 * page - the pages can never drift apart.
 *
 * Phase 7.4: the list renders the professional review card
 * (components/review-card.php) in a timeline layout with the
 * Read More / Read Less body, the reviewer profile links, the
 * Helpful / Report placeholders and the owner/admin Edit / Delete
 * actions - per row, ONLY for rows the actor may manage (their
 * own, or any row for an admin), so a visitor never sees controls
 * they cannot use.
 *
 * Available variables (set by the including page):
 *     $reviews   - review rows ('user_name', 'user_id', 'rating',
 *                  'title', 'review', 'is_edited', 'created_at',
 *                  'book_id', 'book_title')
 *     $canManage - whether the signed-in actor may manage AT LEAST
 *                  one review here (owner of their own row, or an
 *                  admin who may manage any row). Default false.
 */

$reviews   = $reviews ?? [];
$canManage = $canManage ?? false;
$actorId   = auth()?->id();
?>
<?php if ($reviews === []): ?>
    <?php $emptyBase = [
        'title'   => 'No reviews yet',
        'message' => 'Be the first reader to review this book.',
    ]; ?>
    <?php require root_path('app/Views/reviews/partials/_empty.php'); ?>
<?php else: ?>
    <div class="review-list">
        <?php foreach ($reviews as $review): ?>
            <?php
            $mine = $canManage
                && $actorId !== null
                && ((int) ($review['user_id'] ?? 0) === $actorId || auth_is_admin());
            ?>
            <div class="review-list-item">
                <?php $manage = $mine; ?>
                <?php require root_path('app/Views/components/review-card.php'); ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
