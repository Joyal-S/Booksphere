<?php

declare(strict_types=1);

/**
 * SearchHistoryTest — CLI test suite for Phase 11.5 (Search History)
 *
 * Verifies the search-history surface end-to-end:
 *
 *     1. Service gate  - enabled() reads the module + history switches;
 *                        only a VALID request with a term is ever stored
 *                        (empty terms, invalid requests and guests are
 *                        refused)
 *     2. Deduplication - a repeated search (even back-to-back, even
 *                        with different letter case) is an UPSERT on the
 *                        (user_id, query, scope, filters) key: ONE row
 *                        whose count bumps, never a duplicate
 *     3. Partition key  - scope and the active filters are part of the
 *                        unique key (different scope or filters = a
 *                        separate row)
 *     4. list()         - newest-use-first, decorated with a RESTORE
 *                        URL that reproduces the search when fetched
 *     5. Storage policy  - the per-user cap (config history.limit) and
 *                        the TTL prune (history.ttl_days) are enforced
 *                        inside record()
 *     6. Ownership      - remove()/clear() are user-scoped: foreign
 *                        rows are untouched, a disabled history writes
 *                        and lists nothing
 *     7. Controller page - the history card renders (restore links +
 *                        delete/clear forms with CSRF + _method=DELETE)
 *                        and the LIVE fetch never records a row
 *     8. Controller writes - deleteHistory/clearHistory answer JSON
 *                        for fetch callers (dual-answer idiom) and a
 *                        flash + redirect for the no-JS form
 *     9. Router         - the DELETE routes dispatch through the
 *                        _method=DELETE override
 *    10. Probes         - the guest AuthMiddleware gate + the no-JS
 *                        flash answer
 *
 * Run from the project root:
 *
 *     php tests/SearchHistoryTest.php
 *
 * The throwaway database (database/search_history_test.db) is
 * migrated, seeded and left in place for inspection; delete it
 * anytime.
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Builders\SearchQueryBuilder;
use BookSphere\App\Controllers\SearchController;
use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\MiddlewarePipeline;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\RateLimiter;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Router;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\Middleware\AuthMiddleware;
use BookSphere\App\Models\User;
use BookSphere\App\Repositories\SearchRepository;
use BookSphere\App\Requests\SearchQueryRequest;
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

$dbPath = root_path('database/search_history_test.db');

foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

// A session must exist before any output (session_start() refuses to
// run once output has been sent).
$session = new Session('search_history_test');
$session->start();
$auth = new AuthService($session, new User());
AuthService::setInstance($auth);

$users  = new User();
$riya   = $users->findByEmail('riya@booksphere.test');
$riyaId  = (int) $riya['id'];
$adminId = (int) $users->findByEmail('admin@booksphere.test')['id'];

$section = fn (string $title): string => "\n------------------------------------------------------------------------\n{$title}\n------------------------------------------------------------------------";
$check   = function (string $label, bool $ok): void {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . PHP_EOL;
    $GLOBALS['failures'] = ($GLOBALS['failures'] ?? 0) + ($ok ? 0 : 1);
    $GLOBALS['checks']   = ($GLOBALS['checks'] ?? 0) + 1;
};
$capture = function (callable $fn): string {
    ob_start();
    $fn();

    return (string) ob_get_clean();
};
$json = function (callable $fn) use ($capture): array {
    $decoded = json_decode($capture($fn), true);

    return is_array($decoded) ? $decoded : [];
};
$failures = 0;
$checks   = 0;

$fetch = function (): void {
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
};
$noFetch = function (): void {
    unset($_SERVER['HTTP_X_REQUESTED_WITH']);
};

// The module stack wired EXACTLY like routes/web.php. The tests act as
// riya (the session user).
$session->put('auth_user_id', $riyaId);
$session->put('auth_user', ['id' => $riyaId, 'full_name' => 'Riya Sharma', 'email' => 'riya@booksphere.test', 'role' => 'user']);

$config = (array) config('search');
$service = new SearchService(
    (new SearchProviderFactory($config))->create(),
    new SearchQueryBuilder($config),
    new SearchResultFormatter(),
    $config,
);
$suggestions = new SearchSuggestionService(
    (new SearchProviderFactory($config))->create(),
    new SearchQueryBuilder($config),
    $config,
);
$history     = new SearchHistoryService(new SearchRepository(), $config);
$controller  = new SearchController($service, $suggestions, $history, new RateLimiter($session));

/**
 * A validated books-scope request for a term (+ extra inputs to vary
 * the scope/filter key), exactly the shape the controller builds.
 */
function historyRequest(string $q, array $extra = []): SearchQueryRequest
{
    global $config;

    $defaultScope = (isset($extra['status']) || isset($extra['category_id']) || isset($extra['author_id']) || isset($extra['publisher']) || isset($extra['language']) || isset($extra['min_rating'])) ? 'books' : 'all';

    $input = array_replace(
        ['q' => $q, 'scope' => $defaultScope, 'page' => '1', 'per_page' => '0'],
        $extra,
    );

    return new SearchQueryRequest($input, $config);
}

/** A small helper to build a history service with custom config. */
function historyWith(array $overrides): SearchHistoryService
{
    global $config;

    $merged = array_replace_recursive($config, ['history' => $overrides]);

    return new SearchHistoryService(new SearchRepository(), $merged);
}
function historyCount(int $userId): int
{
    return (int) db()->query('SELECT COUNT(*) AS c FROM search_history WHERE user_id = ?', [$userId])[0]['c'];
}

/** The distinct stored queries of a user (newest use first). */
function storedQueries(int $userId): array
{
    return array_column(
        db()->query('SELECT query FROM search_history WHERE user_id = ? ORDER BY last_used_at DESC, id DESC', [$userId]),
        'query',
    );
}

// ---------------------------------------------------------------------
// 1. SERVICE GATE: what may be stored
// ---------------------------------------------------------------------

echo $section('1. SERVICE GATE (enabled() + what is allowed to be stored)');

$check('enabled() respects the module + history switches', $history->enabled() === true);

// An empty term is the EMPTY PAGE, never a search -> nothing stored.
$history->record(historyRequest(''), $riyaId);
$check('An empty term is NOT recorded', historyCount($riyaId) === 0);

// Not a valid search -> nothing stored.
$invalid = new SearchQueryRequest(['q' => str_repeat('x', 300), 'scope' => 'books'], $config);
$history->record($invalid, $riyaId);
$check('An invalid request is NOT recorded', historyCount($riyaId) === 0);

// Guests are never stored (user id < 1).
$history->record(historyRequest('harry'), 0);
$check('A guest (user 0) is never recorded', historyCount(0) === 0 && historyCount($riyaId) === 0);

// The disabled-history service refuses to write AND to list.
$off = historyWith(['enabled' => false, 'limit' => 12, 'ttl_days' => 90]);
$off->record(historyRequest('harry'), $riyaId);
$check('A disabled history never writes', historyCount($riyaId) === 0);
$check('A disabled history lists empty', $off->list($riyaId) === []);

// ---------------------------------------------------------------------
// 2. Deduplication: a repeat is an UPSERT, never a duplicate
// ---------------------------------------------------------------------

echo $section('2. DEDUPE: a repeated search is ONE row with a rising count');

$history->record(historyRequest('harry'), $riyaId);
$history->record(historyRequest('harry'), $riyaId);
$history->record(historyRequest('HARRY'), $riyaId);

$rows = db()->query('SELECT query, count FROM search_history WHERE user_id = ?', [$riyaId]);
$check('Three identical searches → ONE row', count($rows) === 1);
$check('The count bumps on every re-run', (int) $rows[0]['count'] === 3);
$check('Back-to-back duplicates cannot exist', historyCount($riyaId) === 1);

// The same term through a DIFFERENT scope or filters is a NEW key.
$history->record(historyRequest('harry', ['scope' => 'authors']), $riyaId);
$history->record(historyRequest('harry', ['status' => 'published']), $riyaId);
$check('Scope + filters are part of the unique key', historyCount($riyaId) === 3);

db()->execute('DELETE FROM search_history');

// ---------------------------------------------------------------------
// 3. list(): order + the decorated restore URL
// ---------------------------------------------------------------------

echo $section('3. list(): ORDER + RESTORE URL DECORATION');

$history->record(historyRequest('prairie'), $riyaId);
usleep(20000);
$history->record(historyRequest('harry', ['status' => 'published']), $riyaId);

$list = $history->list($riyaId);
$check('Newest use first', count($list) === 2 && $list[0]['query'] === 'harry');
$check('A relative "last used" label is present', $list[0]['lastUsedLabel'] !== '');
$check('The restore URL is a searchable /search URL', str_contains($list[0]['url'], '/search?') && str_contains($list[0]['url'], 'q=harry'), $list[0]['url']);

// The restore URL round-trips: fetching it reproduces the stored search.
parse_str((string) parse_url($list[0]['url'], PHP_URL_QUERY), $qs);
$re = $service->search(new SearchQueryRequest($qs, $config));
$check('The decorated URL re-runs the stored search', $re->ok() && $re->total === 1);

// The stored filter(s) are restored as the module's own query keys.
// The newest row is the harry + status=published search.
$restore = $list[0];
$check('A stored filter survives into the URL', str_contains($restore['url'], 'status=published'), $restore['url']);

db()->execute('DELETE FROM search_history');

// ---------------------------------------------------------------------
// 4. STORAGE POLICY: the cap + the TTL prune
// ---------------------------------------------------------------------

echo $section('4. STORAGE POLICY (the per-user cap + the TTL prune)');

// A small cap of 3: four distinct searches keep the three NEWEST.
$tiny = historyWith(['enabled' => true, 'limit' => 3, 'ttl_days' => 90]);
foreach (['one', 'two', 'three', 'four'] as $term) {
    $tiny->record(historyRequest($term), $riyaId);
    usleep(20000);
}

$keep = storedQueries($riyaId);
$check('The cap keeps only the 3 newest', count($keep) === 3);
$check('The oldest (one) was dropped', !in_array('one', $keep, true) && in_array('four', $keep, true), json_encode($keep));

// TTL: a row last used 100 days ago (beyond the 90-day ttl) is pruned
// by the next record through the same service.
db()->execute(
    "UPDATE search_history SET last_used_at = datetime('now', '-100 days') WHERE query = 'two'",
);
$tiny->record(historyRequest('five'), $riyaId);
$check(
    'The expired row is pruned on the next write',
    !in_array('two', storedQueries($riyaId), true) && in_array('five', storedQueries($riyaId), true),
);

db()->execute('DELETE FROM search_history');

// A service with NO ttl (ttl_days 0) never prunes - the sweep is skipped.
$forever = historyWith(['enabled' => true, 'limit' => 12, 'ttl_days' => 0]);
$forever->record(historyRequest('oldie'), $riyaId);
db()->execute(
    "UPDATE search_history SET last_used_at = datetime('now', '-200 days') WHERE query = 'oldie'",
);
$forever->record(historyRequest('keeper'), $riyaId);
$check('ttl_days=0 disables the pruning sweep', count(storedQueries($riyaId)) === 2, json_encode(storedQueries($riyaId)));

db()->execute('DELETE FROM search_history');

// ---------------------------------------------------------------------
// 5. OWNERSHIP: remove() + clear() are user-scoped
// ---------------------------------------------------------------------

echo $section('5. OWNERSHIP (remove() + clear() are the OWNER alone)');

$history->record(historyRequest('mine'), $riyaId);
$history->record(historyRequest('yours'), $adminId);

$own   = db()->query("SELECT id FROM search_history WHERE query = 'mine'")[0]['id'];
$other = db()->query("SELECT id FROM search_history WHERE query = 'yours'")[0]['id'];

$check('remove() by the foreign owner fails', $history->remove((int) $other, $riyaId) === false);
$check('The foreign row is intact', historyCount($adminId) === 1);
$check('remove() deletes the OWNER row', $history->remove((int) $own, $riyaId) === true);
$check('The owner row is gone', historyCount($riyaId) === 0);

$history->record(historyRequest('re-add'), $riyaId);
$check('clear() removes the user rows', $history->clear($riyaId) === 1);
$check('The foreign row survives clear()', historyCount($adminId) === 1);

db()->execute('DELETE FROM search_history');

// ---------------------------------------------------------------------
// 6. Controller page: the history card renders
// ---------------------------------------------------------------------

echo $section('6. CONTROLLER PAGE (the history card)');

$history->record(historyRequest('harry'), $riyaId);
$history->record(historyRequest('dune'), $riyaId);

$noFetch();
$_GET = ['q' => 'harry'];
$html = $capture(fn () => $controller->index(new Request(), []));

$check('The page renders the history card', str_contains($html, 'data-search-history'));
$check('The card lists the saved rows', substr_count($html, 'data-history-search') >= 2);
$check('Restore rows carry q/scope/filters data', str_contains($html, 'data-scope=') && str_contains($html, 'data-filters='));
$check('The delete form posts the _method=DELETE spoof', str_contains($html, 'name="_method" value="DELETE"'));
$check('The CSRF token is embedded', str_contains($html, 'name="_token"'));
$check('The clear-all form targets /search/history', str_contains($html, 'action="/search/history"'));
$check('The confirm modal exists', str_contains($html, 'id="historyConfirmModal"'));

// The LIVE fetch never stores: typing must not spam the history.
$fetch();
$_GET = ['q' => 'mocking'];
$capture(fn () => $controller->index(new Request(), []));
$noFetch();
$check('The live fetch never records a row', historyCount($riyaId) === 2);

// A full-page no-JS submit (a real search) DOES record.
$history->record(historyRequest('harry'), $riyaId);
$_GET = ['q' => 'harry'];
$noFetch();
ob_start();
$controller->index(new Request(), []);
$htmlAfter = (string) ob_get_clean();
$check('The full-page search page still renders before/after record', str_contains($htmlAfter, 'data-search-history'));

$_GET = [];
$noFetch();

// ---------------------------------------------------------------------
// 7. Controller WRITES: deleteHistory / clearHistory
// ---------------------------------------------------------------------

echo $section('7. CONTROLLER WRITES (delete one + clear all)');

db()->execute('DELETE FROM search_history');
$history->record(historyRequest('harry'), $riyaId);
$history->record(historyRequest('dune'), $riyaId);
$freshId = (int) db()->query("SELECT id FROM search_history WHERE query = 'harry'")[0]['id'];

// deleteHistory: the fetch caller gets JSON and the row is removed.
$fetch();
$payload = $json(fn () => $controller->deleteHistory(new Request(), ['id' => (string) $freshId]));
$noFetch();
$check('deleteHistory → {ok, removed}', ($payload['ok'] ?? null) === true && ($payload['removed'] ?? null) === true);
$check('deleteHistory really deleted the row', historyCount($riyaId) === 1);

// A foreign owner row is untouched by a deletion attempt.
$history->record(historyRequest('foreign-owner'), $adminId);
$foreignId = (int) db()->query("SELECT id FROM search_history WHERE query = 'foreign-owner'")[0]['id'];
$fetch();
$payload = $json(fn () => $controller->deleteHistory(new Request(), ['id' => (string) $foreignId]));
$noFetch();
$check('A foreign row is never removed by another user', ($payload['removed'] ?? null) === false);
$check('The foreign row survives', historyCount($adminId) === 1);

// clearHistory(JSON) removes THIS user, never the foreign rows.
$fetch();
$payload = $json(fn () => $controller->clearHistory(new Request()));
$noFetch();
$check('clearHistory → {ok, cleared}', ($payload['ok'] ?? null) === true && isset($payload['cleared']));
$check('Owner cleared, foreign kept', historyCount($riyaId) === 0 && historyCount($adminId) === 1);

// A MISSING row on delete still answers affably (information, not loss).
$fetch();
$payload = $json(fn () => $controller->deleteHistory(new Request(), ['id' => '999999']));
$noFetch();
$check('A missing id answers removed:false (never an error)', ($payload['removed'] ?? null) === false);

$noFetch();

// ---------------------------------------------------------------------
// 8. ROUTER: the DELETE routes + the _method override
// ---------------------------------------------------------------------

echo $section('8. ROUTER (DELETE routes + the _method override)');

$history->record(historyRequest('router-row'), $riyaId);
$rowId = (int) db()->query("SELECT id FROM search_history WHERE query = 'router-row'")[0]['id'];

$dispatch = function (string $uri, array $post = []) use ($controller, $fetch, $noFetch, $json): array {
    $_SERVER['REQUEST_URI']    = $uri;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST                     = $post;
    $fetch();

    $router = new Router(new Request(), new MiddlewarePipeline());
    $router->delete('/search/history', [$controller, 'clearHistory']);
    $router->delete('/search/history/{id}', [$controller, 'deleteHistory']);

    $payload = $json(fn () => $router->dispatch());
    $noFetch();

    return $payload;
};

// The literal /search/history beats the {id} pattern (exact-first).
$payload = $dispatch('/search/history/' . $rowId, ['_method' => 'DELETE']);
$check('DELETE /search/history/{id} dispatches deleteHistory', ($payload['ok'] ?? null) === true && ($payload['id'] ?? null) === $rowId);
$check('The dispatched delete removed the row', historyCount($riyaId) === 0);

$history->record(historyRequest('clear-row'), $riyaId);
$payload = $dispatch('/search/history', ['_method' => 'DELETE']);
$check('DELETE /search/history dispatches clearHistory', ($payload['ok'] ?? null) === true && isset($payload['cleared']));
$check('The dispatched clear emptied riya history', historyCount($riyaId) === 0);

$_GET = [];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
$noFetch();

// ---------------------------------------------------------------------
// 9. PROBES: the no-JS fallback + the guest gate
// ---------------------------------------------------------------------

echo $section('9. PROBES (no-JS flash + AuthMiddleware gate)');

$probeRoot = root_path();
$probePath = sys_get_temp_dir() . '/booksphere_search_history_probe.php';
$probeHead = '<?php' . PHP_EOL
    . 'declare(strict_types=1);' . PHP_EOL . PHP_EOL
    . 'require ' . var_export($probeRoot . '/bootstrap/constants.php', true) . ';' . PHP_EOL
    . 'require ' . var_export($probeRoot . '/vendor/autoload.php', true) . ';' . PHP_EOL . PHP_EOL
    . 'use BookSphere\\App\\Controllers\\SearchController;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Database;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Environment;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\RateLimiter;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Request;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Session;' . PHP_EOL
    . 'use BookSphere\\App\\Builders\\SearchQueryBuilder;' . PHP_EOL
    . 'use BookSphere\\App\\Middleware\\AuthMiddleware;' . PHP_EOL
    . 'use BookSphere\\App\\Models\\User;' . PHP_EOL
    . 'use BookSphere\\App\\Repositories\\SearchRepository;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\AuthService;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\SearchHistoryService;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\SearchProviderFactory;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\SearchResultFormatter;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\SearchService;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\SearchSuggestionService;' . PHP_EOL . PHP_EOL
    . '(new Environment(root_path(\'.env\')))->load();' . PHP_EOL
    . 'Database::instance(' . var_export($dbPath, true) . ');' . PHP_EOL
    . '$session = new Session(\'history_probe\');' . PHP_EOL
    . '$session->start();' . PHP_EOL
    . '$auth = new AuthService($session, new User());' . PHP_EOL
    . 'AuthService::setInstance($auth);' . PHP_EOL
    . '$config = (array) config(\'search\');' . PHP_EOL
    . '$con = new SearchController(' . PHP_EOL
    . '    new SearchService((new SearchProviderFactory($config))->create(), new SearchQueryBuilder($config), new SearchResultFormatter(), $config),' . PHP_EOL
    . '    new SearchSuggestionService((new SearchProviderFactory($config))->create(), new SearchQueryBuilder($config), $config),' . PHP_EOL
    . '    new SearchHistoryService(new SearchRepository(), $config),' . PHP_EOL
    . '    new RateLimiter($session),' . PHP_EOL
    . ');' . PHP_EOL
    . 'register_shutdown_function(function (): void {' . PHP_EOL
    . '    $flash = session()->getFlash(\'success\') ?? session()->getFlash(\'error\') ?? session()->getFlash(\'info\');' . PHP_EOL
    . '    echo $flash === null ? \'NO_FLASH\' : (string) $flash;' . PHP_EOL
    . '});' . PHP_EOL;

// A guest never reaches the history: AuthMiddleware answers the login.
$probeGuest = $probeHead
    . '$guest = true;' . PHP_EOL
    . '(new AuthMiddleware($auth))->handle(new Request(), static function (): string { return "AUTHORIZED"; });' . PHP_EOL;
file_put_contents($probePath, $probeGuest);
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' 2>&1');
unlink($probePath);
$check('A guest hits the login flash, never the history', str_contains($out, 'Please log in') && !str_contains($out, 'AUTHORIZED'));

// Signed-in + no fetch header: deleteHistory answers a flash, not JSON.
$history->record(historyRequest('flash'), $riyaId);
$flashId = (int) db()->query("SELECT id FROM search_history WHERE query = 'flash'")[0]['id'];
$probeNoJS = $probeHead
    . '$session->put(\'auth_user_id\', ' . $riyaId . ');' . PHP_EOL
    . '$session->put(\'auth_user\', [\'id\' => ' . $riyaId . ', \'full_name\' => \'Riya Sharma\', \'role\' => \'user\']);' . PHP_EOL
    . '$con->deleteHistory(new Request(), [\'id\' => ' . $flashId . ']);' . PHP_EOL;
file_put_contents($probePath, $probeNoJS);
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' delete 2>&1');
unlink($probePath);
$check('The no-JS delete answers a flash (no JSON)', str_contains($out, 'search was removed') && !str_contains($out, 'ok'), trim($out));

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

echo $section('RESULT');

echo '  Passed: ' . ($checks - $failures) . PHP_EOL;
echo '  Failed: ' . $failures . PHP_EOL;

echo PHP_EOL . 'Note: the throwaway database database/search_history_test.db is left in' . PHP_EOL
    . 'place for inspection; delete it anytime.' . PHP_EOL;

exit($failures === 0 ? 0 : 1);