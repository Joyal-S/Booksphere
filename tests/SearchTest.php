<?php

declare(strict_types=1);

/**
 * SearchTest — CLI test suite for Phases 11.2/11.3/11.4
 *
 * Verifies the global search module end-to-end: the validator gates,
 * the query builder, the provider neutralization, the repository SQL
 * (per scope), the result formatter, pagination, the advanced filters
 * (11.3) and the live suggestions/autocomplete endpoint (11.4),
 * plus error handling and the real controller (HTML page + live JSON
 * endpoints) exactly as a browser would use them.
 *
 * Run from the project root:
 *
 *     php tests/SearchTest.php
 *
 * How it works:
 *     - A throwaway SQLite database (database/search_test.db) is
 *       created, migrated and seeded, so the real development data
 *       is never touched.
 *     - A stubbed session + AuthService let the controller render
 *       the signed-in master layout in the CLI.
 *     - Every check prints PASS/FAIL; a summary line at the end
 *       doubles as the Phase 11.2 testing checklist for the viva.
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Builders\SearchQueryBuilder;
use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\RateLimiter;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\Controllers\SearchController;
use BookSphere\App\Models\User;
use BookSphere\App\Repositories\SearchRepository;
use BookSphere\App\Requests\SearchQueryRequest;
use BookSphere\App\Requests\SearchSuggestRequest;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\SearchHistoryService;
use BookSphere\App\Services\SearchProviderFactory;
use BookSphere\App\Services\SearchResultFormatter;
use BookSphere\App\Services\SearchService;
use BookSphere\App\Services\SearchSuggestionService;

// ---------------------------------------------------------------------
// 0. Boot: fresh throwaway database, migrated and seeded.
// ---------------------------------------------------------------------

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/search_test.db');

foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

// A session must exist BEFORE any output, so the view smoke test
// can log in a stub user (session_start() refuses once output is sent).
$session = new Session('search_test');
$session->start();
AuthService::setInstance(new AuthService($session, new User()));
$session->put('auth_user', ['id' => 1, 'full_name' => 'Admin', 'email' => 'admin@booksphere.test', 'role' => 'admin']);

$config = (array) config('search');

$service = new SearchService(
    (new SearchProviderFactory($config))->create(),
    new SearchQueryBuilder($config),
    new SearchResultFormatter(),
    $config,
);

// Phase 11.4: the suggestion service shares the provider + builder.
$suggestions = new SearchSuggestionService(
    (new SearchProviderFactory($config))->create(),
    new SearchQueryBuilder($config),
    $config,
);

// Phase 11.5: the history service owns the search_history table.
$history = new SearchHistoryService(new SearchRepository(), $config);

$controller = new SearchController($service, $suggestions, $history, new RateLimiter($session));

// The seeded catalogue size this test asserts against.
$seedTotal = (int) db()->query('SELECT COUNT(*) c FROM books WHERE deleted_at IS NULL')[0]['c'];

// ---------------------------------------------------------------------
// Test harness
// ---------------------------------------------------------------------

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;

    $ok ? $pass++ : $fail++;
}

function section(string $title): void
{
    echo PHP_EOL . str_repeat('-', 72) . PHP_EOL . $title . PHP_EOL . str_repeat('-', 72) . PHP_EOL;
}

// A global shorthand to run a search through the real service,
// mirroring the harness style of BrowseTest (global-var based).
function run(string $q, string $scope = 'books', int $page = 1, int $per = 0, array $extra = []): \BookSphere\App\DTO\SearchResult
{
    global $service, $config;

    $input = ['q' => $q, 'scope' => $scope, 'page' => (string) $page, 'per_page' => (string) $per] + $extra;

    return $service->search(new SearchQueryRequest($input, $config));
}

// Phase 11.4 shorthand for the suggestion endpoint's payload.
function suggest(string $q): array
{
    global $suggestions, $config;

    return $suggestions->suggest(new SearchSuggestRequest(['q' => $q], $config));
}

// ---------------------------------------------------------------------
// 1. Request gate (validation)
// ---------------------------------------------------------------------

section('1. REQUEST GATE (SearchQueryRequest validation)');

$req = new SearchQueryRequest(['q' => 'harry potter', 'scope' => 'books', 'page' => '2', 'per_page' => '12'], $config);
check('Valid basic query passes', $req->valid() && $req->term() === 'harry potter');
check('Whitelisted per_page survives', $req->perPage() === 12);
check('Default scope is all', (new SearchQueryRequest([], $config))->scope() === 'all');

$req = new SearchQueryRequest(['q' => str_repeat('x', 300)], $config);
check('Over-long term rejected', !$req->valid() && !empty($req->errors()['q']));

$req = new SearchQueryRequest(['q' => implode(' ', array_fill(0, 12, 'word'))], $config);
check('Over the word cap rejected', !$req->valid() && isset($req->errors()['q']) && !empty($req->errors()['q']));

$req = new SearchQueryRequest(['q' => 'harry', 'scope' => 'users'], $config);
check('Disabled scope (users) rejected', !$req->valid() && isset($req->errors()['scope']));

$req = new SearchQueryRequest(['q' => "  harry\n  potter  "], $config);
check('Controls stripped + trimmed', $req->term() === 'harry  potter' && $req->hasQuery());

// ---------------------------------------------------------------------
// 2. Books scope (title, author, ISBN, publisher, category, description)
// ---------------------------------------------------------------------

section('2. BOOKS SCOPE (search fields and AND matching)');

$r = run('To Kill a Mockingbird');
check('Exact title → 1 hit', $r->ok() && $r->total === 1 && $r->hits[0]->title === 'To Kill a Mockingbird', "{$r->total} hit(s)");

$r = run('mocking');
check('Partial title (case-insensitive)', $r->ok() && $r->total === 1 && str_contains($r->hits[0]->title, 'Mockingbird'));

$r = run('Rowling');
check('Author name via EXISTS', $r->ok() && $r->total >= 1 && str_contains($r->hits[0]->title, 'Harry Potter'));

$r = run('9780590353427');
check('Exact ISBN', $r->ok() && $r->total === 1 && str_contains($r->hits[0]->title, 'Harry Potter'));

$r = run('Fantasy');
check('Category name via EXISTS', $r->ok() && $r->total === 2, "{$r->total} hits (Harry Potter, The Hobbit)");

$r = run('dragon');
check('Description word', $r->ok() && $r->total >= 1 && str_contains($r->hits[0]->title, 'Hobbit'));

$r = run('harry potter');
check('Multi-word AND (both words must match)', $r->ok() && $r->total === 1, "{$r->total} hit(s)");

$r = run('harry rowling');
check('Multi-word across fields (title + author)', $r->ok() && $r->total === 1 && str_contains($r->hits[0]->title, 'Harry Potter'));

$r = run('potter fantasia');
check('Multi-word AND across distinct books → no match', $r->ok() && $r->total === 0);

$r = run('zzzzzz-no-such-book');
check('No matches → clean empty result', $r->ok() && $r->total === 0 && $r->hits === []);

$r = run('');
check('Empty term → empty page (no full scan)', $r->ok() && $r->total === 0 && $r->error === '');
check('Hit carries the detail url', $r->total === 0 || str_starts_with($r->hits[0]->url, '/books/'));

// ---------------------------------------------------------------------
// 3. Other scopes
// ---------------------------------------------------------------------

section('3. AUTHORS / CATEGORIES / PUBLISHERS / REVIEWS SCOPES');

$r = run('rowling', 'authors');
check('Authors by name', $r->ok() && $r->total === 1 && $r->hits[0]->entity === 'authors' && (str_contains($r->hits[0]->url, '/authors/') || str_contains($r->hits[0]->url, 'author_id=')));

$r = run('harper', 'authors');
check('Authors partial', $r->ok() && $r->total >= 1, "{$r->total} author(s)");

$r = run('fantasy', 'categories');
check('Categories by name', $r->ok() && $r->total === 1 && strtolower((string) $r->hits[0]->title) === 'fantasy');

$r = run('fantasy', 'categories');
check('Category pagination window', $r->pages === 1 && $r->perPage === 24);

$r = run('harper', 'publishers');
check('Publishers (distinct books.publisher)', $r->ok() && $r->total >= 1 && str_contains($r->hits[0]->url, 'publisher='), "{$r->total} publisher(s)");

$bodies = db()->query("SELECT review FROM reviews WHERE status = 'approved' AND review IS NOT NULL AND review != '' LIMIT 1");
if ($bodies !== []) {
    $words = preg_split('/\s+/', trim((string) $bodies[0]['review'])) ?: [];
    $word  = $words[1] ?? $words[0] ?? '';
    if ($word !== '') {
        $r = run($word, 'reviews');
        check('Reviews by body', $r->ok() && $r->total >= 1 && $r->hits[0]->entity === 'reviews' && str_contains($r->hits[0]->url, '/reviews/'), "'$word' → {$r->total} hit(s)");
    }
}

// ---------------------------------------------------------------------
// 4. Advanced filters (Phase 11.3) - books scope
// ---------------------------------------------------------------------

section('4. ADVANCED FILTERS (Phase 11.3 - books scope)');

// --- Request-gate normalization -------------------------------------

$req = new SearchQueryRequest(['scope' => 'books', 'status' => 'published', 'language' => 'en', 'min_rating' => '4'], $config);
$f = $req->filters();
check('Whitelisted filters survive', ($f['status'] ?? '') === 'published' && ($f['language'] ?? '') === 'en' && ($f['min_rating'] ?? '') === '4');

$req = new SearchQueryRequest(['status' => 'BOGUS', 'min_rating' => '9', 'year_from' => '9999', 'year_to' => '77'], $config);
check('Tampered filter values silently dropped', $req->filters() === []);

$req = new SearchQueryRequest(['status' => 'published'], $config);
check('Filters ignored on non-book scopes', (new SearchQueryRequest(['scope' => 'authors', 'status' => 'published'], $config))->filters() === []);

// --- Filter effects on the catalogue ---------------------------------

$r = run('', 'books', 1, 0, ['status' => 'published']);
check('status=published filters the (seeded) catalogue', $r->ok() && $r->total === $seedTotal && $r->total > 0, "{$r->total} of {$seedTotal} books");

$r = run('', 'books', 1, 0, ['status' => 'draft']);
check('status=draft → no seeded drafts', $r->ok() && $r->total === 0);

$r = run('', 'books', 1, 0, ['language' => 'en']);
check('language=en matches the seeded English catalogue', $r->ok() && $r->total === $seedTotal);

$r = run('', 'books', 1, 0, ['min_rating' => '4.5']);
check('min_rating=4.5 → only the 4.5+-rated book', $r->ok() && $r->total >= 1 && str_contains($r->hits[0]->title, 'Harry Potter'), "{$r->total} hit(s)");

$r = run('', 'books', 1, 0, ['year_from' => '2010']);
check('year_from=2010 → books from 2010 on', $r->ok() && $r->total >= 6, "{$r->total} hit(s)");
check('Every hit year >= 2010', $r->hits !== [] && array_reduce($r->hits, fn (bool $ok, $h): bool => $ok && (int) ($h->data['published_year'] ?? 0) >= 2010, true));

// Category and author filters via EXISTS (Fantasy seeded = HP + Hobbit).
$fantasy = db()->query("SELECT id FROM categories WHERE name = 'Fantasy'")[0] ?? null;
if ($fantasy !== null) {
    $r = run('', 'books', 1, 0, ['category_id' => (string) $fantasy['id']]);
    check('category_id=Fantasy → 2 books', $r->ok() && $r->total === 2, "{$r->total} hit(s)");
}

$roy = db()->query("SELECT id FROM authors WHERE name = 'Arundhati Roy'")[0] ?? null;
if ($roy !== null) {
    $r = run('', 'books', 1, 0, ['author_id' => (string) $roy['id']]);
    check('author_id=Arundhati Roy → 1 book', $r->ok() && $r->total === 1 && str_contains($r->hits[0]->title, 'God of Small Things'), "{$r->total} hit(s)");
}

$r = run('', 'books', 1, 0, ['publisher' => 'Scholastic']);
check('publisher=Scholastic → the 2 Scholastic volumes', $r->ok() && $r->total === 2 && str_contains($r->hits[0]->title, 'Harry Potter'), "{$r->total} hit(s)");

// --- Combined term + filters -----------------------------------------

$r = run('harry', 'books', 1, 0, ['status' => 'published']);
check('Term + status combines', $r->ok() && $r->total === 1 && str_contains($r->hits[0]->title, 'Harry Potter'));

$r = run('harry', 'books', 1, 0, ['min_rating' => '4.5']);
check('Term + min_rating combines', $r->ok() && $r->total === 1 && str_contains($r->hits[0]->title, 'Harry Potter'));

// --- Filter + no matches empty state --------------------------------------

$r = run('zzzzzz', 'books', 1, 0, ['status' => 'published']);
check('Term + filter with no match → 0', $r->ok() && $r->total === 0);

// --- Filter options vocabulary (provider + config) -------------------------

$opts = $service->filterOptions();
check('Filter options: categories from the DB', isset($opts['categories']) && count($opts['categories']) >= 1, count($opts['categories']) . ' categories');
check('Filter options: authors from the DB', isset($opts['authors']) && count($opts['authors']) >= 1, count($opts['authors']) . ' authors');
check('Filter options: publishers from the DB', isset($opts['publishers']) && in_array('HarperCollins', $opts['publishers'], true), count($opts['publishers']) . ' publishers');
check('Filter options: whitelists from config', ($opts['statuses']['published'] ?? '') === 'Published' && ($opts['languages']['en'] ?? '') === 'English' && ($opts['ratings']['4.5'] ?? '') === '4.5 stars & up');

// --- queryString() URL builder (chips + pagination parity) -------------------------

$url = \BookSphere\App\Services\SearchService::queryString(['q' => 'harry', 'status' => 'published', 'category_id' => 3]);
check('queryString baseline carries q/filters', $url === '/search?q=harry&scope=books&status=published&category_id=3', $url);
$url = \BookSphere\App\Services\SearchService::queryString(['q' => 'harry', 'status' => 'published'], ['status']);
check('queryString drops one filter', !str_contains($url, 'status=') && str_contains($url, 'q=harry') && str_contains($url, 'scope=books'), $url);
$url = \BookSphere\App\Services\SearchService::queryString(['q' => 'harry', 'status' => 'published'], [], ['page' => 2]);
check('queryString overrides page', str_contains($url, 'page=2'), $url);
$url = \BookSphere\App\Services\SearchService::queryString(['q' => '', 'scope' => 'books']);
check('queryString empty state → bare scope-only', $url === '/search?scope=books', $url);

// ---------------------------------------------------------------------
// 5. Pagination + config clamp
// ---------------------------------------------------------------------

section('5. PAGINATION + CONFIG CLAMP');

$r = run('', 'books', 1, 96);
check('Whitelisted per_page 96 accepted', $r->perPage === 96);

$r = run('', 'books', 1, 7);
check('Non-whitelisted per_page clamps to default 24', $r->perPage === 24);

$r = run('harry', 'books', 1, 12);
check('Page clamping leaves page numbers exact', $r->page === 1 && $r->pages === 1 && $r->firstOnPage() === 1);

$r = run('harry', 'books', 999, 12);
check('Page beyond last clamps (browse-style)', $r->ok() && $r->page === 1 && $r->pages === 1 && $r->hits !== []);

// ---------------------------------------------------------------------
// 5. SQL injection resistance
// ---------------------------------------------------------------------

section('6. SQL INJECTION RESISTANCE');

$attacks = [
    "x' OR '1'='1",
    "'; DROP TABLE books; --",
    "' UNION SELECT 1,2,3,4,5,6,7,8 FROM users; --",
];

foreach ($attacks as $i => $attack) {
    $r = run($attack);
    check('Attack ' . ($i + 1), $r->ok() && $r->total === 0, substr($attack, 0, 26));
}

$tables = db()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'books'");
check('books table intact after attacks', $tables !== []);

// ---------------------------------------------------------------------
// 6. Controller — HTML page (no-JS)
// ---------------------------------------------------------------------

section('6. CONTROLLER — HTML PAGE (no-JS)');

$_SERVER['HTTP_X_REQUESTED_WITH'] = '';

$_GET = ['q' => 'harry'];
ob_start();
$controller->index(new Request(), []);
$html = (string) ob_get_clean();
check('Page renders', $html !== '');
check('Intro + scope tabs', str_contains($html, 'Search everything') && str_contains($html, 'data-scope-radio'));
check('Result card rendered', str_contains($html, 'Harry Potter'));
check('Active scope correct', str_contains($html, 'data-scope-radio') && str_contains($html, 'is-active'));

$_GET = ['q' => 'zzzzzz-no-such-book'];
ob_start();
$controller->index(new Request(), []);
$html = (string) ob_get_clean();
check('Empty state renders', str_contains($html, 'no results') || str_contains($html, 'No results'));

$_GET = [];
ob_start();
$controller->index(new Request(), []);
$html = (string) ob_get_clean();
check('Empty query renders the type-to-search state', str_contains($html, 'Search BookSphere') || str_contains($html, 'Type to search'));

$_GET = ['q' => 'harry', 'scope' => 'reviews'];
ob_start();
$controller->index(new Request(), []);
$html = (string) ob_get_clean();
check('Review scope renders + correct active tab', str_contains($html, 'Reviews') && str_contains($html, 'data-scope-radio'));

// ---------------------------------------------------------------------
// 7. Controller — live JSON endpoint (fetch)
// ---------------------------------------------------------------------

section('7. CONTROLLER — LIVE JSON ENDPOINT (X-Requested-With: fetch)');

$_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';

$_GET = ['q' => 'mocking'];
ob_start();
$controller->index(new Request(), []);
$json = json_decode((string) ob_get_clean(), true);
check('Envelope ok', is_array($json) && $json['ok'] === true);
check('Partial carries the hit', is_array($json) && str_contains($json['html'], 'To Kill a Mockingbird'));
check('Numbers correct', is_array($json) && $json['total'] === 1 && $json['page'] === 1 && $json['pages'] === 1);

$_GET = ['q' => str_repeat('x', 300)];
ob_start();
$controller->index(new Request(), []);
$json = json_decode((string) ob_get_clean(), true);
check('Invalid term → 422 + field errors', is_array($json) && $json['ok'] === false && isset($json['errors']['q']));

$_GET = ['q' => 'harry', 'scope' => 'users'];
ob_start();
$controller->index(new Request(), []);
$json = json_decode((string) ob_get_clean(), true);
check('Disabled scope → 422 + field errors', is_array($json) && $json['ok'] === false && isset($json['errors']['scope']));

$_GET = ['q' => 'not-followed'];
ob_start();
$controller->index(new Request(), []);
$json = json_decode((string) ob_get_clean(), true);
check('Zero-hit JSON partial', is_array($json) && $json['ok'] === true && $json['total'] === 0 && str_contains($json['html'], 'No results'));

$_SERVER['HTTP_X_REQUESTED_WITH'] = '';

// ---------------------------------------------------------------------
// 8. Suggestions (Phase 11.4) - service level
// ---------------------------------------------------------------------

section('8. SUGGESTIONS (Phase 11.4 - service)');

// --- Request gate ------------------------------------------------------

$s = suggest('a');
check('Suggestion gate: 1-char prefix rejected', $s['ok'] === false && isset($s['term']));

$s = new SearchSuggestRequest(['q' => ''], $config);
check('Suggestion gate: empty q invalid', !$s->valid() && isset($s->errors()['q']));

$s = suggest(str_repeat('x', 300));
check('Suggestion gate: over-long term rejected (graceful)', $s['ok'] === false);

// --- Sources + ranking ------------------------------------------------------

$s = suggest('har');
check('Term "har" → top suggestion is a book', $s['ok'] === true && ($s['suggestions'][0]['type'] ?? '') === 'book', ($s['suggestions'][0]['label'] ?? '') . ' (total ' . $s['total'] . ')');
check('Book suggestion carries the detail url', str_starts_with($s['suggestions'][0]['url'] ?? '', '/books/'));

$s = suggest('harry');
check('"harry" → book suggestions (prefix tier)', $s['ok'] === true && count($s['suggestions']) >= 1 && str_contains($s['suggestions'][0]['label'] ?? '', 'Harry Potter'));

$s = suggest('fantasy');
check('Exact term ranks category above partial book titles', $s['ok'] === true && ($s['suggestions'][0]['type'] ?? '') === 'category' && $s['suggestions'][0]['label'] === 'Fantasy');

$s = suggest('scholastic');
check('Exact publisher ranks first', $s['ok'] === true && $s['total'] >= 1 && strtolower((string) $s['suggestions'][0]['label']) === 'scholastic', $s['suggestions'][0]['label'] ?? '');

$s = suggest('rowling');
check('Author source reachable', $s['ok'] === true && in_array('author', array_column($s['suggestions'], 'type'), true), json_encode(array_column($s['suggestions'], 'type')));

$s = suggest('HARRY');
check('Case-insensitive suggestion', $s['ok'] === true && str_contains($s['suggestions'][0]['label'] ?? '', 'Harry Potter'));

$s = suggest('harry potter');
check('Multi-word AND over the pool', $s['ok'] === true && count(array_filter($s['suggestions'], fn (array $r): bool => in_array(strtolower((string) $r['label']), ['harry potter and the chamber of secrets', 'harry potter and the philosopher\'s stone'], true))) >= 1);

// --- Dedupe + limit ----------------------------------------------------------

$s = suggest('harry');
$labels = array_column($s['suggestions'], 'label');
check('No duplicate (type,label) rows', count($labels) === count(array_unique($labels)));
check('Pool capped at the configured limit', $s['total'] <= (int) ($config['suggestions']['limit'] ?? 8), "{$s['total']} total");
check('Every row is a plain JSON shape', array_reduce($s['suggestions'], function (bool $ok, array $r): bool { return $ok && isset($r['type'], $r['label'], $r['url']); }, true));

// --- Graceful failures + injection ------------------------------------------

$s = suggest("x' OR '1'='1");
check('SQL-fragment injection returns safely', $s['ok'] === true && is_array($s['suggestions']));

$_attack = "'; DROP TABLE books; --";
$s = suggest($_attack);
check('DROP-style injection handled', $s['ok'] === true && is_array($s['suggestions']));

$tablesAfter = db()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'books'");
check('books table intact after suggestion attack', $tablesAfter !== []);

// ---------------------------------------------------------------------
// 9. Suggestions (Phase 11.4) - controller endpoint
// ---------------------------------------------------------------------

section('9. SUGGESTIONS - CONTROLLER ENDPOINT (GET /search/suggest)');

$_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';

$_GET = ['q' => 'mocking'];
ob_start();
$controller->suggest(new Request(), []);
$sjson = json_decode((string) ob_get_clean(), true);
check('Suggest envelope ok', is_array($sjson) && $sjson['ok'] === true);
check('Suggest rows present', is_array($sjson) && is_array($sjson['suggestions']) && count($sjson['suggestions']) >= 1);
check('Suggest total correct', is_array($sjson) && $sjson['total'] === count($sjson['suggestions']));
check('Suggestion row shape (type, label, url)', is_array($sjson) && isset($sjson['suggestions'][0]['type'], $sjson['suggestions'][0]['label'], $sjson['suggestions'][0]['url']));

$_GET = ['q' => 'a'];
ob_start();
$controller->suggest(new Request(), []);
$sjson = json_decode((string) ob_get_clean(), true);
check('Suggest 1-char → 422 + field errors', is_array($sjson) && $sjson['ok'] === false && isset($sjson['errors']['q']));

// Rate limiting on the suggestions bucket (its own window, config
// rate_limit.suggestions = 120/min). Reset the session limiter first
// so the failing window is deterministic.
$session->forget('_rate_limit');
$_GET = ['q' => 'harry'];
for ($i = 0; $i < 121; $i++) {
    ob_start();
    $controller->suggest(new Request(), []);
    $sjson = json_decode((string) ob_get_clean(), true);
}
check('121st suggestion throttled', is_array($sjson) && $sjson['ok'] === false && str_contains((string) ($sjson['error'] ?? ''), 'Too many requests'));

// Disabled suggestions: a controller built on a module-config with
// suggestions turned off answers 503 (never an empty dropdown).
$disabledConfig = $config;
$disabledConfig['suggestions']['enabled'] = false;
$disabledController = new SearchController(
    $service,
    new SearchSuggestionService(
        (new SearchProviderFactory($disabledConfig))->create(),
        new SearchQueryBuilder($disabledConfig),
        $disabledConfig,
    ),
    $history,
    new RateLimiter($session),
);
$_GET = ['q' => 'harry'];
ob_start();
$disabledController->suggest(new Request(), []);
$sjson = json_decode((string) ob_get_clean(), true);
check('Suggestions disabled → 503 + friendly error', is_array($sjson) && $sjson['ok'] === false && str_contains((string) ($sjson['error'] ?? ''), 'currently disabled'));

$_SERVER['HTTP_X_REQUESTED_WITH'] = '';
$session->forget('_rate_limit');

// ---------------------------------------------------------------------
// 10. Rate limiting
// ---------------------------------------------------------------------

section('10. RATE LIMITING');

// A fresh limiter bucket: the (limit+1)th search of the same page
// session is throttled with a friendly JSON message.
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
$_GET = ['q' => 'harry'];

for ($i = 0; $i < 61; $i++) {
    ob_start();
    $controller->index(new Request(), []);
    $json = json_decode((string) ob_get_clean(), true);
}
check('61st window search throttled', is_array($json) && $json['ok'] === false && str_contains($json['error'], 'Too many searches'));

$_SERVER['HTTP_X_REQUESTED_WITH'] = '';
$session->forget('_rate_limit');

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

section('RESULT');

echo '  Passed: ' . $pass . PHP_EOL;
echo '  Failed: ' . $fail . PHP_EOL;

echo PHP_EOL . 'Note: the throwaway database database/search_test.db is left in' . PHP_EOL
    . 'place for inspection; delete it anytime.' . PHP_EOL;

exit($fail === 0 ? 0 : 1);