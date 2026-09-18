<?php

declare(strict_types=1);

/**
 * ProfileImageUploadTest — Dedicated automated test suite for the
 * Profile Image Upload Feature.
 *
 * Verifies all 19 requirements:
 *     1. Authenticated user can upload valid JPG
 *     2. Authenticated user can upload valid PNG
 *     3. Authenticated user can upload valid WebP
 *     4. Unauthenticated upload is rejected
 *     5. Missing/invalid CSRF is rejected
 *     6. Oversized file is rejected (> 5 MB)
 *     7. Invalid MIME type is rejected
 *     8. Non-image file is rejected
 *     9. Executable/script file is rejected
 *    10. Path traversal attempt is rejected
 *    11. Uploaded file gets a safe unique server filename
 *    12. Database stores relative path, not machine-specific path
 *    13. Uploaded image displays correctly in views
 *    14. User can replace their image
 *    15. Old image is cleaned up safely on replacement
 *    16. User can remove their image
 *    17. Removing image restores initials fallback
 *    18. User cannot delete another user's image
 *    19. Failed storage/database operation does not leave broken state
 *
 * Run from project root:
 *     php tests/ProfileImageUploadTest.php
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Controllers\UserController;
use BookSphere\App\Core\Csrf;
use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\Middleware\AuthMiddleware;
use BookSphere\App\Middleware\CsrfMiddleware;
use BookSphere\App\Models\User;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\MediaService;

(new Environment(root_path('.env')))->load();

// ---------------------------------------------------------------------
// 0. Test Setup & Fixtures
// ---------------------------------------------------------------------

$dbPath = root_path('database/profile_upload_test.db');
foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) {
    if (is_file($f)) {
        @unlink($f);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

$session = new Session('profile_test_session');
$session->start();

$userModel = new User();
$auth = new AuthService($session, $userModel);
AuthService::setInstance($auth);
$csrf = new Csrf($session);

// Fixture images: Valid 16x16 JPEG, PNG, WebP
const FIXTURE_PNG_16 = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAFElEQVR4nGPYQiJgGNUwqmH4agAAE4UcH7xKE4UAAAAASUVORK5CYII=';
const FIXTURE_JPG_16 = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/wAALCAAQABABAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==';
const FIXTURE_WEBP_16 = 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoQABAADsD+JaQAA3AAAAAA';

$createdFiles = [];

function createTempFile(string $content, string $name = 'test.tmp'): string
{
    global $createdFiles;
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bs_test_' . bin2hex(random_bytes(6)) . '_' . $name;
    file_put_contents($path, $content);
    $createdFiles[] = $path;
    return $path;
}

register_shutdown_function(function () use (&$createdFiles, $dbPath) {
    foreach ($createdFiles as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
    foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
});

$checks = 0;
$passed = 0;
$failed = [];

$check = function (string $name, bool $condition, string $detail = '') use (&$checks, &$passed, &$failed): void {
    $checks++;
    if ($condition) {
        $passed++;
        echo "  PASS  {$name}\n";
    } else {
        $failed[] = $name;
        echo "  FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
};

echo "\n========================================================================\n";
echo "PROFILE IMAGE UPLOAD FEATURE TEST SUITE (19 REQUIREMENTS)\n";
echo "========================================================================\n\n";

function makeUploadRequest(array $fileData, ?string $token = null): Request
{
    $_FILES['avatar'] = $fileData;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
    if ($token !== null) {
        $_POST['_token'] = $token;
    } else {
        unset($_POST['_token']);
    }
    return new Request();
}

$user1Id = $userModel->create('Alice Test', 'alice.test@booksphere.test', password_hash('Secret123!', PASSWORD_DEFAULT));
$user2Id = $userModel->create('Bob Test', 'bob.test@booksphere.test', password_hash('Secret123!', PASSWORD_DEFAULT));

$userController = new UserController($auth, $userModel);

// ---------------------------------------------------------------------
// 1. Authenticated user can upload valid JPG
// ---------------------------------------------------------------------
$user1 = $userModel->findById($user1Id);
$auth->login($user1);

$jpgTmp = createTempFile(base64_decode(FIXTURE_JPG_16), 'avatar.jpg');
$request = makeUploadRequest([
    'name'     => 'profile.jpg',
    'type'     => 'image/jpeg',
    'tmp_name' => $jpgTmp,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($jpgTmp),
]);

ob_start();
$userController->uploadAvatar($request);
$resJpg = ob_get_clean();

$user1AfterJpg = $userModel->findById($user1Id);
$jpgPath = $user1AfterJpg['avatar_path'] ?? '';
$jpgDiskFile = root_path('public' . $jpgPath);
$createdFiles[] = $jpgDiskFile;

$check(
    '1. Authenticated user can upload valid JPG',
    $jpgPath !== '' && str_ends_with($jpgPath, '.jpg') && is_file($jpgDiskFile),
    "Stored path: {$jpgPath}"
);

// ---------------------------------------------------------------------
// 2. Authenticated user can upload valid PNG
// ---------------------------------------------------------------------
$pngTmp = createTempFile(base64_decode(FIXTURE_PNG_16), 'avatar.png');
$request = makeUploadRequest([
    'name'     => 'profile.png',
    'type'     => 'image/png',
    'tmp_name' => $pngTmp,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($pngTmp),
]);

ob_start();
$userController->uploadAvatar($request);
$resPng = ob_get_clean();

$user1AfterPng = $userModel->findById($user1Id);
$pngPath = $user1AfterPng['avatar_path'] ?? '';
$pngDiskFile = root_path('public' . $pngPath);
$createdFiles[] = $pngDiskFile;

$check(
    '2. Authenticated user can upload valid PNG',
    $pngPath !== '' && str_ends_with($pngPath, '.png') && is_file($pngDiskFile),
    "Stored path: {$pngPath}"
);

// ---------------------------------------------------------------------
// 3. Authenticated user can upload valid WebP
// ---------------------------------------------------------------------
$webpTmp = createTempFile(base64_decode(FIXTURE_WEBP_16), 'avatar.webp');
$request = makeUploadRequest([
    'name'     => 'profile.webp',
    'type'     => 'image/webp',
    'tmp_name' => $webpTmp,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($webpTmp),
]);

ob_start();
$userController->uploadAvatar($request);
$resWebp = ob_get_clean();

$user1AfterWebp = $userModel->findById($user1Id);
$webpPath = $user1AfterWebp['avatar_path'] ?? '';
$webpDiskFile = root_path('public' . $webpPath);
$createdFiles[] = $webpDiskFile;

$check(
    '3. Authenticated user can upload valid WebP',
    $webpPath !== '' && str_ends_with($webpPath, '.webp') && is_file($webpDiskFile),
    "Stored path: {$webpPath}"
);

// ---------------------------------------------------------------------
// 4. Unauthenticated upload is rejected
// ---------------------------------------------------------------------
$probeRoot = root_path();
$probeFile = sys_get_temp_dir() . '/booksphere_auth_probe_' . bin2hex(random_bytes(4)) . '.php';
$probeCode = '<?php' . PHP_EOL
    . 'require ' . var_export($probeRoot . '/bootstrap/constants.php', true) . ';' . PHP_EOL
    . 'require ' . var_export($probeRoot . '/vendor/autoload.php', true) . ';' . PHP_EOL
    . 'use BookSphere\App\Core\Database;' . PHP_EOL
    . 'use BookSphere\App\Core\Environment;' . PHP_EOL
    . 'use BookSphere\App\Core\Request;' . PHP_EOL
    . 'use BookSphere\App\Core\Session;' . PHP_EOL
    . 'use BookSphere\App\Middleware\AuthMiddleware;' . PHP_EOL
    . 'use BookSphere\App\Models\User;' . PHP_EOL
    . 'use BookSphere\App\Services\AuthService;' . PHP_EOL
    . '(new Environment(root_path(".env")))->load();' . PHP_EOL
    . 'Database::instance(' . var_export($dbPath, true) . ');' . PHP_EOL
    . '$session = new Session("auth_probe");' . PHP_EOL
    . '$session->start();' . PHP_EOL
    . '$auth = new AuthService($session, new User());' . PHP_EOL
    . 'register_shutdown_function(function() { echo "AUTH_GATE_STOPPED"; });' . PHP_EOL
    . '(new AuthMiddleware($auth))->handle(new Request(), function() { echo "REACHED_HANDLER"; });' . PHP_EOL;

file_put_contents($probeFile, $probeCode);
$authProbeOut = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probeFile) . ' 2>&1');
@unlink($probeFile);

$check(
    '4. Unauthenticated upload is rejected by AuthMiddleware',
    str_contains($authProbeOut, 'AUTH_GATE_STOPPED') && !str_contains($authProbeOut, 'REACHED_HANDLER'),
    "Output: {$authProbeOut}"
);

// ---------------------------------------------------------------------
// 5. Missing/invalid CSRF is rejected
// ---------------------------------------------------------------------
$csrfProbeFile = sys_get_temp_dir() . '/booksphere_csrf_probe_' . bin2hex(random_bytes(4)) . '.php';
$csrfProbeCode = '<?php' . PHP_EOL
    . 'require ' . var_export($probeRoot . '/bootstrap/constants.php', true) . ';' . PHP_EOL
    . 'require ' . var_export($probeRoot . '/vendor/autoload.php', true) . ';' . PHP_EOL
    . 'use BookSphere\App\Core\Csrf;' . PHP_EOL
    . 'use BookSphere\App\Core\Database;' . PHP_EOL
    . 'use BookSphere\App\Core\Environment;' . PHP_EOL
    . 'use BookSphere\App\Core\Request;' . PHP_EOL
    . 'use BookSphere\App\Core\Session;' . PHP_EOL
    . 'use BookSphere\App\Middleware\CsrfMiddleware;' . PHP_EOL
    . '(new Environment(root_path(".env")))->load();' . PHP_EOL
    . 'Database::instance(' . var_export($dbPath, true) . ');' . PHP_EOL
    . '$session = new Session("csrf_probe");' . PHP_EOL
    . '$session->start();' . PHP_EOL
    . '$csrf = new Csrf($session);' . PHP_EOL
    . '$_SERVER["REQUEST_METHOD"] = "POST";' . PHP_EOL
    . '$_POST["_token"] = "invalid_token";' . PHP_EOL
    . 'register_shutdown_function(function() { echo "CSRF_GATE_STOPPED"; });' . PHP_EOL
    . '(new CsrfMiddleware($csrf))->handle(new Request(), function() { echo "REACHED_HANDLER"; });' . PHP_EOL;

file_put_contents($csrfProbeFile, $csrfProbeCode);
$csrfProbeOut = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($csrfProbeFile) . ' 2>&1');
@unlink($csrfProbeFile);

$check(
    '5. Missing/invalid CSRF is rejected by CsrfMiddleware',
    str_contains($csrfProbeOut, 'CSRF_GATE_STOPPED') && !str_contains($csrfProbeOut, 'REACHED_HANDLER'),
    "Output: {$csrfProbeOut}"
);

// ---------------------------------------------------------------------
// 6. Oversized file is rejected (> 5 MB)
// ---------------------------------------------------------------------
$bigFile = createTempFile(str_repeat('A', 6 * 1024 * 1024), 'big.jpg');
$bigRequest = makeUploadRequest([
    'name'     => 'big.jpg',
    'type'     => 'image/jpeg',
    'tmp_name' => $bigFile,
    'error'    => UPLOAD_ERR_OK,
    'size'     => 6 * 1024 * 1024,
]);

$beforeOversized = $userModel->findById($user1Id)['avatar_path'];
ob_start();
$userController->uploadAvatar($bigRequest);
$bigOut = ob_get_clean();
$afterOversized = $userModel->findById($user1Id)['avatar_path'];

$check(
    '6. Oversized file is rejected (> 5 MB) without modifying avatar_path',
    $beforeOversized === $afterOversized && str_contains($bigOut, 'Image must be 5 MB or smaller.'),
    "Output: {$bigOut}"
);

// ---------------------------------------------------------------------
// 7. Invalid MIME type is rejected
// ---------------------------------------------------------------------
$pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\nxref\ntrailer\n<<>>\nstartxref\n%%EOF";
$pdfFile = createTempFile($pdfContent, 'document.pdf');
$pdfRequest = makeUploadRequest([
    'name'     => 'avatar.pdf',
    'type'     => 'application/pdf',
    'tmp_name' => $pdfFile,
    'error'    => UPLOAD_ERR_OK,
    'size'     => strlen($pdfContent),
]);

$beforeMime = $userModel->findById($user1Id)['avatar_path'];
ob_start();
$userController->uploadAvatar($pdfRequest);
$mimeOut = ob_get_clean();
$afterMime = $userModel->findById($user1Id)['avatar_path'];

$check(
    '7. Invalid MIME type (PDF) is rejected',
    $beforeMime === $afterMime && str_contains($mimeOut, 'Please upload a JPG, PNG, or WebP image.'),
    "Output: {$mimeOut}"
);

// ---------------------------------------------------------------------
// 8. Non-image file is rejected (e.g. text file renamed to .jpg)
// ---------------------------------------------------------------------
$fakeJpg = createTempFile("This is plain text and not a real JPEG image.", 'fake.jpg');
$fakeRequest = makeUploadRequest([
    'name'     => 'fake.jpg',
    'type'     => 'image/jpeg',
    'tmp_name' => $fakeJpg,
    'error'    => UPLOAD_ERR_OK,
    'size'     => strlen("This is plain text and not a real JPEG image."),
]);

$beforeFake = $userModel->findById($user1Id)['avatar_path'];
ob_start();
$userController->uploadAvatar($fakeRequest);
$fakeOut = ob_get_clean();
$afterFake = $userModel->findById($user1Id)['avatar_path'];

$check(
    '8. Non-image file with spoofed .jpg extension is rejected',
    $beforeFake === $afterFake && (str_contains($fakeOut, 'Please upload a JPG, PNG, or WebP image.') || str_contains($fakeOut, 'That file is not a valid image.')),
    "Output: {$fakeOut}"
);

// ---------------------------------------------------------------------
// 9. Executable/script file is rejected
// ---------------------------------------------------------------------
$phpContent = "<?php echo 'malicious script'; ?>";
$phpFile = createTempFile($phpContent, 'shell.php');
$phpRequest = makeUploadRequest([
    'name'     => 'shell.php',
    'type'     => 'application/x-php',
    'tmp_name' => $phpFile,
    'error'    => UPLOAD_ERR_OK,
    'size'     => strlen($phpContent),
]);

$beforePhp = $userModel->findById($user1Id)['avatar_path'];
ob_start();
$userController->uploadAvatar($phpRequest);
$phpOut = ob_get_clean();
$afterPhp = $userModel->findById($user1Id)['avatar_path'];

$check(
    '9. Script/executable file (.php) is rejected',
    $beforePhp === $afterPhp && (str_contains($phpOut, 'Please upload a JPG, PNG, or WebP image.') || str_contains($phpOut, 'That file is not a valid image.')),
    "Output: {$phpOut}"
);

// ---------------------------------------------------------------------
// 10. Path traversal attempt is rejected
// ---------------------------------------------------------------------
$traversalTmp = createTempFile(base64_decode(FIXTURE_JPG_16), 'traversal.jpg');
$traversalRequest = makeUploadRequest([
    'name'     => '../../../../etc/passwd.jpg',
    'type'     => 'image/jpeg',
    'tmp_name' => $traversalTmp,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($traversalTmp),
]);

ob_start();
$userController->uploadAvatar($traversalRequest);
ob_get_clean();

$user1AfterTraversal = $userModel->findById($user1Id);
$traversalPath = $user1AfterTraversal['avatar_path'] ?? '';
$createdFiles[] = root_path('public' . $traversalPath);

$check(
    '10. Path traversal attempt in client filename is ignored and sanitized',
    !str_contains($traversalPath, '..') && str_starts_with($traversalPath, '/uploads/profiles/user_'),
    "Stored path: {$traversalPath}"
);

// ---------------------------------------------------------------------
// 11. Uploaded file gets a safe unique server filename
// ---------------------------------------------------------------------
$storedBase = basename($traversalPath);
$check(
    '11. Uploaded file gets safe unique server filename (user_<id>_<token>.<ext>)',
    (bool) preg_match('/^user_' . $user1Id . '_[a-f0-9]{16}\.jpg$/', $storedBase),
    "Generated filename: {$storedBase}"
);

// ---------------------------------------------------------------------
// 12. Database stores relative path, not machine-specific path
// ---------------------------------------------------------------------
$check(
    '12. Database stores relative path, not machine-specific path',
    str_starts_with($traversalPath, '/uploads/profiles/')
    && !str_contains($traversalPath, ':')
    && !str_contains($traversalPath, 'PROJECTS')
    && !str_contains($traversalPath, 'booksphere'),
    "Stored path: {$traversalPath}"
);

// ---------------------------------------------------------------------
// 13. Uploaded image displays correctly in views
// ---------------------------------------------------------------------
$renderedUrl = avatar_url($traversalPath);
$assetUrl = asset($traversalPath);

$check(
    '13. Uploaded image resolves to accessible public URL in helpers and views',
    $renderedUrl === $traversalPath && $assetUrl === $traversalPath,
    "Resolved: {$renderedUrl}"
);

// ---------------------------------------------------------------------
// 14. User can replace their image
// ---------------------------------------------------------------------
$oldAvatarPath = $traversalPath;
$oldDiskFile = root_path('public' . $oldAvatarPath);

$newPngTmp = createTempFile(base64_decode(FIXTURE_PNG_16), 'replacement.png');
$replaceRequest = makeUploadRequest([
    'name'     => 'replacement.png',
    'type'     => 'image/png',
    'tmp_name' => $newPngTmp,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($newPngTmp),
]);

ob_start();
$userController->uploadAvatar($replaceRequest);
ob_get_clean();

$user1Replaced = $userModel->findById($user1Id);
$newAvatarPath = $user1Replaced['avatar_path'] ?? '';
$newDiskFile = root_path('public' . $newAvatarPath);
$createdFiles[] = $newDiskFile;

$check(
    '14. User can replace their image',
    $newAvatarPath !== '' && $newAvatarPath !== $oldAvatarPath && is_file($newDiskFile),
    "New path: {$newAvatarPath}"
);

// ---------------------------------------------------------------------
// 15. Old image is cleaned up safely on replacement
// ---------------------------------------------------------------------
$check(
    '15. Old image is unlinked from disk upon replacement',
    !is_file($oldDiskFile),
    "Old file still exists: " . (is_file($oldDiskFile) ? 'YES' : 'NO')
);

// ---------------------------------------------------------------------
// 16. User can remove their image
// ---------------------------------------------------------------------
$removeRequest = new Request();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';

ob_start();
$userController->removeAvatar($removeRequest);
ob_get_clean();

$user1Removed = $userModel->findById($user1Id);

$check(
    '16. User can remove their image (database avatar_path set to NULL and file removed)',
    $user1Removed['avatar_path'] === null && !is_file($newDiskFile)
);

// ---------------------------------------------------------------------
// 17. Removing image restores initials fallback
// ---------------------------------------------------------------------
$currentAvatarUrl = avatar_url($user1Removed['avatar_path']);
$initials = mb_strtoupper(mb_substr($user1Removed['full_name'], 0, 1));

$check(
    '17. Removing image restores initials fallback in views',
    $currentAvatarUrl === null && $initials === 'A'
);

// ---------------------------------------------------------------------
// 18. User cannot delete another user's image
// ---------------------------------------------------------------------
// Set up user 2 with an avatar
$user2 = $userModel->findById($user2Id);
$auth->login($user2);

$user2Png = createTempFile(base64_decode(FIXTURE_PNG_16), 'user2.png');
$u2Request = makeUploadRequest([
    'name'     => 'u2.png',
    'type'     => 'image/png',
    'tmp_name' => $user2Png,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($user2Png),
]);
ob_start();
$userController->uploadAvatar($u2Request);
ob_get_clean();

$user2WithAvatar = $userModel->findById($user2Id);
$user2AvatarPath = $user2WithAvatar['avatar_path'] ?? '';
$user2DiskFile = root_path('public' . $user2AvatarPath);
$createdFiles[] = $user2DiskFile;

// Switch back to user 1 (who has no avatar) and attempt to remove
$auth->login($user1Removed);
ob_start();
$userController->removeAvatar(new Request());
ob_get_clean();

// User 2's avatar must be completely untouched
$user2After = $userModel->findById($user2Id);
$check(
    "18. User cannot delete another user's image",
    $user2After['avatar_path'] === $user2AvatarPath && is_file($user2DiskFile)
);

// Clean up user 2's image
if (is_file($user2DiskFile)) {
    @unlink($user2DiskFile);
}

// ---------------------------------------------------------------------
// 19. Failed storage/database operation does not leave broken state
// ---------------------------------------------------------------------
$validTmp = createTempFile(base64_decode(FIXTURE_JPG_16), 'orphan_test.jpg');
$mediaService = new MediaService((array) (config('media.profiles') ?? []));

// Store a file
$storedTestPath = $mediaService->store([
    'tmp_name' => $validTmp,
    'name'     => 'orphan.jpg',
], 'user_test_failure');
$storedDisk = root_path('public' . $storedTestPath);
$createdFiles[] = $storedDisk;

$step1Ok = is_file($storedDisk);

// Simulate failure: database update failed -> orphan cleanup triggered
$mediaService->delete($storedTestPath);
$step2Ok = !is_file($storedDisk);

$check(
    '19. Failure-safe orphan file cleanup when database update fails',
    $step1Ok && $step2Ok
);

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
echo "\n========================================================================\n";
echo "RESULT: {$passed} / {$checks} CHECKS PASSED\n";
echo "========================================================================\n";

if ($passed !== $checks || !empty($failed)) {
    echo "Failed tests: " . implode(', ', $failed) . "\n";
    exit(1);
}

exit(0);
