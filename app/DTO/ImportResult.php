<?php

declare(strict_types=1);

namespace BookSphere\App\DTO;

/**
 * ImportResult
 *
 * The outcome of ONE BookImportService::import() call, delivered to
 * the controller so it can answer the fetch caller (JSON) and the
 * no-JavaScript form (redirect + flash) with the same facts.
 *
 * Statuses:
 *     - imported   -> a new catalogue row was created
 *     - duplicate  -> the record already exists in the catalogue
 *                     (matched on google_book_id, isbn, or the
 *                     title+author fallback); nothing was written
 *
 * The message is already human-readable for humans/views; the status
 * is the stable, machine-readable key that drives the card button
 * state ("In library" / "Import") and the alert tone.
 */
final readonly class ImportResult
{
    public const STATUS_IMPORTED   = 'imported';
    public const STATUS_DUPLICATE  = 'duplicate';

    public function __construct(
        public readonly string $status,
        public readonly int $bookId,
        public readonly string $message,
    ) {}

    /**
     * Whether the record was skipped because it already exists. A
     * duplicate is not an error - the catalogue is already in the
     * desired state, just with a different tone than an import.
     */
    public function isDuplicate(): bool
    {
        return $this->status === self::STATUS_DUPLICATE;
    }
}