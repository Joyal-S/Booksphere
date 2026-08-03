<?php

declare(strict_types=1);

/**
 * config/media.php
 *
 * Media upload configuration (Phase 5.4).
 *
 * Every uploadable media type in the application is configured
 * here as one array entry. The key is the media "kind" (e.g.
 * "covers"); the values describe how files of that kind may be
 * stored and validated:
 *
 *     directory      - storage folder relative to the project root
 *     public_prefix  - the URL prefix served for that folder
 *     max_bytes      - hard size limit per file
 *     mime_extensions- MIME type -> stored extension whitelist
 *     min_width/min_height, max_width/max_height
 *                    - optional image dimension bounds
 *
 * The MediaService reads this table, so adding a NEW media type
 * (author photos, review images, ...) later means adding one
 * entry here - no service or controller changes.
 */

return [
    'covers' => [
        'directory'      => 'public/uploads/books',
        'public_prefix'  => '/uploads/books/',
        'file_prefix'    => 'book',
        'max_bytes'      => 5 * 1024 * 1024,
        'mime_extensions'=> [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ],
        'min_width'      => 100,
        'min_height'     => 100,
        'max_width'      => 8000,
        'max_height'     => 8000,
    ],
];
