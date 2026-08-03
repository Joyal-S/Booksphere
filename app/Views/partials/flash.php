<?php

declare(strict_types=1);

/**
 * partials/flash.php
 *
 * Shows one-time flash messages (set with session()->flash() after
 * a redirect) as alerts, then clears them so they appear exactly
 * once. Rendering is delegated to the reusable alert component.
 *
 * Available variables:
 *     $flashSuccess / $flashError - read from the session
 */

$flashSuccess = session()->getFlash('success');
$flashError   = session()->getFlash('error');

$alerts = [];

if ($flashSuccess !== null) {
    $alerts[] = ['type' => 'success', 'message' => $flashSuccess];
}

if ($flashError !== null) {
    $alerts[] = ['type' => 'danger', 'message' => $flashError];
}

foreach ($alerts as $alert) {
    require root_path('app/Views/components/alert.php');
}

session()->clearFlash();
