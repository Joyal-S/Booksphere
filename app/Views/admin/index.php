<?php

declare(strict_types=1);

/**
 * admin/index.php
 *
 * Placeholder for the administrator area. Reaching this page at
 * all is the proof of role-based authorization: the AdminMiddleware
 * only lets users with the "admin" role through.
 *
 * The actual administration features arrive in a later phase.
 */

?>
<div class="page-intro">
    <p class="eyebrow">Restricted area</p>
    <h1>Administration</h1>
    <p class="lead">You are signed in as an administrator. Management tools will appear here in a later phase.</p>
</div>

<div class="card-base" style="max-width: 640px;">
    <div class="d-flex align-items-center gap-3">
        <i class="fa-solid fa-shield-halved card-icon" aria-hidden="true"></i>
        <div>
            <h2>Access granted</h2>
            <p>This placeholder page is only reachable by users with the admin role.</p>
        </div>
    </div>
</div>
