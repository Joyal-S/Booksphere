<?php

declare(strict_types=1);

namespace BookSphere\App\Repositories;

/**
 * EmailPreferenceRepository
 *
 * The data-access layer of the EMAIL preferences (Phase 9.5): one
 * row per user in email_preferences (migration 0026), the five
 * opt-out toggles a user can silence without touching their in-app
 * notification preferences.
 *
 *     follow          -> follow confirmations + new releases
 *     review          -> "your review was found helpful"
 *     reply           -> "someone replied to your review"
 *     recommendations -> "your picks are ready"
 *     newsletter      -> reserved digests (a later phase)
 *
 * Every toggle is 0/1 and defaults to 1 (opt-out model). The
 * repository never invents column names: PREFERENCE_COLUMNS is the
 * allowlist, so a tampered key can never reach the SQL text.
 *
 * Dependencies:
 *     - db() helper (Core\Database singleton), exactly like the
 *       other repositories.
 */
final class EmailPreferenceRepository
{
    /**
     * Preference key -> column name. The ONLY columns the repository
     * will ever read or write.
     */
    private const PREFERENCE_COLUMNS = [
        'follow'          => 'follow',
        'review'          => 'review',
        'reply'           => 'reply',
        'recommendations' => 'recommendations',
        'newsletter'      => 'newsletter',
    ];

    /**
     * The user's five email toggles, or the defaults (all on) when no
     * row exists yet.
     *
     * @return array<string, int> Key -> 0/1
     */
    public function preferences(int $userId): array
    {
        $rows = db()->query(
            'SELECT follow, review, reply, recommendations, newsletter
             FROM email_preferences
             WHERE user_id = ?',
            [$userId],
        );

        $row = $rows[0] ?? [];

        return array_combine(
            array_keys(self::PREFERENCE_COLUMNS),
            array_map(
                fn (string $key): int => isset($row[$key]) ? (int) $row[$key] : 1,
                array_keys(self::PREFERENCE_COLUMNS),
            ),
        ) ?: [];
    }

    /**
     * Upsert one toggle (the INSERT ... ON CONFLICT (user_id) DO
     * UPDATE pattern of the notification preferences). The key is
     * allowlisted through PREFERENCE_COLUMNS, so an unknown key is a
     * silent no-op - the service validates first, this is the last
     * line of defence.
     */
    public function updatePreference(int $userId, string $key, bool $enabled): void
    {
        $column = self::PREFERENCE_COLUMNS[$key] ?? null;

        if ($column === null) {
            return;
        }

        db()->execute(
            'INSERT INTO email_preferences (user_id, ' . $column . ', updated_at)
             VALUES (?, ?, ?)
             ON CONFLICT (user_id) DO UPDATE SET
                ' . $column . ' = excluded.' . $column . ',
                updated_at = excluded.updated_at',
            [$userId, $enabled ? 1 : 0, $this->now()],
        );
    }

    /**
     * The current UTC timestamp in the database's stored format.
     */
    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}