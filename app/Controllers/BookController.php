<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Core\View;
use BookSphere\App\Services\BookService;
use BookSphere\App\Services\RecommendationService;
use BookSphere\App\Services\ReviewService;

/**
 * BookController
 *
 * The book module: catalogue browsing for every signed-in user,
 * plus admin-only CRUD over the catalogue.
 *
 *     - index     -> the browse page (search, filters, sort, pages)
 *     - searchJson-> JSON endpoint behind the real-time search
 *     - create    -> the "add book" form
 *     - store     -> validate + save a new book (with cover upload)
 *     - show      -> the detail page of one book
 *     - edit      -> the "edit book" form
 *     - update    -> validate + save the changes
 *     - destroy   -> soft delete a book
 *
 * Route protection: the browse routes (/books, /books/search) are
 * open to every signed-in user; every CRUD action stays behind
 * AdminMiddleware in the route table, so only admins reach them.
 *
 * The controller stays thin: it collects request data, asks the
 * BookService to sanitize, query and persist, and renders a view
 * or redirects with a flash message.
 *
 * Phase 6.3: the RecommendationService is injected so the book
 * detail page can feed the "recently viewed" signal of the
 * personalized recommendations - one line, no duplication.
 *
 * Phase 7.2: the ReviewService is injected so the detail page can
 * render the Reviews & Ratings section (write form, the user's own
 * review status, the approved list). The service is optional: a
 * controller without it (some tests) renders the page without the
 * section, and the route wiring always provides it.
 */
final class BookController extends Controller
{
    public function __construct(
        private readonly BookService $service,
        private readonly ?RecommendationService $recommendations = null,
        private readonly ?ReviewService $reviews = null,
    ) {}

    /**
     * The browse page: search, filters, sorting, pagination and
     * the grid/table view toggle over the whole catalogue.
     *
     * Query parameters (all optional):
     *
     *     /books?q=harry                free-text search
     *     /books?category_id=3          category filter
     *     /books?author_id=5            author filter
     *     /books?publisher=Harper       publisher filter
     *     /books?language=en            language filter
     *     /books?year_from=1990&year_to=2010   publication-year range
     *     /books?status=published       status filter
     *     /books?min_rating=4           minimum rating filter
     *     /books?sort=rating_desc       sort preset
     *     /books?per_page=20            page size
     *     /books?page=3                 page number
     */
    public function index(Request $request, array $params = []): void
    {
        $this->view('books.index', [
            'title'  => 'Browse Books',
            'active' => 'books',
        ] + $this->catalogue($this->rawFilters($request)));
    }

    /**
     * The real-time search endpoint.
     *
     * The browse page's JavaScript debounces the search box and
     * fetches this route with the same query string as the page
     * itself. It answers with JSON: the freshly rendered results
     * HTML (reusing the exact partial the full page shows, so the
     * two can never drift apart) plus the numbers the UI needs.
     *
     * Because the endpoint renders the same pipeline as index(),
     * a request without JavaScript simply submits the form and
     * gets the full page - the live search is pure enhancement.
     */
    public function searchJson(Request $request, array $params = []): void
    {
        $data = $this->catalogue($this->rawFilters($request));

        Response::json([
            'html'    => View::fragment('books.partials._results', $data),
            'total'   => $data['result']['total'],
            'page'    => $data['result']['page'],
            'pages'   => $data['result']['pages'],
            'perPage' => $data['result']['perPage'],
            'query'   => $data['filters']['q'],
        ]);
    }

    public function create(Request $request, array $params = []): void
    {
        $this->view('books.create', $this->formData('Add Book', $this->formValues($request, null), []));
    }

    public function store(Request $request, array $params = []): void
    {
        $data   = $this->formValues($request, null);
        $cover  = $request->file('cover');
        $errors = $this->service->errorsFor($data, $cover);

        if ($errors !== []) {
            $this->view('books.create', $this->formData('Add Book', $data, $errors));

            return;
        }

        $id = $this->service->store($data, $cover);

        // A new book changes what every user may be recommended
        // (popularity, trending, recent, category and author shelves
        // all read the catalogue), so every cached shelf is dropped.
        $this->recommendations?->flushPersonalization();

        session()->flash('success', 'The book was added to the catalogue.');
        Response::redirect('/books/' . $id);
    }

    public function show(Request $request, array $params = []): void
    {
        $book = $this->findOrFail($request, $params);

        // Feed the "recently viewed" signal of the personalized
        // recommendations (Phase 6.3). A view is not a rating or a
        // wishlist change, so it never invalidates the user's cache.
        if ($this->recommendations !== null && ($userId = auth()?->id()) !== null) {
            $this->recommendations->recordBookView($userId, (int) $book['id']);
        }

        // Phase 7.2: the Reviews & Ratings section. Three reads -
        // the approved reviews, the denormalized rating summary and
        // the signed-in user's own review (the "Write Review" vs
        // "already reviewed" decision). All SQL stays inside the
        // Reviews module; the controller only asks.
        $reviews = [];
        $stats   = ['average' => 0.0, 'count' => 0];
        $mine    = null;

        if ($this->reviews !== null) {
            $bookId  = (int) $book['id'];
            $reviews = $this->reviews->reviewsForBook($bookId);
            $stats   = $this->reviews->statsForBook($bookId);

            if (($userId = auth()?->id()) !== null) {
                $mine = $this->reviews->userReview($userId, $bookId);
            }
        }

        $this->view('books.show', [
            'title'       => $book['title'],
            'active'      => 'books',
            'book'        => $book,
            'statuses'    => BookService::STATUSES,
            'isAdmin'     => auth_is_admin(),
            'reviews'     => $reviews,
            'reviewStats' => $stats,
            'myReview'    => $mine,
            'canManage'   => auth_is_admin() || $mine !== null,
            'reviewSection' => $this->reviews !== null,
        ]);
    }

    public function edit(Request $request, array $params = []): void
    {
        $book = $this->findOrFail($request, $params);

        $this->view('books.edit', $this->formData('Edit Book', $this->formValues($request, $book), [], $book));
    }

    public function update(Request $request, array $params = []): void
    {
        $book = $this->findOrFail($request, $params);
        $id   = (int) $book['id'];

        $data   = $this->formValues($request, $book);
        $cover  = $request->file('cover');
        $errors = $this->service->errorsFor($data, $cover, $id);

        if ($errors !== []) {
            $this->view('books.edit', $this->formData('Edit Book', $data, $errors, $book));

            return;
        }

        $this->service->update($id, $data, $cover);

        // An updated book (title, authors, categories, status) can
        // change the signals behind every user's shelf, so the
        // per-user caches are flushed like on a create.
        $this->recommendations?->flushPersonalization();

        session()->flash('success', 'The book was updated.');
        Response::redirect('/books/' . $id);
    }

    public function destroy(Request $request, array $params = []): void
    {
        $id = (int) $params['id'];

        if (!$this->service->softDelete($id)) {
            Response::error(404, 'Book not found.');
        }

        // A removed book disappears from every shelf of every user
        // immediately - the cached shelves are flushed like on any
        // other catalogue write.
        $this->recommendations?->flushPersonalization();

        session()->flash('success', 'The book was deleted.');
        Response::redirect('/books');
    }

    /**
     * The form values for the book form, from either source:
     *
     *     - $book === null  -> a NEW book: the request input (which
     *       is empty on the GET create page, so the result is the
     *       form's default values)
     *     - $book !== null  -> an EXISTING book: prefilled from the
     *       row, so the edit form shows what the database has
     *
     * Both shapes use the same twelve keys, so the shared form
     * partial only ever reads one array. The checkbox groups arrive
     * as arrays and pass through Request::input() untouched (only
     * strings get trimmed).
     *
     * @return array<string, mixed>
     */
    private function formValues(Request $request, ?array $book): array
    {
        if ($book !== null) {
            return [
                'title'          => (string) ($book['title'] ?? ''),
                'subtitle'       => (string) ($book['subtitle'] ?? ''),
                'isbn'           => (string) ($book['isbn'] ?? ''),
                'description'    => (string) ($book['description'] ?? ''),
                'publisher'      => (string) ($book['publisher'] ?? ''),
                'published_year' => (string) ($book['published_year'] ?? ''),
                'language'       => (string) ($book['language'] ?? 'en'),
                'page_count'     => (string) ($book['page_count'] ?? ''),
                'status'         => (string) ($book['status'] ?? 'draft'),
                'author_ids'     => array_map(fn (array $author): int => (int) $author['id'], $book['authors'] ?? []),
                'category_ids'   => array_map(fn (array $category): int => (int) $category['id'], $book['categories'] ?? []),
                // A book loaded from the database never has the
                // "remove cover" flag set; only a fresh submit does.
                'remove_cover'   => false,
            ];
        }

        return [
            'title'          => (string) $request->input('title'),
            'subtitle'       => (string) $request->input('subtitle'),
            'isbn'           => (string) $request->input('isbn'),
            'description'    => (string) $request->input('description'),
            'publisher'      => (string) $request->input('publisher'),
            'published_year' => (string) $request->input('published_year'),
            'language'       => (string) $request->input('language', 'en'),
            'page_count'     => (string) $request->input('page_count'),
            'status'         => (string) $request->input('status', 'draft'),
            'author_ids'     => $request->input('author_ids', []),
            'category_ids'   => $request->input('category_ids', []),
            // Hidden field sent by the cover upload card when the
            // user clicks "Remove cover" (checkbox-style flag).
            'remove_cover'   => $request->input('remove_cover') === '1',
        ];
    }

    /**
     * The shared view payload of the four book-form actions
     * (create / store / edit / update). One method, four callers,
     * so the form data can never drift between them.
     *
     * @param array<string, mixed> $old    The values to display
     * @param array<string, mixed> $errors Field -> error messages
     * @param array<string, mixed>|null $book The row being edited (null on create)
     * @return array<string, mixed>
     */
    private function formData(string $title, array $old, array $errors, ?array $book = null): array
    {
        return [
            'title'      => $title,
            'active'     => 'books',
            'book'       => $book,
            'authors'    => $this->service->authors(),
            'categories' => $this->service->categories(),
            'statuses'   => BookService::STATUSES,
            'languages'  => BookService::LANGUAGES,
            'old'        => $old,
            'errors'     => $errors,
        ];
    }

    /**
     * Find the book behind a {id} route parameter, or answer 404.
     *
     * Response::error() terminates the request, so after this call
     * the returned row is guaranteed to exist.
     *
     * @return array<string, mixed> The book row with relations
     */
    private function findOrFail(Request $request, array $params): array
    {
        $book = $this->service->find((int) ($params['id'] ?? 0));

        if ($book === null) {
            Response::error(404, 'Book not found.');
        }

        return $book;
    }

    /**
     * Collect every browse query parameter from the request.
     *
     * @return array<string, mixed> Raw values, sanitized later by
     *                              BookService::combineFilters()
     */
    private function rawFilters(Request $request): array
    {
        $raw = [];

        foreach ([
            'q', 'status', 'category_id', 'author_id', 'publisher',
            'language', 'year_from', 'year_to', 'min_rating',
            'sort', 'per_page', 'page',
        ] as $key) {
            $raw[$key] = $request->input($key);
        }

        return $raw;
    }

    /**
     * Build the shared data set behind the browse page AND the
     * live-search endpoint: normalized filters, the paginated
     * result, and every dropdown source the toolbar renders.
     *
     * Both views consume the same array, so a search typed in the
     * box returns exactly what the page would have shown.
     *
     * @param array<string, mixed> $raw Raw query-string values
     * @return array<string, mixed> View data (result, filters, options, ...)
     */
    private function catalogue(array $raw): array
    {
        $filters = $this->service->combineFilters($raw);

        return [
            'result'    => $this->service->paginate($filters),
            'filters'   => $filters,
            'options'   => $this->service->filterOptions(),
            'sorts'     => BookService::SORTS,
            'pageSizes' => BookService::PAGE_SIZES,
            'ratings'   => BookService::RATING_FILTERS,
            'isAdmin'   => auth_is_admin(),
        ];
    }
}
