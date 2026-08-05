<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Models\Category;
use BookSphere\App\Services\ReviewService;

/**
 * CategoryController
 *
 * The category pages of the platform (Phase 7.6): a listing of
 * every category with its community rating (index) and the full
 * category page (show).
 *
 *     - index -> the category directory with the average category
 *                rating (ReviewService::authorAverage()-style
 *                categoryAverage aggregation)
 *     - show  -> one category's page: average category rating,
 *                top rated books, most reviewed books, the
 *                community favourite and the recent community
 *                reviews - all aggregated by the Reviews module
 *
 * Route protection: AuthMiddleware in the route table (like every
 * catalogue page). The controller stays thin: it resolves the
 * category row, asks the ReviewService for one statistics payload
 * and renders it.
 *
 * This replaces the PageController "coming soon" placeholder for
 * /categories - the sidebar destination now serves a real page.
 */
final class CategoryController extends Controller
{
    public function __construct(
        private readonly Category $categories,
        private readonly ?ReviewService $reviews = null,
    ) {}

    /**
     * The category directory: every category with the average
     * rating and review count its books earned (real aggregation
     * over approved reviews).
     */
    public function index(Request $request, array $params = []): void
    {
        $averages = $this->reviews?->categoryAverage() ?? [];
        $byId     = array_column($averages, null, 'id');

        $categories = array_map(
            fn (array $category): array => [
                'id'      => (int) $category['id'],
                'name'    => $category['name'],
                'slug'    => $category['slug'] ?? '',
                'average' => (float) ($byId[(int) $category['id']]['average'] ?? 0),
                'count'   => (int) ($byId[(int) $category['id']]['count'] ?? 0),
            ],
            $this->categories->all(),
        );

        $this->view('categories.index', [
            'title'      => 'Categories',
            'active'     => 'categories',
            'categories' => $categories,
        ]);
    }

    /**
     * One category's page: the aggregated rating profile of its
     * books (ReviewService::categoryStatistics()), or 404 when the
     * category does not exist.
     */
    public function show(Request $request, array $params = []): void
    {
        $category = $this->categories->findById((int) ($params['id'] ?? 0));

        if ($category === null) {
            Response::error(404, 'Category not found.');
        }

        $this->view('categories.show', [
            'title'      => $category['name'],
            'active'     => 'categories',
            'category'   => $category,
            'statistics' => $this->reviews?->categoryStatistics((int) $category['id']) ?? [],
        ]);
    }
}
