<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Core\Logger;
use BookSphere\App\DTO\ProviderBookDTO;
use BookSphere\App\DTO\SyncReport;
use BookSphere\App\Exceptions\GoogleBooksException;
use BookSphere\App\Models\Book;
use Throwable;

/**
 * GoogleBooksSyncService
 *
 * The Phase 10.6 metadata synchronizer: imported Google Books records
 * are refreshed against the provider, ONE book at a time, with change
 * detection - only fields that actually differ are written, and the
 * app's own data is never touched.
 *
 * Orchestration only, like BulkImportService: the provider transport
 * stays in GoogleBooksService (cache -> breaker -> live), the field
 * mapping lives in BookImportService::providerMetadata() (the SAME map
 * the importer writes with, so a sync can never disagree with an
 * import), the cover pipeline stays in CoverDownloadService, and the
 * database writes stay in the Book model. No import/sync logic is
 * duplicated here.
 *
 * Scope per book:
 *
 *     1. local row   - ONE metadataFor() query resolves the whole id
 *                      set; an id without a local imported book is
 *                      reported as SKIPPED and never fetched
 *     2. fetch       - GoogleBooksService::volume($id) (same
 *                      cache/breaker path as import). A provider
 *                      failure only fails THIS book - the run goes on
 *     3. diff        - the provider's metadata is compared field by
 *                      field against the local row; a field is only
 *                      marked for writing when it changed (Task 3:
 *                      no unnecessary writes)
 *     4. write       - only the changed columns go through the narrow
 *                      updateMetadata() whitelist; the author and
 *                      category relations are replaced only when their
 *                      name lists changed; every write is its own
 *                      single statement (no batch-wide transaction -
 *                      one failure can never take down the books that
 *                      came before it)
 *     5. cover       - CoverDownloadService is consulted only when the
 *                      provider URL differs from the cached source, or
 *                      the book has no usable cover yet; an unchanged
 *                      URL with a fresh cache answers ZERO downloads
 *                      (Task 7)
 *     6. stamp       - synced_at / sync_status / sync_message record
 *                      the outcome on the book row
 *
 * NEVER written (Task 2 + 6): books.average_rating and ratings_count
 * (derived from the app's OWN reviews), status, ISBN, google_book_id,
 * cover_image (the cover pipeline owns it) and every user-generated
 * table. The per-field rules in config sync.fields can disable any
 * metadata field for every run - the Phase 10.6 "configurable
 * synchronization rules" surface.
 *
 * Cancellation: the $advance callback returns whether to keep going
 * (the SSE stream's client-check); the remaining records are reported
 * as skipped via the "unprocessed" counter, exactly like the bulk
 * importer.
 */
final class GoogleBooksSyncService
{
    /** Stored in books.sync_status when the run found no changes. */
    public const STATUS_IN_SYNC = 'in_sync';

    /** Stored in books.sync_status when at least one field was written. */
    public const STATUS_UPDATED = 'updated';

    /** Stored in books.sync_status when the run could not complete. */
    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly GoogleBooksService $volumes,
        private readonly BookImportService $importer,
        private readonly Book $books,
        private readonly ?CoverDownloadService $covers,
        private readonly Logger $logger,
        private readonly array $config = [],
    ) {}

    /**
     * Whether the synchronization feature is switched on (module
     * master switch AND the sync flag).
     */
    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false)
            && (bool) ($this->config['sync']['enabled'] ?? true);
    }

    /**
     * The hard cap on how many ids ONE bulk sync request may carry.
     */
    public function maxBatch(): int
    {
        return max(1, (int) ($this->config['sync']['max_batch'] ?? 200));
    }

    /**
     * The checkpoint size (reporting cadence) for large runs.
     */
    public function batchSize(): int
    {
        return max(1, (int) ($this->config['sync']['batch_size'] ?? 25));
    }

    /**
     * The slim sync-state map for a page of google ids (the search
     * cards show "last synchronized" from this).
     *
     * @param array<int, string> $googleIds
     * @return array<string, array<string, mixed>>
     */
    public function syncMap(array $googleIds): array
    {
        return $this->books->syncOf($googleIds);
    }

    /**
     * Synchronize a set of imported books (by provider id).
     *
     * Every id goes through the six-step pipeline above. One bad book
     * (missing local row, unreachable provider, malformed record,
     * database failure) is logged and skipped WITHOUT aborting the
     * run - the report records exactly what happened to each one.
     *
     * @param array<int, string>                 $ids    The provider ids
     * @param callable(array<string, mixed>): bool|null $advance Progress
     *                hook invoked after EVERY processed record with the
     *                running snapshot; return false to stop early (the
     *                remainder is reported as skipped).
     */
    public function sync(array $ids, ?callable $advance = null): SyncReport
    {
        $ids = $this->normalize($ids);

        $started   = microtime(true);
        $total     = count($ids);
        $processed = 0;
        $covers    = 0;

        // ONE query resolves which ids exist locally - never a lookup
        // per book.
        $local = $this->books->metadataFor($ids);

        $counts  = ['updated' => 0, 'unchanged' => 0, 'failed' => 0, 'skipped' => 0];
        $results = [];

        $this->logger->info('google_books.sync.started', ['total' => $total]);

        foreach ($ids as $id) {
            $outcome = $this->process($id, $local);

            $results[] = [
                'id'      => $id,
                'bookId'  => $outcome['bookId'],
                'status'  => $outcome['status'],
                'message' => $outcome['message'],
                'reason'  => $outcome['reason'],
                'changes' => $outcome['changes'],
                'cover'   => $outcome['cover'],
            ];

            $counts[$outcome['status']]++;

            if ($outcome['cover']) {
                $covers++;
            }

            $processed++;

            // Progress + cancellation checkpoint (per book for real-time
            // feedback, plus a log marker every batch_size books).
            if ($processed === $total || $processed % max(1, $this->batchSize()) === 0) {
                $this->logger->info('google_books.sync.checkpoint', [
                    'processed' => $processed,
                    'updated'   => $counts['updated'],
                    'unchanged' => $counts['unchanged'],
                    'failed'    => $counts['failed'],
                    'skipped'   => $counts['skipped'],
                ]);
            }

            if ($advance !== null && !$advance($this->snapshot($id, $outcome, $counts, $total, $processed))) {
                break;
            }
        }

        $unprocessed = max(0, $total - $processed);

        if ($unprocessed > 0) {
            $this->logger->warning('google_books.sync.cancelled', ['not_attempted' => $unprocessed]);
        }

        $this->logger->info('google_books.sync.finished', [
            'updated'   => $counts['updated'],
            'unchanged' => $counts['unchanged'],
            'failed'    => $counts['failed'],
            'skipped'   => $counts['skipped'],
            'covers'    => $covers,
            'elapsed'   => round(microtime(true) - $started, 3),
        ]);

        return SyncReport::compile($started, $results, $covers, $unprocessed);
    }

    /**
     * Synchronize EVERY imported book in the catalogue (Task 4:
     * "synchronize all imported books"). One query collects the ids,
     * the run is the same per-book pipeline as sync().
     */
    public function syncAll(?callable $advance = null): SyncReport
    {
        $ids = [];

        foreach ($this->books->importedBooks() as $row) {
            $ids[] = (string) $row['google_book_id'];
        }

        $this->logger->info('google_books.sync.all', ['eligible' => count($ids)]);

        return $this->sync($ids, $advance);
    }

    /**
     * Process ONE google id: local lookup, fetch, diff, write, cover,
     * stamp. Returns the slim outcome the caller counts and streams.
     *
     * @param array<string, array<string, mixed>> $local [google id => row]
     * @return array{status: string, bookId: int|null, message: string,
     *               reason: string, title: string, changes: int, cover: bool}
     */
    private function process(string $id, array $local): array
    {
        $book = $local[$id] ?? null;

        // 1. No local imported book - nothing to synchronize, no fetch.
        if ($book === null) {
            return $this->outcome(
                SyncReport::STATUS_SKIPPED,
                null,
                'This record is not in the library and cannot be synchronized.',
                'not_imported',
                '',
                0,
                false,
            );
        }

        $bookId = (int) $book['id'];

        // 2. Fetch the provider record. The sync path deliberately
        //    bypasses the volume cache (refresh): running a sync must
        //    detect provider-side changes, not re-serve the cached
        //    copy the import just wrote. The breaker still guards the
        //    live call - on open it serves the stale copy or fails.
        try {
            $record = $this->volumes->volume($id, refresh: true);
        } catch (Throwable $error) {
            $reason = $error instanceof GoogleBooksException ? $error->reason() : 'unexpected';
            $this->logger->warning('google_books.sync.volume_failed', [
                'id'     => $id,
                'reason' => $reason,
            ]);

            // A failed fetch IS a sync outcome: stamp it on the row so
            // the admin's "last synchronized" state reflects reality.
            $this->stampFailed($bookId, 'The provider record could not be fetched (' . $reason . ').');

            return $this->outcome(
                SyncReport::STATUS_FAILED,
                $bookId,
                'Could not fetch this book from Google Books - ' . $error->getMessage(),
                $reason,
                (string) ($book['title'] ?? ''),
                0,
                false,
            );
        }

        // 3. The record maps to nothing usable (no title).
        if (!$record instanceof ProviderBookDTO) {
            $this->stampFailed($bookId, 'The provider record is unusable.');

            return $this->outcome(
                SyncReport::STATUS_FAILED,
                $bookId,
                'The Google Books record has no usable title and cannot be synchronized.',
                'invalid_record',
                (string) ($book['title'] ?? ''),
                0,
                false,
            );
        }

        // 4. Change detection: the SAME provider mapping the importer
        //    wrote with, compared field by field against the local row.
        $remote  = $this->importer->providerMetadata($record);
        $changes = $this->diff($book, $remote);

        // 5. Cover: only when the provider URL moved or the book has
        //    no usable cover yet; the cache reuse rules of
        //    CoverDownloadService keep an unchanged cover at zero cost.
        $coverChanged = $this->syncCover($bookId, $book, $remote);

        try {
            // 6. Write the changes. The relation keys are consumed
            //    HERE (replaced by their id-resolution), so the stamp
            //    decision below must remember they fired BEFORE they
            //    are unset - otherwise a relation-only change would be
            //    reported as "in sync".
            $relationChanged = false;

            if (isset($changes['authors'])) {
                $relationChanged = true;
                $this->books->replaceAuthors($bookId, $this->importer->authorIds($changes['authors']));
                unset($changes['authors']);
            }

            if (isset($changes['categories'])) {
                $relationChanged = true;
                $this->books->replaceCategories($bookId, $this->importer->categoryIds($changes['categories']));
                unset($changes['categories']);
            }

            if ($changes !== []) {
                $this->books->updateMetadata($bookId, $changes);
            }

            // 7. Stamp the outcome.
            $columnChanges = count($changes);
            $changed       = $relationChanged || $columnChanges > 0 || $coverChanged;
            $status        = $changed ? self::STATUS_UPDATED : self::STATUS_IN_SYNC;
            $message       = $this->messageFor($status, $columnChanges, $coverChanged);

            $this->books->updateSynced($bookId, $status, $message);
        } catch (Throwable $error) {
            $this->logger->error('google_books.sync.write_failed', [
                'id'    => $id,
                'error' => $error->getMessage(),
            ]);

            return $this->outcome(
                SyncReport::STATUS_FAILED,
                $bookId,
                'The synchronization failed to save this book.',
                'database',
                (string) ($book['title'] ?? ''),
                0,
                false,
            );
        }

        $title = (string) ($book['title'] ?? '');

        return $this->outcome(
            $changed ? SyncReport::STATUS_UPDATED : SyncReport::STATUS_UNCHANGED,
            $bookId,
            $message,
            $changed ? SyncReport::STATUS_UPDATED : SyncReport::STATUS_UNCHANGED,
            $title,
            count($changes),
            $coverChanged,
        );
    }

    /**
     * The per-field change set of one book: only the fields whose
     * local value differs from the provider value, and only fields the
     * config rules allow. Relations are represented by their name
     * lists ('authors' / 'categories' keys), which the caller resolves
     * to ids only when they actually changed.
     *
     * @param array<string, mixed> $book   the local row
     * @param array<string, mixed> $remote providerMetadata() output
     * @return array<string, mixed>
     */
    private function diff(array $book, array $remote): array
    {
        $fields  = (array) ($this->config['sync']['fields'] ?? []);
        $columns = [
            'title'                  => 'title',
            'subtitle'               => 'subtitle',
            'description'            => 'description',
            'publisher'              => 'publisher',
            'published_year'         => 'published_year',
            'language'               => 'language',
            'page_count'             => 'page_count',
            'preview_link'           => 'preview_link',
            'provider_rating'        => 'provider_rating',
            'provider_ratings_count' => 'provider_ratings_count',
        ];

        $changes = [];

        foreach ($columns as $field => $column) {
            if (empty($fields[$field])) {
                continue;
            }

            $local  = $book[$column] ?? null;
            $remoteValue = $remote[$field] ?? null;

            if (!$this->same($local, $remoteValue)) {
                $changes[$column] = $remoteValue;
            }
        }

        if (!empty($fields['authors'])) {
            $localNames = $this->relationNames($book, 'authors');
            $remoteNames = $remote['authors'];

            if ($localNames !== $remoteNames) {
                $changes['authors'] = $remoteNames;
            }
        }

        if (!empty($fields['categories'])) {
            $localNames = $this->relationNames($book, 'categories');
            $remoteNames = $remote['categories'];

            if ($localNames !== $remoteNames) {
                $changes['categories'] = $remoteNames;
            }
        }

        return $changes;
    }

    /**
     * The cover half of the sync (Task 7): reuse CoverDownloadService,
     * download only when something actually changed. Three triggers:
     * the provider URL moved, the book was never processed by the
     * cover pipeline ('' status), or the provider record has no cover
     * while the book previously had none ('none' status). An unchanged
     * URL with a fresh local copy answers ZERO network and ZERO writes.
     *
     * @param array<string, mixed> $book   the local row
     * @param array<string, mixed> $remote providerMetadata() output
     */
    private function syncCover(int $bookId, array $book, array $remote): bool
    {
        $fields = (array) ($this->config['sync']['fields'] ?? []);

        if (empty($fields['cover']) || $this->covers === null || !$this->covers->isEnabled()) {
            return false;
        }

        $remoteUrl  = (string) ($remote['cover_image'] ?? '');
        $localUrl   = (string) ($book['cover_source_url'] ?? '');
        $localState = (string) ($book['cover_status'] ?? '');

        $needsAttach = $localUrl !== $remoteUrl
            || ($remoteUrl !== '' && $localState === '')
            || ($remoteUrl !== '' && $localState === 'none');

        if (!$needsAttach) {
            return false;
        }

        $status = $this->covers->attach((string) $bookId, $remoteUrl !== '' ? $remoteUrl : null);

        if ($status === CoverDownloadService::STATUS_DOWNLOADED) {
            $this->logger->info('google_books.sync.cover', [
                'book_id' => $bookId,
                'status'  => $status,
            ]);
        }

        return $status === CoverDownloadService::STATUS_DOWNLOADED;
    }

    /**
     * Stamp a FAILED sync outcome on a book row. Best-effort on
     * purpose: the book already failed, so a bookkeeping write that
     * fails must not take down the run - it is logged and dropped.
     */
    private function stampFailed(int $bookId, string $message): void
    {
        try {
            $this->books->updateSynced($bookId, self::STATUS_FAILED, $message);
        } catch (Throwable $error) {
            $this->logger->error('google_books.sync.stamp_failed', [
                'book_id' => $bookId,
                'error'   => $error->getMessage(),
            ]);
        }
    }

    /**
     * The current author/category display names of a local row.
     * Resolved from the join tables (never from the row itself, which
     * carries no denormalized list).
     *
     * @param array<string, mixed> $book the local row
     * @return array<int, string>
     */
    private function relationNames(array $book, string $relation): array
    {
        $rows = $relation === 'authors'
            ? $this->books->authorsFor((int) $book['id'])
            : $this->books->categoriesFor((int) $book['id']);

        $names = [];

        foreach ($rows as $row) {
            if (is_string($row['name'] ?? null) && trim((string) $row['name']) !== '') {
                $names[] = trim((string) $row['name']);
            }
        }

        return $names;
    }

    /**
     * Whether two values mean the same thing: '' equals null (both
     * mean "no value"), numeric values compare numerically (SQLite may
     * return an integer where the provider sent a float), strings
     * compare exactly after trimming.
     */
    private function same(mixed $local, mixed $remote): bool
    {
        $a = $this->blank($local);
        $b = $this->blank($remote);

        if ($a === null || $b === null) {
            return $a === $b;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return (string) $a === (string) $b;
    }

    private function blank(mixed $value): mixed
    {
        return $value === '' || $value === null ? null : $value;
    }

    /**
     * The human message recorded to sync_message for a book.
     */
    private function messageFor(string $status, int $columnChanges, bool $coverChanged): string
    {
        if ($status === self::STATUS_FAILED) {
            return 'The last synchronization failed.';
        }

        if ($columnChanges === 0 && !$coverChanged) {
            return 'Up to date.';
        }

        $parts = [];

        if ($columnChanges > 0) {
            $parts[] = $columnChanges . ' metadata ' . ($columnChanges === 1 ? 'field' : 'fields') . ' updated';
        }

        if ($coverChanged) {
            $parts[] = 'cover refreshed';
        }

        return ucfirst(implode('; ', $parts)) . '.';
    }

    /**
     * The slim progress snapshot for one processed record.
     *
     * @param array<string, mixed> $outcome the process() outcome
     * @param array<string, int>   $counts  the running totals
     * @return array<string, mixed>
     */
    private function snapshot(string $id, array $outcome, array $counts, int $total, int $processed): array
    {
        return [
            'type'      => 'progress',
            'processed' => $processed,
            'total'     => $total,
            'remaining' => max(0, $total - $processed),
            'updated'   => $counts['updated'],
            'unchanged' => $counts['unchanged'],
            'failed'    => $counts['failed'],
            'skipped'   => $counts['skipped'],
            'book'      => [
                'id'      => $id,
                'title'   => mb_substr((string) $outcome['title'], 0, 120),
                'status'  => $outcome['status'],
                'changes' => (int) $outcome['changes'],
            ],
        ];
    }

    /**
     * @return array{status: string, bookId: int|null, message: string,
     *               reason: string, title: string, changes: int, cover: bool}
     */
    private function outcome(string $status, ?int $bookId, string $message, string $reason, string $title, int $changes, bool $cover): array
    {
        return [
            'status'  => $status,
            'bookId'  => $bookId,
            'message' => $message,
            'reason'  => $reason,
            'title'   => $title,
            'changes' => $changes,
            'cover'   => $cover,
        ];
    }

    /**
     * Trim + de-duplicate + bound the raw id list (defence in depth
     * behind the request gate - the service never trusts its caller).
     *
     * @param array<int, string> $ids
     * @return array<int, string>
     */
    private function normalize(array $ids): array
    {
        $clean = [];

        foreach ($ids as $id) {
            if (!is_string($id)) {
                continue;
            }

            $id = trim($id);

            if ($id === '' || mb_strlen($id) > 128) {
                continue;
            }

            $clean[$id] = $id;
        }

        return array_values($clean);
    }
}