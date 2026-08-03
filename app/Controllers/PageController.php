<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\Request;

/**
 * PageController
 *
 * Serves the placeholder pages of the main navigation: Categories,
 * Authors, Wishlist, Analytics and Settings. None of these
 * features exist yet (they belong to later phases), so every action
 * renders the same "coming soon" page with a page-specific title,
 * icon and description.
 *
 * The routes exist now so the sidebar navigation never leads to
 * a 404 once a user is logged in. (Browse Books became a real,
 * admin-only module in the book management phase and now lives in
 * BookController; Recommendations moved to RecommendationController
 * in Phase 6.1; Reviews became a real module in Phase 7.1 and now
 * lives in ReviewController.)
 */
final class PageController extends Controller
{
    public function categories(Request $request, array $params = []): void
    {
        $this->comingSoon(
            'categories',
            'Categories',
            'fa-tags',
            'Browse books by genre and category once the catalogue phase is complete.',
        );
    }

    public function authors(Request $request, array $params = []): void
    {
        $this->comingSoon(
            'authors',
            'Authors',
            'fa-user-pen',
            'Author pages with their biographies and published books arrive in a later phase.',
        );
    }

    public function wishlist(Request $request, array $params = []): void
    {
        $this->comingSoon(
            'wishlist',
            'Wishlist',
            'fa-heart',
            'Save books for later and organise your reading list in a future phase.',
        );
    }

    public function analytics(Request $request, array $params = []): void
    {
        $this->comingSoon(
            'analytics',
            'Analytics',
            'fa-chart-column',
            'Usage charts and catalogue statistics arrive in a later phase.',
        );
    }

    public function settings(Request $request, array $params = []): void
    {
        $this->comingSoon(
            'settings',
            'Settings',
            'fa-gear',
            'Profile preferences and reading settings arrive in a later phase.',
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
