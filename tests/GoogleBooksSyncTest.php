<?php

declare(strict_types=1);

/**
 * GoogleBooksSyncTest — CLI test suite for Phase 10.6
 *
 * Verifies the Google Books METADATA SYNCHRONIZER:
 *     - GoogleBooksSyncService: single / bulk / sync-all runs, the field
 *       by-field change detection (ZERO writes when nothing changed), the
 *       provider metadata mapping shared with the importer, relation
 *       replacement (authors/categories) only when the name lists moved
 *     - Conflict resolution: the app-owned columns (average_rating,
 *       ratings_count, status, isbn, google_book_id) are NEVER written by
 *       a sync, and config sync.fields can disable individual fields
 *     - Cover synchronization: the service consults CoverDownloadService
 *       ONLY when the provider URL moved or the book has no usable cover -
 *       an unchanged URL with a fresh status answers ZERO downloads
 *     - Error handling: a provider 404 / network failure / unusable
 *       record fails only ITS book - the run keeps going; ids without a
 *       local imported book are SKIPPED without ever fetching; a run told
 *       to cancel reports the remainder as skipped
 *     - SyncReport: the aggregate counts, the export-ready toArray()
 *       shape and the one-line summary
 *     - The controller's dual answer exactly like the bulk importer:
 *       Server-Sent Events for fetch callers (progress + summary), the
 *       flash + redirect for the no-JavaScript forms (probed in
 *       subprocesses, because Response::redirect() exits the process)
 *
 *     php tests/GoogleBooksSyncTest.php
 *
 * How it works:
 *     - The HTTP transport is a stub (GoogleSyncStub written to a file
 *       in the temp dir, so subprocess probes share the class) that
 *       answers volume lookups from canned volumes - no network.
 *     - The response cache is DISABLED for the run, so the synchronizer
 *       always observes the stub's CURRENT payload - mutating a canned
 *       volume between an import and a sync simulates "Google changed
 *       the metadata" deterministically.
 *     - Cover downloads are faked by a SyncCoverStub that records WHO
 *       the sync calls and mirrors the real status/stamp bookkeeping;
 *       the download/validation pipeline itself is already covered by
 *       GoogleBooksCoverTest, so this suite owns the ADJUDICATION.
 *     - A fresh throwaway database (database/gb_sync_test.db) is
 *       migrated and seeded, so the lookup + change detection run
 *       against the real tables.
 *     - Every check prints PASS/FAIL + a summary line at the end.
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Logger;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\Controllers\GoogleBooksController;
use BookSphere\App\DTO\ProviderBookDTO;
use BookSphere\App\DTO\SyncReport;
use BookSphere\App\Models\Author;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\Category;
use BookSphere\App\Models\User;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\BookImportService;
use BookSphere\App\Services\BulkImportService;
use BookSphere\App\Services\CacheManager;
use BookSphere\App\Services\CircuitBreaker;
use BookSphere\App\Services\CoverDownloadService;
use BookSphere\App\Services\GoogleBooksProvider;
use BookSphere\App\Services\GoogleBooksService;
use BookSphere\App\Services\GoogleBooksSyncService;
use BookSphere\App\Services\MediaService;

(new Environment(root_path('.env')))->load();

// A session must exist BEFORE any output, so the controller smoke tests
// can log in a stub admin user.
$session = new Session('gb_sync_test');
$session->start();
AuthService::setInstance(new AuthService($session, new User()));

// ---------------------------------------------------------------------
// 0. Boot: fresh throwaway database, migrated and seeded.
// ---------------------------------------------------------------------

$dbPath      = root_path('database/gb_sync_test.db');
$probeDbPath = root_path('database/gb_sync_probe.db');

foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm', $probeDbPath, $probeDbPath . '-wal', $probeDbPath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

// The seeder's GB001...GB020 are PLACEHOLDER google ids (see the seed
// file) - not volumes this stub answers. Clear them so "sync all" is
// deterministic over the books this test imports, exactly like a real
// deployment where the placeholder records were never Google imports.
db()->execute("UPDATE books SET google_book_id = NULL WHERE google_book_id LIKE 'GB%'");

$temp = rtrim((string) sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'booksphere_gb_sync_test';

$rmrf = function (string $dir) use (&$rmrf): void {
    foreach (glob(rtrim($dir, '/\\') . '/*') ?: [] as $item) {
        is_dir($item) ? $rmrf($item) : @unlink($item);
    }
    @rmdir($dir);
};

if (!is_dir($temp)) {
    mkdir($temp, 0777, true);
}

if (is_dir($temp)) {
    foreach (glob($temp . '/*') ?: [] as $file) {
        is_dir($file) ? $rmrf($file) : @unlink($file);
    }
}

// ---------------------------------------------------------------------
// 1. Shared provider config + the PASS/FAIL harness
// ---------------------------------------------------------------------

$clientConfig = ['timeout_seconds' => 5, 'retry_attempts' => 0];

$config = [
    'enabled'  => true,
    'base_url' => 'https://www.googleapis.com/books/v1',
    'client'   => $clientConfig,
    'search'   => ['display_limit' => 10, 'query_max_length' => 100],
    'cache'    => [
        'search_ttl_seconds' => 60,
        'volume_ttl_seconds' => 600,
        'directory'         => $temp,
        'circuit_breaker'   => ['max_failures' => 3, 'recovery_seconds' => 60],
    ],
    'images' => ['size' => 'thumbnail'],
    'import' => ['default_status' => 'published'],
    'bulk'   => ['max_batch' => 200, 'batch_size' => 40],
    'covers' => [
        'enabled'          => true,
        'directory'        => $temp . '/covers',
        'public_prefix'    => '/assets/covers/google/',
        'ttl_seconds'      => 0,
        'timeout_seconds'  => 5,
        'retry_attempts'   => 0,
        'retry_backoff_ms' => 50,
        'max_redirects'    => 5,
        'max_bytes'        => 5 * 1024 * 1024,
        'optimize'         => ['enabled' => false],
    ],
    'sync'   => [
        'enabled'    => true,
        'max_batch'  => 200,
        'batch_size' => 25,
        'fields'     => [
            'title'                  => true,
            'subtitle'               => true,
            'description'            => true,
            'publisher'              => true,
            'published_year'         => true,
            'language'               => true,
            'page_count'             => true,
            'preview_link'           => true,
            'provider_rating'        => true,
            'provider_ratings_count' => true,
            'authors'                => true,
            'categories'             => true,
            'cover'                  => true,
        ],
    ],
];

$pass = 0;
$fail = 0;

$check = function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$section = function (string $title): void {
    echo PHP_EOL . str_repeat('-', 72) . PHP_EOL . $title . PHP_EOL . str_repeat('-', 72) . PHP_EOL;
};

/** A VALID ISBN-13 for a test book (checksum computed, so the provider
 *  mapper always accepts it). */
$isbn13 = function (int $n): string {
    $body = '978' . str_pad((string) (10000000 + $n), 9, '0', STR_PAD_LEFT);
    $sum  = 0;

    for ($i = 0; $i < 12; $i++) {
        $sum += (int) $body[$i] * ($i % 2 === 0 ? 1 : 3);
    }

    return $body . ((10 - ($sum % 10)) % 10);
};

/** A fully-formed Google Books volume payload for one id. */
$volume = static fn (string $id, int $n): array => [
    'id' => $id,
    'volumeInfo' => [
        'title'      => 'Sync Book ' . $n,
        'authors'    => ['Sync Author ' . $n],
        'categories' => ['Sync Fiction'],
        'description' => 'A description for sync book ' . $n . '.',
        'publisher'  => 'Sync Press',
        'publishedDate' => '2020-01-01',
        'language'   => 'en',
        'pageCount'  => 300,
        'industryIdentifiers' => [
            ['type' => 'ISBN_13', 'identifier' => $isbn13($n)],
        ],
        'averageRating' => 4.5,
        'ratingsCount'  => 120 + $n,
        'imageLinks' => [
            'thumbnail' => 'https://books.google.com/publisher/content/images/front/' . $id . '?zoom=1',
        ],
        'previewLink' => 'https://books.google.com/books?id=' . $id,
        'infoLink'    => 'https://books.google.com/books?id=' . $id,
    ],
];

$row = fn (string $googleId): ?array => db()->query('SELECT * FROM books WHERE google_book_id = ?', [$googleId])[0] ?? null;

$authorNames = static function (int $bookId): array {
    return array_values(array_map(
        static fn (array $a): string => (string) $a['name'],
        (new Book())->authorsFor($bookId),
    ));
};

$categoryNames = static function (int $bookId): array {
    return array_values(array_map(
        static fn (array $c): string => (string) $c['name'],
        (new Book())->categoriesFor($bookId),
    ));
};

// ---------------------------------------------------------------------
// 2. The stubbed transport + the in-process cover adapter
// ---------------------------------------------------------------------

$probeStubFile = $temp . DIRECTORY_SEPARATOR . 'sync_probe_stub.php';

file_put_contents($probeStubFile, '<?php' . PHP_EOL
    . 'use BookSphere\App\Exceptions\GoogleBooksException;' . PHP_EOL
    . 'use BookSphere\App\Services\GoogleBooksClient;' . PHP_EOL
    . 'final class GoogleSyncStub extends GoogleBooksClient' . PHP_EOL
    . '{' . PHP_EOL
    . '    public int $calls = 0;' . PHP_EOL
    . '    public array $volumes = [];' . PHP_EOL
    . '    public array $networkFail = [];' . PHP_EOL
    . '    protected function send(string $url): array' . PHP_EOL
    . '    {' . PHP_EOL
    . '        $this->calls++;' . PHP_EOL
    . '        $path = (string) parse_url($url, PHP_URL_PATH);' . PHP_EOL
    . '        if (!preg_match(\'#/volumes/([^/]+)$#\', $path, $match)) {' . PHP_EOL
    . '            return [\'status\' => 404, \'headers\' => [], \'body\' => \'{}\'];' . PHP_EOL
    . '        }' . PHP_EOL
    . '        $id = urldecode($match[1]);' . PHP_EOL
    . '        if (isset($this->networkFail[$id])) {' . PHP_EOL
    . '            throw GoogleBooksException::networkFailure(\'simulated network outage\');' . PHP_EOL
    . '        }' . PHP_EOL
    . '        if (!isset($this->volumes[$id])) {' . PHP_EOL
    . '            return [\'status\' => 404, \'headers\' => [], \'body\' => \'{}\'];' . PHP_EOL
    . '        }' . PHP_EOL
    . '        return [\'status\' => 200, \'headers\' => [], \'body\' => json_encode($this->volumes[$id])];' . PHP_EOL
    . '    }' . PHP_EOL
    . '}' . PHP_EOL);

require_once $probeStubFile;

/**
 * The in-process cover adapter: records the calls the synchronizer makes
 * and mirrors the real pipeline's bookkeeping WITHOUT touching the
 * network. The adjudication this suite proves lives entirely in
 * GoogleBooksSyncService::syncCover(); the download pipeline itself is
 * already covered by GoogleBooksCoverTest.
 */
final class SyncCoverAdapter extends CoverDownloadService
{
    public int $calls = 0;
    public string $result = 'downloaded';

    public function __construct(private readonly Book $bookModel, array $config = [])
    {
        parent::__construct($bookModel, new MediaService(), $config);
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function attach(string $bookId, ?string $sourceUrl): string
    {
        $this->calls++;

        $url = trim((string) $sourceUrl);

        if ($this->result === 'downloaded' && $url !== '') {
            $this->bookModel->updateCover((int) $bookId, [
                'cover_image'         => '/assets/covers/google/' . sha1($url) . '.jpg',
                'cover_source_url'    => $url,
                'cover_downloaded_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'cover_status'        => CoverDownloadService::STATUS_DOWNLOADED,
            ]);

            return CoverDownloadService::STATUS_DOWNLOADED;
        }

        if ($url !== '') {
            $this->bookModel->updateCover((int) $bookId, [
                'cover_image'      => null,
                'cover_source_url' => $url,
                'cover_status'     => CoverDownloadService::STATUS_FAILED,
            ]);
        }

        return CoverDownloadService::STATUS_FAILED;
    }
}

// ---------------------------------------------------------------------
// 3. The production graph: volume service + importer + synchronizer.
//    The response cache is DISABLED so every volume() call hits the
//    stub's CURRENT payload (deterministic change detection).
// ---------------------------------------------------------------------

$stub = new GoogleSyncStub($config);

$stub->volumes = [
    'sync-01'    => $volume('sync-01', 1),
    'sync-02'    => $volume('sync-02', 2),
    'sync-03'    => $volume('sync-03', 3),
    'sync-11'    => $volume('sync-11', 11),
    'sync-12'    => $volume('sync-12', 12),
    'sync-13'    => $volume('sync-13', 13),
    'sync-c1'    => $volume('sync-c1', 21),
    'sync-c2'    => $volume('sync-c2', 22),
    'sync-net'   => $volume('sync-net', 31),
    'sync-null'  => $volume('sync-null', 32),
];

$service = new GoogleBooksService(
    $stub,
    new GoogleBooksProvider($config),
    new CacheManager($temp, [CacheManager::NS_SEARCH => 60, CacheManager::NS_VOLUME => 600], false),
    new CircuitBreaker($temp, $config['cache']['circuit_breaker']),
    new Logger($temp . '/test.log'),
    $config,
);

$importer = new BookImportService(new Book(), new Author(), new Category(), $config);
$books    = new Book();

$cover = new SyncCoverAdapter(new Book(), $config);

$sync = new GoogleBooksSyncService(
    $service,
    $importer,
    $books,
    $cover,
    new Logger($temp . '/test.log'),
    $config,
);

/** Import one provider id into the local catalogue (returns books.id). */
$importOne = function (string $id) use ($service, $importer): int {
    $record = $service->volume($id);

    if (!$record instanceof ProviderBookDTO) {
        throw new RuntimeException('Test stub could not map ' . $id);
    }

    $result = $importer->import($record);

    if (!is_numeric($result->bookId)) {
        throw new RuntimeException('Test book ' . $id . ' was not imported.');
    }

    return (int) $result->bookId;
};

/** Mark a book as already having a live cached cover. */
$attachCover = static function (int $bookId, string $url) use ($books): void {
    $books->updateCover($bookId, [
        'cover_image'         => '/assets/covers/google/' . sha1($url) . '.jpg',
        'cover_source_url'    => $url,
        'cover_downloaded_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'cover_status'        => CoverDownloadService::STATUS_DOWNLOADED,
    ]);
};

$stub->calls = 0;

// ---------------------------------------------------------------------
// 4. The feature switch (Task 1) + batch ceilings (Task 9)
// ---------------------------------------------------------------------

$section('4. ENABLE SWITCH + BATCH CEILINGS');

$check('The synchronizer reports itself enabled', $sync->isEnabled());
$check('maxBatch honours the config ceiling', $sync->maxBatch() === $config['sync']['max_batch'], (string) $sync->maxBatch());
$check('batchSize honours the reporting cadence', $sync->batchSize() === $config['sync']['batch_size'], (string) $sync->batchSize());

// ---------------------------------------------------------------------
// 5. NO-OP: an imported book whose provider data did not change
// ---------------------------------------------------------------------

$section('5. CHANGE DETECTION - NOTHING CHANGED = ZERO WRITES');

$bookId1 = $importOne('sync-01');
$attachCover($bookId1, 'https://books.google.com/publisher/content/images/front/sync-01?zoom=1');
$before = $row('sync-01');
$callsBefore = $stub->calls;

$report = $sync->sync(['sync-01']);

$check('Identical metadata answers: 1 checked, 0 updated', $report->updated === 0 && $report->unchanged === 1 && $report->checked === 1 && $report->total === 1 && $report->failed === 0, 'u=' . $report->updated . ' c=' . $report->unchanged);
$check('The outcome status is unchanged', ($report->results[0]['status'] ?? '') === SyncReport::STATUS_UNCHANGED, (string) ($report->results[0]['status'] ?? ''));
$check('Zero changed fields are reported', ($report->results[0]['changes'] ?? 1) === 0, 'changes: ' . ($report->results[0]['changes'] ?? ''));
$check('changedFields aggregates to zero', $report->changedFields === 0);
$check('The cover was not re-attached', $report->coversUpdated === 0);
$check('The provider was asked exactly once', $stub->calls === $callsBefore + 1, 'calls: ' . $stub->calls);

$after = $row('sync-01');
$check('No metadata column was written', $before['title'] === $after['title'] && $before['page_count'] === $after['page_count']);
$check('updated_at did not move (no DB write)', $before['updated_at'] === $after['updated_at'], ($before['updated_at'] ?? 'null') . ' vs ' . ($after['updated_at'] ?? 'null'));
$check('The row stamps in_sync', $after['sync_status'] === GoogleBooksSyncService::STATUS_IN_SYNC, (string) ($after['sync_status'] ?? ''));
$check('synced_at was stamped', $after['synced_at'] !== null);

// ---------------------------------------------------------------------
// 6. A SINGLE changed field - only it gets written
// ---------------------------------------------------------------------

$section('6. CHANGE DETECTION - A SINGLE CHANGED FIELD');

$stub->volumes['sync-01']['volumeInfo']['title'] = 'Sync Book 1 - Revised Edition';
$report = $sync->sync(['sync-01']);

$check('The run reports an update', $report->updated === 1 && $report->unchanged === 0, 'u=' . $report->updated);
$check('Exactly one metadata field changed', $report->changedFields === 1, 'cf=' . $report->changedFields);
$check('The outcome says updated + one change', ($report->results[0]['status'] ?? '') === SyncReport::STATUS_UPDATED && ($report->results[0]['changes'] ?? 0) === 1);
$after = $row('sync-01');
$check('The title column was written', $after['title'] === 'Sync Book 1 - Revised Edition', (string) $after['title']);
$check('No other column was touched', $after['page_count'] === $before['page_count'] && $after['publisher'] === $before['publisher']);
$check('The row stamps updated', $after['sync_status'] === GoogleBooksSyncService::STATUS_UPDATED, (string) ($after['sync_status'] ?? ''));

$beforeUpdatedAt = $after['updated_at'];
$again = $sync->sync(['sync-01']);
$againRow = $row('sync-01');
$check('A re-sync of identical data is a clean no-op', $again->updated === 0 && $again->unchanged === 1);
$check('updated_at is not rewritten by the no-op run', $againRow['updated_at'] === $beforeUpdatedAt);

// ---------------------------------------------------------------------
// 7. CONFLICTS: app-owned / admin-managed fields are never overwritten
// ---------------------------------------------------------------------

$section('7. CONFLICT RESOLUTION - PROTECTED FIELDS SURVIVE');

$importOne('sync-02');

// App-accumulated activity + a manual admin edit of status.
db()->execute(
    'UPDATE books SET average_rating = ?, ratings_count = ?, status = ? WHERE id = ?',
    [4.2, 7, 'draft', (int) $row('sync-02')['id']],
);

$stub->volumes['sync-02']['volumeInfo']['averageRating'] = 5.0;
$stub->volumes['sync-02']['volumeInfo']['ratingsCount']  = 999;
$stub->volumes['sync-02']['volumeInfo']['publisher']     = 'Sync Press - Second Edition';
$stub->volumes['sync-02']['volumeInfo']['industryIdentifiers'] = [
    ['type' => 'ISBN_13', 'identifier' => $isbn13(999)],
];

$sync->sync(['sync-02']);

$after = $row('sync-02');
$check('books.average_rating survives (app-derived)', (float) $after['average_rating'] === 4.2, (string) $after['average_rating']);
$check('books.ratings_count survives (app-derived)', (int) $after['ratings_count'] === 7, (string) $after['ratings_count']);
$check('books.status survives (admin-managed)', $after['status'] === 'draft', (string) $after['status']);
$check('books.isbn survives (never a sync column)', $after['isbn'] !== null && $after['isbn'] !== $isbn13(999), (string) $after['isbn']);
$check('The provider rating column DID sync', (float) $after['provider_rating'] === 5.0 && (int) $after['provider_ratings_count'] === 999);

$reviewsBefore = (int) db()->query('SELECT COUNT(*) c FROM reviews')[0]['c'];
$wishlistBefore = (int) db()->query('SELECT COUNT(*) c FROM wishlist')[0]['c'];
$sync->sync(['sync-01', 'sync-02']);
$check('The reviews table is stable across a run', (int) db()->query('SELECT COUNT(*) c FROM reviews')[0]['c'] === $reviewsBefore);
$check('The wishlist table is stable across a run', (int) db()->query('SELECT COUNT(*) c FROM wishlist')[0]['c'] === $wishlistBefore);

// ---------------------------------------------------------------------
// 8. RELATIONS: authors/categories replaced only when the lists changed
// ---------------------------------------------------------------------

$section('8. RELATIONS - AUTHORS / CATEGORIES');

$bookId = (int) ($row('sync-03')['id'] ?? 0);
$bookId = $bookId !== 0 ? $bookId : $importOne('sync-03');
$attachCover($bookId, 'https://books.google.com/publisher/content/images/front/sync-03?zoom=1');

$checkedNames = $authorNames($bookId);
$check('Import attached the author', $checkedNames === ['Sync Author 3'], implode(',', $checkedNames));

$stub->volumes['sync-03']['volumeInfo']['authors']    = ['Sync Author 3', 'Sync Co-Author'];
$stub->volumes['sync-03']['volumeInfo']['categories'] = ['Sync Fiction', 'Sync Sci-Fi'];

$sync->sync(['sync-03']);

$check('The changed author list was replaced in order', $authorNames($bookId) === ['Sync Author 3', 'Sync Co-Author'], implode(',', $authorNames($bookId)));
$check('The changed category list was replaced in order', $categoryNames($bookId) === ['Sync Fiction', 'Sync Sci-Fi'], implode(',', $categoryNames($bookId)));

$countBefore = (int) db()->query('SELECT COUNT(*) c FROM book_authors WHERE book_id = ' . $bookId)[0]['c'];
$sync->sync(['sync-03']);
$check('An unchanged author list is NOT rewritten', (int) db()->query('SELECT COUNT(*) c FROM book_authors WHERE book_id = ' . $bookId)[0]['c'] === $countBefore);

// ---------------------------------------------------------------------
// 9. CONFIGURABLE RULES (Task 6): a disabled field is never written
// ---------------------------------------------------------------------

$section('9. CONFIGURABLE SYNC RULES - FIELD DISABLED');

$disabled = $config;
$disabled['sync']['fields']['title'] = false;

$syncDisabled = new GoogleBooksSyncService(
    $service,
    $importer,
    $books,
    null,
    new Logger($temp . '/test.log'),
    $disabled,
);

$stub->volumes['sync-02']['volumeInfo']['title']     = 'Another New Title';
$stub->volumes['sync-02']['volumeInfo']['pageCount'] = 411;

$report = $syncDisabled->sync(['sync-02']);

$check('The disabled field (title) is not updated', ($row('sync-02')['title'] ?? '') !== 'Another New Title', (string) $row('sync-02')['title']);
$check('The enabled field still updates', (int) $row('sync-02')['page_count'] === 411, 'page_count: ' . (string) $row('sync-02')['page_count']);
$check('The run still reports its one update', $report->updated === 1, 'u=' . $report->updated);

// ---------------------------------------------------------------------
// 10. COVER SYNC (Task 7) - call adjudication around the cover service
// ---------------------------------------------------------------------

$section('10. COVER SYNCHRONIZATION');

$importOne('sync-c1');
$importOne('sync-c2');

$cover->result = 'downloaded';
$cover->calls  = 0;

// Freshly imported books have no cover pipeline state -> attach.
$callsBefore = $stub->calls;
$report = $sync->sync(['sync-c1', 'sync-c2', 'not-imported']);
$check('Fresh-import books get a cover call', $cover->calls === 2, 'calls: ' . $cover->calls);
$check('The run reports the cover updates', $report->coversUpdated === 2, 'cu=' . $report->coversUpdated);
$check('The cover outcome was recorded', ($report->results[0]['cover'] ?? false) === true);
$check('The run fetched exactly the two real books', $stub->calls === $callsBefore + 2, 'calls: ' . $stub->calls);
$check('A non-imported id is skipped with zero cover work', ($report->results[2]['status'] ?? '') === SyncReport::STATUS_SKIPPED && ($report->results[2]['cover'] ?? false) === false);

// An unchanged URL with a fresh copy answers ZERO downloads.
$cover->calls  = 0;
$report = $sync->sync(['sync-c1', 'sync-c2']);
$check('An unchanged cover answers ZERO downloads', $cover->calls === 0, 'calls: ' . $cover->calls);
$check('The run is a clean no-op', $report->coversUpdated === 0 && $report->updated === 0);

// A NEW provider URL triggers exactly one re-attach.
$stub->volumes['sync-c1']['volumeInfo']['imageLinks']['thumbnail'] = 'https://books.google.com/publisher/content/images/front/sync-c1-v2?zoom=1';
$cover->calls  = 0;
$report = $sync->sync(['sync-c1']);
$check('A moved URL re-attaches the cover', $cover->calls === 1 && ($report->results[0]['cover'] ?? false) === true, 'calls: ' . $cover->calls);

// A failing download degrades without marking the book updated.
$stub->volumes['sync-c2']['volumeInfo']['imageLinks']['thumbnail'] = 'https://books.google.com/publisher/content/images/front/sync-c2-v2?zoom=1';
$cover->result = 'failed';
$cover->calls  = 0;
$report = $sync->sync(['sync-c2']);
$check('A failed attach is not counted as a cover update', $report->coversUpdated === 0 && ($report->results[0]['cover'] ?? true) === false);
$check('A failed cover does not crash the run', $report->failed === 0 && ($report->results[0]['status'] ?? '') === SyncReport::STATUS_UNCHANGED);

// Without an injected cover service the cover field is a silent no-op.
$noCovers = new GoogleBooksSyncService($service, $importer, $books, null, new Logger($temp . '/test.log'), $config);
$report = $noCovers->sync(['sync-c2']);
$check('No cover service = no covers, no crash', $report->coversUpdated === 0 && $report->failed === 0 && $report->unchanged === 1);

// ---------------------------------------------------------------------
// 11. ERROR HANDLING - one failure never aborts the run
// ---------------------------------------------------------------------

$section('11. FAILURE ISOLATION');

// A genuine provider 404 (the volume left Google's catalogue): only
// that book fails - typed not_found - and its own rows stamp the
// failure so the admin sees it on the card.
$stub->volumes['sync-gone'] = $volume('sync-gone', 41);
$importOne('sync-gone');
unset($stub->volumes['sync-gone']);

$gone = $sync->sync(['sync-gone']);
$check('A provider 404 fails only that book', $gone->total === 1 && $gone->checked === 1 && $gone->failed === 1 && $gone->skipped === 0, 't=' . $gone->total . ' f=' . $gone->failed);
$check('The 404 carries the typed not_found reason', ($gone->results[0]['reason'] ?? '') === 'not_found', (string) ($gone->results[0]['reason'] ?? ''));
$check('The failed book records sync_status failed', ($row('sync-gone')['sync_status'] ?? '') === 'failed', (string) ($row('sync-gone')['sync_status'] ?? ''));
$check('The failed book records a sync timestamp', ($row('sync-gone')['synced_at'] ?? null) !== null);

$stub->volumes['sync-03']['volumeInfo']['publisher'] = 'Sync Press Deluxe';

$callsBefore = $stub->calls;
$report = $sync->sync(['sync-01', 'sync-404', 'sync-03', 'sync-not-there']);
$check('404s + missing rows never abort a run', $report->failed === 0 && $report->skipped === 2 && $report->updated === 1 && $report->unchanged === 1, 'f=' . $report->failed . ' s=' . $report->skipped . ' u=' . $report->updated . ' c=' . $report->unchanged);
$check('Skipped rows keep the run report green', $report->ok());

$byId = [];
foreach ($report->results as $entry) {
    $byId[(string) $entry['id']] = $entry;
}
$check('A non-imported reprocessed id is skipped', ($byId['sync-404']['status'] ?? '') === SyncReport::STATUS_SKIPPED && ($byId['sync-404']['reason'] ?? '') === 'not_imported', (string) ($byId['sync-404']['reason'] ?? ''));
$check('A totally missing id carries not_imported', ($byId['sync-not-there']['reason'] ?? '') === 'not_imported', (string) ($byId['sync-not-there']['reason'] ?? ''));
$check('Skipped ids are never fetched from the provider', $stub->calls === $callsBefore + 2, 'calls: ' . $stub->calls);
$check('A never-imported id never spawns a row', $row('sync-404') === null);

// The deleted provider record is removed from the library too, so the
// later "sync all" run only touches live records.
db()->execute('DELETE FROM books WHERE google_book_id = ?', ['sync-gone']);

// A network-level failure (the transport throws) fails the book.
$importOne('sync-net');
$stub->networkFail['sync-net'] = true;
$report = $sync->sync(['sync-net']);
$check('A network outage fails with the typed reason', ($report->results[0]['reason'] ?? '') === 'network', (string) ($report->results[0]['reason'] ?? ''));
$check('The failed book stamps failed', ($row('sync-net')['sync_status'] ?? '') === 'failed', (string) ($row('sync-net')['sync_status'] ?? ''));
unset($stub->networkFail['sync-net']);

// A record losing its usable identity (title) between runs.
$importOne('sync-null');
$stub->volumes['sync-null']['volumeInfo']['title'] = '';
$report = $sync->sync(['sync-null']);
$check('A titleless record fails as invalid_record', ($report->results[0]['status'] ?? '') === SyncReport::STATUS_FAILED && ($report->results[0]['reason'] ?? '') === 'invalid_record', (string) ($report->results[0]['reason'] ?? ''));
$check('An invalid record also stamps failed', ($row('sync-null')['sync_status'] ?? '') === 'failed', (string) ($row('sync-null')['sync_status'] ?? ''));
$stub->volumes['sync-null']['volumeInfo']['title'] = 'Sync Book 32';

// ---------------------------------------------------------------------
// 12. CANCELLATION + syncAll
// ---------------------------------------------------------------------

$section('12. CANCELLATION + SYNC ALL');

$importOne('sync-11');
$importOne('sync-12');
$importOne('sync-13');

$cover->result = 'downloaded';
$cover->calls  = 0;
$progress = 0;
$snapshots = [];
$callsBefore = $stub->calls;

$cancelled = $sync->sync(
    ['sync-11', 'sync-12', 'sync-13'],
    function (array $snapshot) use (&$progress, &$snapshots): bool {
        $progress++;
        $snapshots[] = $snapshot;

        return $progress < 2;
    },
);

$check('A cancelled run reports the remainder skipped', $cancelled->total === 3 && count($cancelled->results) === 2 && $cancelled->skipped === 1, 't=' . $cancelled->total . ' r=' . count($cancelled->results) . ' s=' . $cancelled->skipped);
$check('Only the attempted books made provider calls', $stub->calls === $callsBefore + 2, 'calls: ' . $stub->calls);
$check('Progress snapshots carry the running state', ($snapshots[0]['processed'] ?? 0) === 1 && ($snapshots[0]['remaining'] ?? 0) === 2, json_encode($snapshots[0] ?? []));

$importedCount = count((new Book())->importedBooks());
$all = $sync->syncAll();
$check('syncAll covers every imported book', $all->checked === $importedCount && $all->total === $importedCount, 'c=' . $all->checked . ' t=' . $all->total);
$check('syncAll runs the same per-book pipeline without failures', $all->failed === 0);

// ---------------------------------------------------------------------
// 13. REPORT SHAPE (Task 11)
// ---------------------------------------------------------------------

$section('13. REPORT SHAPE + SUMMARY LINE');

$clean = $sync->sync(['sync-01']);
$keys = $clean->toArray();
$check('toArray() has the export fields', array_key_exists('total', $keys) && array_key_exists('checked', $keys) && array_key_exists('updated', $keys) && array_key_exists('unchanged', $keys) && array_key_exists('failed', $keys) && array_key_exists('skipped', $keys) && array_key_exists('covers_updated', $keys) && array_key_exists('changed_fields', $keys) && array_key_exists('elapsed_seconds', $keys) && array_key_exists('status', $keys) && array_key_exists('results', $keys));
$check('A clean run says ok', ($keys['status'] ?? '') === 'ok', (string) ($keys['status'] ?? ''));
$check('Elapsed seconds is a number', is_float($keys['elapsed_seconds'] ?? null) || is_int($keys['elapsed_seconds'] ?? null));

$summary = $clean->summary();
$check('The one-line summary tells the story', str_contains($summary, 'Sync finished:') && str_contains($summary, '1 checked'), $summary);

$stub->networkFail['sync-net'] = true;
$failedSummary = $sync->sync(['sync-net'])->summary();
$stub->networkFail['sync-net'] = false;
$check('The failure summary names the failed book', str_contains($failedSummary, '1 failed'), $failedSummary);

$map = $sync->syncMap(['sync-01']);
$entry = $map['sync-01'] ?? null;
$check('syncMap resolves the local state of an imported id', is_array($entry) && ($entry['book_id'] ?? 0) > 0 && ($entry['sync_status'] ?? '') === GoogleBooksSyncService::STATUS_IN_SYNC);

// ---------------------------------------------------------------------
// 14. CONTROLLER (probed in subprocesses) - the fetch protocol
// ---------------------------------------------------------------------

$section('14. CONTROLLER: SINGLE + BULK (fetch, probed)');

$probeVolumes = [
    'sync-01' => $volume('sync-01', 1),
    'sync-02' => $volume('sync-02', 2),
    'sync-11' => $volume('sync-11', 11),
    'sync-12' => $volume('sync-12', 12),
];

$probeHead = '<?php' . PHP_EOL . 'declare(strict_types=1);' . PHP_EOL
    . 'require ' . var_export(root_path('bootstrap/constants.php'), true) . ';' . PHP_EOL
    . 'require ' . var_export(root_path('vendor/autoload.php'), true) . ';' . PHP_EOL
    . 'require ' . var_export($probeStubFile, true) . ';' . PHP_EOL
    . 'use BookSphere\App\Core\Database;' . PHP_EOL
    . 'use BookSphere\App\Core\Environment;' . PHP_EOL
    . 'use BookSphere\App\Core\Logger;' . PHP_EOL
    . 'use BookSphere\App\Core\Migrator;' . PHP_EOL
    . 'use BookSphere\App\Core\Request;' . PHP_EOL
    . 'use BookSphere\App\Core\Session;' . PHP_EOL
    . 'use BookSphere\App\Controllers\GoogleBooksController;' . PHP_EOL
    . 'use BookSphere\App\Models\Author;' . PHP_EOL
    . 'use BookSphere\App\Models\Book;' . PHP_EOL
    . 'use BookSphere\App\Models\Category;' . PHP_EOL
    . 'use BookSphere\App\Models\User;' . PHP_EOL
    . 'use BookSphere\App\Services\AuthService;' . PHP_EOL
    . 'use BookSphere\App\Services\BookImportService;' . PHP_EOL
    . 'use BookSphere\App\Services\BulkImportService;' . PHP_EOL
    . 'use BookSphere\App\Services\CacheManager;' . PHP_EOL
    . 'use BookSphere\App\Services\CircuitBreaker;' . PHP_EOL
    . 'use BookSphere\App\Services\GoogleBooksProvider;' . PHP_EOL
    . 'use BookSphere\App\Services\GoogleBooksService;' . PHP_EOL
    . 'use BookSphere\App\Services\GoogleBooksSyncService;' . PHP_EOL . PHP_EOL
    . '(new Environment(root_path(\'.env\')))->load();' . PHP_EOL
    . 'Database::instance(root_path(\'database/gb_sync_probe.db\'));' . PHP_EOL
    . '(new Migrator(db(), root_path(\'database/migrations\')))->run();' . PHP_EOL
    . '$session = new Session(\'gb_sync_probe\');' . PHP_EOL
    . '$session->start();' . PHP_EOL
    . 'AuthService::setInstance(new AuthService($session, new User()));' . PHP_EOL
    . '$config = ' . var_export($config, true) . ';' . PHP_EOL
    . '$config[\'cache\'][\'directory\'] = ' . var_export($temp . DIRECTORY_SEPARATOR . 'probe_cache', true) . ';' . PHP_EOL
    . '$probeStub = new GoogleSyncStub($config);' . PHP_EOL
    . '$probeStub->volumes = ' . var_export($probeVolumes, true) . ';' . PHP_EOL
    . '$probeService = new GoogleBooksService(' . PHP_EOL
    . '    $probeStub,' . PHP_EOL
    . '    new GoogleBooksProvider($config),' . PHP_EOL
    . '    new CacheManager($config[\'cache\'][\'directory\'], [CacheManager::NS_SEARCH => 60, CacheManager::NS_VOLUME => 600], false),' . PHP_EOL
    . '    new CircuitBreaker($config[\'cache\'][\'directory\'], $config[\'cache\'][\'circuit_breaker\']),' . PHP_EOL
    . '    new Logger($config[\'cache\'][\'directory\'] . \'/probe.log\'),' . PHP_EOL
    . '    $config,' . PHP_EOL
    . ');' . PHP_EOL
    . '$probeImporter = new BookImportService(new Book(), new Author(), new Category(), $config);' . PHP_EOL
    . '$probeBulk = new BulkImportService($probeService, $probeImporter, new Book(), new Logger($config[\'cache\'][\'directory\'] . \'/probe.log\'), $config);' . PHP_EOL
    . '$probeSync = new GoogleBooksSyncService($probeService, $probeImporter, new Book(), null, new Logger($config[\'cache\'][\'directory\'] . \'/probe.log\'), $config);' . PHP_EOL
    . '$probeController = new GoogleBooksController($probeService, $probeImporter, $probeBulk, $probeSync);' . PHP_EOL;

$probePath = $temp . DIRECTORY_SEPARATOR . 'sync_probe_run.php';
$probeCache = $temp . DIRECTORY_SEPARATOR . 'probe_cache';

$rmCache = function (string $dir) use (&$rmrf): void {
    if (is_dir($dir)) {
        $rmrf($dir);
    }
    mkdir($dir, 0777, true);
};

// Each probe runs against a FRESH state: the cache + circuit breaker
// live in their own directory (the main run trips the breaker as it
// exercises failures), and the probe database starts empty so every
// import + sync sees exactly what THIS probe staged.
$runProbe = function (string $script) use (&$probePath, $probeCache, &$rmCache, $probeDbPath, $temp): array {
    $rmCache($probeCache);

    foreach ([$probeDbPath, $probeDbPath . '-wal', $probeDbPath . '-shm'] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    file_put_contents($probePath, $script);
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' 2>&1');
    @unlink($probePath);

    $marker = strrpos($out, '@@@MARKER@@@');

    return [
        'body' => $marker === false ? $out : substr($out, 0, $marker),
        'code' => $marker === false ? '' : trim(substr($out, $marker + strlen('@@@MARKER@@@'))),
    ];
};

$importPrefix = static function (array $ids): string {
    $code = '';

    foreach ($ids as $id) {
        $code .= '$probeImporter->import($probeService->volume("' . $id . '"));' . PHP_EOL;
    }

    return $code;
};

// --- Single sync (fetch) --------------------------------------------

$r = $runProbe($probeHead
    . $importPrefix(['sync-01'])
    . '$_SERVER[\'HTTP_X_REQUESTED_WITH\'] = \'fetch\';' . PHP_EOL
    . '$_POST = [\'google_book_id\' => \'sync-01\'];' . PHP_EOL
    . '$probeController->sync(new Request(), []);' . PHP_EOL
    . 'echo PHP_EOL . \'@@@MARKER@@@\' . PHP_EOL . http_response_code();' . PHP_EOL);

$single = json_decode(trim($r['body']), true);
$check('Single sync answers HTTP 200 for fetch', $r['code'] === '200', 'code: ' . $r['code'] . ' | ' . trim($r['body']));
$check('Single sync reports the no-op outcome', is_array($single) && $single['ok'] === true && $single['status'] === 'unchanged', (string) json_encode($single));
$check('Single sync carries the run report', is_array($single) && isset($single['report']['total'], $single['report']['results']));

// --- Single sync validation (empty id) -------------------------------
$r = $runProbe($probeHead
    . '$_SERVER["HTTP_X_REQUESTED_WITH"] = \'fetch\';' . PHP_EOL
    . '$_POST = [\'google_book_id\' => \'\'];' . PHP_EOL
    . '$probeController->sync(new Request(), []);' . PHP_EOL
    . 'echo PHP_EOL . \'@@@MARKER@@@\' . PHP_EOL . http_response_code();' . PHP_EOL);

$rejected = json_decode(trim($r['body']), true);
$check('An empty single-sync id answers 422', $r['code'] === '422', 'code: ' . $r['code']);
$check('The 422 carries the field error map', is_array($rejected) && isset($rejected['errors']['google_book_id']));

// --- Bulk sync (fetch -> SSE) --------------------------------------

$r = $runProbe($probeHead
    . $importPrefix(['sync-11', 'sync-12'])
    . '$_SERVER["HTTP_X_REQUESTED_WITH"] = \'fetch\';' . PHP_EOL
    . '$_POST = [\'google_book_id\' => [\'sync-11\', \'sync-12\']];' . PHP_EOL
    . '$probeController->syncBulk(new Request(), []);' . PHP_EOL
    . 'echo PHP_EOL . \'@@@MARKER@@@\' . PHP_EOL . http_response_code();' . PHP_EOL);

$check('Bulk sync streams progress events', str_contains($r['body'], 'event: progress') && str_contains($r['body'], '"total":2'), '');
$check('Bulk sync finishes with a summary event', str_contains($r['body'], 'event: summary'));

$summaryPos = strrpos($r['body'], 'event: summary');
$dataPos = $summaryPos === false ? false : strpos($r['body'], 'data: ', $summaryPos);
$summaryEvent = $dataPos === false ? null : json_decode(trim(substr($r['body'], $dataPos + 6)), true);
$check('The bulk summary carries the report numbers', is_array($summaryEvent) && ($summaryEvent['total'] ?? 0) === 2 && ($summaryEvent['updated'] ?? 1) === 0 && ($summaryEvent['unchanged'] ?? 0) === 2 && ($summaryEvent['failed'] ?? 1) === 0, is_array($summaryEvent) ? json_encode($summaryEvent) : 'no summary');
$check('The bulk summary says ok', ($summaryEvent['status'] ?? '') === 'ok');

// --- Sync bulk: empty selection -------------------------------------

$r = $runProbe($probeHead
    . '$_SERVER["HTTP_X_REQUESTED_WITH"] = \'fetch\';' . PHP_EOL
    . '$_POST = [\'google_book_id\' => []];' . PHP_EOL
    . '$probeController->syncBulk(new Request(), []);' . PHP_EOL
    . 'echo PHP_EOL . \'@@@MARKER@@@\' . PHP_EOL . http_response_code();' . PHP_EOL);

$check('An empty bulk selection answers 422', $r['code'] === '422', 'code: ' . $r['code']);

// --- Sync all (fetch) ----------------------------------------------

$r = $runProbe($probeHead
    . $importPrefix(['sync-01', 'sync-02'])
    . '$_SERVER["HTTP_X_REQUESTED_WITH"] = \'fetch\';' . PHP_EOL
    . '$_POST = [\'noop\' => \'1\'];' . PHP_EOL
    . '$probeController->syncAll(new Request(), []);' . PHP_EOL
    . 'echo PHP_EOL . \'@@@MARKER@@@\' . PHP_EOL . http_response_code();' . PHP_EOL);

$check('syncAll streams progress for its books', str_contains($r['body'], 'event: progress'));
$check('syncAll finishes with a summary', str_contains($r['body'], 'event: summary'));

// --- Sync disabled (fetch -> 503) -----------------------------------

$r = $runProbe($probeHead
    . '$config[\'sync\'][\'enabled\'] = false;' . PHP_EOL
    . '$probeSync = new GoogleBooksSyncService($probeService, $probeImporter, new Book(), null, new Logger($config[\'cache\'][\'directory\'] . \'/probe.log\'), $config);' . PHP_EOL
    . '$probeController = new GoogleBooksController($probeService, $probeImporter, $probeBulk, $probeSync);' . PHP_EOL
    . '$_SERVER["HTTP_X_REQUESTED_WITH"] = \'fetch\';' . PHP_EOL
    . '$_POST = [\'google_book_id\' => \'sync-01\'];' . PHP_EOL
    . '$probeController->sync(new Request(), []);' . PHP_EOL
    . 'echo PHP_EOL . \'@@@MARKER@@@\' . PHP_EOL . http_response_code();' . PHP_EOL);

$check('A disabled sync answers 503 for fetch callers', $r['code'] === '503', 'code: ' . $r['code']);

// ---------------------------------------------------------------------
// 15. CONTROLLER: the no-JS form flashes + redirects (probed)
// ---------------------------------------------------------------------

$section('15. CONTROLLER: NO-JS FORM (flash + redirect, probed)');

// The same fresh-state reset as the fetch probes (own cache dir,
// empty throwaway DB) so syncAll sees exactly the one staged book.
$rmCache($probeCache);
foreach ([$probeDbPath, $probeDbPath . '-wal', $probeDbPath . '-shm'] as $file) {
    if (is_file($file)) {
        @unlink($file);
    }
}

file_put_contents($probePath, $probeHead
    . $importPrefix(['sync-01'])
    . 'register_shutdown_function(function (): void {' . PHP_EOL
    . '    echo \'FLUSH:\' . session()->getFlash(\'success\', \'\') . session()->getFlash(\'warning\', \'\') . session()->getFlash(\'info\', \'\');' . PHP_EOL
    . '});' . PHP_EOL
    . '$probeController->syncAll(new Request(), []);' . PHP_EOL
    . 'echo PHP_EOL . \'@@@MARKER@@@\';' . PHP_EOL);

$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' 2>&1');
@unlink($probePath);

$check('No-JS sync-all flashes the run summary', str_contains($out, 'Sync finished:'), trim($out));
$check('No-JS sync-all does not emit a JSON body', !str_starts_with(trim($out), '{"'));
if (preg_match('/FLUSH:(.*)/', $out, $match)) {
    $check('The flash names the checked book', str_contains($match[1], '1 checked'), trim($match[1]));
}

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

echo PHP_EOL . str_repeat('=', 72) . PHP_EOL;
echo '  PASS ' . $pass . PHP_EOL;
echo '  FAIL ' . $fail . PHP_EOL;
echo $fail === 0
    ? 'ALL GREEN - Phase 10.6 Google Books synchronization verified.'
    : 'FAILURES PRESENT - fix the failing checks before continuing.';
echo PHP_EOL;