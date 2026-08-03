<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\Request;

/**
 * DashboardController
 *
 * Renders the logged-in user's dashboard (the root of the
 * application). The page itself is presentation-only in this
 * phase: every book, review and statistic it shows is placeholder
 * data defined inside the view. Real data arrives in later phases
 * (catalogue, wishlist, reviews, recommendations, analytics).
 *
 * The route is protected by AuthMiddleware, so the greeting can
 * safely read the logged-in user from the session.
 */
final class DashboardController extends Controller
{
    public function index(Request $request, array $params = []): void
    {
        $this->view('dashboard.index', [
            'title'  => 'Dashboard',
            'active' => 'dashboard',
        ]);
    }
}
