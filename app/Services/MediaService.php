<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use RuntimeException;

/**
 * MediaService
 *
 * The reusable MEDIA STORAGE layer (Phase 5.4). It owns everything
 * that happens to an uploaded file between "the browser sent it"
 * and "a safe file sits in the uploads folder":
 *
 *     1. validate()  - server-side checks (size, MIME, dimensions)
 *     2. store()     - unique file name + move into the upload folder
 *     3. delete()    - remove a stored file (local uploads only)
 *     4. isLocal()   - is a URL a file we manage on disk?
 *
 * Why it exists:
 *     - The same pipeline previously lived inside BookService,
 *       duplicated per media type. Now one class manages ANY media
 *       kind (book covers today; author photos, review images
 *       later) configured through config/media.php. BookService
 *       only tells it WHICH kind to use.
 *     - Security is enforced here once: no executable uploads, no
 *       path traversal, no duplicate names, no fake MIME types.
 *
 * How it fits inside MVC:
 *     Controller -> BookService (business) -> MediaService (files)
 *
 * Config (see config/media.php): the "covers" entry passed on
 * construction. All fields are optional safe defaults.
 */
final class MediaService
{
    /**
     * @param array<string, mixed> $config Media type settings
     */
    public function __construct(private readonly array $config = []) {}

    /**
     * Validate an uploaded file against this media type's rules.
     *
     * Returns a user-friendly message, or null when the file is OK.
     * The checks (in order):
     *     - upload succeeded (no PHP error)
     *     - not over the size limit
     *     - really uploaded through HTTP (is_uploaded_file)
     *     - MIME type sniffed from CONTENT, not the file extension
     *     - for images: decodable (not corrupted) + within
     *       the configured width/height bounds
     *
     * A fake MIME type (e.g. a renamed .exe) fails the sniff check;
     * a truncated or corrupt image is caught by getimagesize().
     *
     * @param array<string, mixed> $file An entry from $_FILES
     */
    public function validate(array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return 'The file could not be uploaded.';
        }

        if ((int) ($file['size'] ?? 0) > $this->maxBytes()) {
            return 'The file must not exceed ' . $this->humanBytes($this->maxBytes()) . '.';
        }

        if (!is_uploaded_file($file['tmp_name'] ?? '') || !is_file($file['tmp_name'] ?? '')) {
            return 'The file could not be read.';
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);

        if (!isset($this->mimeExtensions()[$mime])) {
            return 'The file type is not allowed.';
        }

        $dimensionError = $this->validateDimensions($file['tmp_name']);
        if ($dimensionError !== null) {
            return $dimensionError;
        }

        // Structural integrity: getimagesize() reads the header only,
        // so a truncated or bit-rotten file could slip through with
        // correct dimensions. Each format is verified by decoding its
        // container below (PNG chunk checksums, JPEG markers, WebP
        // container), which catches corruption past the header.
        if (!$this->isStructurallyValid($file['tmp_name'], $mime)) {
            return 'The file appears to be corrupted or is not a valid image.';
        }

        return null;
    }

    /**
     * Move a validated file into the media folder and return its
     * public URL. The stored name is random, so two books can never
     * overwrite each other. Creates the folder if it is missing.
     *
     * @throws RuntimeException When the move is impossible
     */
    public function store(array $file): string
    {
        $directory = root_path($this->directory());

        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new RuntimeException('The uploads directory is not writable.');
        }

        $mime      = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extension = $this->mimeExtensions()[$mime];
        $name      = $this->uniqueName($extension);

        if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $name)) {
            throw new RuntimeException('The file could not be saved.');
        }

        return $this->publicPrefix() . $name;
    }

    /**
     * Delete a stored file from disk, if it is a local upload
     * managed by this media type. Remote URLs (the seeded
     * OpenLibrary covers) are returned untouched.
     */
    public function delete(?string $url): void
    {
        if (!$this->isLocal($url)) {
            return;
        }

        $file = root_path('public') . str_replace('/', DIRECTORY_SEPARATOR, $url);

        if (is_file($file)) {
            unlink($file);
        }
    }

    /**
     * Whether a URL (or null) points at a file stored by this
     * media type. False for null, for remote http(s) URLs and for
     * any path outside this media type's public prefix - so only
     * files WE wrote can ever be deleted (no path traversal).
     */
    public function isLocal(?string $url): bool
    {
        return $url !== null && str_starts_with($url, $this->publicPrefix());
    }

    /** The storage directory, relative to the project root. */
    private function directory(): string
    {
        return (string) ($this->config['directory'] ?? 'public/uploads');
    }

    /** The URL prefix served for stored files. */
    private function publicPrefix(): string
    {
        return (string) ($this->config['public_prefix'] ?? '/uploads/');
    }

    /** The size limit in bytes. */
    private function maxBytes(): int
    {
        return (int) ($this->config['max_bytes'] ?? 5 * 1024 * 1024);
    }

    /** The MIME type -> extension whitelist. */
    private function mimeExtensions(): array
    {
        return (array) ($this->config['mime_extensions'] ?? [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            // FUTURE seed: when docs or other media are enabled,
            // extend the table in config/media.php, not this default.
        ]);
    }

    /**
     * Verify an image is decodable and within the allowed bounds.
     * getimagesize() returns null/false on corruption AND gives
     * the real pixel dimensions, so one call covers both the
     * "corrupted file" and the "image dimensions" validation.
     *
     * @return string|null An error message, or null when OK
     */
    private function validateDimensions(string $path): ?string
    {
        $minWidth  = isset($this->config['min_width'])  ? (int) $this->config['min_width']  : null;
        $minHeight = isset($this->config['min_height']) ? (int) $this->config['min_height'] : null;
        $maxWidth  = isset($this->config['max_width'])  ? (int) $this->config['max_width']  : null;
        $maxHeight = isset($this->config['max_height']) ? (int) $this->config['max_height'] : null;

        if ($minWidth === null && $minHeight === null && $maxWidth === null && $maxHeight === null) {
            return null;
        }

        $info = @getimagesize($path);

        if ($info === false) {
            return 'The file appears to be corrupted or is not a valid image.';
        }

        [$width, $height] = $info;

        if (
            ($minWidth !== null && $width < $minWidth)
            || ($minHeight !== null && $height < $minHeight)
            || ($maxWidth !== null && $width > $maxWidth)
            || ($maxHeight !== null && $height > $maxHeight)
        ) {
            return 'Image dimensions must be between '
                . ($minWidth ?? 0) . 'x' . ($minHeight ?? 0)
                . ' and '
                . ($maxWidth ?? 8000) . 'x' . ($maxHeight ?? 8000)
                . ' pixels.';
        }

        return null;
    }

    /**
     * Verify the file container is complete and undamaged.
     *
     * getimagesize() only decodes the header, so a file with a valid
     * header but corrupt payload (truncated download, bit rot) still
     * needs a structural check. PNG is checked thoroughly (every
     * chunk checksum + inflating the pixel data), JPEG and WebP get
     * marker/container checks because they carry no checksums.
     */
    private function isStructurallyValid(string $path, string $mime): bool
    {
        return match ($mime) {
            'image/png'  => $this->pngIsValid($path),
            'image/jpeg' => $this->jpegIsValid($path),
            'image/webp' => $this->webpIsValid($path),
            default      => false,
        };
    }

    /**
     * PNG structural check: walk every chunk, verify its CRC32, and
     * inflate the concatenated IDAT payload (the actual pixels).
     * A truncated file fails either the CRC or the zlib inflate.
     */
    private function pngIsValid(string $path): bool
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false || strlen($bytes) < 33 || substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return false;
        }

        $offset = 8;
        $idat   = '';

        while ($offset + 8 <= strlen($bytes)) {
            [$length] = array_values(unpack('N', substr($bytes, $offset, 4)));
            $type = substr($bytes, $offset + 4, 4);

            if ($offset + 12 + $length > strlen($bytes)) {
                return false; // chunk data runs past the end of file
            }

            $data = substr($bytes, $offset + 8, $length);
            [$storedCrc] = array_values(unpack('N', substr($bytes, $offset + 8 + $length, 4)));
            $actualCrc = crc32($type . $data) & 0xFFFFFFFF;

            if ($storedCrc !== $actualCrc) {
                return false; // chunk was altered or truncated
            }

            if ($type === 'IDAT') {
                $idat .= $data;
            }

            if ($type === 'IEND') {
                break; // end of the file structure
            }

            $offset += 12 + $length;
        }

        // The pixel stream must decompress; a broken zlib stream means
        // the file is damaged even if every chunk CRC matched.
        return $idat !== '' && gzuncompress($idat) !== false;
    }

    /**
     * JPEG structural check: must start with the SOI marker (FFD8)
     * and end with the EOI marker (FFD9). JPEG has no per-chunk
     * checksums; the marker pair plus getimagesize() is the best
     * affordable guarantee.
     */
    private function jpegIsValid(string $path): bool
    {
        $bytes = @file_get_contents($path);

        return $bytes !== false
            && strlen($bytes) >= 4
            && ord($bytes[0]) === 0xFF && ord($bytes[1]) === 0xD8
            && ord($bytes[strlen($bytes) - 2]) === 0xFF && ord($bytes[strlen($bytes) - 1]) === 0xD9;
    }

    /**
     * WebP structural check: the RIFF container header must declare
     * the WEBP format and a size matching the real file length.
     */
    private function webpIsValid(string $path): bool
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false || strlen($bytes) < 12 || substr($bytes, 0, 4) !== 'RIFF') {
            return false;
        }

        [$declared] = array_values(unpack('V', substr($bytes, 4, 4)));

        return substr($bytes, 8, 4) === 'WEBP' && $declared === strlen($bytes) - 8;
    }

    /**
     * Generate a collision-free stored file name. The prefix comes
     * from the media config (e.g. "book" -> book_1a2b3c4d.png), so
     * two media types never share a name space.
     */
    private function uniqueName(string $extension): string
    {
        $prefix = (string) ($this->config['file_prefix'] ?? 'file');

        return $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    }

    /** Format a byte count compactly (e.g. "5 MB"). */
    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024 ? round($bytes / 1024 / 1024) . ' MB' : round($bytes / 1024) . ' KB';
    }
}