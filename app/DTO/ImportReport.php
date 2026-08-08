<?php

declare(strict_types=1);

namespace BookSphere\App\DTO;

/**
 * ImportReport
 *
 * The outcome of ONE BulkImportService::import() run - the complete
 * audit of a bulk import operation (Phase 10.5), delivered to the
 * controller so it can stream progress, render the summary dialog and
 * (later, Phase 10.6 export) serialize the report.
 *
 * The report is the aggregate of the per-record results:
 *
 *     - total       how many derived valid ids the batch asked for
 *     - imported    rows actually created (stored + published)
 *     - duplicates  records SKIPPED because the catalogue already has
 *                   them (matched on google_book_id, ISBN or the
 *                   title+author fallback - the single-book importer's
 *                   own dedupe; a duplicate is never an error)
 *     - failed      records that could NOT be imported (provider
 *                   unreachable, record unusable, validation, database
 *                   failure) - each item carries its reasons
 *     - skipped     duplicates plus any records not reached at all
 *                   (a cancelled run stops early; the remainder never
 *                   touches the provider)
 *     - elapsedSeconds - wall-clock time of the whole run
 *     - results     one slim entry per book: id, status, bookId (when
 *                   one exists), a human message and a machine reason
 *
 * The statuses reuse the single-book importer's vocabulary
 * (ImportResult::STATUS_*) so the REPORT and the CARD never mean two
 * different things by "imported"/"duplicate"; "failed" is the bulk
 * layer's own addition for records the importer could not even build.
 *
 * The report is deliberately export-ready: toArray() is the stable,
 * future-exportable shape (the Phase 10.6 exporter can serialize it
 * without ever re-reading the run).
 */
final readonly class ImportReport
{
    /** A new catalogue row was created. */
    public const STATUS_IMPORTED = 'imported';

    /** The record already exists in the catalogue; nothing was written. */
    public const STATUS_DUPLICATE = 'duplicate';

    /** The record could not be imported (provider / validation / DB). */
    public const STATUS_FAILED = 'failed';

    /**
     * @param int   $total          ids the batch asked for
     * @param int   $imported       catalogue rows created
     * @param int   $duplicates     records skipped as already-existing
     * @param int   $failed         records that could not be imported
     * @param int   $skipped        duplicates + never-reached records
     * @param float $elapsedSeconds wall-clock seconds of the run
     * @param array<int, array{id: string, status: string, bookId: int|null,
     *               message: string, reason: string}> $results per-book entries
     */
    public function __construct(
        public readonly int $total,
        public readonly int $imported,
        public readonly int $duplicates,
        public readonly int $failed,
        public readonly int $skipped,
        public readonly float $elapsedSeconds,
        public readonly array $results,
    ) {}

    /**
     * Build the report from a run's raw per-book entries.
     *
     * @param array<int, array{id: string, status: string, bookId: int|null,
     *               message: string, reason: string}> $results
     * @param int                                      $unprocessed Records not
     *               even attempted (the run was cancelled early).
     */
    public static function compile(float $startedAt, array $results, int $unprocessed = 0): self
    {
        $imported   = 0;
        $duplicates = 0;
        $failed     = 0;

        foreach ($results as $item) {
            match ($item['status']) {
                self::STATUS_IMPORTED  => $imported++,
                self::STATUS_DUPLICATE => $duplicates++,
                default                => $failed++,
            };
        }

        return new self(
            total: count($results) + $unprocessed,
            imported: $imported,
            duplicates: $duplicates,
            failed: $failed,
            skipped: $duplicates + $unprocessed,
            elapsedSeconds: microtime(true) - (float) $startedAt,
            results: $results,
        );
    }

    /** Whether every processed record landed (no failure, no skip). */
    public function ok(): bool
    {
        return $this->failed === 0;
    }

    /** Whether the run had any import failure at all. */
    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }

    /**
     * The future-ready export shape: a stable, plain-array report the
     * Phase 10.6 report exporter can serialize as-is.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total'          => $this->total,
            'imported'       => $this->imported,
            'duplicates'     => $this->duplicates,
            'failed'         => $this->failed,
            'skipped'        => $this->skipped,
            'elapsed_seconds' => round($this->elapsedSeconds, 3),
            'status'         => $this->ok() ? 'success' : ($this->hasFailures() ? 'failed' : 'ok'),
            'results'        => $this->results,
        ];
    }

    /**
     * The one-line human summary (the no-JavaScript flash and the admin
     * console both state the outcome in this form).
     */
    public function summary(): string
    {
        $parts = fn (int $n, string $word): string => $n . ' ' . $word . ($n === 1 ? '' : 's');

        $text = 'Bulk import finished: ' . $parts($this->imported, 'imported');

        if ($this->duplicates > 0) {
            $text .= ', ' . $parts($this->duplicates, 'duplicate skipped');
        }

        if ($this->failed > 0) {
            $text .= ', ' . $parts($this->failed, 'failed');
        }

        if ($this->skipped > $this->duplicates) {
            $text .= ', ' . $parts($this->skipped - $this->duplicates, 'not attempted');
        }

        return $text . '.';
    }
}