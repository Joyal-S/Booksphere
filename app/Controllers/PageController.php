<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\Request;

/**
 * PageController
 *
 * Serves the placeholder pages of the main navigation: Wishlist
 * (nothing else - Analytics became the real user-analytics page in
 * Phase 12.1 and lives in UserAnalyticsController).
 *
 * The routes exist now so the sidebar navigation never leads to
 * a 404 once a user is logged in. (Browse Books became a real,
 * admin-only module in the book management phase and now lives in
 * BookController; Recommendations moved to RecommendationController
 * in Phase 6.1; Reviews became a real module in Phase 7.1 and now
 * lives in ReviewController; Categories and Authors became real
 * pages in Phase 7.6 and now live in CategoryController and
 * AuthorController; Settings became a real page in Phase 9.5 and now
 * lives in SettingsController; Analytics became a real page in
 * Phase 12.1 and now lives in UserAnalyticsController.)
 */
final class PageController extends Controller
{
    public function wishlist(Request $request, array $params = []): void
    {
        $this->comingSoon(
            'wishlist',
            'Wishlist',
            'fa-heart',
            'Save books for later and organise your reading list in a future phase.',
        );
    }

    /**
     * Render the shared "coming soon" placeholder page.
     *
     * @param string $key         Sidebar key used for the active highlight
     * @param string $title       Page title (also the <h1>)
     * @param string $icon        Font Awesome icon class, e.g. "fa-book-open"
     * @param string $description Short lead text under the title
     */
    private function comingSoon(string $key, string $title, string $icon, string $description): void
    {
        $this->view('pages.coming-soon', [
            'title'       => $title,
            'active'      => $key,
            'page'        => [
                'title'       => $title,
                'icon'        => $icon,
                'description' => $description,
            ],
        ]);
    }
}
