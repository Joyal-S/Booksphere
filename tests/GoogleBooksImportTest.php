<?php

declare(strict_types=1);

/**
 * GoogleBooksImportTest — CLI test suite for Phase 10.3
 *
 * Verifies the Google Books IMPORT pipeline (single books only):
 *     - GoogleBooksService::volume(): the single-volume lookup that
 *       feeds the import (cache, circuit breaker, typed failures)
 *     - BookImportService: field mapping, find-or-create staging of
 *       authors/categories, the dedupe order (google_book_id ->
 *       ISBN (both forms) -> title+author fallback) and the
 *       all-or-nothing transaction
 *     - the controller's dual answer: JSON for fetch callers, the
 *       redirect + flash for the no-JavaScript form (probed in a
 *       subprocess, because Response::redirect() exits the process)
 *     - idempotency: a repeated import can never create a second row
 *
 *     php tests/GoogleBooksImportTest.php
 *
 * How it works:
 *     - The HTTP transport is a stub (GoogleBooksImportStub) that
 *       answers volume lookups from canned payloads - no network.
 *     - A fresh throwaway database (database/gb_import_test.db) is
 *       migrated and seeded, so find-or-create runs against the real
 *       authors/categories tables.
 *     - The provider cache + circuit breaker write to a throwaway
 *       directory under the system temp folder.
 *     - Every check prints PASS/FAIL + a summary line at the end.
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Logger;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\Controllers\GoogleBooksController;
use BookSphere\App\DTO\ImportResult;
use BookSphere\App\DTO\ProviderBookDTO;
use BookSphere\App\Exceptions\GoogleBooksException;
use BookSphere\App\Models\Author;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\Category;
use BookSphere\App\Models\User;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\BookImportService;
use BookSphere\App\Services\BookService;
use BookSphere\App\Services\CacheManager;
use BookSphere\App\Services\CircuitBreaker;
use BookSphere\App\Services\GoogleBooksClient;
use BookSphere\App\Services\GoogleBooksProvider;
use BookSphere\App\Services\GoogleBooksService;

(new Environment(root_path('.env')))->load();

// A session must exist BEFORE any output, so the controller smoke
// test can log in a stub admin user (session_start() refuses to run
// once output has been sent).
$session = new Session('gb_import_test');
$session->start();
AuthService::setInstance(new AuthService($session, new User()));

// ---------------------------------------------------------------------
// 0. A stubbed HTTP transport: canned single-volume answers.
// ---------------------------------------------------------------------

final class GoogleBooksImportStub extends GoogleBooksClient
{
    public int $calls = 0;

    /** @var array<string, string> canned volumes keyed by volume id */
    public array $volumes = [];

    protected function send(string $url): array
    {
        $this->calls++;

        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('#/volumes/([^/]+)$#', $path, $match)) {
            return $this->volume(urldecode($match[1]));
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                if ($query['q'] ?? null) {
            return $this->searchAnswer();
        }

        return ['status' => 404, 'headers' => [], 'body' => '{}'];
    }

    private function volume(string $id): array
    {
        // A canned single-volume answer can be queued per id (see
        // BBC books in section 1), overriding the built-ins.
        if (isset($this->volumes[$id])) {
            return ['status' => 200, 'headers' => [], 'body' => $this->volumes[$id]];
        }

        if ($id === 'vol-404') {
            return ['status' => 404, 'headers' => [], 'body' => '{}'];
        }

        if ($id === 'vol-titleless') {
            return ['status' => 200, 'headers' => [], 'body' => json_encode([
                'id'         => $id,
                'volumeInfo' => ['title' => '  '],
            ])];
        }

        if ($id === 'vol-fetch') {
            $volume = [
                'id' => $id,
                'volumeInfo' => [
                    'title'      => 'Dune',
                    'subtitle'   => 'The epic of Arrakis',
                    'authors'    => ['Frank Herbert'],
                    'categories' => ['Science Fiction'],
                    'description' => '<p>A <b>spice</b> and sand saga.</p>',
                    'publisher'  => 'Ace Books',
                    'publishedDate' => '1965-08-01',
                    'language'   => 'en',
                    'pageCount'  => 412,
                    'averageRating' => 4.6,
                    'ratingsCount'  => 9876,
                    'industryIdentifiers' => [
                        ['type' => 'ISBN_13', 'identifier' => '9780441172719'],
                    ],
                    'imageLinks' => [
                        'thumbnail' => 'https://books.google.com/books/content?id=' . $id . '&zoom=1',
                    ],
                    'previewLink' => 'https://books.google.com/books?id=' . $id,
                    'infoLink'    => 'https://books.google.com/books?id=' . $id,
                ],
            ];
        } elseif ($id === 'vol-nojs') {
            $volume = [
                'id' => $id,
                'volumeInfo' => [
                    'title'      => 'The Eye of the World',
                    'authors'    => ['Robert Jordan'],
                    'categories' => ['Fantasy'],
                    'publisher'  => 'Tor Books',
                    'publishedDate' => '1990-01-15',
                    'language'   => 'en',
                    'pageCount'  => 800,
                    'industryIdentifiers' => [
                        ['type' => 'ISBN_13', 'identifier' => '9780812511819'],
                    ],
                    'previewLink' => 'https://books.google.com/books?id=' . $id,
                    'infoLink'    => 'https://books.google.com/books?id=' . $id,
                ],
            ];
        } else {
            // Every OTHER volume id answers 404, so the breaker opens
            // the moment three of them are requested in a row.
            return ['status' => 404, 'headers' => [], 'body' => '{}'];
        }

        return ['status' => 200, 'headers' => [], 'body' => json_encode($volume)];
    }

    private function searchAnswer(): array
    {
        return ['status' => 200, 'headers' => [], 'body' => json_encode([
            'totalItems' => 1,
            'items'      => [
                [
                    'id' => 'vol-fetch',
                    'volumeInfo' => [
                        'title'      => 'Dune',
                        'authors'    => ['Frank Herbert'],
                        'categories' => ['Science Fiction'],
                        'publishedDate' => '1965-08-01',
                        'language'   => 'en',
                        'pageCount'  => 412,
                        'industryIdentifiers' => [
                            ['type' => 'ISBN_13', 'identifier' => '9780441172719'],
                        ],
                        'imageLinks' => [
                            'thumbnail' => 'https://books.google.com/books/content?id=vol-fetch&zoom=1',
                        ],
                        'previewLink' => 'https://books.google.com/books?id=vol-fetch',
                        'infoLink'    => 'https://books.google.com/books?id=vol-fetch',
                    ],
                ],
            ],
        ])];
    }
}

// ---------------------------------------------------------------------
// 1. Boot: fresh throwaway database, migrated and seeded.
// ---------------------------------------------------------------------

$dbPath = root_path('database/gb_import_test.db');

foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

// ---------------------------------------------------------------------
// 2. Shared fixtures: provider config, cache + breaker + temp files
// ---------------------------------------------------------------------

$temp = rtrim((string) sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'booksphere_gb_import_test';

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
        'retry_attempts'   => 0,
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
    'import' => ['default_status' => 'published'],
];

$provider = new GoogleBooksProvider($config);
$stub     = new GoogleBooksImportStub($config);

$service = new GoogleBooksService(
    $stub,
    $provider,
    new CacheManager($temp, [CacheManager::NS_SEARCH => 60, CacheManager::NS_VOLUME => 600], true),
    new CircuitBreaker($temp, $config['cache']['circuit_breaker']),
    new Logger($temp . '/test.log'),
    $config,
);

$importer = new BookImportService(new Book(), new Author(), new Category(), $config);
$books    = new Book();

$controller = new GoogleBooksController($service, $importer);

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

/** A raw books row for one google id. */
$row = fn (string $googleId): ?array => db()->query('SELECT * FROM books WHERE google_book_id = ?', [$googleId])[0] ?? null;

/** A fresh ProviderBookDTO with defaults, overridable per field. */
$dto = function (string $externalId, array $overrides = []) use (&$dto): ProviderBookDTO {
    $base = [
        'externalId'     => $externalId,
        'title'          => 'The Fellowship of the Ring',
        'subtitle'       => null,
        'authors'        => ['J.R.R. Tolkien'],
        'categories'     => ['Fiction'],
        'description'    => null,
        'publisher'      => 'Allen & Unwin',
        'publishedDate'  => '1954-07-29',
        'publishedYear'  => 1954,
        'language'       => 'en',
        'pageCount'      => 423,
        'isbn10'         => '0306406152',
        'isbn13'         => '9780306406157',
        'thumbnail'      => 'https://books.google.com/thumb?id=' . $externalId,
        'previewLink'    => 'https://books.google.com/books?id=' . $externalId,
        'infoLink'       => 'https://books.google.com/books?id=' . $externalId,
        'averageRating'  => 4.7,
        'ratingsCount'   => 1234,
        'provider'       => 'google_books',
    ];

    return new ProviderBookDTO(...array_replace($base, $overrides));
};

// ---------------------------------------------------------------------
// 3. Volume lookup (GoogleBooksService::volume)
// ---------------------------------------------------------------------

section('3. VOLUME LOOKUP (single-volume provider fetch)');

$callsBefore = $stub->calls;
$found = $service->volume('vol-fetch');

check('Lookup returns a mapped DTO', $found instanceof ProviderBookDTO && $found->externalId === 'vol-fetch');
check('Lookup mapped the title + authors', $found?->title === 'Dune' && $found?->authors === ['Frank Herbert']);
check('Lookup validated the ISBN', $found?->isbn() === '9780441172719');
check('Lookup hit the provider once', $stub->calls === $callsBefore + 1, "calls: {$stub->calls}");

$callsAfterFirst = $stub->calls;
$service->volume('vol-fetch');

check('A second lookup is served from the volume cache', $stub->calls === $callsAfterFirst, "calls: {$stub->calls}");

try {
    $service->volume('vol-404');
    check('A missing volume throws not_found', false);
} catch (GoogleBooksException $error) {
    check('A missing volume throws not_found', $error->reason() === 'not_found', $error->reason());
}

check('A title-less record maps to null', $service->volume('vol-titleless') === null);
check('An empty id maps to null', $service->volume('   ') === null);

// --- disabled provider ---

$disabledService = new GoogleBooksService(
    new GoogleBooksImportStub($config),
    $provider,
    new CacheManager($temp . '/disabled', [CacheManager::NS_SEARCH => 60, CacheManager::NS_VOLUME => 600], true),
    new CircuitBreaker($temp . '/disabled', $config['cache']['circuit_breaker']),
    new Logger($temp . '/test.log'),
    array_replace($config, ['enabled' => false]),
);

try {
    $disabledService->volume('vol-fetch');
    check('A disabled provider throws unavailable', false);
} catch (GoogleBooksException $error) {
    check('A disabled provider throws unavailable', $error->reason() === 'unavailable', $error->reason());
}

// --- open circuit breaker refuses live calls ---

$breaker = new CircuitBreaker($temp, $config['cache']['circuit_breaker']);
$breaker->reset();

foreach (['f1', 'f2', 'f3'] as $failure) {
    try {
        $service->volume('vol-' . $failure);
    } catch (GoogleBooksException) {
        // each 404 counts as a breaker failure
    }
}

check('Volume failures open the breaker', $breaker->stats()['state'] === 'open', $breaker->stats()['state']);

$before = $stub->calls;

try {
    $service->volume('vol-uncached');
    check('An open breaker refuses the live call', false);
} catch (GoogleBooksException $error) {
    check('An open breaker refuses the live call', $error->reason() === 'unavailable' && $stub->calls === $before, "reason: {$error->reason()}, calls: {$stub->calls}");
}

$breaker->reset();

// ---------------------------------------------------------------------
// 4. Import: field mapping + find-or-create staging
// ---------------------------------------------------------------------

section('4. IMPORT MAPPING (DTO -> books row + relations)');

$result = $importer->import($dto('dto-a', [
    'subtitle'   => 'Being the First Part of The Lord of the Rings',
    'categories' => ['Fiction', 'Fantasy', 'Epic Poetry'],
    'description' => 'An excellent tale of the Ring.',
]));

check('A fresh record imports', $result->status === ImportResult::STATUS_IMPORTED && $result->bookId > 0, $result->status);
check('Import message names the book', str_contains($result->message, 'The Fellowship of the Ring'), $result->message);

$book = $row('dto-a');

check('Title + subtitle stored', $book !== null && $book['title'] === 'The Fellowship of the Ring' && $book['subtitle'] === 'Being the First Part of The Lord of the Rings');
check('Description + publisher stored', $book['description'] === 'An excellent tale of the Ring.' && $book['publisher'] === 'Allen & Unwin');
check('Year + language + pages stored', $book['published_year'] === 1954 && $book['language'] === 'en' && $book['page_count'] === 423);
check('Imported books are published immediately', $book['status'] === 'published');
check('ISBN-13 preferred over ISBN-10', $book['isbn'] === '9780306406157');
check('Cover is the provider thumbnail URL', $book['cover_image'] === 'https://books.google.com/thumb?id=dto-a');
check('Preview link stored', $book['preview_link'] === 'https://books.google.com/books?id=dto-a');
check('Provider rating kept separate from the app rating', $book['provider_rating'] === 4.7 && $book['provider_ratings_count'] === 1234);
check('App-side rating stays untouched at zero', $book['average_rating'] === 0.0 && $book['ratings_count'] === 0);
check('No soft-delete stamp', $book['deleted_at'] === null);

// --- author + category find-or-create ---

$tolkien = (int) db()->query("SELECT id FROM authors WHERE name = 'J.R.R. Tolkien'")[0]['id'];
check('Existing author is reused, not duplicated', db()->query("SELECT COUNT(*) c FROM authors WHERE name = 'J.R.R. Tolkien'")[0]['c'] === 1);

check('New category is created with a slug', db()->query("SELECT COUNT(*) c FROM categories WHERE name = 'Epic Poetry' AND slug = 'epic-poetry'")[0]['c'] === 1);
check('Existing categories are reused', db()->query("SELECT COUNT(*) c FROM categories WHERE name = 'Fiction'")[0]['c'] === 1 && db()->query("SELECT COUNT(*) c FROM categories WHERE name = 'Fantasy'")[0]['c'] === 1);

$links = db()->query('SELECT COUNT(*) c FROM book_authors WHERE book_id = ?', [$result->bookId])[0]['c'];
check('Author junction linked', $links === 1, "authors: {$links}");

$links = db()->query('SELECT COUNT(*) c FROM book_categories WHERE book_id = ?', [$result->bookId])[0]['c'];
check('Category junction linked (3 categories)', $links === 3, "categories: {$links}");

// --- a brand-new author is created ---

$leftHand = $importer->import($dto('dto-new', [
    'title'      => 'The Left Hand of Darkness',
    'authors'    => ['Ursula K. Le Guin'],
    'categories' => ['Science Fiction'],
    'isbn10'     => null,
    'isbn13'     => '9780441007318',
]));

check('New author created by find-or-create', db()->query("SELECT COUNT(*) c FROM authors WHERE name = 'Ursula K. Le Guin'")[0]['c'] === 1);
check('New author linked to the book', db()->query('SELECT COUNT(*) c FROM book_authors ba JOIN authors a ON a.id = ba.author_id WHERE ba.book_id = ? AND a.name = ?', [$leftHand->bookId, 'Ursula K. Le Guin'])[0]['c'] === 1);

// --- the imported book is instantly discoverable ---

$catalogue = (new BookService(new Book(), new Author(), new Category()))->search('Fellowship');
check('Imported book is instantly findable in the catalogue', $catalogue['total'] === 1 && $catalogue['items'][0]['google_book_id'] === 'dto-a');

// ---------------------------------------------------------------------
// 5. Dedupe: google_book_id -> isbn -> title+author fallback
// ---------------------------------------------------------------------

section('5. DEDUPE (google_book_id -> ISBN -> title+author)');

$totalForA = (int) db()->query("SELECT COUNT(*) c FROM books WHERE google_book_id = 'dto-a'")[0]['c'];

$again = $importer->import($dto('dto-a'));
check('Same google_book_id is a duplicate', $again->isDuplicate() && str_contains($again->message, 'already'));
check('A repeated import creates no second row', (int) db()->query("SELECT COUNT(*) c FROM books WHERE google_book_id = 'dto-a'")[0]['c'] === $totalForA);

$isbnDup = $importer->import($dto('dto-b', [
    'title'   => 'The Fellowship of the Ring (Anniversary Edition)',
    'isbn13'  => '9780306406157',
    'isbn10'  => '0306406152',
]));
check('Same ISBN-13 (different google id) is a duplicate', $isbnDup->isDuplicate());

// The record ships ISBN-10 only, but dto-a stored ISBN-13: the
// converted mirror form must still be detected.
$isbn10Dup = $importer->import($dto('dto-c', [
    'title'  => 'Fellowship: the classic text',
    'isbn13' => null,
    'isbn10' => '0306406152',
]));
check('ISBN-10 only still matches the stored ISBN-13 (cross-form)', $isbn10Dup->isDuplicate());

$titleDup = $importer->import($dto('dto-d', [
    'isbn13' => null,
    'isbn10' => null,
]));
check('Title + author fallback catches a matching record', $titleDup->isDuplicate());

// A record WITHOUT authors falls back to a plain title match.
$importer->import($dto('dto-f', [
    'title'   => 'The Silmarillion, Plain Edition',
    'authors' => [],
    'isbn13'  => null,
    'isbn10'  => null,
]));

$titleOnlyDup = $importer->import($dto('dto-e', [
    'title'   => 'THE SILMARILLION, PLAIN EDITION',
    'authors' => [],
    'isbn13'  => null,
    'isbn10'  => null,
]));
check('Title-only fallback is case-insensitive', $titleOnlyDup->isDuplicate());

// A soft-deleted row still blocks a re-import (UNIQUE columns).
$neuromancer = $importer->import($dto('dto-g', [
    'title'      => 'Neuromancer',
    'authors'    => ['William Gibson'],
    'isbn10'     => '0441569595',
    'isbn13'     => '9780441569595',
]));
$books->softDelete($neuromancer->bookId);

$afterDelete = $importer->import($dto('dto-g'));
check('A soft-deleted book is still reported as a duplicate', $afterDelete->isDuplicate());

// ---------------------------------------------------------------------
// 6. Atomicity: a mid-import failure rolls EVERYTHING back
// ---------------------------------------------------------------------

section('6. ATOMICITY (one transaction, all-or-nothing)');

db()->execute("CREATE TEMPORARY TRIGGER fail_import BEFORE INSERT ON book_categories BEGIN SELECT RAISE(ABORT, 'forced import failure'); END;");

$failed = false;

try {
    $importer->import($dto('dto-rollback', [
        'title'      => 'Hyperion',
        'authors'    => ['Dan Simmons'],
        'categories' => ['Space Opera'],
        'isbn10'     => null,
        'isbn13'     => '9780553822939',
    ]));
} catch (\Throwable $error) {
    $failed = true;
}

db()->execute('DROP TRIGGER IF EXISTS fail_import');

check('A failing import throws (no silent half-write)', $failed);
check('The book row is rolled back', $row('dto-rollback') === null);
check('The new author is rolled back', db()->query("SELECT COUNT(*) c FROM authors WHERE name = 'Dan Simmons'")[0]['c'] === 0);
check('The new category is rolled back', db()->query("SELECT COUNT(*) c FROM categories WHERE name = 'Space Opera'")[0]['c'] === 0);
check('No stray junction rows survive', db()->query('SELECT COUNT(*) c FROM book_authors ba JOIN books b ON b.id = ba.book_id WHERE b.google_book_id = ?', ['dto-rollback'])[0]['c'] === 0);

// ---------------------------------------------------------------------
// 7. The card state map
// ---------------------------------------------------------------------

section('7. IMPORTED MAP (card state, one query per page)');

$map = $importer->importedMap([$dto('dto-a'), $dto('dto-b'), new ProviderBookDTO('dto-missing', 'Never imported')]);

check('Map contains only imported records', isset($map['dto-a']) && !isset($map['dto-b']) && !isset($map['dto-missing']));
check('Map values are local book ids', $map['dto-a'] === (int) $row('dto-a')['id']);

// ---------------------------------------------------------------------
// 8. Controller: fetch callers get JSON
// ---------------------------------------------------------------------

section('8. CONTROLLER IMPORT (fetch -> JSON, probed)');

// The CLI SAPI cannot report HTTP statuses in-process once output has
// started (headers_sent() is true after the first check line, so
// Response::json() skips setting the code), so the fetch path is
// probed in a fresh subprocess: import() runs before any output, the
// code is set, and the probe reports [status, body] pairs per case.

$probeRoot = root_path();
$probePath = sys_get_temp_dir() . '/booksphere_gb_import_probe.php';
$probeHead = '<?php' . PHP_EOL
    . 'declare(strict_types=1);' . PHP_EOL . PHP_EOL
    . 'require ' . var_export($probeRoot . '/bootstrap/constants.php', true) . ';' . PHP_EOL
    . 'require ' . var_export($probeRoot . '/vendor/autoload.php', true) . ';' . PHP_EOL . PHP_EOL
    . 'use BookSphere\\App\\Controllers\\GoogleBooksController;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Database;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Environment;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Logger;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Request;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Session;' . PHP_EOL
    . 'use BookSphere\\App\\Models\\Author;' . PHP_EOL
    . 'use BookSphere\\App\\Models\\Book;' . PHP_EOL
    . 'use BookSphere\\App\\Models\\Category;' . PHP_EOL
    . 'use BookSphere\\App\\Models\\User;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\AuthService;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\BookImportService;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\CacheManager;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\CircuitBreaker;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\GoogleBooksClient;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\GoogleBooksProvider;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\GoogleBooksService;' . PHP_EOL . PHP_EOL
    . '(new Environment(root_path(\'.env\')))->load();' . PHP_EOL
    . 'Database::instance(' . var_export($dbPath, true) . ');' . PHP_EOL
    . '$session = new Session(\'gb_import_probe\');' . PHP_EOL
    . '$session->start();' . PHP_EOL
    . '$auth = new AuthService($session, new User());' . PHP_EOL
    . 'AuthService::setInstance($auth);' . PHP_EOL
    . '$config = ' . var_export($config, true) . ';' . PHP_EOL;

// The fetch probe re-uses the same throwaway database (the stub is
// its transport, so nothing touches a network) and reports every
// scenario as [http status, JSON body].
$probeFetch = $probeHead
    . 'final class GbFetchProbeStub extends GoogleBooksClient' . PHP_EOL
    . '{' . PHP_EOL
    . '    protected function send(string $url): array' . PHP_EOL
    . '    {' . PHP_EOL
    . '        $path = (string) parse_url($url, PHP_URL_PATH);' . PHP_EOL
    . '        if (!preg_match(\'#/volumes/([^/]+)$#\', $path, $match)) {' . PHP_EOL
    . '            return [\'status\' => 404, \'headers\' => [], \'body\' => \'{}\'];' . PHP_EOL
    . '        }' . PHP_EOL
    . '        $id = urldecode($match[1]);' . PHP_EOL
    . '        if ($id === \'vol-404\') {' . PHP_EOL
    . '            return [\'status\' => 404, \'headers\' => [], \'body\' => \'{}\'];' . PHP_EOL
    . '        }' . PHP_EOL
    . '        if ($id === \'vol-titleless\') {' . PHP_EOL
    . '            return [\'status\' => 200, \'headers\' => [], \'body\' => json_encode([\'id\' => $id, \'volumeInfo\' => [\'title\' => \'  \']])];' . PHP_EOL
    . '        }' . PHP_EOL
    . '        return [\'status\' => 200, \'headers\' => [], \'body\' => json_encode([' . PHP_EOL
    . '            \'id\' => $id,' . PHP_EOL
    . '            \'volumeInfo\' => [' . PHP_EOL
    . '                \'title\' => \'Dune\',' . PHP_EOL
    . '                \'authors\' => [\'Frank Herbert\'],' . PHP_EOL
    . '                \'industryIdentifiers\' => [[\'type\' => \'ISBN_13\', \'identifier\' => \'9780441172719\']],' . PHP_EOL
    . '            ],' . PHP_EOL
    . '        ])];' . PHP_EOL
    . '    }' . PHP_EOL
    . '}' . PHP_EOL
    . '$probeService = new GoogleBooksService(' . PHP_EOL
    . '    new GbFetchProbeStub($config),' . PHP_EOL
    . '    new GoogleBooksProvider($config),' . PHP_EOL
    . '    new CacheManager(' . var_export($config['cache']['directory'], true) . ', [CacheManager::NS_SEARCH => 60, CacheManager::NS_VOLUME => 600], true),' . PHP_EOL
    . '    new CircuitBreaker(' . var_export($config['cache']['directory'], true) . ', $config[\'cache\'][\'circuit_breaker\']),' . PHP_EOL
    . '    new Logger(' . var_export($temp . '/fetch_probe.log', true) . '),' . PHP_EOL
    . '    $config,' . PHP_EOL
    . ');' . PHP_EOL
    . '$probeController = new GoogleBooksController($probeService, new BookImportService(new Book(), new Author(), new Category(), $config));' . PHP_EOL
    . '$_SERVER[\'HTTP_X_REQUESTED_WITH\'] = \'fetch\';' . PHP_EOL
    . '$cases = [];' . PHP_EOL
    . '$runCase = function (array $post) use ($probeController, &$cases): void {' . PHP_EOL
    . '    $_POST = $post;' . PHP_EOL
    . '    ob_start();' . PHP_EOL
    . '    $probeController->import(new Request(), []);' . PHP_EOL
    . '    $cases[] = [http_response_code(), (string) ob_get_clean()];' . PHP_EOL
    . '};' . PHP_EOL
    . '$runCase([\'google_book_id\' => \'vol-fetch\']);' . PHP_EOL
    . '$runCase([\'google_book_id\' => \'vol-fetch\']);' . PHP_EOL
    . '$runCase([]);' . PHP_EOL
    . '$runCase([\'google_book_id\' => \'vol-404\']);' . PHP_EOL
    . '$runCase([\'google_book_id\' => \'vol-titleless\']);' . PHP_EOL
    . 'echo json_encode($cases);' . PHP_EOL;

file_put_contents($probePath, $probeFetch);
$probeOut = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' fetch 2>&1');
unlink($probePath);

$cases = json_decode($probeOut, true);
$success   = $cases[0] ?? null;
$duplicate = $cases[1] ?? null;
$missing   = $cases[2] ?? null;
$notFound  = $cases[3] ?? null;
$titleless = $cases[4] ?? null;

check('Fetch import answers 200', ($success[0] ?? 0) === 200, 'http: ' . ($success[0] ?? ''));
check('Fetch import reports imported', is_array($success) && ($json = json_decode((string) $success[1], true)) && $json['ok'] === true && $json['status'] === 'imported', (string) ($success[1] ?? ''));
check('Fetch import carries the book id + message', is_array($success) && ($json = json_decode((string) $success[1], true)) && is_numeric($json['bookId'] ?? null) && str_contains((string) ($json['message'] ?? ''), 'Dune'));

// Idempotency: the same POST twice creates exactly one row.
check('The same import twice answers duplicate', is_array($duplicate) && ($duplicate[0] ?? 0) === 200 && ($json = json_decode((string) $duplicate[1], true)) && $json['ok'] === true && $json['status'] === 'duplicate', (string) ($duplicate[1] ?? ''));
check('No second row was created', (int) db()->query("SELECT COUNT(*) c FROM books WHERE google_book_id = 'vol-fetch'")[0]['c'] === 1);

// Validation: missing id.
check('A missing id answers 422', is_array($missing) && ($missing[0] ?? 0) === 422 && ($json = json_decode((string) $missing[1], true)) && isset($json['errors']['google_book_id']), 'http: ' . ($missing[0] ?? ''));

// Provider 404.
check('A 404 volume answers 404 with the not_found reason', is_array($notFound) && ($notFound[0] ?? 0) === 404 && ($json = json_decode((string) $notFound[1], true)) && $json['ok'] === false && $json['reason'] === 'not_found', 'http: ' . ($notFound[0] ?? ''));

// Unmappable record.
check('An unusable record answers 422', is_array($titleless) && ($titleless[0] ?? 0) === 422 && ($json = json_decode((string) $titleless[1], true)) && $json['ok'] === false, 'http: ' . ($titleless[0] ?? ''));

// ---------------------------------------------------------------------
// 9. Controller: no-JS callers get a redirect + flash (subprocess)
// ---------------------------------------------------------------------

section('9. CONTROLLER IMPORT (no-JS -> redirect + flash, probed)');

// Pre-warm the volume cache so the probe never touches a network.
$probeVolume = $service->volume('vol-nojs');
check('Probe volume is served by the stub', $probeVolume?->externalId === 'vol-nojs' && $probeVolume?->isbn() === '9780812511819');

// The no-JS path: the flash lands in the session and no JSON body is
// emitted. headers_list() is empty in the CLI SAPI, so the probe
// proves the flash instead of the Location header.
$probeRedirect = $probeHead
    . '$service = new GoogleBooksService(' . PHP_EOL
    . '    new GoogleBooksClient($config),' . PHP_EOL
    . '    new GoogleBooksProvider($config),' . PHP_EOL
    . '    new CacheManager(' . var_export($config['cache']['directory'], true) . ', [CacheManager::NS_SEARCH => 60, CacheManager::NS_VOLUME => 600], true),' . PHP_EOL
    . '    new CircuitBreaker(' . var_export($config['cache']['directory'], true) . ', $config[\'cache\'][\'circuit_breaker\']),' . PHP_EOL
    . '    new Logger(' . var_export($temp . '/probe.log', true) . '),' . PHP_EOL
    . '    $config,' . PHP_EOL
    . ');' . PHP_EOL
    . '$controller = new GoogleBooksController(' . PHP_EOL
    . '    $service,' . PHP_EOL
    . '    new BookImportService(new Book(), new Author(), new Category(), $config),' . PHP_EOL
    . ');' . PHP_EOL
    . 'register_shutdown_function(function (): void {' . PHP_EOL
    . '    echo (string) session()->getFlash(\'success\', \'\');' . PHP_EOL
    . '});' . PHP_EOL
    . '$_POST = [\'google_book_id\' => \'vol-nojs\'];' . PHP_EOL
    . '$controller->import(new Request(), []);' . PHP_EOL;
file_put_contents($probePath, $probeRedirect);
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' redirect 2>&1');
unlink($probePath);

check('No-JS import flashes the success message', str_contains($out, 'The Eye of the World" was imported into the catalogue'), trim($out));
check('No-JS import wrote the book row', $row('vol-nojs') !== null && $row('vol-nojs')['title'] === 'The Eye of the World');

// ---------------------------------------------------------------------
// 10. The import button in the rendered card
// ---------------------------------------------------------------------

section("10. VIEW (the card's Import button)");

$fragment = (function () use ($service, $importer, $session): string {
    $result = $service->search(['googleQuery' => 'intitle:"dune"', 'query' => 'dune']);

    return \BookSphere\App\Core\View::fragment('admin.google-books.partials._results', [
        'result'   => $result,
        'query'    => 'dune',
        'existing' => $importer->importedMap($result->items),
    ]);
})();

check('Card renders an Import form', str_contains($fragment, 'data-gb-import-form') && str_contains($fragment, '/admin/google-books/import'));
check('Card carries its own CSRF token', str_contains($fragment, 'name="_token"'));
check('Card carries the volume id', str_contains($fragment, 'name="google_book_id"'));
check('Card shows In library for an imported record', str_contains($fragment, 'In library') && str_contains($fragment, 'disabled'));
check('Card feedback region present', str_contains($fragment, 'data-gb-feedback'));

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

echo PHP_EOL . str_repeat('=', 72) . PHP_EOL;
echo '  PASS ' . $pass . PHP_EOL;
echo '  FAIL ' . $fail . PHP_EOL;
echo $fail === 0
    ? 'ALL GREEN - Phase 10.3 Google Books import pipeline verified.'
    : 'FAILURES PRESENT - fix the failing checks before continuing.';
echo PHP_EOL;