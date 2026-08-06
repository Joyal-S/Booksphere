<?php

declare(strict_types=1);

namespace BookSphere\App\Models;

use BookSphere\App\Repositories\EmailPreferenceRepository;

/**
 * EmailPreference
 *
 * The thin facade of the email preferences data layer (Phase 9.5),
 * following the exact pattern of the Notification model: no business
 * logic, no SQL - just one predictable interface over
 * EmailPreferenceRepository for the service and the settings page.
 *
 * Dependencies:
 *     - EmailPreferenceRepository (the actual PDO/prepared SQL).
 */
final class EmailPreference
{
    public function __construct(private readonly EmailPreferenceRepository $repository = new EmailPreferenceRepository()) {}

    /**
     * The user's five email toggles (defaults: all on, when no row
     * exists yet).
     *
     * @return array<string, int> Key -> 0/1
     */
    public function preferences(int $userId): array
    {
        return $this->repository->preferences($userId);
    }

    /**
     * Upsert one toggle.
     */
    public function updatePreference(int $userId, string $key, bool $enabled): void
    {
        $this->repository->updatePreference($userId, $key, $enabled);
    }
}