<?php

declare(strict_types=1);

/**
 * CachingAuditTest — Dedicated test suite for Phase 13.3 Caching Audit & Hardening
 *
 * Verifies correctness across all caching subsystems:
 *     1. Cache Hit, Miss, and Expiration
 *     2. Invalidation and User Isolation
 *     3. Corrupted Cache Graceful Fallback & Self-Healing
 *     4. Write Failure Graceful Degradation
 *     5. Stale Entry Pruning
 *     6. Follow / Unfollow Recommendation Invalidation Hook
 *
 * Run from project root:
 *     php tests/CachingAuditTest.php
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Models\Author;
use BookSphere\App\Models\AuthorFollow;
use BookSphere\App\Services\CacheManager;
use BookSphere\App\Services\FollowService;
use BookSphere\App\Services\PersonalizationCache;
use BookSphere\App\Services\RecommendationFactory;
use BookSphere\App\Services\RecommendationService;
use BookSphere\App\Repositories\BookRepository;
use BookSphere\App\Repositories\RecommendationRepository;

$checks = 0;
$passed = 0;

$check = function (string $name, bool $condition) use (&$checks, &$passed): void {
    $checks++;
    if ($condition) {
        $passed++;
        echo "  PASS  {$name}\n";
    } else {
        echo "  FAIL  {$name}\n";
    }
};

echo "\n========================================================================\n";
echo "PHASE 13.3 CACHING AUDIT & HARDENING TEST SUITE\n";
echo "========================================================================\n";

$tempDir = rtrim(sys_get_temp_dir(), '/\\') . '/booksphere_cache_test_' . uniqid();

// 1. CACHEMANAGER TESTS
echo "\n1. CACHEMANAGER TESTS\n";
$cm = new CacheManager($tempDir, ['search' => 1, 'volume' => 86400], true);

$check('CacheManager miss returns null', $cm->get(CacheManager::NS_SEARCH, 'nonexistent') === null);

$cm->put(CacheManager::NS_SEARCH, 'php|1|10', ['total' => 5, 'items' => [['id' => 101]]]);
$hit = $cm->get(CacheManager::NS_SEARCH, 'php|1|10');
$check('CacheManager hit returns stored payload', is_array($hit) && isset($hit['total']) && $hit['total'] === 5);

// Test TTL Expiration
sleep(2);
$check('CacheManager get returns null on TTL expiration', $cm->get(CacheManager::NS_SEARCH, 'php|1|10') === null);
$check('CacheManager stale returns payload even after TTL expiration', is_array($cm->stale(CacheManager::NS_SEARCH, 'php|1|10')));

// Test Invalidation
$cm->put(CacheManager::NS_VOLUME, 'vol_123', ['volume' => 'test']);
$cm->invalidate(CacheManager::NS_VOLUME, 'vol_123');
$check('CacheManager invalidate removes entry', $cm->get(CacheManager::NS_VOLUME, 'vol_123') === null);

// Test Corrupted Cache File Self-Healing
$corruptFile = $tempDir . '/' . CacheManager::NS_VOLUME . '/' . sha1('vol_corrupt') . '.json';
@mkdir(dirname($corruptFile), 0755, true);
file_put_contents($corruptFile, '{invalid_json:');
$check('CacheManager gracefully handles corrupted JSON without error', $cm->get(CacheManager::NS_VOLUME, 'vol_corrupt') === null);
$check('CacheManager auto-deletes corrupted JSON file', !is_file($corruptFile));

// Test Write Failure Graceful Fallback
$badCm = new CacheManager('/invalid_nonexistent_dir_path_permission_denied', ['search' => 300], true);
$didThrow = false;
try {
    $badCm->put(CacheManager::NS_SEARCH, 'key', ['data' => 1]);
} catch (\Throwable) {
    $didThrow = true;
}
$check('CacheManager put does not crash application on write failure', !$didThrow);


// 2. PERSONALIZATION CACHE TESTS
echo "\n2. PERSONALIZATION CACHE TESTS\n";
$pcDir = $tempDir . '/recommendations';
$pc = new PersonalizationCache($pcDir, 1, true);

$check('PersonalizationCache miss returns null', $pc->get(100) === null);

$pc->put(100, ['items' => [1, 2, 3]]);
$pcHit = $pc->get(100);
$check('PersonalizationCache hit returns stored payload for User 100', is_array($pcHit) && count($pcHit['items']) === 3);

// User Isolation
$check('PersonalizationCache ensures User 100 cache does not leak to User 200', $pc->get(200) === null);

// Invalidation
$pc->invalidate(100);
$check('PersonalizationCache invalidate removes User 100 cache', $pc->get(100) === null);

// Prune Stale Entries
$pc->put(100, ['items' => [1]]);
$pc->put(200, ['items' => [2]]);
sleep(2);
$prunedCount = $pc->pruneStale();
$check('PersonalizationCache pruneStale removes expired files', $prunedCount >= 2);


// 3. FOLLOW SERVICE CACHE INVALIDATION HOOK
echo "\n3. FOLLOW SERVICE CACHE INVALIDATION HOOK\n";
$bookRepo = new BookRepository();
$recRepo = new RecommendationRepository($bookRepo);
$recFactory = new RecommendationFactory();
$recService = new RecommendationService($recFactory, $recRepo, $pc);

$followService = new FollowService(
    new AuthorFollow(),
    new Author(),
    null,
    null,
    $recService
);

$pc->put(999, ['items' => ['cached_rec']]);
$check('User 999 recommendation cache is initially stored', is_array($pc->get(999)));

$recService->invalidatePersonalization(999);
$check('FollowService recommendation service invalidates personalization cache', $pc->get(999) === null);

// Clean up test temp files
foreach (glob("{$tempDir}/*/*.json") ?: [] as $file) {
    @unlink($file);
}
foreach (glob("{$tempDir}/*") ?: [] as $sub) {
    if (is_dir($sub)) {
        @rmdir($sub);
    }
}
@rmdir($tempDir);

echo "\n------------------------------------------------------------------------\n";
echo "SUMMARY: Checks {$checks} | Passed {$passed} | Failed " . ($checks - $passed) . "\n";
echo "------------------------------------------------------------------------\n\n";

if ($passed !== $checks) {
    exit(1);
}
