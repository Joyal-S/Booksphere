<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Core\Logger;
use BookSphere\App\DTO\ImportReport;
use BookSphere\App\DTO\ProviderBookDTO;
use BookSphere\App\Models\Book;
use Throwable;

/**
 * BulkImportService
 *
 * The Phase 10.5 importer: MANY provider records -> the catalogue.
 * It is an ORCHESTRATOR ONLY - every database decision (dedupe, field
 * mapping, the all-or-nothing single-book transaction) stays in
 * BookImportService, and every metadata fetch stays in
 * GoogleBooksService. No import logic is duplicated here.
 *
 * Flow per volume id:
 *
 *     1. existing map  - ONE query (books->importedIds) tells us which
 *                        ids are already in the catalogue (google_book_id
 *                        is UNIQUE, even a soft-deleted row counts). Those
 *                        books are reported as duplicates and NEVER
 *                        fetched - the batch skips hundreds of wasted
 *                        provider calls.
 *     2. fetch        - GoogleBooksService::volume($id) - the SAME
 *                        cache -> breaker -> live -> stale path as the
 *                        single-book import; a provider failure only
 *                        fails THIS book (reason recorded, batch goes on).
 *     3. dedupe       - BookImportService::import() runs its own four
 *                        checks (google id -> ISBN13/10 -> title+author);
 *                        a duplicate still imports nothing and reports.
 *     4. insert       - the importer's own transaction: one book + its
 *                        authors/categories commit together, and only one
 *                        book ever. There is NO batch-wide transaction:
 *                        a failed book can never take down the imports
 *                        that came before it (Task 6).
 *     5. covers       - BookImportService attaches the cover AFTER the
 *                        commit through the SAME CoverDownloadService,
 *                        so the cache is respected (one download per URL).
 *
 * The whole run answers 2 little pressures at once:
 *     - memory         only the ids array + the slim report entries are
 *                       kept; the catalogue is never loaded as a whole
 *     - cancellation   the $advance callback returns whether to keep
 *                       going; the controller uses it to stop when the
 *                       client disconnected (the SSE stream), the rest
 *                       is reported as "not attempted".
 *
 * Batch semantics (config.google_books.bulk.batch_size): each book has
 * ITS OWN transaction, so "batch" is the REPORTING cadence - the loop
 * logs a checkpoint marker every batch_size books and lets the
 * progress flush land at a steady rhythm for very large runs. The
 * transaction granularity is intentionally not the batch: rolling back
 * one failing book (never the whole batch) is Task 6's requirement.
 */
final class BulkImportService
{
    /** @param BookImportService $single The Phase 10.3 one-record importer. */
    public function __construct(
        private readonly GoogleBooksService $volumes,
        private readonly BookImportService $single,
        private readonly Book $books,
        private readonly Logger $logger,
        private readonly array $config = [],
    ) {}

    /**
     * The maximum number of ids one request may carry.
     */
    public function maxBatch(): int
    {
        return max(1, (int) ($this->config['bulk']['max_batch'] ?? 200));
    }

    /**
     * The batch checkpoint size used by the reporting cadence.
     */
    public function batchSize(): int
    {
        return max(1, (int) ($this->config['bulk']['batch_size'] ?? 40));
    }

    /**
     * Import a set of Google Books volume ids in one run.
     *
     * Every id is processed through the four-step pipeline above. One
     * bad book (missing volume, malformed record, duplicate, database
     * failure) is logged and skipped WITHOUT aborting the batch - the
     * report records exactly what happened to each one.
     *
     * @param array<int, string>          $ids     The validated id list
     * @param callable(array<string, mixed>): bool|null $advance Progress
     *               hook. Invoked after EVERY processed record with the
     *               running snapshot; return false to stop the batch
     *               early (a cancelled client). The remaining records
     *               are reported as "skipped".
     */
    public function import(array $ids, ?callable $advance = null): ImportReport
    {
        $ids = $this->normalize($ids);

        $started   = microtime(true);
        $total     = count($ids);
        $processed = 0;

        // ONE query answers "which of these are already in the
        // catalogue?" for the whole batch - never a lookup per book.
        $existing = $this->books->importedIds($ids);

        $counts   = ['imported' => 0, 'duplicates' => 0, 'failed' => 0];
        $results  = [];

        $this->logger->info('google_books.bulk.started', ['total' => $total]);

        foreach ($ids as $id) {
            $outcome = $this->process($id, $existing);

            // classify + record
            $results[] = [
                'id'      => $id,
                'status'  => $outcome['status'],
                'bookId'  => $outcome['bookId'],
                'message' => $outcome['message'],
                'reason'  => $outcome['reason'],
            ];

            match ($outcome['status']) {
                ImportReport::STATUS_IMPORTED  => $counts['imported']++,
                ImportReport::STATUS_DUPLICATE => $counts['duplicates']++,
                default                        => $counts['failed']++,
            };

            $processed++;

            // Progress + cancellation checkpoint (per book for real-time
            // feedback, and additionally a log marker every batch_size).
            if ($processed === $total || $processed % max(1, $this->batchSize()) === 0) {
                $this->logger->info('google_books.bulk.checkpoint', [
                    'processed'  => $processed,
                    'imported'   => $counts['imported'],
                    'duplicates' => $counts['duplicates'],
                    'failed'     => $counts['failed'],
                ]);
            }

            if ($advance !== null && !$advance($this->snapshot($id, $outcome, $counts, $total, $processed))) {
                break;
            }
        }

        $unprocessed = max(0, $total - $processed);

        if ($unprocessed > 0) {
            $this->logger->warning('google_books.bulk.cancelled', ['not_attempted' => $unprocessed]);
        }

        $this->logger->info('google_books.bulk.finished', [
            'imported'   => $counts['imported'],
            'duplicates' => $counts['duplicates'],
            'failed'     => $counts['failed'],
            'elapsed'    => round(microtime(true) - $started, 3),
        ]);

        return ImportReport::compile($started, $results, $unprocessed);
    }

    /**
     * Process ONE id: pre-existing check, fetch, dedupe, insert.
     * Returns a slim outcome the caller can count and stream.
     *
     * @param array<string, int> $existing [google_book_id => local id]
     * @return array{status: string, bookId: int|null, message: string, reason: string}
     */
    private function process(string $id, array $existing): array
    {
        // 1. Already imported (google_book_id). No fetch, no insert.
        if (isset($existing[$id])) {
            return $this->outcome(
                ImportReport::STATUS_DUPLICATE,
                (int) $existing[$id],
                'A book with this Google Books id is already in the catalogue.',
                'already_imported',
            );
        }

        // 2. Fetch the provider record (cache + breaker + stale all live
        //    behind this single call - the SAME path the single import
        //    uses, so a bulk run NEVER fetches a book twice).
        try {
            $record = $this->volumes->volume($id);
        } catch (Throwable $error) {
            $this->recordProviderFailure($id, $error);

            return $this->outcome(
                ImportReport::STATUS_FAILED,
                null,
                'This book could not be fetched from Google Books - ' . $error->getMessage(),
                $this->failureReason($error),
            );
        }

        // 3. The record maps to nothing usable (no title).
        if (!$record instanceof ProviderBookDTO) {
            return $this->outcome(
                ImportReport::STATUS_FAILED,
                null,
                'This Google Books record has no usable title and cannot be imported.',
                'invalid_record',
            );
        }

        // 4. BookImportService owns the dedupe + the single-book
        //    transaction (rollback on failure = nothing left behind).
        try {
            $result = $this->single->import($record);
        } catch (Throwable $error) {
            $this->logger->error('google_books.bulk.import_failed', [
                'id'    => $id,
                'error' => $error->getMessage(),
            ]);

            return $this->outcome(
                ImportReport::STATUS_FAILED,
                null,
                'The import failed to save this book and was rolled back.',
                'database',
            );
        }

        if ($result->isDuplicate()) {
            return $this->outcome(
                ImportReport::STATUS_DUPLICATE,
                $result->bookId,
                $result->message,
                'duplicate',
                $record->title,
            );
        }

        return $this->outcome(
            ImportReport::STATUS_IMPORTED,
            $result->bookId,
            $result->message,
            'imported',
            $record->title,
        );
    }

    /**
     * The slim progress snapshot for one processed record.
     *
     * @param array{status: string, bookId: int|null, message: string, reason: string} $outcome
     * @return array<string, mixed>
     */
    private function snapshot(string $id, array $outcome, array $counts, int $total, int $processed): array
    {
        return [
            'type'       => 'progress',
            'processed'  => $processed,
            'total'      => $total,
            'remaining'  => max(0, $total - $processed),
            'imported'   => $counts['imported'],
            'duplicates' => $counts['duplicates'],
            'failed'     => $counts['failed'],
            'book'       => [
                'id'     => $id,
                'title'  => $this->titleFrom($outcome),
                'status' => $outcome['status'],
            ],
        ];
    }

    private function titleFrom(array $outcome): string
    {
        return (string) mb_substr($outcome['title'] ?? '', 0, 120);
    }

    /**
     * @return array{status: string, bookId: int|null, message: string, reason: string, title: string}
     */
    private function outcome(string $status, ?int $bookId, string $message, string $reason, string $title = ''): array
    {
        return [
            'status'  => $status,
            'bookId'  => $bookId,
            'message' => $message,
            'reason'  => $reason,
            'title'   => $title,
        ];
    }

    private function recordProviderFailure(string $id, Throwable $error): void
    {
        $this->logger->warning('google_books.bulk.volume_failed', [
            'id'     => $id,
            'reason' => $this->failureReason($error),
        ]);
    }

    /** Map any provider failure onto the machine-readable reason. */
    private function failureReason(Throwable $error): string
    {
        if ($error instanceof \BookSphere\App\Exceptions\GoogleBooksException) {
            return $error->reason();
        }

        return 'unexpected';
    }

    /**
     * Trim + de-duplicate + bound the raw id list (defence in depth
     * behind BulkImportRequest - the service never trusts its caller).
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