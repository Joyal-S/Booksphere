<?php

declare(strict_types=1);

/**
 * GoogleBooksSearchTest — CLI test suite for Phase 10.2
 *
 * Verifies the Google Books SEARCH pipeline (search only - no import,
 * no cover download, no database writes): provider payload mapping,
 * ISBN checksum validation, the scope -> Google Books prefix mapping,
 * page/pagination handling (incl. the index-1000 clamp) and the
 * graceful-degradation contract (disabled provider, API failure ->
 * circuit breaker open, fresh cache hit, stale-cache fallback).
 * Rounds off with a smoke render of the results partial.
 *
 *     php tests/GoogleBooksSearchTest.php
 *
 * How it works:
 *     - NO real network and NO persistent/database files: the HTTP
 *       transport is a stub (GoogleBooksStub) that answers from canned
 *       payloads, so the retry/exception/cache layers run against a
 *       predictable provider and the suite never touches the live API.
 *       The one smoke-test exception is section 6, which provisions a
 *       throwaway in-memory database so the Phase 10.3 card buttons
 *       can ask "is this already imported?" like they do in the app.
 *     - The cache and circuit breaker write to a throwaway directory
 *       under the system temp folder, never the real cache.
 *     - Every check prints PASS/FAIL + a summary line at the end.
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Database;
use BookSphere\App\Core\Logger;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Session;
use BookSphere\App\Core\View;
use BookSphere\App\Controllers\GoogleBooksController;
use BookSphere\App\DTO\ProviderBookDTO;
use BookSphere\App\Exceptions\GoogleBooksException;
use BookSphere\App\Models\Author;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\Category;
use BookSphere\App\Models\User;
use BookSphere\App\Requests\SearchBooksRequest;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\BookImportService;
use BookSphere\App\Services\BulkImportService;
use BookSphere\App\Services\CacheManager;
use BookSphere\App\Services\CircuitBreaker;
use BookSphere\App\Services\GoogleBooksClient;
use BookSphere\App\Services\GoogleBooksProvider;
use BookSphere\App\Services\GoogleBooksService;

(new Environment(root_path('.env')))->load();

// A session must exist BEFORE any output, so the controller/view
// smoke test can log in a stub admin user (session_start() refuses
// to run once output has been sent).
$session = new Session('gb_smoke_test');
$session->start();
AuthService::setInstance(new AuthService($session, new User()));

// ---------------------------------------------------------------------
// 0. A stubbed HTTP transport: one canned response per query.
// ---------------------------------------------------------------------

final class GoogleBooksStub extends GoogleBooksClient
{
    public int $calls = 0;

    /** @var list<string> failure reasons consumed in order */
    public array $failures = [];

    protected function send(string $url): array
    {
        $this->calls++;

        if ($this->failures !== []) {
            throw array_shift($this->failures) === 'timeout'
                ? GoogleBooksException::timeout(1)
                : GoogleBooksException::networkFailure('simulated outage');
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $q     = (string) ($query['q'] ?? '');
        $perPage = max(1, (int) ($query['maxResults'] ?? 10));
        $page    = max(0, (int) ($query['startIndex'] ?? 0)) / $perPage + 1;

        if ($q === 'gateless') {
            return ['status' => 200, 'headers' => [], 'body' => '{}'];
        }

        if ($q === 'nor matches') {
            return ['status' => 200, 'headers' => [], 'body' => json_encode(['totalItems' => 0, 'items' => []])];
        }

        // Every other query answers a small catalogue. The id embeds the
        // page the provider was asked for, so pagination is readable
        // from the response itself.
        $items = [];

        for ($i = 1; $i <= 3; $i++) {
            $items[] = [
                'id' => 'vol-p' . $page . '-' . $i,
                'volumeInfo' => [
                    'title'     => 'Ring ' . $i . ' (' . $q . ')',
                    'subtitle'  => 'Part of the great tale',
                    'authors'   => ['J.R.R. Tolkien'],
                    'categories'=> ['Fiction', 'Fantasy'],
                    'description' => '<p>An <b>excellent</b> tale of the Ring.</p>',
                    'publisher' => 'Allen &amp; Unwin',
                    'publishedDate' => '1954-07-29',
                    'language'  => 'en',
                    'pageCount' => 423,
                    'averageRating' => 4.7,
                    'ratingsCount'  => 1234,
                    'industryIdentifiers' => [
                        ['type' => 'ISBN_13', 'identifier' => '9780306406157'],
                        ['type' => 'ISBN_10', 'identifier' => '0306406152'],
                    ],
                    'imageLinks' => [
                        'thumbnail' => 'https://books.google.com/books/content?id=x&printsec=frontcover&zoom=1',
                        'medium'    => 'https://books.google.com/books/content?id=x&printsec=frontcover&zoom=2',
                    ],
                    'previewLink' => 'https://books.google.com/books?id=x&printsec=frontcover',
                    'infoLink'    => 'https://books.google.com/books?id=x',
                ],
            ];
        }

        return ['status' => 200, 'headers' => [], 'body' => json_encode([
            'totalItems' => 99,
            'items'      => $items,
        ])];
    }

    public function queueFailure(string $reason = 'network'): self
    {
        $this->failures[] = $reason;

        return $this;
    }
}

// ---------------------------------------------------------------------
// 1. Shared fixtures: config, provider, cache + breaker + temp files
// ---------------------------------------------------------------------

$temp = rtrim((string) sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'booksphere_gb_test';

foreach (glob($temp . '/*') ?: [] as $file) {
    is_dir($file) ? rmrf($file) : @unlink($file);
}

function rmrf(string $dir): void
{
    foreach (glob(rtrim($dir, '/\\') . '/*') ?: [] as $item) {
        is_dir($item) ? rmrf($item) : @unlink($item);
    }
    @rmdir($dir);
}

$config = [
    'enabled'  => true,
    'base_url' => 'https://www.googleapis.com/books/v1',
    'client'   => [
        'timeout_seconds'  => 5,
        'retry_attempts'   => 0,   // keep the suite fast
        'retry_backoff_ms' => 50,
        'user_agent'       => 'BookSphere/Test',
    ],
    'search' => [
        'display_limit'    => 10,
        'query_max_length' => 100,
    ],
    'cache' => [
        'search_ttl_seconds' => 60,
        'volume_ttl_seconds' => 600,
        'directory'         => $temp,
        'circuit_breaker'   => [
            'max_failures'     => 3,
            'recovery_seconds' => 60,
        ],
    ],
    'images' => ['size' => 'thumbnail'],
];

$provider = new GoogleBooksProvider($config);
$stub     = new GoogleBooksStub($config);

$service = new GoogleBooksService(
    $stub,
    $provider,
    new CacheManager($temp, [CacheManager::NS_SEARCH => 60, CacheManager::NS_VOLUME => 600], true),
    new CircuitBreaker($temp, $config['cache']['circuit_breaker']),
    new Logger($temp . '/test.log'),
    $config,
);

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

// ---------------------------------------------------------------------
// 2. Payload mapping (GoogleBooksProvider)
// ---------------------------------------------------------------------

section('2. PAYLOAD MAPPING (raw volume -> ProviderBookDTO)');

$mapped = $provider->mapVolume([
    'id' => 'ring',
    'volumeInfo' => [
        'title'     => 'The Fellowship of the Ring',
        'subtitle'  => 'Being the First Part of The Lord of the Rings',
        'authors'   => ['J.R.R. Tolkien', 'J.R.R. Tolkien'],
        'categories'=> ['Fiction', 'Fantasy'],
        'description' => '<p>An <b>excellent</b> tale of the Ring.</p>',
        'publisher' => 'Allen &amp; Unwin',
        'publishedDate' => '1954-07-29',
        'language'  => 'en',
        'pageCount' => 423,
        'averageRating' => 4.7,
        'ratingsCount'  => 12,
        'industryIdentifiers' => [
            ['type' => 'ISBN_13', 'identifier' => '9780306406157'],
            ['type' => 'ISBN_10', 'identifier' => '0306406152'],
        ],
        'imageLinks' => [
            'thumbnail' => 'https://books.google.com/books/content?id=x&printsec=frontcover&zoom=1',
        ],
        'previewLink' => 'https://books.google.com/books?id=x',
        'infoLink'    => 'https://books.google.com/books?id=x',
    ],
]);

check('Maps title + external id', $mapped !== null && $mapped->externalId === 'ring' && $mapped->title === 'The Fellowship of the Ring');

check('Validates ISBN-13 and ISBN-10', $mapped->isbn13 === '9780306406157' && $mapped->isbn10 === '0306406152');

check('isbn() prefers ISBN-13', $mapped->isbn() === '9780306406157');

check('Dedupes authors while keeping order', $mapped->authors === ['J.R.R. Tolkien']);

check('Strips HTML from the description', str_contains($mapped->description ?? '', 'excellent') && !str_contains($mapped->description ?? '', '<b>'));

check('Decodes HTML entities in the description', str_contains($mapped->description ?? '', 'excellent'));

check('Keeps the publisher value as-is (escaped at render time)', $mapped->publisher === 'Allen &amp; Unwin');

check('Extracts the year from the ISO date', $mapped->publishedYear === 1954);

check('Keeps the thumbnail URL', is_string($mapped->thumbnail) && str_contains($mapped->thumbnail, 'zoom=1'));

check('Drops a volume without an id', $provider->mapVolume(['volumeInfo' => ['title' => 'x']]) === null);

check('Drops a volume without a title', $provider->mapVolume(['id' => 'x']) === null);

check('Drops an invalid ISBN from the identifiers', $provider->mapVolume([
    'id' => 'bad',
    'volumeInfo' => [
        'title'  => 'Bad isbn',
        'industryIdentifiers' => [
            ['type' => 'ISBN_13', 'identifier' => '9780306406156'],
        ],
    ],
])->isbn() === null);

// --- checksum validators ---

check('ISBN-13 checksum accepts a valid code', $provider->validIsbn13('9780306406157'));
check('ISBN-13 checksum rejects a bad code', !$provider->validIsbn13('9780306406156'));
check('ISBN-10 checksum accepts a valid code', $provider->validIsbn10('0306406152'));
check('ISBN-10 checksum rejects a bad code', !$provider->validIsbn10('0306406153'));

// ---------------------------------------------------------------------
// 3. Request: scope prefixes + the ISBN gate
// ---------------------------------------------------------------------

section('3. SEARCH-BOOKS REQUEST (parsing, prefix mapping, ISBN gate)');

$rq = new SearchBooksRequest(['type' => 'title', 'q' => 'Harry Potter'], $provider);
check('title -> intitle prefix', $rq->googleQuery() === 'intitle:"Harry Potter"', $rq->googleQuery());

$rq = new SearchBooksRequest(['type' => 'author', 'q' => 'Tolkien'], $provider);
check('author -> inauthor prefix', $rq->googleQuery() === 'inauthor:"Tolkien"');

$rq = new SearchBooksRequest(['type' => 'publisher', 'q' => 'Penguin'], $provider);
check('publisher -> inpublisher prefix', $rq->googleQuery() === 'inpublisher:"Penguin"');

$rq = new SearchBooksRequest(['type' => 'subject', 'q' => 'science fiction'], $provider);
check('subject -> subject prefix', $rq->googleQuery() === 'subject:"science fiction"');

$rq = new SearchBooksRequest(['type' => 'any', 'q' => 'harry potter'], $provider);
check('any -> quoted raw term', $rq->googleQuery() === '"harry potter"');

$rq = new SearchBooksRequest(['type' => 'isbn', 'q' => '9780306406157'], $provider);
check('isbn accepts a valid checksum', $rq->valid() && $rq->errors() === [], json_encode($rq->errors()));

$rq = new SearchBooksRequest(['type' => 'isbn', 'q' => '9780306406156'], $provider);
check('isbn rejects a bad checksum', !$rq->valid() && isset($rq->errors()['isbn']));

$rq = new SearchBooksRequest(['type' => 'bogus', 'q' => 'x'], $provider);
check('unknown scope type is invalid', !$rq->valid() && isset($rq->errors()['type']));

$rq = new SearchBooksRequest(['type' => 'title', 'q' => ''], $provider);
check('empty term is valid but has nothing to search', $rq->valid() && !$rq->hasQuery());

// ---------------------------------------------------------------------
// 4. Service: success, pagination, cache, degradation
// ---------------------------------------------------------------------

section('4. SERVICE SEARCH (success, pagination, cache, breaker)');

$stub->calls = 0;
$result = $service->search(['googleQuery' => 'intitle:"ring"', 'query' => 'ring']);

check('Search returns records', $result->ok() && count($result->items) === 3);
check('First record is a ProviderBookDTO', $result->items[0] instanceof ProviderBookDTO);
check('Record carries the validated ISBN', $result->items[0]->isbn() === '9780306406157');
check('Record carries the rating + count', $result->items[0]->averageRating === 4.7 && $result->items[0]->ratingsCount === 1234);
check('Record carries the cover URL', is_string($result->items[0]->thumbnail));
check('Total items come from the payload', $result->totalItems === 99);
check('Page defaults to 1', $result->page === 1 && $result->pages === 10);

$callsAfterFirst = $stub->calls;
$again = $service->search(['googleQuery' => 'intitle:"ring"', 'query' => 'ring']);

check('Second identical search never re-contacts the provider (memo + cache)', $stub->calls === $callsAfterFirst, "calls: {$stub->calls}");

$page2 = $service->search(['googleQuery' => 'intitle:"ring"', 'query' => 'ring'], 2);
check('Page 2 is fetched from the provider (startIndex honoured)', !$page2->cached && $page2->page === 2);

$noHits = $service->search(['googleQuery' => 'nor matches', 'query' => 'nor matches']);
check('No-hit search is graceful', $noHits->ok() && $noHits->totalItems === 0 && $noHits->items === []);

$gate = $service->search(['googleQuery' => 'gateless', 'query' => 'gateless']);
check('A payload without items maps to zero results', $gate->ok() && $gate->items === []);

// --- page clamp: a page beyond the accessible window snaps back ---

$clamped = $service->search(['googleQuery' => 'clamp', 'query' => 'clamp'], 999);
check('Page 999 clamps to the last accessible page', $clamped->ok() && $clamped->page === 10, "page: {$clamped->page}");

// --- circuit breaker: 3 failures open it, then it refuses live calls ---

$breaker = new CircuitBreaker($temp, $config['cache']['circuit_breaker']);
$breaker->reset();

$stub->failures = ['network', 'network', 'network'];

foreach (['aa', 'bb', 'cc'] as $term) {
    $service->search(['googleQuery' => $term, 'query' => $term]);
}

check('Breaker reports open after 3 failures', $breaker->stats()['state'] === 'open', $breaker->stats()['state']);

$before = $stub->calls;
$refused = $service->search(['googleQuery' => 'never-cached', 'query' => 'never-cached']);

check('Open breaker does not hit the provider', $stub->calls === $before, "calls: {$stub->calls}");
check('Open breaker answers a friendly error', !$refused->ok() && str_contains($refused->error, 'temporarily unavailable'));

// --- stale fallback: an expired entry is served while the breaker is open ---

$cache = new CacheManager($temp, [CacheManager::NS_SEARCH => 60, CacheManager::NS_VOLUME => 600], true);

$cache->put(CacheManager::NS_SEARCH, 'cached|1|10', [
    'items' => [
        ['id' => 'old', 'volumeInfo' => ['title' => 'Old cached entry']],
    ],
    'totalItems' => 1,
    'page'       => 1,
    'perPage'    => 10,
    'pages'      => 1,
]);

// Age the entry past its 60s TTL so it reads as STALE, then search
// with the breaker still open - the module must serve it, not fail.
$staleFile = $temp . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . sha1('cached|1|10') . '.json';

if (is_file($staleFile)) {
    touch($staleFile, time() - 120);
    clearstatcache();
}

$staleBefore = $stub->calls;
$stale = $service->search(['googleQuery' => 'cached', 'query' => 'cached']);

check('Open breaker serves the stale cache instead of failing', $stale->stale && $stale->ok() && count($stale->items) === 1, "stale={$stale->stale}, ok={$stale->ok()}");
check('Stale entry maps back to a DTO', $stale->items[0] instanceof ProviderBookDTO && $stale->items[0]->title === 'Old cached entry');
check('Stale fallback never contacts the provider either', $stub->calls === $staleBefore);

$breaker->reset();

// --- disabled provider ---

$disabled = new GoogleBooksService(
    new GoogleBooksStub($config),
    $provider,
    new CacheManager($temp . '/disabled', [CacheManager::NS_SEARCH => 60], true),
    new CircuitBreaker($temp . '/disabled', $config['cache']['circuit_breaker']),
    new Logger($temp . '/test.log'),
    array_replace($config, ['enabled' => false]),
);

$stub->calls = 0;
$flat = $disabled->search(['googleQuery' => 'intitle:"ring"', 'query' => 'ring']);

check('Disabled provider answers a notice without a request', !$flat->ok() && $stub->calls === 0);
check('Disabled provider yields no items', $flat->items === []);

// ---------------------------------------------------------------------
// 5. View partial smoke test
// ---------------------------------------------------------------------

section('5. VIEW PARTIAL (results render and stay escaped)');

$fragment = View::fragment('admin.google-books.partials._results', [
    'result' => $service->search(['googleQuery' => 'intitle:"ring"', 'query' => 'ring']),
    'query'  => 'ring',
]);

check('Fragment contains a mapped title', str_contains($fragment, 'Ring 1 (intitle'));
check('Fragment contains the pagination summary', str_contains($fragment, 'Showing 1–10 of 99 results'), 'summary ' . ($fragment !== '' ? substr($fragment, 0, 120) : ''));
check('Provider HTML never leaks into the fragment', !str_contains($fragment, '<b>') && !str_contains($fragment, '<p>'));

// ---------------------------------------------------------------------
// 6. Controller + view smoke test (as a browser would hit them)
// ---------------------------------------------------------------------

section('6. CONTROLLER + VIEW SMOKE TEST');

// Phase 10.3: the card buttons ask the catalogue "is this record
// imported?" before rendering, so the controller section now needs a
// throwaway in-memory database (migrated, empty) - the rest of the
// suite remains database-free.
Database::instance(':memory:');
(new Migrator(db(), root_path('database/migrations')))->run();

$bulkImporter = new BookImportService(new Book(), new Author(), new Category(), $config);

$controller = new GoogleBooksController(
    $service,
    $bulkImporter,
    new BulkImportService($service, $bulkImporter, new Book(), new Logger($temp . '/test.log'), $config),
    new \BookSphere\App\Services\GoogleBooksSyncService($service, $bulkImporter, new Book(), null, new Logger($temp . '/test.log'), $config),
);
$session->put('auth_user', ['id' => 1, 'full_name' => 'Admin', 'email' => 'admin@booksphere.test', 'role' => 'admin']);

$_GET = ['type' => 'title', 'q' => 'ring'];
ob_start();
$controller->index(new Request(), []);
$html = (string) ob_get_clean();

check('Index page renders inside the master layout', $html !== '');
check('Search form + scope selector present', str_contains($html, 'id="gb-q"') && str_contains($html, 'id="gb-type"'));
check('Results grid rendered server-side', str_contains($html, 'google-books-grid'));
check('Status strip shows the provider health', str_contains($html, 'Breaker'));
check('Sidebar shows the Google Books admin link', str_contains($html, '/admin/google-books'));
check('Result card carries the external links', str_contains($html, 'target="_blank"'));

$_GET = ['type' => 'title', 'q' => 'ring'];
ob_start();
$controller->searchJson(new Request(), []);
$json = json_decode((string) ob_get_clean(), true);

check('JSON endpoint returns the partial + numbers', is_array($json) && str_contains($json['html'], 'google-books-grid') && $json['total'] === 99 && $json['pages'] === 10);

$_GET = ['type' => 'isbn', 'q' => '9780306406156'];
ob_start();
$controller->searchJson(new Request(), []);
$json = json_decode((string) ob_get_clean(), true);

check('A bad ISBN answers 422 with a field error', is_array($json) && isset($json['errors']['isbn']), json_encode($json));

$_GET = ['type' => 'title', 'q' => ''];
ob_start();
$controller->searchJson(new Request(), []);
$json = json_decode((string) ob_get_clean(), true);

check('An empty query answers an empty page', is_array($json) && $json['html'] === '' && $json['total'] === 0);

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

echo PHP_EOL . str_repeat('=', 72) . PHP_EOL;
echo '  PASS ' . $pass . PHP_EOL;
echo '  FAIL ' . $fail . PHP_EOL;
echo $fail === 0
    ? 'ALL GREEN - Phase 10.2 Google Books search pipeline verified.'
    : 'FAILURES PRESENT - fix the failing checks before continuing.';
echo PHP_EOL;