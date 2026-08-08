<?php

declare(strict_types=1);

/**
 * routes/web.php
 *
 * The route table of the application: every URL the application
 * understands is registered here.
 *
 * $router is created in Application::run() before this file is
 * loaded. Each route maps an HTTP method + path to a controller
 * action, with optional middleware that runs first.
 *
 * Middleware rules used by this phase:
 *     - SecureHeadersMiddleware -> security headers on every route
 *     - CsrfMiddleware          -> rejects POSTs without a valid token
 *     - GuestMiddleware         -> guest-only pages (login/register)
 *     - AuthMiddleware          -> pages that require a login
 *     - AdminMiddleware         -> admin-only pages (role check)
 *
 * Route parameters: a path segment written as {name} captures the
 * matching part of the URL and passes it to the action as
 * $params['name']. Example: /hello/{name} matches /hello/Alice and
 * delivers $params['name'] = "Alice".
 */

use BookSphere\App\Controllers\AdminController;
use BookSphere\App\Controllers\AuthorController;
use BookSphere\App\Controllers\AuthController;
use BookSphere\App\Controllers\BookController;
use BookSphere\App\Controllers\CategoryController;
use BookSphere\App\Controllers\DashboardController;
use BookSphere\App\Controllers\GoogleBooksController;
use BookSphere\App\Controllers\LibraryController;
use BookSphere\App\Controllers\NotificationController;
use BookSphere\App\Controllers\PageController;
use BookSphere\App\Controllers\RecommendationController;
use BookSphere\App\Controllers\ReviewController;
use BookSphere\App\Controllers\SearchController;
use BookSphere\App\Controllers\SettingsController;
use BookSphere\App\Controllers\UserController;
use BookSphere\App\Core\Csrf;
use BookSphere\App\Core\Logger;
use BookSphere\App\Core\RateLimiter;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Mail\Mailer;
use BookSphere\App\Middleware\AdminMiddleware;
use BookSphere\App\Middleware\AuthMiddleware;
use BookSphere\App\Middleware\CsrfMiddleware;
use BookSphere\App\Middleware\GuestMiddleware;
use BookSphere\App\Middleware\SecureHeadersMiddleware;
use BookSphere\App\Models\Author;
use BookSphere\App\Models\AuthorFollow;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\Category;
use BookSphere\App\Models\EmailLog;
use BookSphere\App\Models\EmailPreference;
use BookSphere\App\Models\EmailQueue;
use BookSphere\App\Models\Notification;
use BookSphere\App\Models\Review;
use BookSphere\App\Models\User;
use BookSphere\App\Models\UserLibrary;
use BookSphere\App\Models\PasswordResetToken;
use BookSphere\App\Policies\FollowPolicy;
use BookSphere\App\Policies\LibraryPolicy;
use BookSphere\App\Policies\RecommendationPolicy;
use BookSphere\App\Policies\ReviewPolicy;
use BookSphere\App\Presenters\RecommendationDashboardPresenter;
use BookSphere\App\Presenters\ReviewListPresenter;
use BookSphere\App\Repositories\BookRepository;
use BookSphere\App\Repositories\RecommendationRepository;
use BookSphere\App\Repositories\SearchRepository;
use BookSphere\App\Builders\SearchQueryBuilder;
use BookSphere\App\Services\SearchProviderFactory;
use BookSphere\App\Services\SearchResultFormatter;
use BookSphere\App\Services\SearchHistoryService;
use BookSphere\App\Services\SearchService;
use BookSphere\App\Services\SearchSuggestionService;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\BookImportService;
use BookSphere\App\Services\BulkImportService;
use BookSphere\App\Services\BookService;
use BookSphere\App\Services\CacheManager;
use BookSphere\App\Services\CircuitBreaker;
use BookSphere\App\Services\EmailNotificationService;
use BookSphere\App\Services\FollowService;
use BookSphere\App\Services\GoogleBooksClient;
use BookSphere\App\Services\GoogleBooksProvider;
use BookSphere\App\Services\GoogleBooksService;
use BookSphere\App\Services\GoogleBooksSyncService;
use BookSphere\App\Services\LibraryService;
use BookSphere\App\Services\MediaService;
use BookSphere\App\Services\CoverDownloadService;
use BookSphere\App\Services\NotificationDispatcher;
use BookSphere\App\Services\NotificationFormatter;
use BookSphere\App\Services\NotificationService;
use BookSphere\App\Services\PersonalizationCache;
use BookSphere\App\Services\RecommendationFactory;
use BookSphere\App\Services\RecommendationMetrics;
use BookSphere\App\Services\RecommendationService;
use BookSphere\App\Services\ReviewService;
use BookSphere\App\Strategies\HighestRatedStrategy;
use BookSphere\App\Strategies\PopularBooksStrategy;
use BookSphere\App\Strategies\RecentlyAddedStrategy;
use BookSphere\App\Strategies\SameAuthorStrategy;
use BookSphere\App\Strategies\SameCategoryStrategy;
use BookSphere\App\Strategies\TrendingBooksStrategy;

// --- Shared services -------------------------------------------------
// One AuthService per request, registered globally so the auth()
// helpers and the views can reach the logged-in user.
$users = new User();
$auth  = new AuthService(session(), $users);
AuthService::setInstance($auth);

$csrf   = new Csrf(session());
$secure = new SecureHeadersMiddleware();

// --- Pages -----------------------------------------------------------

$authController = new AuthController($auth, $users, new PasswordResetToken());
$pageController = new PageController();

// --- Notifications (Phase 9.2 infrastructure + Phase 9.3 API) ----------
// The dispatcher is the module's single creation door (its formatter
// is the single source of the message templates). It is built HERE,
// before the services, because the follow, review, library and
// recommendation services all share the SAME instance for their event
// pings - an event can never notify twice because of duplicate
// dispatcher wiring.
//
// Phase 9.5: the email stack is wired into the SAME dispatcher as a
// purely additive channel. EmailNotificationService reads its settings
// from config/email.php (EMAIL_ENABLED=false by default), gates every
// recipient through their email preferences, applies the per-event
// dedupe key and delivers through the Mailer (log or SMTP transport) -
// and it NEVER throws, so an unconfigured or broken email setup can
// never affect the notification flow.
$notificationFormatter = new NotificationFormatter();
$emailService = new EmailNotificationService(
    new User(),
    new EmailPreference(),
    new EmailLog(),
    new EmailQueue(),
    new Mailer(),
);
$notificationDispatcher = new NotificationDispatcher(new Notification(), $notificationFormatter, null, $emailService);
$notificationService = new NotificationService(new Notification(), $notificationDispatcher);
$notificationController = new NotificationController($notificationService);

// --- Recommendations (Phase 6.2: six algorithms; Phase 6.3: hybrid
// personalization) ------------------------------------------------------
// One repository shared by every strategy (it delegates book reads to
// the Book module's repository, so no SQL is duplicated); each strategy
// receives it through its constructor (dependency injection). The
// controller gets the service (orchestrator) and the policy (fine
// authorization).
//
// Phase 6.3: the same service also carries the per-user hybrid shelf.
// Its PersonalizationCache (file-based, 30-minute TTL) is wired from
// config/recommendations.php, and BookController gets the service so
// the book detail page can feed the "recently viewed" signal.
// Phase 9.3: the service also receives the shared notification
// dispatcher, so a FRESH personalized shelf generation (a cache miss)
// leaves a "Your picks are ready" notification behind.
$recommendationRepository = new RecommendationRepository(new BookRepository());

// Phase 6.5: the cache instance is extracted so the metrics service
// and the recommendation service share ONE cache object - flushing
// from the admin page and invalidating from the engine touch the
// exact same files.
$personalizationCache = new PersonalizationCache(
    (string) config('recommendations.cache.directory', root_path('database/cache/recommendations')),
    (int) config('recommendations.cache.ttl_seconds', 1800),
    (bool) config('recommendations.cache.enabled', true),
);

$recommendationService = new RecommendationService(
    new RecommendationFactory(
        new PopularBooksStrategy($recommendationRepository),
        new HighestRatedStrategy($recommendationRepository),
        new TrendingBooksStrategy($recommendationRepository),
        new SameCategoryStrategy($recommendationRepository),
        new RecentlyAddedStrategy($recommendationRepository),
        new SameAuthorStrategy($recommendationRepository),
    ),
    $recommendationRepository,
    $personalizationCache,
    null,
    $notificationDispatcher,
);

// --- Reviews & Ratings (Phase 7.1 backend + Phase 7.2 CRUD) -------------
// ONE ReviewService instance is shared by the review controller AND
// the book controller (the detail page renders the Reviews section),
// so the book page and the review pages always see the same rules
// and the same recommendation-cache hook.
// Phase 9.3: it also gets the shared dispatcher + a User model, so a
// first "helpful" vote leaves a "Review appreciated" notification for
// the review's owner.
$reviewService = new ReviewService(new Review(), new Book(), $recommendationService, null, new User(), $notificationDispatcher);

// Phase 8.1 + 8.2: the personal library. ONE LibraryService instance
// is shared by the library controller, the book controller (the
// detail page's Add / Update Library panel) and the dashboard
// controller (the Continue Reading shelf); it receives the SAME
// RecommendationService as the review service, so every library
// write invalidates the user's cached recommendation shelf (the
// Phase 6.3 signal hook) without the engine itself changing.
// Phase 9.3: it also gets the shared dispatcher, so finishing a book
// leaves a "Library milestone" notification behind.
$libraryService = new LibraryService(new UserLibrary(), new Book(), $recommendationService, null, $notificationDispatcher);

// Phase 10.4: the cover downloader is created EARLY because it is
// shared by two consumers - the book service (admin cover removal
// deletes the cached FILE too) and the Google Books importer. Its
// config is the module's, so a disabled module means a passive
// service; the validation rules extend the media "covers" type with
// the downloader's own size/dimension caps.
$googleBooksConfig = (array) (config('google_books') ?? []);
$coverMediaConfig = array_replace(
    (array) config('media.covers', []),
    [
        'max_bytes'  => (int) ($googleBooksConfig['covers']['max_bytes'] ?? 5 * 1024 * 1024),
        'min_width'  => (int) ($googleBooksConfig['covers']['min_width'] ?? 50),
        'min_height' => (int) ($googleBooksConfig['covers']['min_height'] ?? 50),
        'max_width'  => (int) ($googleBooksConfig['covers']['max_source_dimension'] ?? 4000),
        'max_height' => (int) ($googleBooksConfig['covers']['max_source_dimension'] ?? 4000),
    ],
);
$coverService = new CoverDownloadService(new Book(), new MediaService($coverMediaConfig), $googleBooksConfig);

$bookController = new BookController(new BookService(new Book(), new Author(), new Category(), $coverService), $recommendationService, $reviewService, $libraryService);
$reviewListPresenter = new ReviewListPresenter($reviewService);
// Phase 7.7: the review write endpoints get the session-backed
// throttle (RateLimiter), the same wiring as the recommendation
// dashboard writes.
$reviewController = new ReviewController($reviewService, new ReviewPolicy(), $reviewListPresenter, new RateLimiter(session()));

// Phase 7.3: the dashboard needs the SAME ReviewService instance for
// its real Top Rated Books section (rating analytics come from the
// Reviews module - the dashboard only asks), so it is created here,
// after the shared service, never before it. Phase 8.2: the same
// pattern for the personal library - the Continue Reading shelf is
// read through the shared LibraryService, never duplicated.
// Phase 8.5: the dashboard's real recommendation shelves come from
// the SHARED RecommendationService (the personalized shelf, the
// trending shelf and the library-based "Because you read" section).
$dashboardController = new DashboardController($reviewService, $libraryService, $recommendationService);
$adminController = new AdminController(
    new RecommendationMetrics($recommendationRepository, $personalizationCache),
    $reviewService,
    // Phase 7.5: the fine per-action gate of the moderation actions
    // (defence in depth behind AdminMiddleware).
    new ReviewPolicy(),
);

// Phase 9.2: the Follow Authors module. The service and its policy
// are wired HERE, before the user controller (the profile's "Authors
// I follow" page needs them) and before the author controller (the
// Follow button). The dispatcher was already built above (the shared
// notification stack), so a follow always leaves its
// "author_followed" notification behind.
$followService = new FollowService(new AuthorFollow(), new Author(), $notificationDispatcher);
$followPolicy = new FollowPolicy();

// Phase 7.3: the profile page's "My rating activity" block comes
// from the same shared ReviewService, so the controller is wired
// here as well - after the service exists. Phase 8.4: the profile's
// "My Library" block uses the shared LibraryService too.
// Phase 8.5: the profile's "Reading Preferences & Recommendation
// Insights" block uses the SHARED RecommendationService (the same
// engine every page asks).
$userController = new UserController($auth, $users, $reviewService, $libraryService, $recommendationService, $followService, $followPolicy);

// Phase 7.6: the author and category pages are real pages now. They
// share the SAME ReviewService instance, so the statistics they show
// (average author rating, top rated books, recent community reviews)
// always match the reviews every other page reads.
// Phase 9.2: the follow service + policy were already wired above
// (they must exist before the user controller); the author controller
// gets that shared instance for its Follow button.
$authorController   = new AuthorController(new Author(), $reviewService, $followService, $followPolicy, new RateLimiter(session()));
$categoryController = new CategoryController(new Category(), $reviewService);

$recommendationController = new RecommendationController(
    $recommendationService,
    new RecommendationPolicy(),
    // Phase 6.4: the dashboard view-model. It REUSES the service and
    // repositories above - it only shapes their output for the
    // templates, so the engine stays untouched.
    new RecommendationDashboardPresenter(
        $recommendationService,
        $recommendationRepository,
        new BookRepository(),
        new Category(),
    ),
    // Phase 6.5: the write-endpoint throttle (session-backed).
    new RateLimiter(session()),
);

// Phase 8.1 + 8.2: Wishlist & Personal Reading Library. The
// LibraryService is shared (see above); the controller additionally
// gets the fine policy and the session-backed write throttle.
// Phase 8.5: the library dashboard's recommendation sections
// ("Because this is in your library", "People who saved this also
// liked", ...) come from the SHARED RecommendationService.
$libraryController = new LibraryController(
    $libraryService,
    new LibraryPolicy(),
    new RateLimiter(session()),
    $recommendationService,
);

// Phase 10.2: the Google Books provider search (admin only). The whole
// module is optional - with GOOGLE_BOOKS_ENABLED=false the service is a
// pure no-op (no request, no cache write, a friendly notice). The four
// pieces are composed here, exactly like every other module: client
// (HTTP + retries), provider (payload mapping), cache + circuit breaker
// (file-based, the Phase 10.1 strategy), service (orchestration). The
// shared Logger goes to the application log like every other module.
// ($googleBooksConfig was hoisted above for the Phase 10.4 cover service.)
$googleBooksService = new GoogleBooksService(
    new GoogleBooksClient($googleBooksConfig),
    new GoogleBooksProvider($googleBooksConfig),
    new CacheManager(
        (string) ($googleBooksConfig['cache']['directory'] ?? root_path('database/cache/google_books')),
        [
            CacheManager::NS_SEARCH => (int) ($googleBooksConfig['cache']['search_ttl_seconds'] ?? 900),
            CacheManager::NS_VOLUME => (int) ($googleBooksConfig['cache']['volume_ttl_seconds'] ?? 86400),
        ],
        (bool) ($googleBooksConfig['enabled'] ?? false),
    ),
    new CircuitBreaker(
        (string) ($googleBooksConfig['cache']['directory'] ?? root_path('database/cache/google_books')),
        (array) ($googleBooksConfig['cache']['circuit_breaker'] ?? []),
    ),
    new Logger(root_path('storage/logs/application.log')),
    $googleBooksConfig,
);

// Phase 10.3: the importer. It owns the local-catalogue writes of the
// module (dedupe + transactional insert) and is composed with the same
// shared config, so a disabled provider module leaves imports disabled
// too. The models are the thin facades every other module uses.
// Phase 10.4: the shared cover service rides along, so a successful
// import downloads + caches the cover right after the transaction.
// Phase 10.5: the SAME importer instance also feeds the bulk importer -
// one importer, one dedupe rule, whether a run imports 1 book or 200.
// Phase 10.6: the SAME importer instance feeds the synchronizer too -
// one provider metadata map, one field rule, whether it is written at
// import time or re-checked by a sync run.
$googleBooksImporter = new BookImportService(new Book(), new Author(), new Category(), $googleBooksConfig, $coverService);

$googleBooksSyncService = new GoogleBooksSyncService(
    $googleBooksService,
    $googleBooksImporter,
    new Book(),
    $coverService,
    new Logger(root_path('storage/logs/application.log')),
    $googleBooksConfig,
);

$googleBooksController = new GoogleBooksController(
    $googleBooksService,
    $googleBooksImporter,
    new BulkImportService(
        $googleBooksService,
        $googleBooksImporter,
        new Book(),
        new Logger(root_path('storage/logs/application.log')),
        $googleBooksConfig,
    ),
    $googleBooksSyncService,
);

// Phase 11.2: the global search module. One wired SearchService is
// shared by the page and the live endpoint (the same pipeline both
// answer), composed from the architecture's pieces exactly like every
// other module: builder + provider (resolved by the factory from
// config('search.provider')) + repository + formatter. The RateLimiter
// is the same session-backed throttle every write endpoint already
// uses, guarding the search bucket from config('search').
$searchConfig = (array) (config('search') ?? []);
$searchService = new SearchService(
    (new SearchProviderFactory($searchConfig))->create(),
    new SearchQueryBuilder($searchConfig),
    new SearchResultFormatter(),
    $searchConfig,
);

// Phase 11.4: the suggestion service shares the SAME provider and
// builder (one tokenizer, one WHERE vocabulary), so a type-ahead
// pool and a full search can never disagree about a term.
$suggestionService = new SearchSuggestionService(
    (new SearchProviderFactory($searchConfig))->create(),
    new SearchQueryBuilder($searchConfig),
    $searchConfig,
);

// Phase 11.5: the history service owns the search_history table
// (the module's one SQL layer, SearchRepository - the same
// repository every other search read uses). Its caps/TTL come from
// config('search.history'), so the operator tunes the storage
// without touching a class.
$historyService = new SearchHistoryService(new SearchRepository(), $searchConfig);

$searchController = new SearchController($searchService, $suggestionService, $historyService, new RateLimiter(session()));

// Home. The root of the app serves two audiences:
//     - guests      -> the public cover page (pages.landing inside the
//                      bare layouts.landing shell) - the project's
//                      marketing front door, with Log in / Get started
//     - signed-in   -> the personal dashboard (the long-standing home)
// The guest branch is decided here (not by AuthMiddleware) so the
// cover page is public while everything else keeps its protection.
$router->get('/', function (Request $request, array $params = []) use ($dashboardController): void {
    if (!auth_check()) {
        Response::view('pages.landing', [
            'title' => 'BookSphere — Discover, Review, Recommend',
        ], 200, 'layouts.landing');

        return;
    }

    $dashboardController->index($request);
}, [$secure]);

// Parameterized demo route: try /hello/Alice in your browser.
// The {name} placeholder captures whatever single segment appears
// after "/hello/" and passes it to the action below.
$router->get('/hello/{name}', function (Request $request, array $params): void {
    Response::view('foundation.hello', [
        'name' => $params['name'],
    ]);
}, [$secure]);

// --- Authentication (guest only) -------------------------------------

$router->get('/register', [$authController, 'showRegister'], [$secure, new GuestMiddleware($auth)]);
$router->post('/register', [$authController, 'register'], [$secure, new GuestMiddleware($auth), new CsrfMiddleware($csrf)]);

$router->get('/login', [$authController, 'showLogin'], [$secure, new GuestMiddleware($auth)]);
$router->post('/login', [$authController, 'login'], [$secure, new GuestMiddleware($auth), new CsrfMiddleware($csrf)]);

$router->get('/forgot-password', [$authController, 'showForgotPassword'], [$secure, new GuestMiddleware($auth)]);
$router->post('/forgot-password', [$authController, 'forgotPassword'], [$secure, new GuestMiddleware($auth), new CsrfMiddleware($csrf)]);

// The reset link from the forgot step: /reset-password?token=...
// GET shows the form (or the invalid-token state), POST redeems the
// single-use token and replaces the password.
$router->get('/reset-password', [$authController, 'showResetPassword'], [$secure, new GuestMiddleware($auth)]);
$router->post('/reset-password', [$authController, 'resetPassword'], [$secure, new GuestMiddleware($auth), new CsrfMiddleware($csrf)]);

// Logout is a POST form so it is CSRF protected.
$router->post('/logout', [$authController, 'logout'], [$secure, new CsrfMiddleware($csrf)]);

// --- Account area (requires login) -----------------------------------

$router->get('/profile', [$userController, 'show'], [$secure, new AuthMiddleware($auth)]);
$router->get('/profile/edit', [$userController, 'showEdit'], [$secure, new AuthMiddleware($auth)]);
$router->post('/profile/edit', [$userController, 'edit'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->get('/change-password', [$userController, 'showChangePassword'], [$secure, new AuthMiddleware($auth)]);
$router->post('/change-password', [$userController, 'changePassword'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);

// --- Administration (admin role only) ---------------------------------

$router->get('/admin', [$adminController, 'index'], [$secure, new AdminMiddleware($auth)]);

// Phase 6.5: the recommendation engine monitoring page and its one
// write tool. Both stay behind AdminMiddleware; the flush is a POST
// so it carries CSRF protection like every other data change.
$router->get('/admin/recommendations', [$adminController, 'metrics'], [$secure, new AdminMiddleware($auth)]);
$router->post('/admin/recommendations/cache/flush', [$adminController, 'flushCache'], [$secure, new AdminMiddleware($auth), new CsrfMiddleware($csrf)]);

// Phase 10.2/10.3/10.5: the Google Books provider (admin only). The
// index shows the search page (renders results server-side for no-JS), the
// /search route answers the live AJAX endpoint with the results partial, and the
// /import POST takes one provider result into the local catalogue - identical
// structure to the browse module's /books, /books/search + the admin CRUD.
// Phase 10.5: /bulk-import takes the SELECTION (google_book_id[]) and streams
// the run's progress as Server-Sent Events for fetch callers, or flashes the
// summary + redirects for the no-JavaScript form.
// Phase 10.6: the three synchronization POSTs - /sync (one imported
// book), /sync-bulk (the selection) and /sync-all (every imported book) -
// refresh provider metadata with change detection; the fetch callers get
// the same SSE stream the bulk importer uses. All carry CSRF like every
// other data change.
$router->get('/admin/google-books', [$googleBooksController, 'index'], [$secure, new AdminMiddleware($auth)]);
$router->get('/admin/google-books/search', [$googleBooksController, 'searchJson'], [$secure, new AdminMiddleware($auth)]);
$router->post('/admin/google-books/import', [$googleBooksController, 'import'], [$secure, new AdminMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->post('/admin/google-books/bulk-import', [$googleBooksController, 'importBulk'], [$secure, new AdminMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->post('/admin/google-books/sync', [$googleBooksController, 'sync'], [$secure, new AdminMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->post('/admin/google-books/sync-bulk', [$googleBooksController, 'syncBulk'], [$secure, new AdminMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->post('/admin/google-books/sync-all', [$googleBooksController, 'syncAll'], [$secure, new AdminMiddleware($auth), new CsrfMiddleware($csrf)]);

// Phase 11.2: the global search. ONE route answers both consumers -
// a fetch() request (X-Requested-With: fetch) gets the live JSON
// results partial, a plain GET renders the full search page
// server-side. Behind the same signed-in stack as the browse module,
// with the module's own session-backed rate limit inside the
// controller.
$router->get('/search', [$searchController, 'index'], [$secure, new AuthMiddleware($auth)]);

// Phase 11.4: the type-ahead endpoint of the search boxes. A literal
// GET - the router's exact-match-first order keeps it clear of any
// future parameterized /search sub-route.
$router->get('/search/suggest', [$searchController, 'suggest'], [$secure, new AuthMiddleware($auth)]);

// Phase 11.5: the search-history writes. DELETE semantics via the
// _method spoof (the no-JS UI posts forms with a hidden _method =
// DELETE, the same idiom the notification center uses), with the
// exact-match-first literals (/search/history) ordered before the
// parameterized pattern. Both require the signed-in stack plus CSRF,
// and ownership is re-gated from the session inside the controller
// (the history service, in fact - foreign rows are never touched).
$router->delete('/search/history', [$searchController, 'clearHistory'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->delete('/search/history/{id}', [$searchController, 'deleteHistory'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);

// --- Book module ------------------------------------------------------
// Browsing (search, filters, sort, pagination, grid/table) is open
// to every signed-in user - the sidebar "Browse Books" link points
// here. CRUD actions stay admin-only.
//
// Route resolution order matters: the Router tries exact routes
// FIRST, so "/books/create" and "/books/search" are matched as the
// create page / live-search endpoint and can never be captured as
// the {id} of "/books/{id}".
//
// Following the application convention, forms POST back to the
// same path that shows them (/books/create, /books/{id}/edit).

$router->get('/books', [$bookController, 'index'], [$secure, new AuthMiddleware($auth)]);
$router->get('/books/search', [$bookController, 'searchJson'], [$secure, new AuthMiddleware($auth)]);
$router->get('/books/create', [$bookController, 'create'], [$secure, new AdminMiddleware($auth)]);
$router->post('/books/create', [$bookController, 'store'], [$secure, new AdminMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->get('/books/{id}', [$bookController, 'show'], [$secure, new AuthMiddleware($auth)]);
$router->get('/books/{id}/edit', [$bookController, 'edit'], [$secure, new AdminMiddleware($auth)]);
$router->post('/books/{id}/edit', [$bookController, 'update'], [$secure, new AdminMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->post('/books/{id}/delete', [$bookController, 'destroy'], [$secure, new AdminMiddleware($auth), new CsrfMiddleware($csrf)]);

// --- Recommendations (Phase 6.2: the six algorithms) --------------------
// The six strategy pages plus the per-book "more like this" page. Open
// to every signed-in user (like the browse routes). Exact routes are
// tried before parameterized ones, so "/recommendations/popular" can
// never be captured by "/recommendations/book/{id}" or
// "/recommendations/category/{id}".
//
// Phase 6.2 note: the old "/recommendations/personalized" stand-in is
// gone - the category strategy now requires a real category, and full
// profile personalization is the Phase 6.3 deliverable.

$router->get('/recommendations', [$recommendationController, 'index'], [$secure, new AuthMiddleware($auth)]);
$router->get('/recommendations/popular', [$recommendationController, 'popular'], [$secure, new AuthMiddleware($auth)]);
$router->get('/recommendations/top-rated', [$recommendationController, 'topRated'], [$secure, new AuthMiddleware($auth)]);
$router->get('/recommendations/trending', [$recommendationController, 'trending'], [$secure, new AuthMiddleware($auth)]);
$router->get('/recommendations/recent', [$recommendationController, 'recent'], [$secure, new AuthMiddleware($auth)]);
$router->get('/recommendations/category/{id}', [$recommendationController, 'category'], [$secure, new AuthMiddleware($auth)]);
$router->get('/recommendations/book/{id}', [$recommendationController, 'show'], [$secure, new AuthMiddleware($auth)]);

// Phase 6.4: the two write actions of the recommendation dashboard.
// Both change data, so both carry CSRF protection; both require a
// login (AuthMiddleware), and the fine RecommendationPolicy gate
// runs inside the controller like every other recommendations route.
$router->post('/recommendations/refresh', [$recommendationController, 'refresh'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->post('/wishlist/toggle', [$recommendationController, 'toggleWishlist'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);

// --- Reviews & Ratings (Phase 7.1 + Phase 7.2 + Phase 7.4) --------------
// Every route requires a login (AuthMiddleware); the write routes
// carry CSRF protection like every other data change in the app.
// The fine gates (owner-or-admin) run inside the controller via
// ReviewPolicy. "/books/{id}/reviews" has two segments, so it can
// never collide with the one-segment "/books/{id}" show route.
//
// Phase 7.1 note: "/reviews" used to be a "coming soon" placeholder
// page (PageController); the route now serves the real module.
// Phase 7.2 note: "/reviews/{id}" is the single-review detail page.
// Phase 7.4 note: the three literal routes below (search, statistics,
// user/{id}) are checked by the router's exact-match fast path BEFORE
// the parameterized "/reviews/{id}" pattern, so registration order is
// irrelevant - "/reviews/search" can never fall into the {id} bucket.
$router->get('/reviews', [$reviewController, 'index'], [$secure, new AuthMiddleware($auth)]);
$router->get('/reviews/search', [$reviewController, 'search'], [$secure, new AuthMiddleware($auth)]);
$router->get('/reviews/statistics', [$reviewController, 'statistics'], [$secure, new AuthMiddleware($auth)]);
$router->get('/reviews/user/{id}', [$reviewController, 'userReviews'], [$secure, new AuthMiddleware($auth)]);
$router->get('/reviews/{id}', [$reviewController, 'show'], [$secure, new AuthMiddleware($auth)]);
$router->get('/books/{id}/reviews', [$reviewController, 'bookReviews'], [$secure, new AuthMiddleware($auth)]);
$router->post('/books/{id}/reviews', [$reviewController, 'store'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->get('/reviews/{id}/edit', [$reviewController, 'edit'], [$secure, new AuthMiddleware($auth)]);
$router->post('/reviews/{id}/edit', [$reviewController, 'update'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->post('/reviews/{id}/delete', [$reviewController, 'destroy'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);

// Phase 7.5: community engagement. The helpful toggle and the report
// modal submit via fetch (X-Requested-With: fetch) and get JSON back;
// the routes also answer plain POSTs with a redirect + flash as the
// no-JS fallback. The anchored parameterized patterns differ by
// segment count (/reviews/{id} is two segments, these are three and
// four), and the literal /reviews/search stays in the exact-match
// table, so no pattern can collide.
$router->post('/reviews/{id}/helpful', [$reviewController, 'helpful'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->post('/reviews/{id}/helpful/remove', [$reviewController, 'removeHelpful'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->post('/reviews/{id}/report', [$reviewController, 'report'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);

// Phase 7.5: the review-management console (moderation foundation).
// The queue page reads the ?status tab (pending | reviewed |
// dismissed | resolved); the four write routes move reports along
// their lifecycle and hide / restore reviews. All admin-only.
$router->get('/admin/reviews', [$adminController, 'reports'], [$secure, new AdminMiddleware($auth)]);
$router->post('/admin/reports/{id}/resolve', [$adminController, 'resolveReport'], [$secure, new AdminMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->post('/admin/reports/{id}/dismiss', [$adminController, 'dismissReport'], [$secure, new AdminMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->post('/admin/reviews/{id}/hide', [$adminController, 'hideReview'], [$secure, new AdminMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->post('/admin/reviews/{id}/unhide', [$adminController, 'unhideReview'], [$secure, new AdminMiddleware($auth), new CsrfMiddleware($csrf)]);

// --- Personal Library (Phase 8.1 backend) ---------------------------
// The wishlist's successor: one record per user per book with a
// reading-status lifecycle, favourites, progress and statistics.
// Every route requires a login (AuthMiddleware); the write routes
// carry CSRF protection like every other data change. The fine
// ownership gate (own record only, even for admins) runs inside the
// controller via LibraryPolicy.
//
// The GET shelves answer JSON in Phase 8.1 - the Library Dashboard
// UI is Phase 8.2 and will render these exact payloads. The literal
// routes (/library/wishlist, /library/favorites, /library/statistics,
// ...) are matched before the parameterized /library/{id} pattern,
// so a path can never be misread, and the parameterized routes are
// POST-only while every literal is GET - the Router's method-first
// resolution keeps them fully apart.
$router->get('/library', [$libraryController, 'index'], [$secure, new AuthMiddleware($auth)]);
$router->post('/library', [$libraryController, 'store'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->get('/library/wishlist', [$libraryController, 'wishlist'], [$secure, new AuthMiddleware($auth)]);
$router->get('/library/currently-reading', [$libraryController, 'currentlyReading'], [$secure, new AuthMiddleware($auth)]);
$router->get('/library/finished', [$libraryController, 'finished'], [$secure, new AuthMiddleware($auth)]);
$router->get('/library/favorites', [$libraryController, 'favorites'], [$secure, new AuthMiddleware($auth)]);
$router->get('/library/statistics', [$libraryController, 'statistics'], [$secure, new AuthMiddleware($auth)]);
// Phase 8.2 additions: the live-search endpoint of the My Library
// page and the two fetch-driven write endpoints (favourite toggle and
// progress update). All three were already implemented in the
// controller during Phase 8.1; only the route registration was
// pending. /library/search is a GET literal (the router's exact-match
// fast path keeps it away from the parameterized /library/{id} POST),
// and the two writes carry CSRF like every other data change - the
// fetch calls include the token, the no-JS forms post it natively.
$router->get('/library/search', [$libraryController, 'search'], [$secure, new AuthMiddleware($auth)]);
$router->post('/library/{id}/favorite', [$libraryController, 'toggleFavourite'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->post('/library/{id}/progress', [$libraryController, 'updateProgress'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->post('/library/{id}', [$libraryController, 'update'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->post('/library/{id}/delete', [$libraryController, 'destroy'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
// Phase 8.3: the premium library dashboard's fetch endpoints - the
// grid filter/sort reads, the continue-shelf fragment and the
// view-mode write. The GET literals are matched before the
// parameterized /library/{id} POST patterns (the router's exact-match
// fast path), and the one write (view-mode) carries CSRF like every
// other data change.
$router->get('/library/filter', [$libraryController, 'filter'], [$secure, new AuthMiddleware($auth)]);
$router->get('/library/sort', [$libraryController, 'sort'], [$secure, new AuthMiddleware($auth)]);
$router->get('/library/continue-reading', [$libraryController, 'continueReading'], [$secure, new AuthMiddleware($auth)]);
$router->post('/library/view-mode', [$libraryController, 'viewMode'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
// Phase 8.4: the bulk actions endpoint (move / favourite / un-favourite
// / remove the selected records). POST-only like every library write,
// CSRF-protected; the record ids travel in the form and are re-gated
// by the owner check inside the repository.
$router->post('/library/bulk', [$libraryController, 'bulk'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);

// --- Main navigation (placeholder pages) ------------------------------
// Every sidebar destination is a real route. The remaining features
// (analytics, settings) arrive in later phases; for now each page
// shows a "coming soon" placeholder so the navigation never breaks.
// Categories and Authors became real pages in Phase 7.6 - they are
// registered with the catalogue routes below. The /wishlist sidebar
// link still lands here: its BACKEND moved to the Personal Library
// module in Phase 8.1 (/library routes above), and Phase 8.2 will
// point this link at the real library dashboard.
//
// Phase 7.6: the author and category pages. The literal /authors and
// /categories routes are matched before the parameterized /authors/{id}
// and /categories/{id} patterns, so a path can never be misread.

$router->get('/authors', [$authorController, 'index'], [$secure, new AuthMiddleware($auth)]);
$router->get('/authors/{id}', [$authorController, 'show'], [$secure, new AuthMiddleware($auth)]);
// Phase 9.2: the Follow Authors module. The follow/unfollow writes
// are POST/DELETE, CSRF-protected like every other data change, and
// throttled inside the controller (the "follow_write" bucket). The
// followers list is a GET read next to the parameterized show route.
$router->post('/authors/{id}/follow', [$authorController, 'follow'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->delete('/authors/{id}/follow', [$authorController, 'unfollow'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->get('/authors/{id}/followers', [$authorController, 'followers'], [$secure, new AuthMiddleware($auth)]);

$router->get('/categories', [$categoryController, 'index'], [$secure, new AuthMiddleware($auth)]);
$router->get('/categories/{id}', [$categoryController, 'show'], [$secure, new AuthMiddleware($auth)]);

// Phase 9.2: the signed-in user's "Authors I follow" page - the
// counterpart of the author page's Follow button.
$router->get('/profile/following', [$userController, 'following'], [$secure, new AuthMiddleware($auth)]);

$router->get('/wishlist', [$pageController, 'wishlist'], [$secure, new AuthMiddleware($auth)]);
$router->get('/analytics', [$pageController, 'analytics'], [$secure, new AuthMiddleware($auth)]);

// Phase 9.5: Settings became a REAL page - the Email notifications
// section (the five per-user toggles). The write endpoint saves them;
// it answers JSON for fetch callers (X-Requested-With: fetch) and a
// redirect + flash for the no-JS form, exactly the dual-answer
// convention of the other write routes.
$settingsController = new SettingsController($emailService);
$router->get('/settings', [$settingsController, 'show'], [$secure, new AuthMiddleware($auth)]);
$router->post('/settings/email-preferences', [$settingsController, 'emailPreferences'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);

// --- Notifications (Phase 9.3: the backend API) ---------------------------
// The center's UI (page, bell dropdown, badge) is Phase 9.4; these
// routes are the prepared API surface for it - the list read, the two
// idempotent read writes and the two deletes. Every route requires a
// login (AuthMiddleware) and the writes carry CSRF like every other
// data change; ownership is re-gated from the session inside the
// controller, so the routes themselves stay short.
//
// The literal /notifications/read-all and /notifications are checked
// before the parameterized patterns (the router's exact-match fast
// path), so the two PATCHes and the two DELETEs can never collide.
// Fetch callers (X-Requested-With: fetch) get JSON; plain forms get a
// redirect + flash.
$router->get('/notifications', [$notificationController, 'index'], [$secure, new AuthMiddleware($auth)]);
// Phase 9.4: the rendered center page (share the JSON feed's query
// string - ?tab, ?filter, ?page - so the page and the feed agree),
// the badge number the bell polls, and the two surface reads that
// complete the read-state toggle (unread) and the bulk delete.
// The literals (center, unread-count, read-all, bulk) are checked
// before the parameterized patterns, so none can collide.
$router->get('/notifications/center', [$notificationController, 'center'], [$secure, new AuthMiddleware($auth)]);
$router->get('/notifications/unread-count', [$notificationController, 'unreadCount'], [$secure, new AuthMiddleware($auth)]);
$router->get('/notifications/fragment', [$notificationController, 'fragment'], [$secure, new AuthMiddleware($auth)]);
$router->patch('/notifications/read-all', [$notificationController, 'markAllRead'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->patch('/notifications/{id}/read', [$notificationController, 'markRead'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->patch('/notifications/{id}/unread', [$notificationController, 'markUnread'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->post('/notifications/bulk', [$notificationController, 'bulkDestroy'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->delete('/notifications', [$notificationController, 'deleteAll'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->delete('/notifications/{id}', [$notificationController, 'destroy'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
