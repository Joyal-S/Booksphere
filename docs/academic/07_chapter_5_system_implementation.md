# CHAPTER 5 — SYSTEM IMPLEMENTATION

## 5.1 Introduction

System implementation is the realization of the architectural design into executable software code. This chapter details the technical implementation of BookSphere, outlining its layered Model-View-Controller (MVC) architecture, the operational mechanics of each functional module (including the proprietary Recommendation Engine V2), coding standards, security enforcement, and algorithmic optimizations applied to guarantee sub-160ms response times.

---

## 5.2 System Architecture

BookSphere is architected around a clean, layered **Model-View-Controller (MVC)** design pattern, augmented by dedicated **Service** and **Repository** layers to achieve strict separation of concerns.

```text
+-------------------------------------------------------------------------------+
|                      BOOKSPHERE SYSTEM ARCHITECTURE                           |
+-------------------------------------------------------------------------------+

       Client Request (HTTP GET/POST)
             |
             v
       [public/index.php] (Front Controller & Bootstrap)
             |
             v
       [App\Core\Router] <---> [App\Middleware]
             |                 (AuthMiddleware, AdminMiddleware, CsrfMiddleware)
             v
       [App\Controllers]
             |
             v
       [App\Services] (Business Logic & Recommendation Engine V2)
             |
             +------------------------------+
             |                              |
             v                              v
       [App\Repositories]             [External APIs]
       (Data Access Layer)            (Google Books REST API)
             |
             v
       [App\Core\Database] (PDO SQLite Wrapper)
             |
             v
       [database/booksphere.db] (Relational SQLite Store)
```

### Architectural Layer Responsibilities:
1. **Front Controller (`public/index.php`)**: Single entry point for all HTTP requests. Bootstraps environment variables, initializes configuration, registers error handlers, and invokes the router.
2. **Routing & Middleware (`App\Core\Router`, `App\Middleware`)**: Matches incoming request URIs and HTTP methods against registered route definitions. Executes middleware pipelines (e.g., authentication verification, admin authorization, CSRF token validation) prior to dispatching requests to controllers.
3. **Controllers (`App\Controllers`)**: Accept user input from HTTP requests, coordinate with appropriate services, and select views to render. Controllers contain no raw SQL or heavy business logic.
4. **Services (`App\Services`)**: Encapsulate core business rules, algorithms, and orchestration. The `RecommendationService` is the primary service governing candidate generation, scoring, and diversity reranking.
5. **Repositories (`App\Repositories`)**: Provide an abstraction over database tables. Responsible for constructing parameterized SQL queries and mapping raw database rows to domain entities.
6. **Models / DTOs (`App\Models`, `App\DTO`)**: Represent domain entities (`Book`, `User`, `Author`, `Review`) and structured data transfer objects (`RecommendationResult`, `PersonalizedRecommendationItem`).
7. **Views (`App\Views`)**: Render clean HTML views utilizing native PHP templating with consistent layouts, partials, and components.

---

## 5.3 Module Implementation

### 1. Authentication & Security Subsystem
- **Implementation**: `AuthController`, `Auth` service, `Session`, `CsrfMiddleware`.
- **Mechanics**:
  - Registration captures `name`, `email`, and `password`. Passwords are encrypted using PHP's native `password_hash($password, PASSWORD_BCRYPT)`.
  - Login verifies credentials via `password_verify()`. Upon successful authentication, `session_regenerate_id(true)` is immediately executed to prevent session fixation attacks.
  - State-mutating POST requests require a hidden `_token` field matching the cryptographically secure token stored in the user's session (`CsrfMiddleware`).

### 2. Catalog & Discovery Subsystem
- **Implementation**: `BookController`, `AuthorController`, `CategoryController`, `SearchController`, `BookRepository`.
- **Mechanics**:
  - Supports full-text search across titles, author names, and descriptions using parameterized SQL `LIKE` queries optimized with compiled B-tree indexes.
  - Multi-criteria faceted filtering allows readers to narrow titles by category ID, publication year range, and minimum average rating.
  - Soft-delete pattern (`deleted_at IS NULL`) guarantees that archived titles remain recoverable without compromising relational integrity.

### 3. Personal Library & Shelves Subsystem
- **Implementation**: `LibraryController`, `LibraryService`, `LibraryRepository`.
- **Mechanics**:
  - Provides a three-state reading status workflow: `want_to_read`, `reading`, and `finished`.
  - Tracks reading start/completion timestamps, favorite toggles, and private star ratings.
  - Library state modifications automatically trigger recommendation cache invalidation, ensuring recommendations dynamically reflect recent reading updates.

### 4. Recommendation Engine V2 Subsystem
- **Implementation**: `RecommendationController`, `RecommendationService`, `RecommendationRepository`, `RecommendationScoring`, `RecommendationConfig`.
- **Candidate Generation Architecture (Sources A through H)**:
  - **Source A (Category Affinity)**: Evaluates user's top categories from library shelves and reviews.
  - **Source B (Author Affinity)**: Identifies authors of highly rated books in user's library.
  - **Source C (Wishlist Associations)**: Finds titles frequently co-occurring with user's wishlist saves.
  - **Source D (Rating Preferences)**: Analyzes books with rating patterns matching user's positive reviews.
  - **Source E (Recently Viewed)**: Retrieves related books matching recently inspected title view logs.
  - **Source F (Followed Authors)**: Surfaces newly released or popular books by followed authors.
  - **Source G (Community Engagement)**: Detects titles actively discussed in community threads.
  - **Source H (Popularity Fallback)**: Transparent baseline scoring for cold-start readers based on:
    $$\text{Popularity} = (\frac{\text{Average Rating}}{5} \times 0.50) + (\text{Review Count} \times 0.20) + (\text{Wishlist Count} \times 0.30)$$
- **Maximal Marginal Relevance (MMR) Diversity Reranking**:
  - Prevents genre over-concentration by reordering top candidates to maximize both individual relevance and pairwise genre dissimilarity.
- **Explainability**:
  - Composes human-readable explanation strings (e.g., *"Because you enjoyed Fantasy and Science Fiction books"*) injected directly into the `PersonalizedRecommendationItem` DTO.

### 5. Community & Discussion Subsystem
- **Implementation**: `CommunityController`, `CommunityService`, `CommunityRepository`.
- **Mechanics**:
  - Readers can author discussion posts optionally linked to specific catalog books.
  - Threaded comment hierarchy supports interactive book discussions.
  - Post likes and comment counts are maintained via transactional triggers and atomic SQL updates.

### 6. Administration & API Synchronization Subsystem
- **Implementation**: `AdminController`, `AdminAuthorController`, `GoogleBooksController`, `GoogleBooksService`.
- **Mechanics**:
  - Restricted to authenticated administrators via `AdminMiddleware` (checking `is_admin == 1`).
  - Google Books API client executes queries against `https://www.googleapis.com/books/v1/volumes`, parses volume metadata (ISBN, page counts, descriptions, high-resolution covers), and performs idempotent bulk imports.

---

## 5.4 Coding

BookSphere adheres strictly to professional PHP coding standards (PSR-1, PSR-12):
- **Strict Typing**: All PHP files declare `declare(strict_types=1);` at line 1.
- **Type Hints & Return Types**: Every function and method explicitly declares parameter types and return types.
- **PSR-4 Autoloading**: Namespaces map directly to directory structures under `BookSphere\App\`.
- **Immutability & Encapsulation**: Domain DTOs utilize `readonly` properties to enforce data immutability.

---

## 5.5 Code Validation and Optimization

### 1. Database Indexing Strategy
To ensure lightning-fast lookups across all 31 tables, composite indexes were compiled:
- `idx_books_status_rating` on `books(status, average_rating DESC)`
- `idx_book_authors_author` on `book_authors(author_id, book_id)`
- `idx_book_categories_cat` on `book_categories(category_id, book_id)`
- `idx_user_library_user_status` on `user_library(user_id, status)`
- `idx_recommendation_logs_user` on `recommendation_logs(user_id, shown_at)`

### 2. Query Optimization & EXPLAIN Query Plan
All complex queries in `RecommendationRepository` and `BookRepository` were analyzed using SQLite's `EXPLAIN QUERY PLAN` to ensure index-backed `SEARCH TABLE` operations instead of costly full-table `SCAN TABLE` operations.

### 3. File-Based Caching
Personalized recommendation results are serialized to JSON cache files with configurable Time-To-Live (TTL) windows, drastically minimizing redundant scoring computations on repeated dashboard visits.
