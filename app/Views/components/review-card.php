<?php

declare(strict_types=1);

/**
 * components/review-card.php
 *
 * The reusable REVIEW CARD (Phase 7.4) - the professional review
 * presentation shared by every review list in the app, with the
 * Goodreads-style anatomy:
 *
 *     - avatar with initials (deterministic gradient tone, one of
 *       the six avatar-* keys) linking to the reviewer's public
 *       reviews page, reviewer name, review date, the "Edited"
 *       badge and the future-ready "Verified" badge (Phase 7.5)
 *     - the star rating and the review title
 *     - the review body with Read More / Read Less: reviews.js
 *       truncates at ~250 characters and expands smoothly with a
 *       CSS transition - no page reload, keyboard accessible
 *     - the book link, the live "Helpful" toggle (count + voted
 *       state repaint via fetch, Phase 7.5) and the "Report"
 *       button that opens the shared report modal (Phase 7.5) -
 *       both disabled for guests and for the review's own author,
 *       with an explanatory title
 *     - the owner/admin Edit / Delete actions when $manage is true
 *
 * A compact variant (the dashboard / profile cards, $compact = true)
 * renders the classic two-line card with the existing review-card
 * CSS classes - one component, two layouts, zero duplication.
 *
 * Included from a view that sets $review (a review row with the
 * 'user_name' / 'book_id' / 'book_title' display columns attached):
 *
 *     $review   = [...];   // required: the review row
 *     $compact  = false;   // optional: the small dashboard card
 *     $manage   = false;   // optional: render Edit / Delete actions
 *     $showBook = true;    // optional: the book title link
 *     $verified = false;   // optional: the "Verified" badge
 *
 * The full variant expects the Phase 7.5 engagement columns on the
 * row (attached by ReviewService::attachVoteState):
 *
 *     helpful_count - the review's helpful-vote count
 *     helpful_voted - whether the signed-in actor voted (bool)
 *     is_owner      - whether the review belongs to the actor
 *
 * The Edit / Delete buttons reuse the shared delete confirmation
 * modal (#reviewDeleteModal) and the Report button the shared
 * report modal (#reviewReportModal); the including page renders
 * ONE instance of each (the _delete-modal.php / _report-modal.php
 * partials).
 */

$review = array_merge([
    'id'         => 0,
    'user_id'    => 0,
    'user_name'  => 'Reader',
    'rating'     => 5,
    'title'      => '',
    'review'     => '',
    'is_edited'  => 0,
    'created_at' => '',
    'book_id'    => 0,
    'book_title' => '',
], $review ?? []);

$compact  = $compact ?? false;
$manage   = $manage ?? false;
$showBook = $showBook ?? true;
$verified = $verified ?? false;

// A deterministic avatar tone per reviewer (avatar-1..avatar-6 are
// the existing CSS gradients) - the same reviewer always gets the
// same tone without any extra column. The tone + initials are
// computed inside the shared reviews/partials/_avatar.php.
$name = (string) ($review['user_name'] !== '' ? $review['user_name'] : 'Reader');

$reviewDate = format_review_date((string) $review['created_at']);
?>

<?php if ($compact): ?>
    <article class="review-card">
        <div class="review-card-head">
            <?php $avatarName = $name; ?>
            <?php $avatarHref = (int) $review['user_id'] > 0 ? '/reviews/user/' . (int) $review['user_id'] : ''; ?>
            <?php require root_path('app/Views/reviews/partials/_avatar.php'); ?>
            <div class="review-card-who">
                <h3 class="review-card-name">
                    <?php if ((int) $review['user_id'] > 0): ?>
                        <a class="text-decoration-none" href="/reviews/user/<?= (int) $review['user_id'] ?>"><?= e($name) ?></a>
                    <?php else: ?>
                        <?= e($name) ?>
                    <?php endif; ?>
                </h3>
                <div class="star-row" role="img" aria-label="<?= (int) $review['rating'] ?> out of 5 stars">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="fa-solid fa-star<?= $i <= (int) $review['rating'] ? ' is-filled' : '' ?>" aria-hidden="true"></i>
                    <?php endfor; ?>
                </div>
            </div>
            <span class="review-card-time"><?= e($reviewDate) ?></span>
        </div>
        <p class="review-card-text">&ldquo;<?= e($review['review'] ?? '') ?>&rdquo;</p>
        <?php if ($showBook && (int) $review['book_id'] > 0): ?>
            <p class="review-card-book">
                <i class="fa-solid fa-book-open" aria-hidden="true"></i>
                <a class="text-decoration-none" href="/books/<?= (int) $review['book_id'] ?>">
                    <?= e($review['book_title'] !== '' ? $review['book_title'] : 'Book') ?>
                </a>
            </p>
        <?php endif; ?>
    </article>
<?php else: ?>
    <article class="review-card review-card--full" data-review-card>
        <div class="review-card-head">
            <?php $avatarName = $name; ?>
            <?php $avatarHref = (int) $review['user_id'] > 0 ? '/reviews/user/' . (int) $review['user_id'] : ''; ?>
            <?php require root_path('app/Views/reviews/partials/_avatar.php'); ?>

            <div class="review-card-who">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <?php if ((int) $review['user_id'] > 0): ?>
                        <a class="review-card-name" href="/reviews/user/<?= (int) $review['user_id'] ?>"><?= e($name) ?></a>
                    <?php else: ?>
                        <h3 class="review-card-name"><?= e($name) ?></h3>
                    <?php endif; ?>
                    <?php if ((int) ($review['is_edited'] ?? 0) === 1): ?>
                        <span class="badge text-bg-light" title="This review was edited after it was published">Edited</span>
                    <?php endif; ?>
                    <?php if ($verified): ?>
                        <span class="badge text-bg-success" title="Verified reader review (Phase 7.5)">Verified</span>
                    <?php endif; ?>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2 review-card-meta">
                    <?php $ratingInfo = [
                        'rating'  => (float) ($review['rating'] ?? 0),
                        'count'   => null,
                        'compact' => true,
                    ]; ?>
                    <?php require root_path('app/Views/books/components/rating-stars.php'); ?>
                    <?php if ($reviewDate !== ''): ?>
                        <time class="review-card-time" datetime="<?= e($review['created_at']) ?>"><?= e($reviewDate) ?></time>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($review['title'] !== ''): ?>
            <h3 class="review-card-title"><?= e($review['title']) ?></h3>
        <?php endif; ?>

        <p class="review-card-text review-card-text--long" data-review-body><?= e($review['review'] ?? '') ?></p>

        <?php if ($showBook && (int) $review['book_id'] > 0): ?>
            <p class="review-card-book">
                <i class="fa-solid fa-book-open" aria-hidden="true"></i>
                <a class="text-decoration-none" href="/books/<?= (int) $review['book_id'] ?>">
                    <?= e($review['book_title'] !== '' ? $review['book_title'] : 'Book') ?>
                </a>
            </p>
        <?php endif; ?>

        <div class="review-card-actions">
            <?php
            // Phase 7.5: the Helpful button is live. The count comes
            // from the row (helpful_count travels inside every list
            // read); the voted state and is_owner are attached by
            // ReviewService::attachVoteState for the signed-in
            // actor. Guests and the review's own author get a
            // disabled button with an explanatory title - the
            // policy gates (canVote / canReport) are the real
            // enforcement on the server.
            $actorId  = auth()?->id();
            $isOwner  = $actorId !== null && (int) ($review['user_id'] ?? 0) === $actorId;
            $canEngage = !$isOwner && auth_check();
            $helpfulCount = (int) ($review['helpful_count'] ?? 0);
            $helpfulVoted = (bool) ($review['helpful_voted'] ?? false);
            ?>
            <form class="d-inline-block m-0" method="post" data-helpful-form>
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <button class="btn btn-sm btn-outline-success review-helpful<?= $helpfulVoted ? ' is-active' : '' ?>"
                        type="submit"
                        data-review-id="<?= (int) $review['id'] ?>"
                        data-helpful-url="/reviews/<?= (int) $review['id'] ?>/helpful"
                        data-helpful-remove-url="/reviews/<?= (int) $review['id'] ?>/helpful/remove"
                        title="<?= !auth_check() ? 'Sign in to mark reviews as helpful' : ($isOwner ? 'You cannot mark your own review as helpful' : ($helpfulVoted ? 'Remove your helpful vote' : 'Mark this review as helpful')) ?>"
                        <?= $canEngage ? '' : 'disabled' ?>
                        aria-pressed="<?= $helpfulVoted ? 'true' : 'false' ?>">
                    <i class="fa-solid fa-thumbs-up me-1" aria-hidden="true"></i>
                    <span class="review-helpful-label">Helpful</span>
                    <span class="review-helpful-count" data-helpful-count><?= $helpfulCount ?></span>
                </button>
            </form>
            <button class="btn btn-sm btn-outline-secondary review-report" type="button"
                    data-bs-toggle="modal" data-bs-target="#reviewReportModal"
                    data-report-id="<?= (int) $review['id'] ?>"
                    title="<?= !auth_check() ? 'Sign in to report reviews' : ($isOwner ? 'You cannot report your own review' : 'Report this review') ?>"
                    <?= $canEngage ? '' : 'disabled' ?>>
                <i class="fa-regular fa-flag me-1" aria-hidden="true"></i>Report
            </button>

            <?php if ($manage): ?>
                <span class="ms-auto d-flex gap-2">
                    <a class="btn btn-sm btn-outline-primary" href="/reviews/<?= (int) $review['id'] ?>/edit">
                        <i class="fa-solid fa-pen me-1" aria-hidden="true"></i>Edit
                    </a>
                    <button class="btn btn-sm btn-outline-danger" type="button"
                            data-bs-toggle="modal" data-bs-target="#reviewDeleteModal"
                            data-delete-url="/reviews/<?= (int) $review['id'] ?>/delete"
                            data-delete-title="<?= e($review['title'] !== '' ? $review['title'] : 'Review #' . (int) $review['id']) ?>">
                        <i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Delete
                    </button>
                </span>
            <?php endif; ?>
        </div>
    </article>
<?php endif; ?>
