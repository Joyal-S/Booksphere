# BookSphere — Feature Inventory & Capability Matrix

**System**: BookSphere — Digital Book Recommendation & Social Reading Platform  
**Target Environment**: PHP 8.2+, SQLite 3, Windows / Linux  
**Status**: Feature-Complete & Production Verified  

---

## 1. Executive Summary

BookSphere is an enterprise-grade digital library, personalized book discovery, and social reading application. Built on clean **PHP MVC** principles without heavy full-stack frameworks, it provides sub-100ms response times, pure SQL indexing, a hybrid multi-factor recommendation engine, and an interactive reader community.

---

## 2. Feature Inventory by Functional Module

### Module 1: Authentication & Identity Management
| Feature Name | Description | Route(s) | HTTP | Controller & Services | Primary Tables | Access |
| :--- | :--- | :--- | :---: | :--- | :--- | :---: |
| **Landing Page** | Public welcome page showcasing features and top catalogue items. | `/` | GET | `PageController::landing` | `books` | Public |
| **User Registration** | Secure signup with input validation and bcrypt password hashing. | `/register` | GET, POST | `AuthController::showRegister`, `register`<br>`AuthService` | `users` | Guest |
| **User Sign In** | Email/password login with CSRF defense, rate-limiting, and "Remember Me". | `/login` | GET, POST | `AuthController::showLogin`, `login`<br>`AuthService` | `users`, `rate_limits` | Guest |
| **Session Revocation** | Secure session invalidation and remember token removal. | `/logout` | POST | `AuthController::logout` | `users` | User |
| **Password Reset** | Single-use token generation and password updating via email or screen. | `/forgot-password`<br>`/reset-password` | GET, POST | `AuthController::forgotPassword`, `resetPassword` | `password_resets` | Guest |

---

### Module 2: Book Catalogue & Taxonomy
| Feature Name | Description | Route(s) | HTTP | Controller & Services | Primary Tables | Access |
| :--- | :--- | :--- | :---: | :--- | :--- | :---: |
| **Catalogue Browse** | Paginated listing (20/page) with sorting by title, rating, year, or recency. | `/books` | GET | `BookController::index`<br>`BookService`, `BookRepository` | `books`, `authors`, `categories` | User |
| **Filter Sidebar** | Multi-faceted filtering by genre/category, author, publication year, and language. | `/books` | GET | `BookController::index`<br>`BookService::combineFilters` | `books`, `book_categories` | User |
| **Catalogue View Switch** | Toggle between responsive Grid View (cards) and Table View (dense list). | `/books?view=grid`<br>`/books?view=table` | GET | `BookController::index`<br>`user_preferences` | `books`, `user_preferences` | User |
| **Category Directory** | Browse books categorized under genres (Fiction, Science, History, etc.). | `/categories`<br>`/categories/{id}` | GET | `CategoryController::index`, `show` | `categories`, `books` | User |
| **Author Directory** | Author profiles, biographies, and published catalogues. | `/authors`<br>`/authors/{id}` | GET | `AuthorController::index`, `show` | `authors`, `books` | User |
| **Author Following** | Follow authors to receive notifications upon new catalogue additions. | `/authors/{id}/follow` | POST | `AuthorController::follow`<br>`FollowService` | `author_follows` | User |

---

### Module 3: Search System
| Feature Name | Description | Route(s) | HTTP | Controller & Services | Primary Tables | Access |
| :--- | :--- | :--- | :---: | :--- | :--- | :---: |
| **Global Search Console** | Multi-field search across titles, authors, and descriptions. | `/search` | GET | `SearchController::index`<br>`SearchService`, `SearchRepository` | `books`, `authors` | User |
| **Live Suggestions** | Instant search suggestions powered by debounced asynchronous API. | `/search/suggest` | GET | `SearchController::suggest`<br>`SearchSuggestionService` | `books`, `authors` | User |
| **Search History** | Persistent search telemetry tracking recent queries per user. | `/search/history` | GET, POST, DELETE | `SearchController::history`, `clearHistory`<br>`SearchHistoryService` | `search_history` | User |

---

### Module 4: Book Details & Interactivity
| Feature Name | Description | Route(s) | HTTP | Controller & Services | Primary Tables | Access |
| :--- | :--- | :--- | :---: | :--- | :--- | :---: |
| **Book Detail View** | Comprehensive metadata, cover art, publisher details, and reading telemetry. | `/books/{id}` | GET | `BookController::show`<br>`BookRepository` | `books`, `authors`, `categories` | User |
| **Rating Distribution** | Visual percentage breakdown of 1-star through 5-star community reviews. | `/books/{id}` | GET | `BookController::show`<br>`ReviewRepository::ratingDistribution` | `reviews` | User |
| **Cover SVG Fallbacks** | Clean SVG placeholder rendering when remote cover images are missing. | `/books/{id}` | GET | `View::component('book-cover')` | N/A (Static SVG) | User |

---

### Module 5: Personal Library & Reading Lifecycle
| Feature Name | Description | Route(s) | HTTP | Controller & Services | Primary Tables | Access |
| :--- | :--- | :--- | :---: | :--- | :--- | :---: |
| **Smart Shelves** | Categorize reading across 5 states: Want to Read, Currently Reading, Finished, On Hold, Dropped. | `/library` | GET | `LibraryController::index`<br>`LibraryService`, `LibraryRepository` | `user_library` | User |
| **Reading Progress** | Real-time 0–100% progress tracking with auto-finish triggers upon 100%. | `/library/update` | POST | `LibraryController::update`<br>`LibraryService::updateProgress` | `user_library` | User |
| **Favourites Flagging** | Mark personal favourites independent of reading shelf status. | `/library/favorite` | POST | `LibraryController::toggleFavorite` | `user_library` | User |
| **Continue Reading** | Instant resume shelf on Reader Dashboard highlighting in-progress books. | `/` | GET | `DashboardController::index`<br>`LibraryRepository::currentlyReading` | `user_library` | User |
| **Library Statistics** | Reading metrics: total books read, pages completed, reading streaks. | `/library/statistics` | GET | `LibraryController::statistics` | `user_library` | User |

---

### Module 6: Reviews & Ratings System
| Feature Name | Description | Route(s) | HTTP | Controller & Services | Primary Tables | Access |
| :--- | :--- | :--- | :---: | :--- | :--- | :---: |
| **Review Submission** | 1–5 star rating with structured title and 20–2000 character review body. | `/reviews` | POST | `ReviewController::store`<br>`ReviewService`, `ReviewRepository` | `reviews`, `books` | User |
| **Review Editing** | Edit existing review with automatic "Edited" badge tagging. | `/reviews/{id}/edit` | POST | `ReviewController::update` | `reviews` | User |
| **Review Deletion** | Remove review and automatically recalculate book's average rating. | `/reviews/{id}/delete` | POST | `ReviewController::delete` | `reviews`, `books` | User |
| **Helpful Upvoting** | Upvote quality reviews with live counts and duplicate prevention. | `/reviews/{id}/helpful` | POST | `ReviewController::helpful` | `review_helpful_votes` | User |
| **Abuse Reporting** | Flag inappropriate reviews with 6 categorized violation reasons for admin review. | `/reviews/{id}/report` | POST | `ReviewController::report` | `review_reports` | User |

---

### Module 7: Recommendation Engine (Hybrid Personalization)
| Feature Name | Description | Route(s) | HTTP | Controller & Services | Primary Tables | Access |
| :--- | :--- | :--- | :---: | :--- | :--- | :---: |
| **Personalized Feed** | Multi-factor hybrid recommendations tailored to personal reading profile. | `/recommendations` | GET | `RecommendationController::index`<br>`RecommendationService` | `books`, `user_library`, `reviews` | User |
| **Dashboard Shelves** | 8 curated shelves: "Recommended for You", "Because You Read", "Trending", "Top Rated", "Community Favourites", "Continue Reading", "Recently Added", "Hidden Gems". | `/` | GET | `DashboardController::index`<br>`RecommendationDashboardPresenter` | `books`, `recommendations` | User |
| **Library Exclusion** | Strict exclusion ensuring books already in the user's library are never recommended. | `/recommendations` | GET | `RecommendationService::buildProfile`<br>`PersonalizationProfile::$libraryBookIds` | `user_library` | User |
| **Explainable Badges** | Transparent match reasons ("85% Match · Based on your love of Mystery & Dan Brown"). | `/recommendations` | GET | `RecommendationScoring::explain()` | N/A (Algorithmic) | User |
| **Recommendation Cache** | Atomic file-caching in `database/cache/recommendations/` (~0.3 ms response). | All rec surfaces | Internal | `PersonalizationCache` | Filesystem Cache | User |

---

### Module 8: Social & Reader Community
| Feature Name | Description | Route(s) | HTTP | Controller & Services | Primary Tables | Access |
| :--- | :--- | :--- | :---: | :--- | :--- | :---: |
| **Community Feed** | Public discussion forum for readers to share insights, queries, and thoughts. | `/community` | GET | `CommunityController::index`<br>`CommunityService` | `community_posts` | User |
| **Post Creation & Edit** | Rich discussion creation with optional book association tagging. | `/community/create`<br>`/community/posts/{id}/edit` | GET, POST | `CommunityController::storePost`, `updatePost` | `community_posts` | User |
| **Post Comments** | Multi-user comment threads on discussion posts. | `/community/posts/{id}/comments` | POST | `CommunityController::storeComment` | `community_comments` | User |
| **Like Interactions** | Heart/like interaction with live counter updates. | `/community/posts/{id}/like` | POST | `CommunityController::toggleLike` | `community_likes` | User |
| **Reader Profiles** | Public community profiles showing reading stats, recent posts, and badges. | `/community/profile/{id}` | GET | `CommunityController::profile` | `users`, `user_library` | User |
| **Reader Following** | Follow fellow readers to cultivate a curated social reading feed. | `/community/profile/{id}/follow` | POST | `CommunityController::toggleFollow` | `community_follows` | User |

---

### Module 9: Notifications & Dispatcher
| Feature Name | Description | Route(s) | HTTP | Controller & Services | Primary Tables | Access |
| :--- | :--- | :--- | :---: | :--- | :--- | :---: |
| **Notification Center** | Inbox for author releases, community comments, likes, and shelf milestones. | `/notifications/center` | GET | `NotificationController::index`<br>`NotificationService` | `notifications` | User |
| **Mark as Read** | Instant unread badge clearing via single-item or bulk actions. | `/notifications/read` | POST | `NotificationController::markAsRead` | `notifications` | User |
| **Email Queue System** | Background email queue for transactional notifications and digests. | CLI / Internal | Internal | `EmailNotificationService` | `email_queue`, `email_logs` | System |

---

### Module 10: Administration & Analytics
| Feature Name | Description | Route(s) | HTTP | Controller & Services | Primary Tables | Access |
| :--- | :--- | :--- | :---: | :--- | :--- | :---: |
| **Admin Overview** | System overview: total books, users, reviews, and storage health. | `/admin` | GET | `AdminController::dashboard`<br>`AdminAnalyticsService` | `books`, `users`, `reviews` | Admin |
| **Catalogue Management** | Full CRUD for books: add book, edit details, upload cover, soft-delete. | `/admin/books` | GET, POST | `BookController::create`, `store`, `edit`, `update`, `destroy` | `books`, `book_authors` | Admin |
| **Review Moderation** | Review reported reviews: approve, hide, or dismiss reports. | `/admin/reviews` | GET, POST | `AdminController::reviews`, `moderateReview` | `review_reports`, `reviews` | Admin |
| **Community Moderation** | Inspect and take action on reported community posts and comments. | `/admin/community` | GET, POST | `AdminCommunityController::index`, `resolveReport` | `community_reports` | Admin |
| **Analytics Reports** | Deep-dive telemetry: genre distributions, rating histograms, active readers. | `/admin/analytics/report` | GET | `AdminController::analytics`<br>`BookAnalyticsService` | `books`, `reviews`, `users` | Admin |
| **Recommendation Insights** | Real-time telemetry on recommendation click-through rates and cache hits. | `/admin/recommendations` | GET | `AdminController::recommendations`<br>`RecommendationMetrics` | `recommendation_logs` | Admin |
| **Google Books Integration** | Live search and one-click import of books and metadata via Google Books API. | `/admin/google-books` | GET, POST | `GoogleBooksController::search`, `import`<br>`GoogleBooksService` | `books`, `authors` | Admin |
| **Catalogue Sync** | Asynchronous or batch synchronization of ISBNs, ratings, and covers. | `/admin/google-books/sync` | POST | `GoogleBooksController::sync`<br>`GoogleBooksSyncService` | `books` | Admin |
