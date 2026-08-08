<?php

declare(strict_types=1);

/**
 * GoogleBooksBulkImportTest — CLI test suite for Phase 10.5
 *
 * Verifies the Google Books BULK import pipeline:
 *     - BulkImportRequest: the list gate (trim, allowlist, de-dup,
 *       the batch ceiling, the 422 error shapes)
 *     - BulkImportService: a WHOLE batch through the same four-step
 *       single-book pipeline - imports many, skips the already-imported
 *       WITHOUT refetching them, one failure never aborts the rest,
 *       and a cancelled run reports the remainder as "not attempted"
 *     - ImportReport: the aggregate + export-ready toArray() shape
 *     - the controller's dual answer: Server-Sent Events for fetch
 *       callers (progress + summary), the flash + redirect for the
 *       no-JavaScript form (probed in subprocesses, because
 *       redirect() exits the process)
 *
 *     php tests/GoogleBooksBulkImportTest.php
 *
 * How it works:
 *     - The HTTP transport is a stub (the GoogleBulkStub written to a
 *       file in the temp dir, so subprocess probes share the class)
 *       that answers volume lookups from canned volumes - no network.
 *     - A fresh throwaway database (database/gb_bulk_test.db) is
 *       migrated and seeded, so find-or-create and dedupe run against
 *       the real tables.
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
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\Controllers\GoogleBooksController;
use BookSphere\App\DTO\ImportReport;
use BookSphere\App\Models\Author;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\Category;
use BookSphere\App\Models\User;
use BookSphere\App\Requests\BulkImportRequest;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\BookImportService;
use BookSphere\App\Services\BulkImportService;
use BookSphere\App\Services\CacheManager;
use BookSphere\App\Services\CircuitBreaker;
use BookSphere\App\Services\GoogleBooksClient;
use BookSphere\App\Services\GoogleBooksProvider;
use BookSphere\App\Services\GoogleBooksService;

(new Environment(root_path('.env')))->load();

// A session must exist BEFORE any output, so the controller smoke
// tests can log in a stub admin user.
$session = new Session('gb_bulk_test');
$session->start();
AuthService::setInstance(new AuthService($session, new User()));

// ---------------------------------------------------------------------
// 0. Boot: fresh throwaway database, migrated and seeded.
// ---------------------------------------------------------------------

$dbPath         = root_path('database/gb_bulk_test.db');
$probeDbPath    = root_path('database/gb_bulk_probe.db');

foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm', $probeDbPath, $probeDbPath . '-wal', $probeDbPath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

$temp = rtrim((string) sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'booksphere_gb_bulk_test';

if (!is_dir($temp)) {
    mkdir($temp, 0777, true);
}

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

// ---------------------------------------------------------------------
// 1. Shared fixtures: provider config, cache + breaker + temp files
// ---------------------------------------------------------------------

$config = [
    'enabled'  => true,
    'base_url' => 'https://www.googleapis.com/books/v1',
    'client'   => [
        'timeout_seconds'  => 5,
        'retry_attempts'   => 0,
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
    'bulk'   => ['max_batch' => 200, 'batch_size' => 40],
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

/** A raw books row for one google id. */
$row = fn (string $googleId): ?array => db()->query('SELECT * FROM books WHERE google_book_id = ?', [$googleId])[0] ?? null;

/** How many books rows carry one of these google ids (only imports). */
$countByIds = function (array $ids): int {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = db()->query("SELECT COUNT(*) c FROM books WHERE google_book_id IN ($placeholders)", $ids);

    return (int) ($rows[0]['c'] ?? 0);
};

// ---------------------------------------------------------------------
// 2. The canned volume map: every bulk book answers with a distinct
// ISBN + title + author, so the ONLY collision in the catalogue is the
// one each section deliberately creates.
// ---------------------------------------------------------------------

$volume = fn (string $id, int $n, string $isbn): array => [
    'id' => $id,
    'volumeInfo' => [
        'title'      => 'Bulk Book ' . $n,
        'authors'    => ['Bulk Author ' . $n],
        'categories' => ['Bulk Fiction'],
        'publisher'  => 'Bulk Press',
        'publishedDate' => '2020-01-01',
        'language'   => 'en',
        'pageCount'  => 100 + $n,
        'industryIdentifiers' => [
            ['type' => 'ISBN_13', 'identifier' => $isbn],
        ],
        'previewLink' => 'https://books.google.com/books?id=' . $id,
        'infoLink'    => 'https://books.google.com/books?id=' . $id,
    ],
];

$volumes = [];

for ($i = 1; $i <= 10; $i++) {
    $volumes['bk-00' . $i] = $volume('bk-00' . $i, $i, '978' . str_pad((string) $i, 10, '0', STR_PAD_LEFT));
}

foreach ([21 => '9789990000021', 22 => '9789990000022', 23 => '9789990000023', 25 => '9789990000025'] as $n => $isbn) {
    $volumes['bk-0' . $n] = $volume('bk-0' . $n, $n, $isbn);
}

for ($i = 50; $i <= 54; $i++) {
    $volumes['bk-0' . $i] = $volume('bk-0' . $i, $i, '9789' . str_pad((string) $i, 9, '0', STR_PAD_LEFT));
}

foreach ([101, 102, 103] as $n) {
    $volumes['bk-' . $n] = $volume('bk-' . $n, $n, '9789' . str_pad((string) $n, 9, '0', STR_PAD_LEFT));
}

foreach ([201, 202] as $n) {
    $volumes['bk-' . $n] = $volume('bk-' . $n, $n, '9789' . str_pad((string) (200 + $n), 9, '0', STR_PAD_LEFT));
}

// ---------------------------------------------------------------------
// 3. The stubbed transport. Written to a file so the subprocess probes
//    can reuse the exact class (GoogleBooksClient::send() is the hook).
// ---------------------------------------------------------------------

$probeStubFile = $temp . DIRECTORY_SEPARATOR . 'bulk_probe_stub.php';

file_put_contents($probeStubFile, '<?php' . PHP_EOL
    . 'use BookSphere\App\Services\GoogleBooksClient;' . PHP_EOL
    . 'final class GoogleBulkStub extends GoogleBooksClient' . PHP_EOL
    . '{' . PHP_EOL
    . '    public int $calls = 0;' . PHP_EOL
    . '    public array $volumes = [];' . PHP_EOL
    . '    protected function send(string $url): array' . PHP_EOL
    . '    {' . PHP_EOL
    . '        $this->calls++;' . PHP_EOL
    . '        $path = (string) parse_url($url, PHP_URL_PATH);' . PHP_EOL
    . '        if (!preg_match(\'#/volumes/([^/]+)$#\', $path, $match)) {' . PHP_EOL
    . '            return [\'status\' => 404, \'headers\' => [], \'body\' => \'{}\'];' . PHP_EOL
    . '        }' . PHP_EOL
    . '        $id = urldecode($match[1]);' . PHP_EOL
    . '        if (!isset($this->volumes[$id])) {' . PHP_EOL
    . '            return [\'status\' => 404, \'headers\' => [], \'body\' => \'{}\'];' . PHP_EOL
    . '        }' . PHP_EOL
    . '        return [\'status\' => 200, \'headers\' => [], \'body\' => json_encode(' . PHP_EOL
    . '            $this->volumes[$id],' . PHP_EOL
    . '        )];' . PHP_EOL
    . '    }' . PHP_EOL
    . '}' . PHP_EOL);

require_once $probeStubFile;

$stub = new GoogleBulkStub($config);
$stub->volumes = $volumes;

$service = new GoogleBooksService(
    $stub,
    new GoogleBooksProvider($config),
    new CacheManager($temp, [CacheManager::NS_SEARCH => 60, CacheManager::NS_VOLUME => 600], true),
    new CircuitBreaker($temp, $config['cache']['circuit_breaker']),
    new Logger($temp . '/test.log'),
    $config,
);

$bulkImporter = new BookImportService(new Book(), new Author(), new Category(), $config);
$books        = new Book();

$bulkService = new BulkImportService(
    $service,
    $bulkImporter,
    $books,
    new Logger($temp . '/test.log'),
    $config,
);

// ---------------------------------------------------------------------
// 4. BulkImportRequest: the POSTed list gate
// ---------------------------------------------------------------------

$section('4. REQUEST GATE');

$empty = new BulkImportRequest([]);
$check('An empty selection is not valid', $empty->isEmpty() && !$empty->valid());
$check('Empty answers the 422 error map', isset($empty->errors()['google_book_id']));

$garbage = new BulkImportRequest([123, '', 'bad id!', 'ok_1.a', ' ', 'ok_1.a', 'second']);
$check('Garbage is dropped, ids are trimmed + collated', $garbage->ids() === ['ok_1.a', 'second'], implode(',', $garbage->ids()));

$over = new BulkImportRequest(['a', 'b', 'c', 'd'], 3);
$check('More than max carries exceeds the limit', $over->exceedsLimit() && !$over->valid());
$check('The limit answers a bounded message', in_array('Select at most 3 books per import.', $over->errors()['google_book_id'] ?? []));
$check('The limit clamps to >= 1', (new BulkImportRequest(['a'], 0))->limit() === 1);

// ---------------------------------------------------------------------
// 5. SERVICE: a whole batch imports with the right counts
// ---------------------------------------------------------------------

$section('5. SERVICE: BATCH IMPORT');

$ids = array_keys(array_slice($volumes, 0, 10, true));

$report = $bulkService->import($ids);

$check('Ten books imported, nothing skipped', $report->imported === 10 && $report->duplicates === 0 && $report->failed === 0 && $report->skipped === 0 && $report->total === 10, 'i=' . $report->imported . ' d=' . $report->duplicates . ' f=' . $report->failed . ' s=' . $report->skipped);
$check('The run reports itself successful', $report->ok() && !$report->hasFailures());
$check('Every id produced an imported entry', count(array_filter($report->results, fn (array $e): bool => $e['status'] === ImportReport::STATUS_IMPORTED)) === 10);
$check('Each entry carries a local book id', count(array_filter($report->results, fn (array $e): bool => is_numeric($e['bookId'] ?? null))) === 10);
$check('Every book row really exists', $countByIds($ids) === 10);
$check('Each volume fetched exactly once', $stub->calls === 10, 'calls: ' . $stub->calls);

// ---------------------------------------------------------------------
// 6. The same batch again: duplicates, and NOTHING re-fetched
// ---------------------------------------------------------------------

$section('6. BATCH IDEMPOTENCY');

$again = $bulkService->import($ids);

$check('The second run reports all duplicates', $again->imported === 0 && $again->duplicates === 10 && $again->failed === 0 && $again->skipped === 10);
$check('No second rows were created', $countByIds($ids) === 10);
$check('No provider call was wasted', $stub->calls === 10, 'calls: ' . $stub->calls);

// ---------------------------------------------------------------------
// 7. ONE failure never aborts the batch
// ---------------------------------------------------------------------

$section('7. FAILURE ISOLATION');

// bk-024 intentionally has NO canned volume -> the provider answers
// 404 for it. The other three must still land.
$mixed = ['bk-021', 'bk-024', 'bk-022', 'bk-023'];
$mixedReport = $bulkService->import($mixed);

$check('The missing book is the only failure', $mixedReport->failed === 1 && $mixedReport->imported === 3 && $mixedReport->duplicates === 0, 'i=' . $mixedReport->imported . ' d=' . $mixedReport->duplicates . ' f=' . $mixedReport->failed);
$check('The run reports a failure flag', $mixedReport->hasFailures() && !$mixedReport->ok());
$check('The failure is typed not_found', ($mixedReport->results[1]['reason'] ?? '') === 'not_found', (string) ($mixedReport->results[1]['reason'] ?? ''));
$check('The good books were still written', $row('bk-021') !== null && $row('bk-022') !== null && $row('bk-023') !== null);
$check('The missing book wrote nothing', $row('bk-024') === null);

// ---------------------------------------------------------------------
// 8. CANCELLATION: a run that is told to stop mid-flight
// ---------------------------------------------------------------------

$section('8. CANCELLATION');

$before = $stub->calls;
$cancelledIds = ['bk-050', 'bk-051', 'bk-052', 'bk-053', 'bk-054'];
$progress = 0;
$events = [];

$cancelled = $bulkService->import(
    $cancelledIds,
    function (array $snapshot) use (&$progress, &$events): bool {
        $progress++;
        $events[] = $snapshot;
        return $progress < 2; // stop after the second book
    },
);

$check('Only the two books were attempted', count($cancelled->results) === 2 && $cancelled->imported === 2 && $cancelled->failed === 0 && $cancelled->skipped === 3 && $cancelled->total === 5, 'i=' . $cancelled->imported . ' s=' . $cancelled->skipped . ' t=' . $cancelled->total);
$check('No provider request went to the skipped books', $stub->calls === $before + 2, 'calls: ' . $stub->calls);
$check('Progress snapshots carried the running counts', ($events[1]['processed'] ?? 0) === 2 && ($events[1]['remaining'] ?? 0) === 3 && ($events[1]['imported'] ?? 0) === 2);

// ---------------------------------------------------------------------
// 9. ImportReport: the aggregate + export shape
// ---------------------------------------------------------------------

$section('9. REPORT SHAPE AND SUMMARY LINE');

$fields = $mixedReport->toArray();
$check('toArray() has the export fields', array_key_exists('total', $fields) && array_key_exists('imported', $fields) && array_key_exists('duplicates', $fields) && array_key_exists('failed', $fields) && array_key_exists('skipped', $fields) && array_key_exists('elapsed_seconds', $fields) && array_key_exists('status', $fields) && array_key_exists('results', $fields));
$check('Elapsed seconds is a number', is_float($fields['elapsed_seconds']) || is_int($fields['elapsed_seconds']));
$check('Failed runs say failed', ($fields['status'] ?? '') === 'failed');
$check('Successful runs say success', ($report->toArray()['status'] ?? '') === 'success');

$summary = $mixedReport->summary();
$check('The one-line summary tells the story', str_contains($summary, 'Bulk import finished: 3 imported') && str_contains($summary, '1 failed'));

// ---------------------------------------------------------------------
// 10. CONTROLLER (probed in subprocesses)
// ---------------------------------------------------------------------

$section('10. CONTROLLER: THE FETCH PROTOCOL (SSE stream, probed)');

// The probe head: a fresh process with its own throwaway database,
// the stub transport and the same service graph as the main suite.
// bulk.max_batch is 3 for the probe, so the ceiling path is real.
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
    . 'use BookSphere\App\Services\GoogleBooksService;' . PHP_EOL . PHP_EOL
    . '(new Environment(root_path(\'.env\')))->load();' . PHP_EOL
    . 'Database::instance(root_path(\'database/gb_bulk_probe.db\'));' . PHP_EOL
    . '(new Migrator(db(), root_path(\'database/migrations\')))->run();' . PHP_EOL
    . '$session = new Session(\'gb_bulk_probe\');' . PHP_EOL
    . '$session->start();' . PHP_EOL
    . 'AuthService::setInstance(new AuthService($session, new User()));' . PHP_EOL
    . '$config = ' . var_export($config, true) . ';' . PHP_EOL
    . '$config[\'bulk\'] = [\'max_batch\' => 3, \'batch_size\' => 2];' . PHP_EOL
    . '$probeStub = new GoogleBulkStub($config);' . PHP_EOL
    . '$probeStub->volumes = ' . var_export($volumes, true) . ';' . PHP_EOL
    . '$probeService = new GoogleBooksService(' . PHP_EOL
    . '    $probeStub,' . PHP_EOL
    . '    new GoogleBooksProvider($config),' . PHP_EOL
    . '    new CacheManager($config[\'cache\'][\'directory\'], [CacheManager::NS_SEARCH => 60, CacheManager::NS_VOLUME => 600], true),' . PHP_EOL
    . '    new CircuitBreaker($config[\'cache\'][\'directory\'], $config[\'cache\'][\'circuit_breaker\']),' . PHP_EOL
    . '    new Logger($config[\'cache\'][\'directory\'] . \'/probe.log\'),' . PHP_EOL
    . '    $config,' . PHP_EOL
    . ');' . PHP_EOL
    . '$probeImporter = new BookImportService(new Book(), new Author(), new Category(), $config);' . PHP_EOL
    . '$probeBulk = new BulkImportService($probeService, $probeImporter, new Book(), new Logger($config[\'cache\'][\'directory\'] . \'/probe.log\'), $config);' . PHP_EOL
    . '$probeSync = new BookSphere\App\Services\GoogleBooksSyncService($probeService, $probeImporter, new Book(), null, new Logger($config[\'cache\'][\'directory\'] . \'/probe.log\'), $config);' . PHP_EOL
    . '$probeController = new GoogleBooksController($probeService, $probeImporter, $probeBulk, $probeSync);' . PHP_EOL;

$probePath = $temp . DIRECTORY_SEPARATOR . 'bulk_probe_run.php';

/**
 * Run one controller scenario in a fresh subprocess and hand the raw
 * stdout to the assertion (the SSE stream, the 422 body, whatever the
 * controller emitted, then a marker line + the final http code).
 */
$runProbe = function (array $post, bool $fetch) use (&$probePath, &$probeHead): array {
    file_put_contents($probePath, $probeHead
        . '$_POST = ' . var_export($post, true) . ';' . PHP_EOL
        . ($fetch ? '$_SERVER[\'HTTP_X_REQUESTED_WITH\'] = \'fetch\';' . PHP_EOL : '')
        . '$probeController->importBulk(new Request(), []);' . PHP_EOL
        . 'echo PHP_EOL . \'@@@MARKER@@@\' . PHP_EOL . http_response_code();' . PHP_EOL);

    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' 2>&1');
    @unlink($probePath);

    $marker = strrpos($out, '@@@MARKER@@@');

    return [
        'body' => $marker === false ? $out : substr($out, 0, $marker),
        'code' => $marker === false ? '' : trim(substr($out, $marker + strlen('@@@MARKER@@@'))),
    ];
};

// --- Success: 3 books, SSE stream with progress + a final summary ---

$sse = $runProbe(['google_book_id' => ['bk-101', 'bk-102', 'bk-103']], true);

$check('Fetch import streams progress events', str_contains($sse['body'], 'event: progress'), 'code ' . $sse['code']);
$check('Progress carries the running numbers', str_contains($sse['body'], '"total":3'), '');
$check('Fetch import finishes with a summary event', str_contains($sse['body'], 'event: summary'));

// Parse the LAST summary block: take everything from its data: line.
$summaryEvent = null;
$summaryPos = strrpos($sse['body'], 'event: summary');
$dataPos = $summaryPos === false ? false : strpos($sse['body'], 'data: ', $summaryPos);

if ($dataPos !== false) {
    $jsonText = trim(substr($sse['body'], $dataPos + strlen('data: ')));
    $summaryEvent = json_decode($jsonText, true);
}

$check('The summary carries the report numbers', is_array($summaryEvent) && ($summaryEvent['imported'] ?? 0) === 3 && ($summaryEvent['total'] ?? 0) === 3 && ($summaryEvent['failed'] ?? 1) === 0 && ($summaryEvent['duplicates'] ?? 1) === 0, (string) ($summaryEvent === null ? 'no summary' : json_encode($summaryEvent)));

// --- Validation: an empty selection answers the 422 contract ---

$rejected = $runProbe([], true);
$check('An empty selection answers 422', $rejected['code'] === '422', 'code ' . $rejected['code']);
$json = json_decode(trim($rejected['body']), true);
$check('The 422 carries the google_book_id error', is_array($json) && isset($json['errors']['google_book_id']), (string) $rejected['body']);

// --- Validation: past the batch ceiling ---

$capped = $runProbe(['google_book_id' => ['bk-101', 'bk-102', 'bk-103', 'bk-201']], true);
$check('An over-limit selection answers 422', $capped['code'] === '422', 'code ' . $capped['code']);
$json = json_decode(trim($capped['body']), true);
$check('The 422 names the ceiling', is_array($json) && str_contains(json_encode($json), 'Select at most 3 books per import.'), (string) $capped['body']);

// ---------------------------------------------------------------------
// 11. CONTROLLER: the no-JavaScript form flashes + redirects (probed)
// ---------------------------------------------------------------------

$section('11. CONTROLLER: THE NO-JS FORM (flash + redirect, probed)');

file_put_contents($probePath, $probeHead
    . 'register_shutdown_function(function (): void {' . PHP_EOL
    . '    echo \'FLUSH:\' . session()->getFlash(\'success\', \'\') . \'|\' . session()->getFlash(\'warning\', \'\');' . PHP_EOL
    . '});' . PHP_EOL
    . '$_POST = ' . var_export(['google_book_id' => ['bk-201', 'bk-202']], true) . ';' . PHP_EOL
    . '$probeController->importBulk(new Request(), []);' . PHP_EOL);

$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' 2>&1');
@unlink($probePath);

$check('No-JS import flashes the summary', str_contains($out, 'Bulk import finished: 2 imported'), trim($out));

// The rows landed in the PROBE's throwaway db (Database::instance only
// ever takes the first connection, so a fresh wrapper reads them).
$probeDb = new Database($probeDbPath);
$rows = $probeDb->query("SELECT google_book_id FROM books WHERE google_book_id IN ('bk-201','bk-202')");
$check('No-JS import writes the rows', count($rows) === 2, 'rows: ' . count($rows));

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

echo PHP_EOL . str_repeat('=', 72) . PHP_EOL;
echo '  PASS ' . $pass . PHP_EOL;
echo '  FAIL ' . $fail . PHP_EOL;
echo $fail === 0
    ? 'ALL GREEN - Phase 10.5 bulk import pipeline verified.'
    : 'FAILURES PRESENT - fix the failing checks before continuing.';
echo PHP_EOL;
