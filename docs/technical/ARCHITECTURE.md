# BookSphere — Technical Architecture Specification

## 1. Architectural Overview

BookSphere is engineered as a clean, layered Model-View-Controller (MVC) web application in pure PHP 8.2+. The application enforces a strict separation of concerns across presentation, routing, HTTP middleware, controllers, domain services, repositories, and persistence.

```text
+-------------------------------------------------------------------------------+
|                      LAYERED ARCHITECTURAL PIPELINE                           |
+-------------------------------------------------------------------------------+

  [Client Browser]
        |
        | HTTP Request (GET/POST)
        v
  [Web Server] (Nginx / Apache / PHP CLI Built-in Server)
        |
        v
  [public/index.php] (Front Controller)
        |
        +---> Bootstrap (constants.php, vendor/autoload.php, Environment, Config)
        |
        v
  [App\Core\Router] (Fast Pattern Matching Dispatcher)
        |
        +---> [App\Middleware] Pipeline:
        |       - AuthMiddleware (Verifies active session & redirects)
        |       - AdminMiddleware (Enforces is_admin == 1 authorization)
        |       - CsrfMiddleware (Validates synchronizer tokens on state mutations)
        |
        v
  [App\Controllers] (HTTP Request Handling & View Selection)
        |
        +---> [App\Services] (Domain Business Logic & Algorithms)
        |       - RecommendationService & RecommendationScoring (V2 Engine)
        |       - CommunityService (Discussions & Social Signals)
        |       - GoogleBooksService (External API Sync)
        |
        +---> [App\Repositories] (Data Access Layer & Parameterized SQL)
        |       - BookRepository, RecommendationRepository, etc.
        |
        v
  [App\Core\Database] (PDO SQLite Driver)
        |
        v
  [database/booksphere.db] (Relational SQLite Store with WAL Mode)
```

---

## 2. Layer Specifications

### 2.1 Front Controller (`public/index.php`)
- Initializes application constants via `bootstrap/constants.php`.
- Loads PSR-4 class autoloading via `vendor/autoload.php`.
- Instantiates `BookSphere\App\Core\Environment` to load environment variables from `.env`.
- Loads subsystem configuration arrays from `config/` via `BookSphere\App\Core\Config`.
- Constructs the `Router`, registers route definitions from `routes/web.php`, and dispatches the incoming `Request`.

### 2.2 Routing & Middleware Layer
- **Router (`App\Core\Router`)**: Resolves dynamic parameter patterns (e.g., `/books/{id}`) using compiled regular expressions.
- **Middleware Pipeline**:
  - `CsrfMiddleware`: Intercepts POST/PUT/DELETE requests and verifies the `_token` parameter against `Session::get('_token')`.
  - `AuthMiddleware`: Guards protected user routes (`/library`, `/wishlist`, `/profile`), redirecting unauthenticated users to `/login`.
  - `AdminMiddleware`: Enforces administrative privilege checks, returning `403 Forbidden` if `is_admin !== 1`.

### 2.3 Controllers (`App\Controllers`)
- Extends standard controller patterns, accepting incoming `Request` objects and returning `Response` instances.
- Contain zero raw SQL queries; all data operations are delegated to Repositories and Services.

### 2.4 Services (`App\Services`)
- Encapsulate complex domain logic. Primary services include:
  - `RecommendationService`: Governs candidate generation, hybrid scoring, and MMR diversity reranking.
  - `CommunityService`: Handles discussion creation, moderation, and like/comment tracking.
  - `GoogleBooksService`: Handles external API calls, volume JSON parsing, and metadata synchronization.

### 2.5 Repositories (`App\Repositories`)
- Provide an abstraction over SQLite database tables.
- Execute 100% of queries through PDO prepared statements, guaranteeing complete immunity against SQL injection.
- Expose typed, domain-specific query methods (`findById`, `popularBooks`, `userLibraryBooks`).

### 2.6 Persistence Layer
- SQLite 3 database (`database/booksphere.db`) operating in Write-Ahead Logging (`WAL`) mode with `foreign_keys = ON`.
