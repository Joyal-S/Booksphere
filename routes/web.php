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
use BookSphere\App\Controllers\AuthController;
use BookSphere\App\Controllers\BookController;
use BookSphere\App\Controllers\DashboardController;
use BookSphere\App\Controllers\PageController;
use BookSphere\App\Controllers\RecommendationController;
use BookSphere\App\Controllers\ReviewController;
use BookSphere\App\Controllers\UserController;
use BookSphere\App\Core\Csrf;
use BookSphere\App\Core\RateLimiter;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Middleware\AdminMiddleware;
use BookSphere\App\Middleware\AuthMiddleware;
use BookSphere\App\Middleware\CsrfMiddleware;
use BookSphere\App\Middleware\GuestMiddleware;
use BookSphere\App\Middleware\SecureHeadersMiddleware;
use BookSphere\App\Models\Author;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\Category;
use BookSphere\App\Models\Review;
use BookSphere\App\Models\User;
use BookSphere\App\Policies\RecommendationPolicy;
use BookSphere\App\Policies\ReviewPolicy;
use BookSphere\App\Presenters\RecommendationDashboardPresenter;
use BookSphere\App\Repositories\BookRepository;
use BookSphere\App\Repositories\RecommendationRepository;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\BookService;
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

$authController = new AuthController($auth, $users);
$userController = new UserController($auth, $users);
$dashboardController = new DashboardController();
$pageController = new PageController();

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
);

// --- Reviews & Ratings (Phase 7.1 backend + Phase 7.2 CRUD) -------------
// ONE ReviewService instance is shared by the review controller AND
// the book controller (the detail page renders the Reviews section),
// so the book page and the review pages always see the same rules
// and the same recommendation-cache hook.
$reviewService = new ReviewService(new Review(), new Book(), $recommendationService);

$bookController = new BookController(new BookService(new Book(), new Author(), new Category()), $recommendationService, $reviewService);
$reviewController = new ReviewController($reviewService, new ReviewPolicy());

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

// Phase 6.5: the admin monitoring surface - one metrics service
// sharing the engine's repository and cache, so the page shows the
// live state of the exact objects the engine runs with.
$adminController = new AdminController(
    new RecommendationMetrics($recommendationRepository, $personalizationCache),
);

// Home: the logged-in user's dashboard (the root of the app).
// The route is protected so the greeting can use the session user.
$router->get('/', [$dashboardController, 'index'], [$secure, new AuthMiddleware($auth)]);

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

// --- Reviews & Ratings (Phase 7.1 + Phase 7.2) ---------------------------
// Every route requires a login (AuthMiddleware); the write routes
// carry CSRF protection like every other data change in the app.
// The fine gates (owner-or-admin) run inside the controller via
// ReviewPolicy. "/books/{id}/reviews" has two segments, so it can
// never collide with the one-segment "/books/{id}" show route.
//
// Phase 7.1 note: "/reviews" used to be a "coming soon" placeholder
// page (PageController); the route now serves the real module.
// Phase 7.2 note: "/reviews/{id}" is the single-review detail page.
$router->get('/reviews', [$reviewController, 'index'], [$secure, new AuthMiddleware($auth)]);
$router->get('/reviews/{id}', [$reviewController, 'show'], [$secure, new AuthMiddleware($auth)]);
$router->get('/books/{id}/reviews', [$reviewController, 'bookReviews'], [$secure, new AuthMiddleware($auth)]);
$router->post('/books/{id}/reviews', [$reviewController, 'store'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->get('/reviews/{id}/edit', [$reviewController, 'edit'], [$secure, new AuthMiddleware($auth)]);
$router->post('/reviews/{id}/edit', [$reviewController, 'update'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);
$router->post('/reviews/{id}/delete', [$reviewController, 'destroy'], [$secure, new AuthMiddleware($auth), new CsrfMiddleware($csrf)]);

// --- Main navigation (placeholder pages) ------------------------------
// Every sidebar destination is a real route. The features themselves
// arrive in later phases; for now each page shows a "coming soon"
// placeholder so the navigation never breaks.

$router->get('/categories', [$pageController, 'categories'], [$secure, new AuthMiddleware($auth)]);
$router->get('/authors', [$pageController, 'authors'], [$secure, new AuthMiddleware($auth)]);
$router->get('/wishlist', [$pageController, 'wishlist'], [$secure, new AuthMiddleware($auth)]);
$router->get('/analytics', [$pageController, 'analytics'], [$secure, new AuthMiddleware($auth)]);
$router->get('/settings', [$pageController, 'settings'], [$secure, new AuthMiddleware($auth)]);
