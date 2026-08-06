<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Exceptions\NotificationException;

/**
 * NotificationFormatter
 *
 * The pure content generator of the Notification module (Phase 9.2,
 * blueprint Task 3): a type key + a context array in, a ready-to-
 * store content array out. It has NO I/O and NO state - it is a
 * plain function table, deterministic and exhaustively unit-testable.
 *
 * The per-type templates live in the TEMPLATES map - the ONE map the
 * whole module describes its types in (NotificationService::types()
 * enumerates its keys, the dispatcher resolves through it, the
 * database CHECK constraint in migration 0023 mirrors it). A type
 * missing from the map raises NotificationException::invalidType().
 *
 * Placeholder substitution: any "{key}" in a template's title,
 * message or action is replaced with the same-key value of the
 * context array at format time (e.g. "{author}" with the author's
 * name, "{author_id}" with the author's id). All user-supplied names
 * enter the content only through these placeholders - the VIEW
 * escapes them with e() at render time, never the formatter. A
 * context value that is missing or null substitutes as an empty
 * string (the system_announcement type leans on this: a missing
 * "{action_url}" collapses to a NULL action).
 *
 * The color values are the app's accent tokens (primary | info |
 * success | warning | danger); the view maps a token to a CSS class.
 */
final class NotificationFormatter
{
    /**
     * The type catalog (blueprint Task 5): every key the module
     * knows, with its title / message templates, its Font Awesome
     * 6.5.2 icon class, its accent token and its action URL
     * template. The single source of truth - NotificationService::
     * types() enumerates this map.
     *
     * @var array<string, array{title: string, message: string, icon: string, color: string, action: ?string}>
     */
    public const TEMPLATES = [
        'author_followed' => [
            'title'   => 'Following {author}',
            'message' => 'You started following {author}.',
            'icon'    => 'fa-solid fa-user-plus',
            'color'   => 'primary',
            'action'  => '/authors/{author_id}',
        ],
        'author_new_release' => [
            'title'   => '{author} published',
            'message' => '{book} by {author} is here.',
            'icon'    => 'fa-solid fa-book',
            'color'   => 'info',
            'action'  => '/books/{book_id}',
        ],
        'review_reacted' => [
            'title'   => 'Review appreciated',
            'message' => '{actor} found your review of {book} helpful.',
            'icon'    => 'fa-solid fa-thumbs-up',
            'color'   => 'success',
            'action'  => '/books/{book_id}/reviews',
        ],
        'review_replied' => [
            'title'   => 'New reply on your review',
            'message' => '{actor} replied to your review of {book}.',
            'icon'    => 'fa-solid fa-comment',
            'color'   => 'info',
            'action'  => '/reviews/{review_id}',
        ],
        'recommendation_ready' => [
            'title'   => 'Your picks are ready',
            'message' => 'New recommendations based on your library.',
            'icon'    => 'fa-solid fa-wand-magic-sparkles',
            'color'   => 'success',
            'action'  => '/recommendations',
        ],
        'wishlist_reminder' => [
            'title'   => 'Wishlist reminder',
            'message' => '{title} is waiting in your wishlist.',
            'icon'    => 'fa-solid fa-bell',
            'color'   => 'warning',
            'action'  => '/library',
        ],
        'library_milestone' => [
            'title'   => 'Library milestone',
            'message' => 'You finished {title}. Well read!',
            'icon'    => 'fa-solid fa-trophy',
            'color'   => 'success',
            'action'  => '/library',
        ],
        'system_announcement' => [
            'title'   => '{title}',
            'message' => '{message}',
            'icon'    => 'fa-solid fa-bullhorn',
            'color'   => 'danger',
            'action'  => '{action_url}',
        ],
        'admin_alert' => [
            'title'   => 'System alert',
            'message' => 'Something needs your attention.',
            'icon'    => 'fa-solid fa-triangle-exclamation',
            'color'   => 'danger',
            'action'  => null,
        ],
        'account_notice' => [
            'title'   => 'Account notice',
            'message' => '{message}',
            'icon'    => 'fa-solid fa-shield-halved',
            'color'   => 'warning',
            'action'  => '/profile',
        ],
    ];

    /**
     * Turn a type key + event context into the content row the
     * repository stores. The action_url collapses to null when the
     * substitution left it empty (no jump).
     *
     * @param string              $type    A TEMPLATES key
     * @param array<string, mixed> $context The event's values, e.g.
     *                                      ['author' => 'George Orwell',
     *                                       'author_id' => 2]
     * Turn a type key + event context into the content row the
     * repository stores. The action_url collapses to null when the
     * substitution left it empty (no jump).
     *
     * Phase 9.6 hardening: the action URL is an IN-APP PATH or
     * nothing. Any value that is not root-relative (starting with a
     * single "/") is dropped - an admin-facing template that passes
     * "{action_url}" verbatim can never produce a "javascript:…" or
     * "data:…" link that would later render in the browser of a
     * reader.
     *
     * @param string              $type    A TEMPLATES key
     * @param array<string, mixed> $context The event's values, e.g.
     *                                      ['author' => 'George Orwell',
     *                                       'author_id' => 2]
     * @return array<string, mixed> Keys: title, message, icon, color,
     *                              action_url
     */
    public function format(string $type, array $context): array
    {
        $template = self::TEMPLATES[$type] ?? null;

        if ($template === null) {
            throw NotificationException::invalidType($type);
        }

        $substitute = fn (string $template): string => (string) preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            fn (array $match): string => (string) ($context[$match[1]] ?? ''),
            $template,
        );

        $action = $template['action'] === null ? null : $substitute($template['action']);

        return [
            'title'      => $substitute($template['title']),
            'message'    => $substitute($template['message']),
            'icon'       => $template['icon'],
            'color'      => $template['color'],
            'action_url' => self::safeActionPath($action),
        ];
    }

    /**
     * Only an in-app (root-relative) path survives: '' -> null;
     * "javascript:…", "//host…", "\host…" and absolute URLs are all
     * dropped. A lone "/" links home.
     */
    public static function safeActionPath(?string $value): ?string
    {
        $path = trim((string) $value);

        if ($path === '' || !str_starts_with($path, '/')) {
            return null;
        }

        $rest = substr($path, 1);

        if ($rest !== '' && (str_starts_with($rest, '/') || str_starts_with($rest, '\\') || str_contains($path, "\0"))) {
            return null;
        }

        return $path;
    }
}
