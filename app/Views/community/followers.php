<?php

declare(strict_types=1);

/**
 * community/followers.php
 *
 * Followers List View (Phase C7-B).
 * Displays paginated list of users following a community member.
 */

$profileUser = $profileUser ?? [];
$followers   = $followers   ?? [];
$total       = $total       ?? 0;
$page        = $page        ?? 1;
$pages       = $pages       ?? 1;

$userId   = (int) ($profileUser['id'] ?? 0);
$fullName = (string) ($profileUser['full_name'] ?? 'Member');
?>
<div class="mb-3">
    <a href="/community/user/<?= $userId ?>" class="text-decoration-none text-muted small">
        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i> Back to <?= e($fullName) ?>'s Profile
    </a>
</div>

<div class="card-base p-4 mb-4">
    <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-3">
        <h1 class="h4 mb-0 fw-bold">
            <i class="fa-solid fa-users text-primary me-2" aria-hidden="true"></i>Followers of <?= e($fullName) ?>
        </h1>
        <span class="badge bg-secondary-subtle text-secondary fs-6"><?= $total ?></span>
    </div>

    <?php if (empty($followers)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fa-solid fa-user-group fs-1 text-tertiary mb-3" aria-hidden="true"></i>
            <p class="mb-0">No followers yet.</p>
        </div>
    <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($followers as $follower): ?>
                <?php
                $fId     = (int) $follower['id'];
                $fName   = (string) $follower['full_name'];
                $initial = mb_strtoupper(mb_substr($fName, 0, 1));
                $since   = !empty($follower['created_at']) ? date('M Y', strtotime((string) $follower['created_at'])) : 'Member';
                ?>
                <div class="list-group-item d-flex align-items-center justify-content-between py-3 px-0 border-subtle">
                    <div class="d-flex align-items-center gap-3">
                        <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary fw-bold flex-shrink-0"
                             style="width: 44px; height: 44px; font-size: 1.1rem;">
                            <?= e($initial) ?>
                        </div>
                        <div>
                            <a href="/community/user/<?= $fId ?>" class="fw-bold text-dark text-decoration-none hover-primary">
                                <?= e($fName) ?>
                            </a>
                            <div class="text-muted small">
                                Joined <?= e($since) ?>
                            </div>
                        </div>
                    </div>
                    <a href="/community/user/<?= $fId ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3">
                        View Profile
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($pages > 1): ?>
            <div class="mt-4 d-flex justify-content-center">
                <nav aria-label="Followers pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <?php for ($p = 1; $p <= $pages; $p++): ?>
                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                <a class="page-link" href="/community/user/<?= $userId ?>/followers?page=<?= $p ?>"><?= $p ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
