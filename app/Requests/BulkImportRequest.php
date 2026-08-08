<?php

declare(strict_types=1);

namespace BookSphere\App\Requests;

/**
 * BulkImportRequest
 *
 * The gate for a bulk-import submission (Phase 10.5): turns the POSTed
 * `google_book_id[]` array into a clean, bounded, de-duplicated list of
 * volume ids the server is willing to import - or a validation error
 * the controller can answer with the right tone.
 *
 * Guards (mirroring the single-import guard in the controller, scaled
 * to a LIST):
 *     - the payload must be an array of strings
 *     - each id is trimmed, must be present and at most 128 chars
 *     - a charset allowlist (letters+digits plus `._-`, which covers
 *       every real Google Books volume id) rejects anything that could
 *       ever reach a URL / log line shaped like attacker input
 *     - repeated ids collapse to one (a duplicate submission is
 *       harmless - the request never imports the same book twice)
 *     - the total count is capped at the configured batch maximum, so
 *       a pasted monster payload can never start an unbounded run
 *
 * The valid, ordered id list is consumed by the controller:
 *     $request->ids() -> BulkImportService::import()
 */
final class BulkImportRequest
{
    /** The longest accepted Google Books volume id. */
    private const MAX_ID_LENGTH = 128;

    /** IDs that reach a provider URL / log line. Google volume ids are
     *  letters, digits and `._-` (e.g. "xEC2DwAAQBAJ"). */
    private const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D';

    /** @param array<int, mixed> $raw The raw POST value of google_book_id[] */
    public function __construct(
        private readonly array $raw,
        private readonly int $maxBatch = 100,
    ) {}

    /**
     * The validated, trimmed, de-duplicated ids (preserving the order
     * the admin selected them in), or [] when the payload was invalid.
     *
     * @return array<int, string>
     */
    public function ids(): array
    {
        $ids = [];

        foreach ($this->raw as $value) {
            if (!is_string($value)) {
                continue;
            }

            $id = trim($value);

            if ($id === '' || preg_match(self::ID_PATTERN, $id) !== 1) {
                continue;
            }

            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    /** Whether there is anything to import at all. */
    public function isEmpty(): bool
    {
        return $this->ids() === [];
    }

    /** Whether the request carries MORE ids than the batch allows. */
    public function exceedsLimit(): bool
    {
        return count($this->ids()) > max(1, $this->maxBatch);
    }

    /** The maximum number of ids one request may carry. */
    public function limit(): int
    {
        return max(1, $this->maxBatch);
    }

    /**
     * Whether the request may proceed: at least one id and not above
     * the batch ceiling.
     */
    public function valid(): bool
    {
        return !$this->isEmpty() && !$this->exceedsLimit();
    }

    /**
     * The validation error map (the same shape the single-import guard
     * answers with, so the 422 JSON contract does not change).
     *
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        if ($this->isEmpty()) {
            return ['google_book_id' => ['No books were selected for import.']];
        }

        if ($this->exceedsLimit()) {
            return ['google_book_id' => ['Select at most ' . $this->limit() . ' books per import.']];
        }

        return [];
    }
}