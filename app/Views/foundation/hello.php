<?php

declare(strict_types=1);

/**
 * app/Views/foundation/hello.php
 *
 * Demo template for the parameterized route "/hello/{name}".
 * It shows how a value captured from the URL (via $params in the
 * route closure) reaches the view, and how e() safely escapes it.
 */

?>
<section class="page-intro" data-animate>
    <p class="eyebrow">Route parameters</p>
    <h1>Hello, <?= e($name) ?>!</h1>
    <p class="lead">This page was rendered by a parameterized route. The name part of the URL
        (<code>/hello/<?= e($name) ?></code>) was captured by the router and passed to the view.</p>
</section>
