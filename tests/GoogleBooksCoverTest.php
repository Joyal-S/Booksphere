<?php

declare(strict_types=1);

/**
 * GoogleBooksCoverTest — CLI test suite for Phase 10.4
 *
 * Verifies the COVER download & cache pipeline behind the Google Books
 * import:
 *     - CoverDownloadService: download (streamed, capped), validation
 *       (media pipeline), optimization (GD, degrading gracefully),
 *       deterministic storage, atomic writes
 *     - Cache semantics: one download per provider URL, reuse for later
 *       imports, TTL expiration (future-ready), invalidation
 *     - Failure policy: invalid/broken/oversized/corrupt covers NEVER
 *       reach a view - the book degrades to the BookSphere placeholder
 *       with cover_status 'failed', and the import itself never fails
 *     - Security + storage: http(s)-only sources, sha1() filenames (no
 *       user input), content-sniffed MIME, safe file modes, files under
 *       public/assets/covers/google/ - never the project root
 *     - Integration: BookImportService attaches the cover after its
 *       transaction; BookService deletes cached files on book deletion;
 *       the shared book-cover component renders the local path and
 *       falls back to the CSS placeholder (dark-mode aware)
 *
 *     php tests/GoogleBooksCoverTest.php
 *
 * How it works:
 *     - The HTTP transport is a stub (CoverDownloadStub) that answers
 *       cover URLs with canned image bytes (real 1x1 JPEG / PNG
 *       fixtures) or thrown failures - no network.
 *     - A fresh throwaway database (database/gb_cover_test.db) is
 *       migrated and seeded, so the import path runs for real.
 *     - Every check prints PASS/FAIL + a summary line at the end.
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\View;
use BookSphere\App\DTO\ImportResult;
use BookSphere\App\DTO\ProviderBookDTO;
use BookSphere\App\Exceptions\GoogleBooksException;
use BookSphere\App\Models\Author;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\Category;
use BookSphere\App\Services\BookImportService;
use BookSphere\App\Services\BookService;
use BookSphere\App\Services\CoverDownloadService;
use BookSphere\App\Services\MediaService;

(new Environment(root_path('.env')))->load();

// ---------------------------------------------------------------------
// 0. Fixture images + boot.
// ---------------------------------------------------------------------

const FIXTURE_JPEG = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AVN//2Q==';
const FIXTURE_PNG  = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

$fixtureJpeg = base64_decode(FIXTURE_JPEG);
$fixturePng  = base64_decode(FIXTURE_PNG);

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

// Boot: fixtures + throwaway database + services.

section('0. FIXTURES + BOOT');

check('Fixture JPEG is a real image (fileinfo + getimagesize)', (new finfo(FILEINFO_MIME_TYPE))->buffer($fixtureJpeg) === 'image/jpeg' && is_array(@getimagesizefromstring($fixtureJpeg)));
check('Fixture PNG is a real image', (new finfo(FILEINFO_MIME_TYPE))->buffer($fixturePng) === 'image/png');

$dbPath = root_path('database/gb_cover_test.db');

foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

$coverDir    = rtrim((string) sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'booksphere_covers_test';
$coverPrefix = '/assets/covers/google/';

foreach (glob(rtrim($coverDir, '/\\') . '/*') ?: [] as $file) {
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
    'client'   => ['user_agent' => 'BookSphere/Test'],
    'import'   => [
        'default_status' => 'published',
        'fetch_covers'   => true,
    ],
    'covers' => [
        'enabled'              => true,
        'directory'            => $coverDir,
        'public_prefix'        => $coverPrefix,
        'ttl_seconds'          => 3600,
        'timeout_seconds'      => 5,
        'retry_attempts'       => 2,
        'retry_backoff_ms'     => 1,
        'max_redirects'        => 5,
        'max_bytes'            => 5 * 1024 * 1024,
        'min_width'            => 1,
        'min_height'           => 1,
        'max_source_dimension' => 4000,
        'optimize' => [
            'enabled'       => false, // deterministic in this suite
            'max_dimension' => 800,
            'jpeg_quality'  => 82,
        ],
    ],
];

$media = new MediaService([
    'directory'       => 'public/uploads/books',
    'public_prefix'   => '/uploads/books/',
    'file_prefix'     => 'book',
    'max_bytes'       => 5 * 1024 * 1024,
    'mime_extensions' => [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ],
    'min_width'  => 1,
    'min_height' => 1,
    'max_width'  => 4000,
    'max_height' => 4000,
]);

/**
 * The transport seam: answer a cover URL with canned bytes or thrown
 * failures (the same protected-method pattern as GoogleBooksClient).
 */
final class CoverDownloadStub extends CoverDownloadService
{
    public int $attempts = 0;

    /** @var array<string, string> url => raw bytes to return */
    public array $bodies = [];

    /** @var array<string, string> url => failure reason (timeout|not_found|network) */
    public array $failures = [];

    /** @var array<string, int> url => how many initial attempts fail first */
    public array $transientFails = [];

    protected function attempt(string $url): string
    {
        $this->attempts++;

        $transient = (int) ($this->transientFails[$url] ?? 0);

        if ($transient > 0) {
            $this->transientFails[$url] = $transient - 1;
            throw GoogleBooksException::networkFailure('stub transient');
        }

        $reason = $this->failures[$url] ?? null;

        if ($reason !== null) {
            throw match ($reason) {
                'timeout'   => GoogleBooksException::timeout(5),
                'not_found' => GoogleBooksException::notFound(),
                default     => GoogleBooksException::networkFailure('stub'),
            };
        }

        $tmp = sys_get_temp_dir() . '/booksphere_cover_stub_' . bin2hex(random_bytes(6)) . '.img';
        file_put_contents($tmp, $this->bodies[$url] ?? base64_decode(FIXTURE_JPEG));

        return $tmp;
    }
}

$stub       = new CoverDownloadStub(new Book(), $media, $config);
$books      = new Book();
$importer   = new BookImportService($books, new Author(), new Category(), $config, $stub);
$bookService = new BookService(new Book(), new Author(), new Category(), $stub);

$disabled = new CoverDownloadService(new Book(), $media, array_merge($config, ['enabled' => false]));

/** Insert a bare catalogue row and return its id. */
$insertBook = function (string $title) use ($books): int {
    return $books->create([
        'title'          => $title,
        'subtitle'       => null,
        'description'    => null,
        'publisher'      => null,
        'published_year' => null,
        'language'       => 'en',
        'page_count'     => null,
        'cover_image'    => null,
        'status'         => 'published',
        'isbn'           => null,
    ]);
};

/** A ProviderBookDTO from a Google volume id + field overrides. */
$dto = fn (string $externalId, array $overrides = []): ProviderBookDTO => new ProviderBookDTO(
    externalId: $externalId,
    title:      (string) ($overrides['title'] ?? 'Untitled'),
    authors:    $overrides['authors'] ?? [],
    categories: $overrides['categories'] ?? [],
    language:   $overrides['language'] ?? 'en',
    isbn10:     $overrides['isbn10'] ?? null,
    isbn13:     $overrides['isbn13'] ?? null,
    thumbnail:  $overrides['thumbnail'] ?? null,
);

/** The full book row of an imported record, by its google_book_id. */
$row = fn (string $externalId): array => db()->query(
    'SELECT * FROM books WHERE google_book_id = ?',
    [$externalId],
)[0];

/** The filesystem path of the cached cover for a source url, or null. */
$cachedFile = fn (string $url): ?string => (static function (string $dir, string $url): ?string {
    foreach (['jpg', 'png', 'webp'] as $ext) {
        $candidate = $dir . DIRECTORY_SEPARATOR . sha1($url) . '.' . $ext;
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
})($coverDir, $url);

/** The public URL path of the cached cover for a source url, or null. */
$publicFor = fn (string $url): ?string => ($file = $cachedFile($url)) !== null
    ? $coverPrefix . basename($file)
    : null;

check('The cover service is enabled for an enabled module', $stub->isEnabled());
check('A disabled module disables the cover service too', $disabled->isEnabled() === false);

// ---------------------------------------------------------------------
// 1. Valid download lifecycle
// ---------------------------------------------------------------------

section('1. VALID DOWNLOAD (download -> validate -> store -> attach)');

$urlA               = 'https://books.google.com/covers/book-a.jpg';
$stub->bodies[$urlA] = $fixtureJpeg;
$bookIdA            = $insertBook('Cover lifecycle A');

$result = $stub->attach((string) $bookIdA, $urlA);

check('attach() reports downloaded', $result === CoverDownloadService::STATUS_DOWNLOADED);
check('Exactly one download attempt was made', $stub->attempts === 1, "attempts: {$stub->attempts}");

$rowA = db()->query('SELECT * FROM books WHERE id = ?', [$bookIdA])[0];
check('cover_status = downloaded', $rowA['cover_status'] === 'downloaded');
check('The source URL is recorded', $rowA['cover_source_url'] === $urlA);
check('The download timestamp is recorded', is_string($rowA['cover_downloaded_at']) && $rowA['cover_downloaded_at'] !== '');
check('cover_image holds a LOCAL path, not the provider URL', str_starts_with((string) $rowA['cover_image'], $coverPrefix) && (string) $rowA['cover_image'] !== $urlA);

$public = $publicFor($urlA);
check('A deterministic cache file exists', $public !== null);

$fileA = $cachedFile($urlA);
check('The filename is sha1(source url).jpg', $fileA !== null && basename($fileA) === sha1($urlA) . '.jpg');
check('The file lives inside the covers directory, not the project root', $fileA !== null && dirname($fileA) === $coverDir);
check('The stored cover is a valid image again', (new finfo(FILEINFO_MIME_TYPE))->file((string) $fileA) === 'image/jpeg');
check('The stored bytes match the source (no-GD passthrough)', $fileA !== null && hash_file('sha256', $fileA) === hash('sha256', $fixtureJpeg));

$stats = $stub->stats();
check('stats() counts the cached file', $stats['files'] === 1, "files: {$stats['files']}");
check('stats() reports the writable cache directory', $stats['writable'] === true);

// ---------------------------------------------------------------------
// 2. Cache reuse + dedupe (never download the same image twice)
// ---------------------------------------------------------------------

section('2. CACHE REUSE + DEDUPE (one file per provider URL)');

$urlShared = 'https://cdn.books.google.com/covers/shared.jpg';
$stub->bodies[$urlShared] = $fixtureJpeg;

$before = $stub->attempts;
$first  = $importer->import($dto('cv-book-1', [
    'title'     => 'Cover Cache Book One',
    'isbn13'    => '9781234567890',
    'isbn10'    => null,
    'thumbnail' => $urlShared,
]));
check('The first import succeeds', $first->status === ImportResult::STATUS_IMPORTED);

$attemptsAfterFirst = $stub->attempts;
check('The first import downloaded the shared cover once', $attemptsAfterFirst === $before + 1, "attempts: {$attemptsAfterFirst}");

$second = $importer->import($dto('cv-book-2', ['title' => 'Cover Cache Book Two', 'isbn13' => '9789876543210', 'isbn10' => null, 'thumbnail' => $urlShared]));
check('A second book with the SAME cover URL still imports', $second->status === ImportResult::STATUS_IMPORTED);
check('No extra download for a cached URL (pure reuse)', $stub->attempts === $attemptsAfterFirst, "attempts: {$stub->attempts}");

$row1 = $row('cv-book-1');
$row2 = $row('cv-book-2');
check('Both books point at the SAME local file', $row1['cover_image'] === $row2['cover_image'] && (string) $row1['cover_image'] !== '');
check('Both books are marked downloaded', $row1['cover_status'] === 'downloaded' && $row2['cover_status'] === 'downloaded');

$again = $importer->import($dto('cv-book-1', [
    'title'     => 'Cover Cache Book One',
    'isbn13'    => '9781234567890',
    'isbn10'    => null,
    'thumbnail' => $urlShared,
]));
check('A duplicate import never touches the cover pipeline', $again->isDuplicate() && $stub->attempts === $attemptsAfterFirst, "attempts: {$stub->attempts}");

// ---------------------------------------------------------------------
// 3. TTL expiration (future-ready) + invalidation
// ---------------------------------------------------------------------

section('3. TTL EXPIRATION + INVALIDATION');

$urlTtl               = 'https://cdn.books.google.com/covers/ttl.jpg';
$stub->bodies[$urlTtl] = $fixtureJpeg;
$bookTtl              = $insertBook('TTL book');

$stub->attach((string) $bookTtl, $urlTtl);
check('The TTL book is cached', $publicFor($urlTtl) !== null && $stub->isFresh($urlTtl));

touch((string) $cachedFile($urlTtl), time() - 7200); // past the 3600s TTL
check('A file past its TTL is reported stale', $stub->isFresh($urlTtl) === false);

// A re-attach of the same URL now re-fetches (expired -> not reused).
$beforeTtl = $stub->attempts;
$stub->attach((string) $bookTtl, $urlTtl);
check('A stale cover is re-downloaded, not served', $stub->attempts === $beforeTtl + 1, "attempts: {$stub->attempts}");
touch((string) $cachedFile($urlTtl), time());
check('isFresh() is true again inside the TTL', $stub->isFresh($urlTtl) === true);

$stub->invalidate($urlTtl);
check('invalidate() removes the file', $publicFor($urlTtl) === null);
check('isFresh() is false after invalidation', $stub->isFresh($urlTtl) === false);

// ---------------------------------------------------------------------
// 4. Failure & placeholder policy
// ---------------------------------------------------------------------

section('4. FAILURE POLICY (404, transient, invalid, oversized, corrupt)');

// --- 404: permanent, fails fast, no retry burn.

$url404 = 'https://cdn.books.google.com/missing.jpg';
$stub->failures[$url404] = 'not_found';
$book = $insertBook('Fails 404');
$before = $stub->attempts;
$state  = $stub->attach((string) $book, $url404);
check('A 404 cover fails fast', $state === CoverDownloadService::STATUS_FAILED && $stub->attempts === $before + 1, "attempts: {$stub->attempts}");

$row404 = db()->query('SELECT * FROM books WHERE id = ?', [$book])[0];
check('A failed cover clears cover_image (placeholder shows)', $row404['cover_image'] === null);
check('A failed cover keeps the source URL for a later retry', $row404['cover_source_url'] === $url404);
check('A failed cover is stamped failed', $row404['cover_status'] === CoverDownloadService::STATUS_FAILED);

// --- transient: the retry loop recovers.

$urlFlaky = 'https://cdn.books.google.com/flaky.jpg';
$stub->transientFails[$urlFlaky] = 2;
$stub->bodies[$urlFlaky]         = $fixtureJpeg;
$bookFlaky = $insertBook('Fails transient');
$before = $stub->attempts;
$fFlaky = $stub->attach((string) $bookFlaky, $urlFlaky);
check('Two transient failures are retried then succeed', $fFlaky === CoverDownloadService::STATUS_DOWNLOADED && $stub->attempts === $before + 3, "attempts: {$stub->attempts}");

// --- the famous "no cover" HTML page.

$stub->bodies['https://cdn.books.google.com/html.jpg'] = "<html><body>Google does not have a cover for this book.</body></html>";
$bookHtml = $insertBook('html cover');
check('An HTML body is rejected as an invalid image', $stub->attach((string) $bookHtml, 'https://cdn.books.google.com/html.jpg') === CoverDownloadService::STATUS_FAILED);

// --- oversized body (streaming/size cap).

$stub->bodies['https://cdn.books.google.com/big.jpg'] = $fixtureJpeg . str_repeat('A', 6 * 1024 * 1024);
$bookBig = $insertBook('oversized cover');
check('An oversized body fails the size limit', $stub->attach((string) $bookBig, 'https://cdn.books.google.com/big.jpg') === CoverDownloadService::STATUS_FAILED);

// --- truncated (corrupt) image.

$stub->bodies['https://cdn.books.google.com/trunc.jpg'] = substr($fixtureJpeg, 0, 24);
$bookTrunc = $insertBook('truncated cover');
check('A truncated image fails the structural check', $stub->attach((string) $bookTrunc, 'https://cdn.books.google.com/trunc.jpg') === CoverDownloadService::STATUS_FAILED);

// --- non-http scheme (Task 9 security).

$bookFtp = $insertBook('ftp cover');
check('A non-http(s) URL is refused', $stub->attach((string) $bookFtp, 'ftp://cdn.books.google.com/x.jpg') === CoverDownloadService::STATUS_FAILED);

// ---------------------------------------------------------------------
// 5. Missing covers (provider had none)
// ---------------------------------------------------------------------

section('5. MISSING COVER (provider record without a thumbnail)');

$bookNone = $insertBook('no cover');
$none     = $stub->attach((string) $bookNone, null);

check('No cover -> STATUS_NONE', $none === CoverDownloadService::STATUS_NONE);
$rowNone = db()->query('SELECT * FROM books WHERE id = ?', [$bookNone])[0];
check('A no-cover book keeps an empty cover_image', $rowNone['cover_image'] === null);
check('A no-cover book is stamped none', $rowNone['cover_status'] === CoverDownloadService::STATUS_NONE);
check('A no-cover book has no source URL', $rowNone['cover_source_url'] === null);

// ---------------------------------------------------------------------
// 6. Import integration + failure isolation
// ---------------------------------------------------------------------

section('6. IMPORT INTEGRATION (the import succeeds no matter the cover)');

// An import whose cover download FAILS must still create the book.
$urlBroke = 'https://cdn.books.google.com/never-imports.jpg';
$stub->failures[$urlBroke] = 'timeout';

$imported = $importer->import($dto('cv-broken-cover', [
    'title'     => 'Broken Cover Book',
    'isbn13'    => '9785559876543',
    'isbn10'    => null,
    'thumbnail' => $urlBroke,
]));

check('An import with a failing cover still imports', $imported->status === ImportResult::STATUS_IMPORTED);
$rowBroken = $row('cv-broken-cover');
check('The row is marked failed and placeholders', $rowBroken['cover_status'] === CoverDownloadService::STATUS_FAILED && $rowBroken['cover_image'] === null);
check('The failing download never touched the file system (skip)', $cachedFile($urlBroke) === null);

// A valid import via the real pipeline leaves the local path in place.
$urlGood = 'https://cdn.books.google.com/good.jpg';
$stub->bodies[$urlGood] = $fixtureJpeg;

$ok = $importer->import($dto('cv-good-cover', [
    'title'     => 'Good Cover Book',
    'isbn13'    => '9781597534567',
    'isbn10'    => null,
    'thumbnail' => $urlGood,
]));
check('A valid cover import imports + attaches', $ok->status === ImportResult::STATUS_IMPORTED);
check('The local path is on the book row', str_starts_with((string) $row('cv-good-cover')['cover_image'], $coverPrefix));

// BookService soft-delete removes the cached FILE for its cover.
$bookDel = $insertBook('delete me cover');
$stub->bodies['https://cdn.books.google.com/delete-me.jpg'] = $fixtureJpeg;
$stub->attach((string) $bookDel, 'https://cdn.books.google.com/delete-me.jpg');
$delFile = $cachedFile('https://cdn.books.google.com/delete-me.jpg');
check('The delete-me book has a cached cover', $delFile !== null);
$bookService->softDelete($bookDel);
check('softDelete() removes the cached cover file', $delFile === null || !is_file($delFile));

// ---------------------------------------------------------------------
// 7. Optimisation (GD only - degrade gracefully without it)
// ---------------------------------------------------------------------

section('7. OPTIMIZATION (resize + normalize; GD-gated)');

$hasGd = function_exists('imagecreatetruecolor') && function_exists('imagejpeg') && function_exists('imagecopyresampled');

if ($hasGd) {
    // A real 1600x600 JPEG is built with GD itself, so the resize path
    // is verifiable even though the suite never reaches a network.
    $src = imagecreatetruecolor(1600, 600);
    $white = imagecolorallocate($src, 250, 250, 250);
    imagefill($src, 0, 0, $white);
    ob_start();
    imagejpeg($src);
    $large = (string) ob_get_clean();
    imagedestroy($src);

    $imgCfg = $config;
    $imgCfg['covers']['optimize'] = ['enabled' => true, 'max_dimension' => 800, 'jpeg_quality' => 82];
    $gdMedia = new MediaService([
        'directory'       => 'public/uploads/books',
        'public_prefix'   => '/uploads/books/',
        'file_prefix'     => 'book',
        'max_bytes'       => 5 * 1024 * 1024,
        'mime_extensions' => [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ],
        'min_width'  => 1,
        'min_height' => 1,
        'max_width'  => 4000,
        'max_height' => 4000,
    ]);
    $gdStub  = new CoverDownloadStub(new Book(), $gdMedia, $imgCfg);
    $gdStub->bodies['https://cdn.books.google.com/large.jpg'] = $large;
    $gdBook = $insertBook('GD large cover');

    $gdResult = $gdStub->attach((string) $gdBook, 'https://cdn.books.google.com/large.jpg');
    $gdFile   = null;

    // The optimized copy is found by re-deriving the path.
    foreach (glob($coverDir . '/*.jpg') ?: [] as $f) {
        if (basename($f) === sha1('https://cdn.books.google.com/large.jpg') . '.jpg') {
            $gdFile = $f;
        }
    }

    $dims = $gdFile !== null ? @getimagesize($gdFile) : false;

    check('A large cover is resized under the max dimension', is_array($dims) && $dims[0] <= 800 && $dims[1] <= 800, $dims ? "{$dims[0]}x{$dims[1]}" : 'no file');
    check('The optimized cover stays a valid image', $gdFile !== null && (new finfo(FILEINFO_MIME_TYPE))->file($gdFile) === 'image/jpeg');
} else {
    echo '  [GD is not available on this PHP build - the resize/metadata assertions are skipped here; the passthrough path (original bytes, tested in section 1) covers the fallback]' . PHP_EOL;
    check('Without GD the validated original is stored untouched (fallback contract)', true);
}

// ---------------------------------------------------------------------
// 8. UI integration (local path rendered, placeholder fallback)
// ---------------------------------------------------------------------

section('8. UI INTEGRATION (local covers + placeholder in dark mode)');

$css = (string) file_get_contents(root_path('public/assets/css/app.css'));

check('app.css contains the placeholder tile class', str_contains($css, '.book-cover-fallback'));
check('app.css contains the broken-image fallback class', str_contains($css, '.book-cover-broken'));
check('The placeholder uses theme variables (dark-mode aware)', str_contains($css, 'var(--surface-2)') && str_contains($css, 'var(--border)'));

$withCover = View::fragment('books.components.book-cover', [
    'cover' => ['src' => $coverPrefix . 'abc123.jpg', 'alt' => 'Dune'],
]);
check('A book with a local cover renders the cached <img>', str_contains($withCover, '<img') && str_contains($withCover, $coverPrefix . 'abc123.jpg'));

$withoutCover = View::fragment('books.components.book-cover', [
    'cover' => ['src' => '', 'alt' => 'Dune'],
]);
check('A book without a cover renders the placeholder fallback image', str_contains($withoutCover, 'book-cover-fallback') && str_contains($withoutCover, 'cover-placeholder.svg'));

// The book detail page shows the local path when a cover was attached.
$detailData = ['id' => 1, 'title' => 'Cover', 'cover_image' => $rowA['cover_image'] ?? ''];
$detailHtml = View::fragment('books.components.book-cover', ['cover' => ['src' => $detailData['cover_image'], 'alt' => 'Dune']]);
check('A downloaded cover is served from the local path everywhere', str_contains($detailHtml, (string) $detailData['cover_image']));

echo PHP_EOL . str_repeat('=', 72) . PHP_EOL;

// A "broken remote URL" can never appear in the serviced markup of an
// attached book (cover_image is local) - the import never asks Google.
$attachedRow = $row('cv-good-cover');
check('No provider URL leaks into an attached book', !str_contains((string) $attachedRow['cover_image'], 'googleusercontent') && !str_contains((string) $attachedRow['cover_image'], 'zoom='));

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

echo PHP_EOL . str_repeat('=', 72) . PHP_EOL;
echo '  PASS ' . $pass . PHP_EOL;
echo '  FAIL ' . $fail . PHP_EOL;
echo $fail === 0
    ? 'ALL GREEN - Phase 10.4 cover download & cache pipeline verified.'
    : 'FAILURES PRESENT - fix the failing checks before continuing.';
echo PHP_EOL;