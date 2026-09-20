# APPENDIX

## Appendix A: Core Code Samples

The following concise code excerpts demonstrate core architectural patterns, security controls, and algorithmic implementations across BookSphere.

---

### 1. Front Controller & Routing Pipeline (`app/Core/Router.php`)
*Demonstrates route registration, parameter extraction, and middleware pipeline execution.*

```php
<?php

declare(strict_types=1);

namespace BookSphere\App\Core;

class Router
{
    protected array $routes = [];

    public function get(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $handler, $middleware);
    }

    public function post(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    protected function addRoute(string $method, string $path, array $handler, array $middleware): void
    {
        $pattern = preg_replace('/\{([A-Za-z0-9_]+)\}/', '(?P<$1>[^/]+)', $path);
        $this->routes[] = [
            'method'     => $method,
            'path'       => $path,
            'pattern'    => '#^' . $pattern . '$#',
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $uri = parse_url($request->uri(), PHP_URL_PATH);

        foreach ($this->routes as $route) {
            if ($route['method'] === $method && preg_match($route['pattern'], $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Execute middleware pipeline
                foreach ($route['middleware'] as $mw) {
                    $result = $mw->handle($request);
                    if ($result instanceof Response) {
                        return $result;
                    }
                }

                [$controller, $action] = $route['handler'];
                return $controller->$action($request, $params);
            }
        }

        return new Response('404 Not Found', 404);
    }
}
```

---

### 2. CSRF Protection Middleware (`app/Middleware/CsrfMiddleware.php`)
*Demonstrates synchronizer token pattern protecting state-mutating requests.*

```php
<?php

declare(strict_types=1);

namespace BookSphere\App\Middleware;

use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Core\Session;

class CsrfMiddleware
{
    public function handle(Request $request): ?Response
    {
        if (in_array($request->method(), ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            $sessionToken = Session::get('_token');
            $submittedToken = $request->input('_token') ?? $request->header('X-CSRF-TOKEN');

            if (!$sessionToken || !hash_equals($sessionToken, (string)$submittedToken)) {
                return new Response('403 Forbidden: Invalid or missing CSRF token', 403);
            }
        }

        return null;
    }
}
```

---

### 3. Recommendation Scoring Formulas (`app/Services/RecommendationScoring.php`)
*Demonstrates deterministic, parameterized scoring formulas for popularity and trending signals.*

```php
<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

final class RecommendationScoring
{
    public const POPULARITY_WEIGHTS = [
        'rating'   => 0.50,
        'wishlist' => 0.30,
        'review'   => 0.20,
    ];

    public static function popularityScore(float $avgRating, int $wishlistCount, int $reviewCount): float
    {
        $normalizedRating = min(1.0, max(0.0, $avgRating / 5.0));
        return ($normalizedRating * self::POPULARITY_WEIGHTS['rating'])
             + ($wishlistCount   * self::POPULARITY_WEIGHTS['wishlist'])
             + ($reviewCount     * self::POPULARITY_WEIGHTS['review']);
    }

    public static function popularitySql(): string
    {
        return '((books.average_rating / 5.0) * :w_rating) + ' .
               '((SELECT COUNT(*) FROM wishlist WHERE wishlist.book_id = books.id) * :w_wishlist) + ' .
               '((SELECT COUNT(*) FROM reviews WHERE reviews.book_id = books.id) * :w_review)';
    }

    public static function popularityParams(): array
    {
        return [
            ':w_rating'   => self::POPULARITY_WEIGHTS['rating'],
            ':w_wishlist' => self::POPULARITY_WEIGHTS['wishlist'],
            ':w_review'   => self::POPULARITY_WEIGHTS['review'],
        ];
    }
}
```

---

### 4. Maximal Marginal Relevance (MMR) Diversity Reranking (`app/Services/RecommendationService.php`)
*Demonstrates algorithmic diversity reranking to mitigate genre over-concentration.*

```php
<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

class RecommendationService
{
    public function diversityRerank(array $candidates, int $limit = 10, float $lambda = 0.7): array
    {
        if (count($candidates) <= 1) {
            return $candidates;
        }

        $selected = [];
        $unselected = $candidates;

        // Select highest scoring candidate as seed
        $selected[] = array_shift($unselected);

        while (count($selected) < $limit && !empty($unselected)) {
            $bestIdx = null;
            $bestMmr = -INF;

            foreach ($unselected as $idx => $candidate) {
                $relevance = $candidate['score'];
                $maxSimilarity = 0.0;

                foreach ($selected as $chosen) {
                    $sim = $this->calculateGenreSimilarity($candidate, $chosen);
                    if ($sim > $maxSimilarity) {
                        $maxSimilarity = $sim;
                    }
                }

                $mmr = ($lambda * $relevance) - ((1.0 - $lambda) * $maxSimilarity);
                if ($mmr > $bestMmr) {
                    $bestMmr = $mmr;
                    $bestIdx = $idx;
                }
            }

            if ($bestIdx !== null) {
                $selected[] = $unselected[$bestIdx];
                unset($unselected[$bestIdx]);
                $unselected = array_values($unselected);
            } else {
                break;
            }
        }

        return $selected;
    }
}
```

---

### 5. Repository Layer & Parameterized Database Access (`app/Repositories/BookRepository.php`)
*Demonstrates secure data access using PDO prepared statements.*

```php
<?php

declare(strict_types=1);

namespace BookSphere\App\Repositories;

use BookSphere\App\Core\Database;
use PDO;

class BookRepository
{
    protected PDO $db;

    public function __construct()
    {
        $this->db = Database::instance()->pdo();
    }

    public function findById(int $id): ?array
    {
        $sql = 'SELECT b.*, 
                       GROUP_CONCAT(DISTINCT a.name) AS author_names,
                       GROUP_CONCAT(DISTINCT c.name) AS category_names
                FROM books b
                LEFT JOIN book_authors ba ON b.id = ba.book_id
                LEFT JOIN authors a ON ba.author_id = a.id
                LEFT JOIN book_categories bc ON b.id = bc.book_id
                LEFT JOIN categories c ON bc.category_id = c.id
                WHERE b.id = :id AND b.deleted_at IS NULL
                GROUP BY b.id
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
```

---

## Appendix B: Screenshots & Visual Artifacts

The application screens verified during system audits are cataloged in `docs/academic/16_screenshot_checklist.md`.
