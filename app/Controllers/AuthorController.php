<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Models\Author;
use BookSphere\App\Services\ReviewService;

/**
 * AuthorController
 *
 * The author pages of the platform (Phase 7.6): a listing of every
 * author with their community rating (index) and the full author
 * page (show).
 *
 *     - index -> the author directory with the average author
 *                rating per author (ReviewService::authorAverage())
 *     - show  -> one author's page: average author rating, books
 *                reviewed, highest rated book, most reviewed book,
 *                recent community reviews and top reviewers - all
 *                aggregated by the Reviews module
 *
 * Route protection: AuthMiddleware in the route table (like every
 * catalogue page). The controller stays thin: it resolves the author
 * row, asks the ReviewService for one statistics payload and renders
 * it.
 *
 * This replaces the PageController "coming soon" placeholder for
 * /authors - the sidebar destination now serves a real page.
 */
final class AuthorController extends Controller
{
    public function __construct(
        private readonly Author $authors,
        private readonly ?ReviewService $reviews = null,
    ) {}

    /**
     * The author directory: every author with the average rating
     * their books earned (real aggregation over approved reviews).
     */
    public function index(Request $request, array $params = []): void
    {
        $averages = $this->reviews?->authorAverage() ?? [];
        $byId     = array_column($averages, null, 'id');

        $authors = array_map(
            fn (array $author): array => [
                'id'      => (int) $author['id'],
                'name'    => $author['name'],
                'average' => (float) ($byId[(int) $author['id']]['average'] ?? 0),
                'count'   => (int) ($byId[(int) $author['id']]['count'] ?? 0),
            ],
            $this->authors->all(),
        );

        $this->view('authors.index', [
            'title'   => 'Authors',
            'active'  => 'authors',
            'authors' => $authors,
        ]);
    }

    /**
     * One author's page: the aggregated rating profile of their
     * books (ReviewService::authorStatistics()), or 404 when the
     * author does not exist.
     */
    public function show(Request $request, array $params = []): void
    {
        $author = $this->authors->findById((int) ($params['id'] ?? 0));

        if ($author === null) {
            Response::error(404, 'Author not found.');
        }

        $this->view('authors.show', [
            'title'      => $author['name'],
            'active'     => 'authors',
            'author'     => $author,
            'statistics' => $this->reviews?->authorStatistics((int) $author['id']) ?? [],
        ]);
    }
}
