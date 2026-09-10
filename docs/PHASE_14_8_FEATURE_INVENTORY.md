# BOOKSPHERE — PHASE 14.8: FEATURE & ARCHITECTURE INVENTORY

================================================================================
**DOCUMENT:** PHASE 14.8 COMPREHENSIVE FEATURE INVENTORY  
**DATE:** 2026-08-15  
**SYSTEM:** BookSphere (Intelligent Book Discovery & Recommendation Platform)  
**STATUS:** COMPLETE AUDIT BASELINE  
================================================================================

---

## 1. EXECUTIVE SUMMARY

This feature inventory document provides a complete, source-verified catalog of every architectural component in the BookSphere application. It reflects the real source code without relying solely on past reports.

---

## 2. APPLICATION ROUTING & HTTP ENDPOINTS

### 2.1 Public & Authentication Routes
| Method | URI | Controller Action | Middleware | Description |
|---|---|---|---|---|
| GET | `/` | `PageController::landing` | `GuestMiddleware` | Standalone landing page |
| GET | `/login` | `AuthController::showLogin` | `GuestMiddleware` | Authentication split screen (Sign In) |
| POST | `/login` | `AuthController::login` | `GuestMiddleware`, `CsrfMiddleware` | Process user login with remember-me |
| GET | `/register` | `AuthController::showRegister` | `GuestMiddleware` | Create account form |
| POST | `/register` | `AuthController::register` | `GuestMiddleware`, `CsrfMiddleware` | Process user registration |
| POST | `/logout` | `AuthController::logout` | `AuthMiddleware`, `CsrfMiddleware` | Revoke session & remember tokens |
| GET | `/forgot-password` | `AuthController::showForgotPassword` | `GuestMiddleware` | Password reset request form |
| POST | `/forgot-password` | `AuthController::forgotPassword` | `GuestMiddleware`, `CsrfMiddleware` | Generate single-use reset token |
| GET | `/reset-password` | `AuthController::showResetPassword` | `GuestMiddleware` | Password reset execution form |
| POST | `/reset-password` | `AuthController::resetPassword` | `GuestMiddleware`, `CsrfMiddleware` | Verify token & update password |

### 2.2 Core Application & Catalog Routes
| Method | URI | Controller Action | Middleware | Description |
|---|---|---|---|---|
| GET | `/dashboard` | `DashboardController::index` | `AuthMiddleware` | Reader dashboard with shelves & recommendations |
| GET | `/books` | `BookController::index` | `AuthMiddleware` | Browse books catalog with pagination & filters |
| GET | `/books/{id}` | `BookController::show` | `AuthMiddleware` | Single book details with reviews & metadata |
| GET | `/categories` | `CategoryController::index` | `AuthMiddleware` | Browse category taxonomy |
| GET | `/categories/{id}` | `CategoryController::show` | `AuthMiddleware` | Category detail & associated books |
| GET | `/authors` | `AuthorController::index` | `AuthMiddleware` | Browse author directory |
| GET | `/authors/{id}` | `AuthorController::show` | `AuthMiddleware` | Author profile & bibliography |
| POST | `/authors/{id}/follow` | `AuthorController::follow` | `AuthMiddleware`, `CsrfMiddleware` | Follow/unfollow author |

### 2.3 Search System Routes
| Method | URI | Controller Action | Middleware | Description |
|---|---|---|---|---|
| GET | `/search` | `SearchController::index` | `AuthMiddleware` | Global search console |
| GET | `/search/suggest` | `SearchController::suggest` | `AuthMiddleware` | Autocomplete / live search suggestions |
| GET | `/search/history` | `SearchController::history` | `AuthMiddleware` | User search history |
| POST | `/search/history/clear` | `SearchController::clearHistory` | `AuthMiddleware`, `CsrfMiddleware` | Clear user search history |
| DELETE | `/search/history/{id}` | `SearchController::deleteHistoryItem` | `AuthMiddleware`, `CsrfMiddleware` | Delete specific history query |

### 2.4 Personal Library & Wishlist Routes
| Method | URI | Controller Action | Middleware | Description |
|---|---|---|---|---|
| GET | `/library` | `LibraryController::index` | `AuthMiddleware` | Personal library shelves (want_to_read, reading, finished, on_hold, dropped) |
| POST | `/library` | `LibraryController::store` | `AuthMiddleware`, `CsrfMiddleware` | Add book to library / set shelf status |
| POST | `/library/update` | `LibraryController::update` | `AuthMiddleware`, `CsrfMiddleware` | Update reading progress & shelf |
| POST | `/library/favorite` | `LibraryController::toggleFavorite` | `AuthMiddleware`, `CsrfMiddleware` | Toggle favorite status |
| POST | `/library/remove` | `LibraryController::remove` | `AuthMiddleware`, `CsrfMiddleware` | Remove book from library |
| GET | `/library/statistics` | `LibraryController::statistics` | `AuthMiddleware` | Library reading stats snapshot |
| GET | `/wishlist` | `LibraryController::wishlist` | `AuthMiddleware` | Legacy wishlist route (redirects to Want to Read) |

### 2.5 Reviews & Ratings Routes
| Method | URI | Controller Action | Middleware | Description |
|---|---|---|---|---|
| GET | `/reviews` | `ReviewController::index` | `AuthMiddleware` | Community reviews feed & statistics |
| GET | `/reviews/book/{bookId}` | `ReviewController::forBook` | `AuthMiddleware` | Reviews for specific book |
| GET | `/reviews/user/{userId}` | `ReviewController::forUser` | `AuthMiddleware` | Public reviews by user |
| GET | `/reviews/{id}` | `ReviewController::show` | `AuthMiddleware` | Detailed review view |
| POST | `/reviews` | `ReviewController::store` | `AuthMiddleware`, `CsrfMiddleware` | Submit new book review & rating |
| POST | `/reviews/{id}/edit` | `ReviewController::update` | `AuthMiddleware`, `CsrfMiddleware` | Edit existing user review |
| POST | `/reviews/{id}/delete` | `ReviewController::delete` | `AuthMiddleware`, `CsrfMiddleware` | Delete user review |
| POST | `/reviews/{id}/report` | `ReviewController::report` | `AuthMiddleware`, `CsrfMiddleware` | Report review for moderation |
| POST | `/reviews/{id}/helpful` | `ReviewController::helpful` | `AuthMiddleware`, `CsrfMiddleware` | Vote review as helpful |

### 2.6 Recommendation Engine Routes
| Method | URI | Controller Action | Middleware | Description |
|---|---|---|---|---|
| GET | `/recommendations` | `RecommendationController::index` | `AuthMiddleware` | Recommendation hub (multi-strategy) |
| GET | `/recommendations/strategy/{key}` | `RecommendationController::strategy` | `AuthMiddleware` | Single strategy recommendations view |
| POST | `/recommendations/dismiss` | `RecommendationController::dismiss` | `AuthMiddleware`, `CsrfMiddleware` | Dismiss recommendation item |

### 2.7 Community & Social Routes
| Method | URI | Controller Action | Middleware | Description |
|---|---|---|---|---|
| GET | `/community` | `CommunityController::index` | `AuthMiddleware` | Community discussions feed |
| GET | `/community/create` | `CommunityController::create` | `AuthMiddleware` | Create new discussion post form |
| POST | `/community/posts` | `CommunityController::storePost` | `AuthMiddleware`, `CsrfMiddleware` | Publish community discussion post |
| GET | `/community/posts/{id}` | `CommunityController::showPost` | `AuthMiddleware` | Discussion post & comment thread |
| POST | `/community/posts/{id}/edit` | `CommunityController::updatePost` | `AuthMiddleware`, `CsrfMiddleware` | Edit discussion post |
| POST | `/community/posts/{id}/delete` | `CommunityController::deletePost` | `AuthMiddleware`, `CsrfMiddleware` | Delete discussion post |
| POST | `/community/posts/{id}/comments` | `CommunityController::storeComment` | `AuthMiddleware`, `CsrfMiddleware` | Post comment on discussion |
| POST | `/community/comments/{id}/delete`| `CommunityController::deleteComment`| `AuthMiddleware`, `CsrfMiddleware` | Delete user comment |
| POST | `/community/posts/{id}/like` | `CommunityController::toggleLike` | `AuthMiddleware`, `CsrfMiddleware` | Like/unlike post |
| POST | `/community/posts/{id}/report` | `CommunityController::reportPost` | `AuthMiddleware`, `CsrfMiddleware` | Report community post |
| GET | `/community/profile/{id}` | `CommunityController::profile` | `AuthMiddleware` | Community user profile |

### 2.8 Notifications Routes
| Method | URI | Controller Action | Middleware | Description |
|---|---|---|---|---|
| GET | `/notifications` | `NotificationController::index` | `AuthMiddleware` | In-app notification center |
| GET | `/notifications/unread-count` | `NotificationController::unreadCount` | `AuthMiddleware` | Live unread counter endpoint |
| POST | `/notifications/{id}/read` | `NotificationController::markAsRead` | `AuthMiddleware`, `CsrfMiddleware` | Mark notification as read |
| POST | `/notifications/read-all` | `NotificationController::markAllAsRead` | `AuthMiddleware`, `CsrfMiddleware` | Mark all notifications as read |

### 2.9 Analytics & Reports Routes
| Method | URI | Controller Action | Middleware | Description |
|---|---|---|---|---|
| GET | `/analytics` | `UserAnalyticsController::show` | `AuthMiddleware` | Personal reading analytics snapshot |
| GET | `/analytics/report` | `UserAnalyticsController::report` | `AuthMiddleware` | Print-friendly personal reading report |
| GET | `/book-analytics` | `BookAnalyticsController::index` | `AuthMiddleware` | Whole-catalogue analytics & metrics |

### 2.10 Admin Console Routes
| Method | URI | Controller Action | Middleware | Description |
|---|---|---|---|---|
| GET | `/admin` | `AdminController::index` | `AdminMiddleware` | Admin overview dashboard |
| GET | `/admin/books` | `AdminController::books` | `AdminMiddleware` | Admin books management |
| GET | `/admin/books/create` | `AdminController::createBook` | `AdminMiddleware` | Add book manually form |
| POST | `/admin/books` | `AdminController::storeBook` | `AdminMiddleware`, `CsrfMiddleware` | Store new book |
| GET | `/admin/books/{id}/edit` | `AdminController::editBook` | `AdminMiddleware` | Edit book form |
| POST | `/admin/books/{id}` | `AdminController::updateBook` | `AdminMiddleware`, `CsrfMiddleware` | Update book |
| POST | `/admin/books/{id}/delete` | `AdminController::deleteBook` | `AdminMiddleware`, `CsrfMiddleware` | Soft-delete book |
| GET | `/admin/users` | `AdminController::users` | `AdminMiddleware` | User directory & roles |
| GET | `/admin/categories` | `AdminController::categories` | `AdminMiddleware` | Manage categories |
| GET | `/admin/authors` | `AdminController::authors` | `AdminMiddleware` | Manage authors |
| GET | `/admin/reviews` | `AdminController::reviews` | `AdminMiddleware` | Review moderation queue |
| POST | `/admin/reviews/{id}/approve` | `AdminController::approveReview` | `AdminMiddleware`, `CsrfMiddleware` | Approve review |
| POST | `/admin/reviews/{id}/reject` | `AdminController::rejectReview` | `AdminMiddleware`, `CsrfMiddleware` | Reject review |
| GET | `/admin/community` | `AdminCommunityController::index` | `AdminMiddleware` | Community moderation dashboard |
| POST | `/admin/community/reports/{id}/resolve`| `AdminCommunityController::resolveReport`| `AdminMiddleware`, `CsrfMiddleware` | Resolve report |
| GET | `/admin/analytics` | `AdminController::analytics` | `AdminMiddleware` | System-wide analytics |
| GET | `/admin/analytics/report` | `AdminController::analyticsReport` | `AdminMiddleware` | Printable system report |
| GET | `/admin/recommendations` | `AdminController::recommendations` | `AdminMiddleware` | Recommendation engine telemetry |
| GET | `/admin/google-books` | `GoogleBooksController::index` | `AdminMiddleware` | Google Books sync & search control |

---

## 3. CONTROLLERS & SERVICES INVENTORY

### Controllers (20 total):
1. `AdminCommunityController` — Community moderation management
2. `AdminController` — Main admin console actions
3. `AuthController` — Login, register, password reset, session control
4. `AuthorController` — Author directory, author details, author follow
5. `BookAnalyticsController` — Whole-catalogue analytics
6. `BookController` — Browse, book detail
7. `CategoryController` — Categories list and category details
8. `CommunityController` — Feeds, posts, comments, likes, reporting
9. `DashboardController` — User home dashboard
10. `GoogleBooksController` — Google Books search and sync admin
11. `LibraryController` — Personal reading lists, statuses, progress
12. `NotificationController` — Notifications feed, badge counter, read states
13. `PageController` — Landing page and static informational views
14. `RecommendationController` — Recommendation dashboard and strategies
15. `ReviewController` — Review CRUD, helpful votes, moderation reports
16. `SearchController` — Search hub, live suggestions, search history
17. `SettingsController` — User profile, appearance preferences, email settings
18. `UserAnalyticsController` — Personal analytics and printable reading report
19. `UserController` — Public reader profile view
20. `Core/Controller` (Base) — Abstract base controller with view rendering and flash handling

### Services (38 total):
1. `AdminAnalyticsService` — System metrics aggregation
2. `AuthService` — User authentication, hashing, remember tokens
3. `BookAnalyticsService` — Catalog analytics and ranking computation
4. `BookImportService` — Single book import pipeline
5. `BookService` — Book catalog querying, filtering, sorting
6. `BulkImportService` — Bulk dataset importing
7. `CacheManager` — File-based caching layer
8. `CircuitBreaker` — Third-party API failure protection
9. `CommunityRecommendationSignalService` — Bridge community interactions to recommendations
10. `CommunityService` — Posts, comments, likes, reputation
11. `CoverDownloadService` — Asynchronous and synchronous book cover fetching
12. `EmailNotificationService` — Transactional email dispatching and logging
13. `FollowService` — User-to-user and user-to-author follow relationships
14. `GoogleBooksClient` — Low-level HTTP client for Google Books API v1
15. `GoogleBooksProvider` — Provider abstraction for Google Books API
16. `GoogleBooksService` — Search and volume metadata mapping
17. `GoogleBooksSyncService` — Catalog synchronization pipeline
18. `LibraryService` — User reading tracking and status state transitions
19. `MediaService` — Image and cover asset management
20. `NotificationDispatcher` — Multi-channel event notification broadcaster
21. `NotificationFormatter` — Notification copy and link generator
22. `NotificationService` — In-app notification creation and query management
23. `PersonalizationCache` — User preference caching
24. `RecommendationConfig` — Recommendation engine hyperparameters
25. `RecommendationFactory` — Strategy instantiation factory
26. `RecommendationMetrics` — Telemetry and precision tracking
27. `RecommendationScoring` — Multi-factor recommendation ranking formulas
28. `RecommendationService` — Core recommendation orchestration engine
29. `ReviewService` — Review validation, status transitions, average computations
30. `SearchHistoryService` — Query logging and user history management
31. `SearchProvider` — Interface for search engines
32. `SearchProviderFactory` — Search provider selection
33. `SearchResultFormatter` — Search hit shaping and score tagging
34. `SearchService` — Search orchestration across books, authors, categories, reviews
35. `SearchSuggestionService` — Instant prefix suggestions
36. `SqliteSearchProvider` — Full-text and LIKE search over SQLite
37. `UserAnalyticsService` — Personal reading analytics calculations

---

## 4. DATABASE LAYER INVENTORY

### Tables (18 total):
1. `books` (529 rows in frozen baseline)
2. `authors` (889 rows in frozen baseline)
3. `categories` (17 rows in frozen baseline)
4. `book_authors` (Many-to-many relationship)
5. `book_categories` (Many-to-many relationship)
6. `users` (User accounts and credentials)
7. `user_library` (Personal shelves, reading status, progress)
8. `wishlist` (Personalization interest signals)
9. `reviews` (Ratings and written reviews)
10. `community_posts` (Community discussion threads)
11. `community_comments` (Post comments)
12. `community_likes` (Post upvotes)
13. `community_reports` (Moderation reports)
14. `community_follows` (User follow relationships)
15. `author_follows` (Author follow relationships)
16. `notifications` (In-app notification records)
17. `password_reset_tokens` (Expiring password recovery tokens)
18. `search_history` (User search queries)

---

## 5. DESIGN SYSTEM & ASSET INVENTORY

### Layouts:
1. `app/Views/layouts/master.php` — Main authenticated application shell (Navbar, Sidebar, Content, Footer)
2. `app/Views/layouts/landing.php` — Public full-bleed landing page
3. `app/Views/layouts/auth.php` — Split-screen authentication layout

### Core Stylesheets:
1. `public/assets/css/app.css` (92.8 KB) — Master design system, tokens, theme variables, components
2. `public/assets/css/fontawesome.min.css` (102.9 KB) — Local Font Awesome 6 Free webfont definitions & utilities
3. `public/assets/css/auth.css` (24.0 KB) — Split authentication screens
4. `public/assets/css/landing.css` (28.4 KB) — Landing page typography & hero layout
5. `public/assets/css/library.css` (36.1 KB) — Shelf grid, book cards, progress sliders
6. `public/assets/css/search.css` (12.3 KB) — Search bar, filters drawer, search result hits
7. `public/assets/css/reviews.css` (9.7 KB) — Review cards, helpful counter, rating stars
8. `public/assets/css/notifications.css` (11.6 KB) — Notification item states, badge
9. `public/assets/css/google-books.css` (10.0 KB) — Google Books sync console
10. `public/assets/css/charts.css` (2.3 KB) — CSS bar charts and sparklines
11. `public/assets/css/rating.css` (8.7 KB) — Interactive star rating widget
12. `public/assets/css/follow.css` (5.2 KB) — Follow buttons and social badges
13. `public/assets/css/settings.css` (3.1 KB) — Settings forms and appearance options

### Fonts & Webfonts:
1. `public/assets/fonts/fa-solid-900.woff2` (156.4 KB) / `.ttf` (420.3 KB)
2. `public/assets/fonts/fa-regular-400.woff2` (25.4 KB) / `.ttf` (67.9 KB)
3. `public/assets/fonts/fa-brands-400.woff2` (117.9 KB) / `.ttf` (209.1 KB)
4. Google Fonts: `Inter` (Sans-serif UI) & `Fraunces` (Editorial serif)

---
