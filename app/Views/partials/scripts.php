<?php

declare(strict_types=1);

/**
 * partials/scripts.php
 *
 * All JavaScript loaded at the end of the page body. Scripts are
 * placed at the bottom so the page content renders first and
 * visitors do not have to wait for JavaScript to finish loading.
 *
 * It is a partial so every layout includes exactly the same scripts.
 */

?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="<?= e(asset('js/charts.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/rating.js')) ?>"></script>
<script src="<?= e(asset('js/reviews.js')) ?>"></script>
<script src="<?= e(asset('js/library.js')) ?>"></script>
<script src="<?= e(asset('js/follow.js')) ?>"></script>
<script src="<?= e(asset('js/notifications.js')) ?>"></script>
<script src="<?= e(asset('js/settings.js')) ?>"></script>
<script src="<?= e(asset('js/google-books.js')) ?>"></script>
<script src="<?= e(asset('js/search.js')) ?>"></script>
