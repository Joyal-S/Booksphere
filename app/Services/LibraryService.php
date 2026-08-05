<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Core\Logger;
use BookSphere\App\DTO\LibraryItemDTO;
use BookSphere\App\Exceptions\LibraryException;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\UserLibrary;
use BookSphere\App\Requests\StoreLibraryRequest;
use BookSphere\App\Requests\UpdateLibraryRequest;

/**
 * LibraryService
 *
 * The business logic of the Wishlist & Personal Reading Library
 * module (Phase 8.1). Controllers stay thin: they translate the
 * request into a LibraryItemDTO, ask the policy for permission, and
 * hand the DTO to this service. Every DECISION lives here:
 *
 *     - validation (the field rules of StoreLibraryRequest /
 *       UpdateLibraryRequest, plus the service-level status and
 *       progress rule checks - an invalid status or a progress
 *       outside 0-100 is rejected here too, defence in depth)
 *     - the "book must exist" rule (LibraryException::bookNotFound)
 *     - duplicate prevention - one record per user per book
 *       (LibraryException::duplicateEntry, backed by the UNIQUE
 *       (user_id, book_id) index as the last line of defence)
 *     - the status LIFECYCLE:
 *         * changing status refreshes the updated_at stamp (the
 *           repository does that on every partial update)
 *         * becoming 'currently_reading' sets started_reading_at
 *           when it is empty
 *         * becoming 'finished' forces progress = 100 and stamps
 *           finished_reading_at
 *         * progress reaching 100 auto-finishes the record (progress
 *           and status stay consistent by design)
 *     - favourites are independent of the status: a finished book
 *       may also be a favourite, and toggling one never touches the
 *       other
 *     - exception handling: every failed rule raises a
 *       LibraryException with a meaningful message - SQL errors never
 *       leak to the caller
 *     - logging: every write is logged with the record id, the user
 *       id and the book id
 *     - the recommendation hook: a library change alters the user's
 *       personalization signals, so the cached recommendation shelf
 *       is invalidated (the same hook Phase 6.3 reserved for signal
 *       changes)
 *
 * Phase 8.1 also prepares the RECOMMENDATION hooks of Phase 8.5 by
 * exposing reusable reads - favoriteBooks(), readingHistory(),
 * completedBooks(), preferredGenres() - WITHOUT touching
 * RecommendationService itself. The engine module stays untouched.
 *
 * Phase 8.3 adds the library DASHBOARD reads - continueReading(),
 * libraryDashboard(), filterLibrary(), viewPreference(), the s/sort
 * and view allowlists - the backend the premium "My Library" page
 * renders (the presentation keeps living in the Views).
 *
 * Not implemented here (later phases): reading goals, challenges and
 * the full analytics module (Phase 8.4+).
 *
 * Dependencies:
 *     - UserLibrary model (facade) for the user_library table.
 *     - Book model (facade) for existence checks.
 *     - Logger (optional, defaults to the application log) for the
 *       write audit trail.
 *     - RecommendationService (optional) for the per-user cache
 *       invalidation hook.
 *
 * How it fits inside MVC:
 *     Controller -> LibraryService (rules) -> UserLibrary/Book models
 *     -> LibraryRepository/BookRepository (SQL) -> PDO -> SQLite.
 */
final class LibraryService
{
    /** The five library shelves (array keys are the stored values). */
    public const STATUSES = [
        'want_to_read'      => 'Want to read',
        'currently_reading' => 'Currently reading',
        'finished'          => 'Finished',
        'on_hold'           => 'On hold',
        'dropped'           => 'Dropped',
    ];

    /** The reading progress bounds (0-100). */
    public const PROGRESS_MIN = 0;
    public const PROGRESS_MAX = 100;

    /** The dashboard grid sorts (Phase 8.3): key -> display label. The
     *  SQL fragments live in LibraryRepository::SORTS - the service
     *  owns what the user sees, the repository owns what SQLite runs,
     *  and the two key lists must stay in step (a key unknown to
     *  either side falls back to the default sort, never to an error). */
    public const SORTS = [
        'newest_added'     => 'Newest Added',
        'oldest_added'     => 'Oldest Added',
        'recently_updated' => 'Recently Updated',
        'title_asc'        => 'Title (A-Z)',
        'title_desc'       => 'Title (Z-A)',
        'highest_rated'    => 'Highest Rated',
        'lowest_rated'     => 'Lowest Rated',
        'progress'         => 'Most Progress',
        'most_reviewed'    => 'Most Reviewed',
        'most_recommended' => 'Most Recommended',
    ];

    /** The dashboard grid views (Phase 8.3) - the closed, CHECK-
     *  constrained set of the user_preferences table. */
    public const VIEWS = ['grid', 'list'];

    /** The dashboard grid chunk sizes: 12 cards per grid page, 20
     *  rows per list page (rows are denser). */
    public const PER_PAGE_GRID = 12;
    public const PER_PAGE_LIST = 20;

    /** The dashboard defaults when a user has no preferences row yet. */
    public const DEFAULT_SORT = 'newest_added';
    public const DEFAULT_VIEW = 'grid';

    private readonly Logger $logger;

    public function __construct(
        private readonly UserLibrary $library,
        private readonly Book $books,
        private readonly ?RecommendationService $recommendations = null,
        ?Logger $logger = null,
    ) {
        $this->logger = $logger ?? new Logger(root_path('storage/logs/application.log'));
    }

    // --- Validation ----------------------------------------------------

    /**
     * Validate a submitted "add to library" form (book_id required,
     * status in the five-shelf enum, progress 0-100, favourite a
     * boolean). The pure field rules live in StoreLibraryRequest;
     * this method is the service-level entry point so the controller
     * never touches the validator directly.
     *
     * @param array<string, mixed> $data The submitted form values
     * @return array<string, array<int, string>> Field -> error messages
     */
    public function errorsFor(array $data): array
    {
        return StoreLibraryRequest::validate($data)->errors();
    }

    /**
     * Validate a "change my library entry" form (status / progress /
     * favourite - each optional, each bound-checked).
     *
     * @param array<string, mixed> $data The submitted form values
     * @return array<string, array<int, string>> Field -> error messages
     */
    public function updateErrorsFor(array $data): array
    {
        return UpdateLibraryRequest::validate($data)->errors();
    }

    // --- Reads ---------------------------------------------------------

    /**
     * The book behind a record must exist (and not be soft-deleted).
     *
     * @return array<string, mixed>|null The book row, or null
     */
    public function bookExists(int $bookId): bool
    {
        return $this->books->findById($bookId) !== null;
    }

    /**
     * Find a single library record by id.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->library->find($id);
    }

    /**
     * The full library of one user, newest first (the index shelf).
     *
     * @return array<int, array<string, mixed>>
     */
    public function userLibrary(int $userId, int $limit = 50): array
    {
        return $this->library->findByUser($userId, $limit);
    }

    /**
     * The user's "want to read" shelf (the modern wishlist).
     *
     * @return array<int, array<string, mixed>>
     */
    public function wishlist(int $userId, int $limit = 50): array
    {
        return $this->library->wishlist($userId, $limit);
    }

    /**
     * The user's "currently reading" shelf.
     *
     * @return array<int, array<string, mixed>>
     */
    public function currentlyReading(int $userId, int $limit = 50): array
    {
        return $this->library->currentlyReading($userId, $limit);
    }

    /**
     * The user's finished shelf.
     *
     * @return array<int, array<string, mixed>>
     */
    public function finished(int $userId, int $limit = 50): array
    {
        return $this->library->finished($userId, $limit);
    }

    /**
     * The user's library overview: total books, favourites, shelf
     * counts, average progress, books added this month.
     *
     * @return array<string, mixed>
     */
    public function libraryStatistics(int $userId): array
    {
        return $this->library->statistics($userId);
    }

    /**
     * The status counters of one user's library: every one of the
     * five shelves plus the favourites - every key guaranteed to be
     * present (a shelf without books reads 0), so the view never
     * has to guard a missing status. Backed by the same aggregate
     * query as libraryStatistics().
     *
     * @return array<string, int> Keys: total, favorites, want_to_read,
     *                            currently_reading, finished, on_hold,
     *                            dropped
     */
    public function statusCounts(int $userId): array
    {
        $stats    = $this->libraryStatistics($userId);
        $statuses = (array) ($stats['statuses'] ?? []);

        $counts = ['total' => (int) $stats['total']];

        foreach (array_keys(self::STATUSES) as $status) {
            $counts[$status] = (int) ($statuses[$status] ?? 0);
        }

        $counts['favorites'] = (int) $stats['favorites'];

        return $counts;
    }

    /**
     * The Phase 8.2 library search: the user's own records whose
     * book title, author name or category name matches the query.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchLibrary(int $userId, string $query, int $limit = 50): array
    {
        $query = trim($query);

        if ($query === '') {
            return $this->userLibrary($userId, $limit);
        }

        return $this->library->search($userId, $query, $limit);
    }

    /**
     * The library state of ONE book for ONE user (the book detail
     * page question): the user's record for the book - or null when
     * the book is not in their library yet. The view uses this to
     * choose between the "Add to library" panel and the "Update
     * library entry" panel.
     *
     * @return array<string, mixed>|null The library record with the
     *                                   book display columns, or null
     */
    public function bookDetailsState(int $userId, int $bookId): ?array
    {
        return $this->library->findByBook($userId, $bookId);
    }

    /**
     * One status shelf of the user - the generic bucket behind the
     * library sections (on_hold and dropped have no dedicated scope
     * method, so the sections all ask here; the well-known shelves
     * keep their named methods as the documented API).
     *
     * @return array<int, array<string, mixed>>
     * @throws LibraryException When the status is not one of the five
     */
    public function shelf(int $userId, string $status, int $limit = 50): array
    {
        $this->assertValidStatus($status);

        return $this->library->findByStatus($userId, $status, $limit);
    }

    // --- Phase 8.5: recommendation engine hooks -------------------------
    //
    // These four reads are the extension points the recommendation
    // engine will consume when library integration lands. They only
    // RE-EXPOSE existing repository reads under the names the future
    // integration needs (the same alias pattern the Reviews module
    // uses) or compose one small aggregation - RecommendationService
    // is not modified.

    /**
     * The user's favourite books (favorites is_favorite = 1) - the
     * "strongest affinity" signal for Phase 8.5 scoring.
     *
     * @return array<int, array<string, mixed>>
     */
    public function favoriteBooks(int $userId, int $limit = 50): array
    {
        return $this->library->favorites($userId, $limit);
    }

    /**
     * The user's finished books, most recently finished first - the
     * two-shelf family of the Phase 8.5 reading-history factor (see
     * completedBooks(); one query, two names).
     *
     * @return array<int, array<string, mixed>>
     */
    public function readingHistory(int $userId, int $limit = 50): array
    {
        return $this->library->finished($userId, $limit);
    }

    /**
     * The user's finished books - the same shelf as readingHistory()
     * under the name the engine's "completed books" factor expects.
     *
     * @return array<int, array<string, mixed>>
     */
    public function completedBooks(int $userId, int $limit = 50): array
    {
        return $this->library->finished($userId, $limit);
    }

    /**
     * The genres the user's library favours, most-kept first - the
     * library-derived category preference of Phase 8.5.
     *
     * @return array<int, array<string, mixed>>
     */
    public function preferredGenres(int $userId, int $limit = 5): array
    {
        return $this->library->preferredGenres($userId, $limit);
    }

    // --- Phase 8.3: library dashboard reads ----------------------------
    //
    // The premium "My Library" dashboard composes its data through
    // these service methods. Everything is a READ: the dashboard
    // never writes through here (writes stay in the addBook /
    // updateStatus family), and every method falls back to a safe
    // default for an empty library instead of raising.

    /**
     * The "Continue Reading" resume shelf (Phase 8.3): the books the
     * user is currently reading, newest activity first. The Phase 8.2
     * dashboard read the same shelf through currentlyReading();
     * this is the dashboard's own name for it (both share one query).
     *
     * @return array<int, array<string, mixed>>
     */
    public function continueReading(int $userId, int $limit = self::PER_PAGE_GRID): array
    {
        return $this->library->continueReading($userId, $limit);
    }

    /**
     * The one-composed-call payload of the library dashboard (Phase
     * 8.3): the overview statistics, the reading summary and the
     * reading streak - plus the recommendation badge set, the book
     * ids the engine currently suggests to the user (Phase 6.4
     * serves the shelf; the dashboard badges matching cards). The
     * engine is OPTIONAL: without a wired RecommendationService the
     * badge set is simply empty (the badges disappear, nothing
     * breaks).
     *
     * @return array<string, mixed> Keys: statistics, summary, streak,
     *                              recommended (array<int, int>)
     */
    public function libraryDashboard(int $userId): array
    {
        $payload = $this->library->dashboard($userId);

        $payload['recommended'] = $this->recommendations !== null
            ? $this->recommendedFor($userId)
            : [];

        return $payload;
    }

    /**
     * The book ids the engine currently recommends to the user (the
     * Phase 8.3 "Recommended for you" badges), best effort: a cold
     * cache, a changed profile or an engine failure must never take
     * the dashboard down, so any error yields an empty set.
     *
     * @return array<int, int>
     */
    public function recommendedFor(int $userId): array
    {
        if ($this->recommendations === null) {
            return [];
        }

        try {
            $shelf = $this->recommendations->getPersonalizedRecommendations($userId, self::PER_PAGE_GRID);

            $ids = [];
            foreach ($shelf->items as $item) {
                $id = (int) ($item['id'] ?? $item['book_id'] ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }

            return array_values($ids);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The reading summary statistics of the dashboard (favourite
     * genre / author, average rating given, average progress).
     *
     * @return array<string, mixed>
     */
    public function readingSummary(int $userId): array
    {
        return $this->library->readingSummary($userId);
    }

    /**
     * The reading streak of the dashboard: the current consecutive-
     * day library-activity run (and the longest one, for later
     * phases) - a real count computed from the user's own records.
     *
     * @return array<string, int> Keys: current, longest
     */
    public function readingStreak(int $userId): array
    {
        return $this->library->readingStreak($userId);
    }

    /**
     * The distinct category / author dropdown vocabulary of the
     * user's library (the filter bar options).
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function filterOptions(int $userId): array
    {
        return $this->library->filterOptions($userId);
    }

    // --- Phase 8.4: collections, recent activity ----------------------
    //
    // The Smart Collections reads of the Phase 8.4 extension: the
    // per-collection occupancy numbers (count / average rating / last
    // updated) behind the collections rail, and the recently-added /
    // recently-updated strips of the dashboard and profile. All of
    // them are thin reads over the repository - the business value is
    // the single, composed surface they offer the views.

    /**
     * The collection statistics of the user's library: for "all", the
     * five status shelves and "favorites" the book count, the mean
     * book rating and the last activity timestamp - one read the
     * collections rail paints its numbers from.
     *
     * @return array<string, array<string, mixed>>
     */
    public function collectionStatistics(int $userId): array
    {
        return $this->library->collectionStatistics($userId);
    }

    /**
     * The user's most recently added books, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentlyAdded(int $userId, int $limit = self::PER_PAGE_GRID): array
    {
        return $this->library->recentlyAdded($userId, $limit);
    }

    /**
     * The user's most recently updated books, most recent first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentlyUpdated(int $userId, int $limit = self::PER_PAGE_GRID): array
    {
        return $this->library->recentlyUpdated($userId, $limit);
    }

    /**
     * One page of the library dashboard grid (Phase 8.3): the
     * combined filter + sort + paginate read. The $filters keys are
     * normalized here (an unknown status is dropped, an id is cast to
     * an int, the query trimmed), and an unknown $sort falls back to
     * the dashboard default - a tampered request can never produce an
     * error, only the default view.
     *
     * @param array<string, mixed> $filters Recognized keys: q, status,
     *                                      category, author, rating,
     *                                      favorite, recently_added,
     *                                      recently_updated
     * @return array<string, mixed> The paginate() payload
     */
    public function filterLibrary(int $userId, array $filters, string $sort = self::DEFAULT_SORT, int $page = 1, int $perPage = self::PER_PAGE_GRID): array
    {
        $normalized = [];

        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $normalized['q'] = $query;
        }

        $status = (string) ($filters['status'] ?? '');
        if (array_key_exists($status, self::STATUSES)) {
            $normalized['status'] = $status;
        }

        $category = (int) ($filters['category'] ?? 0);
        if ($category > 0) {
            $normalized['category'] = $category;
        }

        $author = (int) ($filters['author'] ?? 0);
        if ($author > 0) {
            $normalized['author'] = $author;
        }

        $rating = (int) ($filters['rating'] ?? 0);
        if ($rating >= 1 && $rating <= 5) {
            $normalized['rating'] = $rating;
        }

        foreach (['favorite', 'recently_added', 'recently_updated'] as $flag) {
            if (!empty($filters[$flag])) {
                $normalized[$flag] = 1;
            }
        }

        return $this->library->paginate(
            $userId,
            $normalized,
            $this->validSort($sort),
            max(1, $page),
            max(1, min(50, $perPage)),
            $this->recommendedForSort($userId, $sort),
        );
    }

    /**
     * The recommendation book-id set a 'most_recommended' page needs,
     * best effort: the engine's current suggestion ids for the user -
     * or an empty set (the sort then degrades to its static fallback)
     * when the engine is optional, cold or failing. Any other sort
     * simply gets an empty set.
     *
     * @param string $sort The validated sort id
     * @return array<int, int>
     */
    private function recommendedForSort(int $userId, string $sort): array
    {
        return $sort === 'most_recommended' ? $this->recommendedFor($userId) : [];
    }

    /**
     * The dashboard's persistent presentation preferences (Phase
     * 8.3): read the user's stored sort and view, apply any changes
     * this call carries (each validated against its allowlist - an
     * unknown value is ignored, not stored), persist, and return the
     * merged result. The return value is the source of truth for the
     * request: every caller renders with the values it got back.
     *
     * @param string|null $sort The sort id to store (or null to keep)
     * @param string|null $view The view id to store (or null to keep)
     * @return array<string, string> Keys: sort, view
     */
    public function viewPreference(int $userId, ?string $sort = null, ?string $view = null): array
    {
        $storedSort = $this->library->preference($userId, 'library_sort', self::DEFAULT_SORT);
        $storedView = $this->library->preference($userId, 'library_view', self::DEFAULT_VIEW);

        // Only a KNOWN value is applied - an unknown one is ignored
        // (the stored value survives, nothing is persisted), so a
        // tampered request can never reset a user's own choice.
        $sort = ($sort !== null && array_key_exists($sort, self::SORTS)) ? $sort : $storedSort;
        $view = in_array((string) $view, self::VIEWS, true) ? (string) $view : $storedView;

        $changes = [];

        if ($sort !== $storedSort) {
            $changes['library_sort'] = $sort;
        }

        if ($view !== $storedView) {
            $changes['library_view'] = $view;
        }

        if ($changes !== []) {
            $this->library->savePreferences($userId, $changes);

            // The one library write outside the record CRUD family:
            // keep the same audit trail (user + what changed), so a
            // preference change is traceable like any other write.
            $this->logger->info('library.preference_changed', [
                'user_id' => $userId,
                'sort'    => $sort,
                'view'    => (string) $view,
            ]);
        }

        return ['sort' => $sort, 'view' => $view];
    }

    /**
     * A sort id, or the dashboard default when it is unknown - the
     * allowlist gate the controller and filterLibrary() share.
     */
    private function validSort(?string $sort): string
    {
        return array_key_exists((string) $sort, self::SORTS) ? (string) $sort : self::DEFAULT_SORT;
    }

    // --- Writes ---------------------------------------------------------

    /**
     * Add a book to the user's library.
     *
     * Business rules enforced here:
     *     - the book must exist (and not be soft-deleted)
     *     - the book must not already be in the library
     *     - the status must be one of the five shelves
     *     - the initial progress must be 0-100
     *     - the lifecycle timestamps: starting to read immediately
     *       stamps started_reading_at; adding as finished forces
     *       progress 100 and stamps finished_reading_at; a progress
     *       of 100 auto-finishes the record
     *
     * After the insert the user's cached recommendation shelf is
     * invalidated and the event is logged.
     *
     * @return int The id of the new library record
     * @throws LibraryException When the book is missing, the book is a
     *                          duplicate, the status is invalid or the
     *                          progress is out of range
     */
    public function addBook(LibraryItemDTO $dto): int
    {
        $this->assertBookExists($dto->bookId);
        $this->assertNotDuplicate($dto->userId, $dto->bookId);

        $progress = $dto->progress ?? self::PROGRESS_MIN;
        $status   = $dto->status ?? 'want_to_read';

        $this->assertValidStatus($status);
        $this->assertValidProgress($progress);

        $started  = null;
        $finished = null;

        // The lifecycle on a fresh record: starting to read stamps
        // the start date; a finished book forces 100% and its date.
        if ($status === 'currently_reading') {
            $started = $this->now();
        }

        if ($status === 'finished' || $progress === self::PROGRESS_MAX) {
            $status   = 'finished';
            $progress = self::PROGRESS_MAX;
            $finished = $this->now();
        }

        $id = $this->library->create([
            'user_id'             => $dto->userId,
            'book_id'             => $dto->bookId,
            'library_status'      => $status,
            'is_favorite'         => $dto->isFavorite ? 1 : 0,
            'progress_percentage' => $progress,
            'started_reading_at'  => $started,
            'finished_reading_at' => $finished,
        ]);

        $this->afterWrite($dto->userId, 'library.created', $dto->bookId, $id);

        return $id;
    }

    /**
     * Move a book to a new shelf.
     *
     * The status change is where the lifecycle really lives:
     *     - becoming 'currently_reading' stamps started_reading_at
     *       when it is still empty
     *     - becoming 'finished' forces progress to 100 and stamps
     *       finished_reading_at
     *
     * Note: leaving the finished shelf keeps the finished timestamp
     * as history (the brief only ever SETS these stamps; the future
     * reading-history feature will read them as a record).
     *
     * @throws LibraryException When the record is missing or the status
     *                          is invalid
     */
    public function updateStatus(int $userId, int $bookId, string $status): bool
    {
        $record = $this->requireRecord($userId, $bookId);

        $this->assertValidStatus($status);

        $changes = ['library_status' => $status];

        // The lifecycle on an existing record: moving to
        // 'currently_reading' stamps the start date only when it is
        // still empty; moving to 'finished' forces 100% and stamps
        // the finish date. Leaving the finished shelf keeps the
        // finished timestamp as history (the brief only ever SETS
        // these stamps; the future reading-history feature reads
        // them as a record).
        if ($status === 'currently_reading' && empty($record['started_reading_at'])) {
            $changes['started_reading_at'] = $this->now();
        }

        if ($status === 'finished') {
            $changes['progress_percentage'] = self::PROGRESS_MAX;
            $changes['finished_reading_at'] = $this->now();
        }

        $updated = $this->library->update((int) $record['id'], $changes);

        if ($updated) {
            $this->afterWrite($userId, 'library.status_changed', $bookId, (int) $record['id']);
        }

        return $updated;
    }

    /**
     * Set the reading progress of a book (0-100).
     *
     * When progress reaches 100 the record is auto-finished: the
     * status flips to 'finished' and finished_reading_at is stamped
     * (progress and status can never disagree).
     *
     * @throws LibraryException When the record is missing or the progress
     *                          is out of range
     */
    public function updateProgress(int $userId, int $bookId, int $progress): bool
    {
        $record = $this->requireRecord($userId, $bookId);

        $this->assertValidProgress($progress);

        $changes = ['progress_percentage' => $progress];

        // Progress reaching 100 auto-finishes the record so progress
        // and status can never disagree; the finish date is stamped
        // only when it is still empty (a re-set of 100 keeps it).
        if ($progress === self::PROGRESS_MAX) {
            $changes['library_status'] = 'finished';

            if (empty($record['finished_reading_at'])) {
                $changes['finished_reading_at'] = $this->now();
            }
        }

        $updated = $this->library->update((int) $record['id'], $changes);

        if ($updated) {
            $this->afterWrite($userId, 'library.progress_updated', $bookId, (int) $record['id']);
        }

        return $updated;
    }

    /**
     * Toggle the favourite star of a library record. Favourites work
     * independently of the status, so a finished book can sit on the
     * favourites shelf too.
     *
     * @return bool The NEW favourite state (true = now a favourite)
     * @throws LibraryException When the record is missing
     */
    public function toggleFavorite(int $userId, int $bookId): bool
    {
        $record = $this->requireRecord($userId, $bookId);

        $favorite = (int) ($record['is_favorite'] ?? 0) === 1 ? 0 : 1;

        $this->library->update((int) $record['id'], ['is_favorite' => $favorite]);

        $this->afterWrite($userId, 'library.favorite_toggled', $bookId, (int) $record['id']);

        return $favorite === 1;
    }

    /**
     * Remove a book from the user's library (idempotent: removing a
     * record that does not exist is a silent no-op, which keeps the
     * destroy action safe for double-clicks).
     */
    public function removeBook(int $userId, int $bookId): bool
    {
        $record = $this->library->findByBook($userId, $bookId);

        if ($record === null) {
            return false;
        }

        if ($this->library->delete((int) $record['id'])) {
            $this->afterWrite($userId, 'library.deleted', $bookId, (int) $record['id']);

            return true;
        }

        return false;
    }

    // --- Phase 8.4: bulk actions ---------------------------------------

    /**
     * Move several of the user's library records to one shelf (the
     * "Move To" bulk action). The status must be one of the five
     * shelves; a record that does not belong to the user is silently
     * skipped by the repository guard.
     *
     * @param array<int|string> $ids Record ids
     * @return int The number of records actually moved
     * @throws LibraryException When the status is invalid
     */
    public function bulkStatus(int $userId, array $ids, string $status): int
    {
        $this->assertValidStatus($status);

        $affected = $this->library->bulkStatus($userId, $ids, $status);

        if ($affected > 0) {
            $this->afterBulkWrite($userId, 'library.bulk_status_changed', $affected);
        }

        return $affected;
    }

    /**
     * Mark or un-mark several of the user's books as favourites (the
     * "Favourite / Un-favourite" bulk action).
     *
     * @param array<int|string> $ids Record ids
     * @return int The number of records actually updated
     */
    public function bulkFavorite(int $userId, array $ids, bool $favorite): int
    {
        $affected = $this->library->bulkFavorite($userId, $ids, $favorite);

        if ($affected > 0) {
            $this->afterBulkWrite($userId, $favorite ? 'library.bulk_favorited' : 'library.bulk_unfavorited', $affected);
        }

        return $affected;
    }

    /**
     * Remove several of the user's library records (the destructive
     * "Remove" bulk action - the controller confirms it on the client
     * side before this is reached).
     *
     * @param array<int|string> $ids Record ids
     * @return int The number of records actually removed
     */
    public function bulkDelete(int $userId, array $ids): int
    {
        $affected = $this->library->bulkDelete($userId, $ids);

        if ($affected > 0) {
            $this->afterBulkWrite($userId, 'library.bulk_deleted', $affected);
        }

        return $affected;
    }

    // --- Internals ------------------------------------------------------

    /**
     * The post-write pipeline of a bulk write: invalidate the user's
     * cached recommendation shelf (any library signal change alters
     * their personal profile) and log the event with the affected
     * record count. The per-record audit trail of the single writes
     * does not apply here - one entry per bulk action is the honest
     * record.
     *
     * @param string $event 'library.bulk_status_changed' |
     *                      'library.bulk_favorited' |
     *                      'library.bulk_unfavorited' |
     *                      'library.bulk_deleted'
     */
    private function afterBulkWrite(int $userId, string $event, int $affected): void
    {
        if ($this->recommendations !== null) {
            $this->recommendations->invalidatePersonalization($userId);
        }

        $this->logger->info($event, [
            'user_id'  => $userId,
            'affected' => $affected,
        ]);
    }

    /**
     * A library operation must target an existing book.
     */
    private function assertBookExists(?int $bookId): void
    {
        if ($bookId === null || !$this->bookExists($bookId)) {
            throw LibraryException::bookNotFound((int) $bookId);
        }
    }

    /**
     * A book may appear only once in a user's library.
     */
    private function assertNotDuplicate(?int $userId, ?int $bookId): void
    {
        if ($userId !== null && $bookId !== null && $this->library->exists($userId, $bookId)) {
            throw LibraryException::duplicateEntry($userId, $bookId);
        }
    }

    /**
     * The status must be one of the five shelves - the service-side
     * gate (the request also validates it; the CHECK constraint is
     * the last line of defence).
     */
    private function assertValidStatus(string $status): void
    {
        if (!array_key_exists($status, self::STATUSES)) {
            throw LibraryException::invalidStatus($status);
        }
    }

    /**
     * The progress must stay inside 0-100.
     */
    private function assertValidProgress(int $progress): void
    {
        if ($progress < self::PROGRESS_MIN || $progress > self::PROGRESS_MAX) {
            throw LibraryException::invalidProgress($progress);
        }
    }

    /**
     * Fetch the user's record for a book or raise the not-found
     * exception (the owner check is the policy's job - this answers
     * "does the record exist" for the write methods).
     *
     * @return array<string, mixed>
     */
    private function requireRecord(int $userId, int $bookId): array
    {
        $record = $this->library->findByBook($userId, $bookId);

        if ($record === null) {
            throw LibraryException::recordNotFound($userId, $bookId);
        }

        return $record;
    }

    /**
     * The shared post-write pipeline of every library write:
     *
     *     1. invalidate the user's cached recommendation shelf
     *        (a library signal change alters their personal profile)
     *     2. log the event with the record id, the user id and the
     *        book id (the Logger stamps the timestamp)
     *
     * @param string $event 'library.created' | 'library.status_changed' |
     *                      'library.progress_updated' |
     *                      'library.favorite_toggled' | 'library.deleted'
     */
    private function afterWrite(int $userId, string $event, int $bookId, ?int $recordId = null): void
    {
        if ($this->recommendations !== null) {
            $this->recommendations->invalidatePersonalization($userId);
        }

        $this->logger->info($event, [
            'record_id' => $recordId,
            'user_id'   => $userId,
            'book_id'   => $bookId,
        ]);
    }

    /**
     * The current UTC timestamp in the database's stored format.
     */
    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}