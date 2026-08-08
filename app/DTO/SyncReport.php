<?php

declare(strict_types=1);

namespace BookSphere\App\DTO;

/**
 * SyncReport
 *
 * The outcome of ONE GoogleBooksSyncService run - the complete audit
 * of a metadata synchronization operation (Phase 10.6), delivered to
 * the controller so it can stream progress, render the summary dialog
 * and (later, Phase 10.7 export) serialize the report.
 *
 * The report is the aggregate of the per-record results:
 *
 *     - total           books the run was asked to check
 *     - checked         books actually processed (local row found +
 *                       provider record fetched) = updated + unchanged
 *                       + failed
 *     - updated         books with at least one metadata field written
 *     - unchanged       books whose local metadata already matched the
 *                       provider (ZERO writes - "no unnecessary writes")
 *     - failed          books that could not be synchronized (provider
 *                       unreachable, missing record, validation,
 *                       database failure) - each item carries its reason
 *     - skipped         books never processed at all: supplied ids with
 *                       no local imported book (e.g. a non-imported
 *                       record ticked in the bulk bar) plus any records
 *                       not reached after a cancellation
 *     - coversUpdated   how many books had their cover re-attached by
 *                       the cover pipeline (URL change, loss of a
 *                       cached copy, or a first-time fetch) - an
 *                       unchanged URL with a fresh cache answers ZERO
 *     - changedFields   the sum of all metadata columns written
 *     - elapsedSeconds  wall-clock time of the whole run
 *     - results         one slim entry per book: id, bookId, status,
 *                       message, reason and the change count
 *
 * The statuses are the sync vocabulary (updated / unchanged / failed /
 * skipped). The report is deliberately export-ready: toArray() is the
 * stable, future-serializable shape (Phase 10.7 export can use it
 * without re-reading the run).
 */
final readonly class SyncReport
{
    /** At least one metadata field was written. */
    public const STATUS_UPDATED = 'updated';

    /** The local metadata already matched the provider; nothing written. */
    public const STATUS_UNCHANGED = 'unchanged';

    /** The book could not be synchronized (provider / validation / DB). */
    public const STATUS_FAILED = 'failed';

    /** Not processed: no local book for the id, or cancelled before it. */
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @param int   $total         books the run asked for
     * @param int   $checked       books fully processed
     * @param int   $updated       books with at least one change written
     * @param int   $unchanged     books with nothing to change
     * @param int   $failed        books that could not be synchronized
     * @param int   $skipped       never processed (no local book / cancelled)
     * @param int   $coversUpdated books whose cover the pipeline touched
     * @param int   $changedFields total metadata columns written
     * @param float $elapsedSeconds wall-clock seconds of the run
     * @param array<int, array{id: string, bookId: int|null, status: string,
     *               message: string, reason: string, changes: int}> $results
     */
    public function __construct(
        public readonly int $total,
        public readonly int $checked,
        public readonly int $updated,
        public readonly int $unchanged,
        public readonly int $failed,
        public readonly int $skipped,
        public readonly int $coversUpdated,
        public readonly int $changedFields,
        public readonly float $elapsedSeconds,
        public readonly array $results,
    ) {}

    /**
     * Build the report from a run's raw per-book entries.
     *
     * @param array<int, array{id: string, bookId: int|null, status: string,
     *               message: string, reason: string, changes: int}> $results
     * @param int                                                          $unprocessed
     *               records not even attempted (the run was cancelled).
     */
    public static function compile(float $startedAt, array $results, int $covers = 0, int $unprocessed = 0): self
    {
        $updated     = 0;
        $unchanged   = 0;
        $failed      = 0;
        $changedSum  = 0;

        foreach ($results as $item) {
            $changedSum  += (int) ($item['changes'] ?? 0);
            match ($item['status']) {
                self::STATUS_UPDATED   => $updated++,
                self::STATUS_UNCHANGED => $unchanged++,
                self::STATUS_FAILED    => $failed++,
                default                => null,
            };
        }

        $checked = $updated + $unchanged + $failed;

        return new self(
            total: count($results) + $unprocessed,
            checked: $checked,
            updated: $updated,
            unchanged: $unchanged,
            failed: $failed,
            skipped: count(array_filter($results, fn (array $e): bool => $e['status'] === self::STATUS_SKIPPED)) + $unprocessed,
            coversUpdated: $covers,
            changedFields: $changedSum,
            elapsedSeconds: microtime(true) - (float) $startedAt,
            results: $results,
        );
    }

    /** Whether the whole run went through without any failure. */
    public function ok(): bool
    {
        return $this->failed === 0;
    }

    /** Whether any book failed to synchronize. */
    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }

    /**
     * The export-ready shape (stable, serializable - the Phase 10.7
     * exporter can use it as-is).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $status = $this->hasFailures()
            ? 'failed'
            : ($this->updated > 0 ? 'updated' : 'ok');

        return [
            'total'           => $this->total,
            'checked'         => $this->checked,
            'updated'         => $this->updated,
            'unchanged'       => $this->unchanged,
            'failed'          => $this->failed,
            'skipped'         => $this->skipped,
            'covers_updated'  => $this->coversUpdated,
            'changed_fields'  => $this->changedFields,
            'elapsed_seconds' => round($this->elapsedSeconds, 3),
            'status'          => $status,
            'results'         => $this->results,
        ];
    }

    /**
     * The one-line human summary (the no-JavaScript flash and the
     * admin console both state the outcome in this form).
     */
    public function summary(): string
    {
        $parts = fn (int $n, string $word): string => $n . ' ' . $word . ($n === 1 ? '' : 's');

        $text = 'Sync finished: ' . $parts($this->checked, 'checked');

        if ($this->updated > 0) {
            $text .= ', ' . $parts($this->updated, 'updated');
        }

        if ($this->unchanged > 0) {
            $text .= ', ' . $parts($this->unchanged, 'unchanged');
        }

        if ($this->failed > 0) {
            $text .= ', ' . $parts($this->failed, 'failed');
        }

        if ($this->skipped > 0) {
            $text .= ', ' . $parts($this->skipped, 'skipped');
        }

        return $text . '.';
    }
}