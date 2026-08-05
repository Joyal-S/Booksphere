<?php

declare(strict_types=1);

namespace BookSphere\App\Presenters;

use BookSphere\App\Core\Request;
use BookSphere\App\Services\ReviewService;

/**
 * ReviewListPresenter (Phase 7.4)
 *
 * The VIEW-MODEL of the professional review lists. A presenter is the
 * presentation half of the MVC triangle: it shapes the request state
 * (sort, page size, page, search term, filters) into the two arrays
 * the review pages render - the toolbar (search box, sort and per-
 * page selects, filter chips) and the pagination (result line,
 * per-page select and pager links) - and it never owns SQL or
 * business rules (the repository and the service own those).
 *
 * Every review list page shares this presenter, so the book detail
 * page, the /books/{id}/reviews page, "My Reviews", the community
 * search, the statistics timeline and the per-user page can never
 * drift apart in how they read, sort, filter and paginate reviews:
 *
 *     $state      = $presenter->state($request);       // normalized
 *     $toolbar    = $presenter->toolbar($state, $base);
 *     $pagination = $presenter->pagination($state, $result, $base);
 *
 * The state values are already normalized by ReviewService::sort()
 * (allowlist) and ReviewService::normalizeListOptions() (casts and
 * safe defaults), so the query strings built here can only contain
 * values the repository accepts.
 */
final class ReviewListPresenter
{
    public function __construct(
        private readonly ReviewService $service,
    ) {}

    /**
     * The normalized list state for the current request (sort, page
     * size, page number, search term, rating / edited / mine filters,
     * book and user scopes). The values are safe to pass straight to
     * the repository queries.
     *
     * @return array<string, mixed>
     */
    public function state(Request $request): array
    {
        return $this->service->normalizeListOptions([
            'sort'    => $request->input('sort'),
            'perPage' => $request->input('per_page'),
            'page'    => $request->input('page'),
            'q'       => $request->input('q'),
            'rating'  => $request->input('rating'),
            'edited'  => $request->input('edited'),
            'mine'    => $request->input('mine'),
        ]);
    }

    /**
     * The toolbar payload of a review list: the base URL every form
     * and chip submits to, the current sort / page size / search
     * term / filters and the option lists the selects render.
     *
     * @param array<string, mixed> $state Normalized list state
     * @param array<string, mixed> $extra Extra keys (e.g. showMine)
     * @return array<string, mixed>
     */
    public function toolbar(array $state, string $base, array $extra = []): array
    {
        return array_merge([
            'base'     => $base,
            'sort'     => $state['sort'],
            'sorts'    => ReviewService::SORT_OPTIONS,
            'perPage'  => $state['perPage'],
            'perPages' => ReviewService::PER_PAGE_OPTIONS,
            'q'        => $state['q'],
            'rating'   => $state['rating'],
            'edited'   => $state['edited'],
            'mine'     => $state['mine'],
            'showMine' => false,
            'bookId'   => (int) ($state['book_id'] ?? 0),
            'userId'   => (int) ($state['user_id'] ?? 0),
        ], $extra);
    }

    /**
     * The pagination payload of a review list: the pager line (total
     * and pages), the current window, the per-page options and the
     * query-string params the pager links must preserve (sort, search
     * term, filters - the page number is replaced per link).
     *
     * @param array<string, mixed> $state  Normalized list state
     * @param array<string, mixed> $result A paginate() result
     * @return array<string, mixed>
     */
    public function pagination(array $state, array $result, string $base): array
    {
        return [
            'base'    => $base,
            'params'  => $this->preservedParams($state),
            'page'    => (int) $result['page'],
            'pages'   => (int) $result['pages'],
            'total'   => (int) $result['total'],
            'perPage' => (int) $result['perPage'],
            'perPages'=> ReviewService::PER_PAGE_OPTIONS,
        ];
    }

    /**
     * The query-string params that survive a page change: sort, the
     * search term, the rating filter and the edited / mine toggles.
     * The page number is deliberately absent - the pager links
     * replace it per link.
     *
     * @param array<string, mixed> $state Normalized list state
     * @return array<string, mixed>
     */
    private function preservedParams(array $state): array
    {
        $params = [
            'sort'   => $state['sort'],
            'q'      => $state['q'],
            'rating' => $state['rating'] > 0 ? (string) $state['rating'] : null,
        ];

        if (!empty($state['edited'])) {
            $params['edited'] = '1';
        }

        if (!empty($state['mine'])) {
            $params['mine'] = '1';
        }

        return array_filter($params, static fn ($value): bool => $value !== null && $value !== '');
    }
}
