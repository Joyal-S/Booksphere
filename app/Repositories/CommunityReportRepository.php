<?php

declare(strict_types=1);

namespace BookSphere\App\Repositories;

/**
 * CommunityReportRepository
 *
 * Data-access layer for the community_reports table (migration 0036).
 * Mirrors the ReviewRepository report section: status lifecycle
 * (pending -> reviewed -> resolved | dismissed), reason CHECK enum,
 * and an index-backed moderation queue read (idx_community_reports_status).
 */
final class CommunityReportRepository
{
    // ------------------------------------------------------------------ //
    // Writes                                                               //
    // ------------------------------------------------------------------ //

    /**
     * Insert a report row and return its id.
     *
     * @param array<string,mixed> $data  reported_by, post_id (nullable),
     *                                   comment_id (nullable), reason, description
     */
    public function create(array $data): int
    {
        db()->execute(
            'INSERT INTO community_reports
                (post_id, comment_id, reported_by, reason, description, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, \'pending\', ?, ?)',
            [
                isset($data['post_id'])    ? (int) $data['post_id']    : null,
                isset($data['comment_id']) ? (int) $data['comment_id'] : null,
                (int)    $data['reported_by'],
                (string) $data['reason'],
                (string) ($data['description'] ?? ''),
                $this->now(),
                $this->now(),
            ],
        );

        return (int) db()->lastInsertId();
    }

    /**
     * Advance a report's status (reviewed | dismissed | resolved).
     */
    public function updateStatus(int $id, string $status): bool
    {
        return db()->execute(
            'UPDATE community_reports SET status = ?, updated_at = ? WHERE id = ?',
            [$status, $this->now(), $id],
        ) > 0;
    }

    // ------------------------------------------------------------------ //
    // Reads                                                                //
    // ------------------------------------------------------------------ //

    /**
     * Find a single report by id.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $rows = db()->query(
            'SELECT * FROM community_reports WHERE id = ?',
            [$id],
        );

        return $rows[0] ?? null;
    }

    /**
     * Pending reports for the moderation queue, oldest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findPending(int $limit = 50, int $offset = 0): array
    {
        return db()->query(
            "SELECT r.*,
                    u.full_name AS reporter_name
             FROM community_reports r
             JOIN users u ON u.id = r.reported_by
             WHERE r.status = 'pending'
             ORDER BY r.created_at ASC, r.id ASC
             LIMIT ? OFFSET ?",
            [$limit, max(0, $offset)],
        );
    }

    /**
     * Count of pending reports (moderation queue badge).
     */
    public function countPending(): int
    {
        return (int) (db()->query(
            "SELECT COUNT(*) AS n FROM community_reports WHERE status = 'pending'"
        )[0]['n'] ?? 0);
    }

    /**
     * All reports for a specific post (admin inspection).
     *
     * @return array<int,array<string,mixed>>
     */
    public function findByPost(int $postId): array
    {
        return db()->query(
            'SELECT * FROM community_reports WHERE post_id = ? ORDER BY created_at ASC',
            [$postId],
        );
    }

    /**
     * All reports for a specific comment.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findByComment(int $commentId): array
    {
        return db()->query(
            'SELECT * FROM community_reports WHERE comment_id = ? ORDER BY created_at ASC',
            [$commentId],
        );
    }

    /**
     * Check whether a user already has an active (pending|reviewed) report
     * for the given target. Used to prevent duplicate submissions.
     */
    public function existsByReporter(int $reportedBy, ?int $postId, ?int $commentId): bool
    {
        if ($postId !== null) {
            $rows = db()->query(
                "SELECT id FROM community_reports
                 WHERE reported_by = ? AND post_id = ? AND status IN ('pending', 'reviewed')
                 LIMIT 1",
                [$reportedBy, $postId],
            );
        } else {
            $rows = db()->query(
                "SELECT id FROM community_reports
                 WHERE reported_by = ? AND comment_id = ? AND status IN ('pending', 'reviewed')
                 LIMIT 1",
                [$reportedBy, $commentId],
            );
        }

        return !empty($rows);
    }

    /**
     * All reports filtered by status for the admin moderation queue.
     * Joins reporter name + a content preview.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findAll(int $limit = 50, int $offset = 0, string $status = 'pending'): array
    {
        $statusWhere = ($status === 'all') ? '' : 'WHERE r.status = ?';
        $params      = ($status === 'all') ? [$limit, max(0, $offset)] : [$status, $limit, max(0, $offset)];

        return db()->query(
            "SELECT r.*,
                    u.full_name                                       AS reporter_name,
                    CASE
                        WHEN r.post_id    IS NOT NULL THEN 'post'
                        WHEN r.comment_id IS NOT NULL THEN 'comment'
                        ELSE 'unknown'
                    END                                               AS content_type,
                    COALESCE(p.title,    SUBSTR(cc.body, 1, 120))    AS content_preview,
                    COALESCE(pu.full_name, cu.full_name)             AS content_author
             FROM community_reports r
             JOIN users u  ON u.id  = r.reported_by
             LEFT JOIN community_posts    p  ON p.id  = r.post_id
             LEFT JOIN users             pu ON pu.id = p.user_id
             LEFT JOIN community_comments cc ON cc.id = r.comment_id
             LEFT JOIN users             cu ON cu.id = cc.user_id
             {$statusWhere}
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT ? OFFSET ?",
            $params,
        );
    }

    /**
     * Total count of reports for a given status (for pagination).
     */
    public function countAll(string $status = 'pending'): int
    {
        if ($status === 'all') {
            return (int) (db()->query(
                'SELECT COUNT(*) AS n FROM community_reports',
            )[0]['n'] ?? 0);
        }

        return (int) (db()->query(
            'SELECT COUNT(*) AS n FROM community_reports WHERE status = ?',
            [$status],
        )[0]['n'] ?? 0);
    }

    /**
     * Return a single report enriched with content context (for detail page).
     *
     * @return array<string,mixed>|null
     */
    public function findWithContext(int $id): ?array
    {
        $rows = db()->query(
            "SELECT r.*,
                    u.full_name                                      AS reporter_name,
                    CASE
                        WHEN r.post_id    IS NOT NULL THEN 'post'
                        WHEN r.comment_id IS NOT NULL THEN 'comment'
                        ELSE 'unknown'
                    END                                              AS content_type,
                    p.title                                          AS post_title,
                    p.body                                           AS post_body,
                    p.status                                         AS post_status,
                    p.book_id                                        AS post_book_id,
                    cc.body                                          AS comment_body,
                    cc.status                                        AS comment_status,
                    cc.post_id                                       AS comment_post_id,
                    cp.title                                         AS comment_post_title,
                    COALESCE(b.title, cb.title)                      AS book_title,
                    COALESCE(b.id, cb.id)                            AS book_id,
                    COALESCE(pu.full_name, cu.full_name)            AS content_author
             FROM community_reports r
             JOIN users u  ON u.id  = r.reported_by
             LEFT JOIN community_posts    p  ON p.id  = r.post_id
             LEFT JOIN users             pu ON pu.id = p.user_id
             LEFT JOIN books              b  ON b.id  = p.book_id
             LEFT JOIN community_comments cc ON cc.id = r.comment_id
             LEFT JOIN users             cu ON cu.id = cc.user_id
             LEFT JOIN community_posts   cp  ON cp.id = cc.post_id
             LEFT JOIN books             cb  ON cb.id = cp.book_id
             WHERE r.id = ?",
            [$id],
        );

        return $rows[0] ?? null;
    }

    /** Current UTC timestamp. */
    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
