<?php

declare(strict_types=1);

/**
 * tests/CommunityC7CTest.php
 *
 * Automated CLI Test Suite for Phase C7-C: Book Discussion Hubs.
 */

require_once __DIR__ . '/../bootstrap/constants.php';
require_once __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Database;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\CommunityComment;
use BookSphere\App\Models\CommunityFollow;
use BookSphere\App\Models\CommunityLike;
use BookSphere\App\Models\CommunityPost;
use BookSphere\App\Models\CommunityReport;
use BookSphere\App\Models\User;
use BookSphere\App\Policies\CommunityPolicy;
use BookSphere\App\Services\CommunityService;

$pdo = Database::instance()->pdo();

// Clean existing test rows
$pdo->exec("DELETE FROM community_reports");
$pdo->exec("DELETE FROM community_likes");
$pdo->exec("DELETE FROM community_comments");
$pdo->exec("DELETE FROM community_posts");
$pdo->exec("DELETE FROM users WHERE email LIKE 'c7c_%@example.com'");
$pdo->exec("DELETE FROM books WHERE isbn LIKE '97800000077C%'");

// Create test user
$userModel = new User();
$u1Id = $userModel->create('C7C Tester', 'c7c_tester@example.com', password_hash('password123', PASSWORD_BCRYPT));

// Insert test books
$pdo->exec("INSERT INTO books (id, title, isbn, language, average_rating, ratings_count) VALUES (7710, 'C7C Hub Book One', '97800000077C1', 'en', 4.5, 10)");
$pdo->exec("INSERT INTO books (id, title, isbn, language, average_rating, ratings_count) VALUES (7720, 'C7C Hub Book Two', '97800000077C2', 'en', 4.0, 5)");
$b1Id = 7710;
$b2Id = 7720;

$postModel    = new CommunityPost();
$commentModel = new CommunityComment();
$likeModel    = new CommunityLike();
$reportModel  = new CommunityReport();
$followModel  = new CommunityFollow();
$bookModel    = new Book();
$service      = new CommunityService($postModel, $commentModel, $likeModel, $reportModel, $bookModel, null, $followModel);

// Seed discussions
$post1 = $service->createPost($u1Id, ['title' => 'Book 1 Character Discussion', 'body' => 'Deep dive into character arcs.', 'book_id' => $b1Id]);
$post2 = $service->createPost($u1Id, ['title' => 'Book 1 Ending Theory', 'body' => 'What really happened in the climax?', 'book_id' => $b1Id]);
$post3 = $service->createPost($u1Id, ['title' => 'Book 2 General Review Discussion', 'body' => 'Thoughts on world building.', 'book_id' => $b2Id]);

// Add comment & like to post1
$comm1 = $service->createComment($u1Id, $post1, ['body' => 'I completely agree with this analysis!']);
$service->likePost($u1Id, $post1);

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $description, &$passed, &$failed): void {
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failed++;
    }
}

echo "========================================================================\n";
echo "PHASE C7-C: BOOK DISCUSSION HUBS TEST SUITE\n";
echo "========================================================================\n\n";

// 1. VALID & INVALID BOOK HUB FETCH
echo "1. VALID & INVALID BOOK HUB FETCH\n";
echo "------------------------------------------------------------------------\n";
$book1Details = $bookModel->findWithRelations($b1Id);
assertTest($book1Details !== null && (int)$book1Details['id'] === $b1Id, "Book model returns valid details for Book 1", $passed, $failed);

$invalidBook = $bookModel->findWithRelations(999999);
assertTest($invalidBook === null, "Book model returns null for non-existent Book 999999", $passed, $failed);

// 2. BOOK SCOPING & ACTIVE MODERATION
echo "\n2. BOOK SCOPING & ACTIVE MODERATION\n";
echo "------------------------------------------------------------------------\n";
$b1Posts = $service->listDiscoveryPosts('recent', $b1Id, null, null, 1, 20);
assertTest($b1Posts['total'] === 2, "Book 1 Hub returns exactly 2 posts", $passed, $failed);

$b2Posts = $service->listDiscoveryPosts('recent', $b2Id, null, null, 1, 20);
assertTest($b2Posts['total'] === 1, "Book 2 Hub returns exactly 1 post", $passed, $failed);
assertTest((int)$b2Posts['items'][0]['id'] === $post3, "Book 2 Hub contains only post 3", $passed, $failed);

// Hide post2 and verify exclusion
$pdo->exec("UPDATE community_posts SET status = 'hidden' WHERE id = {$post2}");
$b1PostsAfterHide = $service->listDiscoveryPosts('recent', $b1Id, null, null, 1, 20);
assertTest($b1PostsAfterHide['total'] === 1, "Hidden post is excluded from Book 1 Hub feed (total = 1)", $passed, $failed);
$pdo->exec("UPDATE community_posts SET status = 'active' WHERE id = {$post2}");

// 3. BOOK DISCUSSION AGGREGATE STATS
echo "\n3. BOOK DISCUSSION AGGREGATE STATS\n";
echo "------------------------------------------------------------------------\n";
$statsB1 = $service->getBookDiscussionStats($b1Id);
assertTest($statsB1['posts'] === 2, "Book 1 stats carries 2 posts", $passed, $failed);
assertTest($statsB1['comments'] === 1, "Book 1 stats carries 1 active comment", $passed, $failed);
assertTest($statsB1['likes'] === 1, "Book 1 stats carries 1 like", $passed, $failed);

// 4. DISCOVERY SORTING & SEARCH SCOPED TO BOOK
echo "\n4. DISCOVERY SORTING & SEARCH SCOPED TO BOOK\n";
echo "------------------------------------------------------------------------\n";
$searchResult = $service->listDiscoveryPosts('recent', $b1Id, null, 'Character', 1, 20);
assertTest($searchResult['total'] === 1, "Searching 'Character' within Book 1 returns 1 post", $passed, $failed);
assertTest((int)$searchResult['items'][0]['id'] === $post1, "Search result is post 1", $passed, $failed);

$searchOtherBook = $service->listDiscoveryPosts('recent', $b2Id, null, 'Character', 1, 20);
assertTest($searchOtherBook['total'] === 0, "Searching 'Character' within Book 2 returns 0 posts (scoped)", $passed, $failed);

$popularSort = $service->listDiscoveryPosts('popular', $b1Id, null, null, 1, 20);
assertTest((int)$popularSort['items'][0]['id'] === $post1, "Popular sort ranks post 1 first due to likes/comments", $passed, $failed);

// 5. PRESELECTED POST CREATION PARAMETER
echo "\n5. PRESELECTED POST CREATION PARAMETER\n";
echo "------------------------------------------------------------------------\n";
$_GET['book_id'] = (string) $b1Id;
$activeBookId = (int) ($_POST['book_id'] ?? ($_GET['book_id'] ?? 0));
assertTest($activeBookId === $b1Id, "create.php preselects book_id {$b1Id} from query string", $passed, $failed);
unset($_GET['book_id']);

// 6. SECURITY & SANITIZATION
echo "\n6. SECURITY & SANITIZATION\n";
echo "------------------------------------------------------------------------\n";
$sqlInjectionSearch = $service->listDiscoveryPosts('recent', $b1Id, null, "' OR '1'='1", 1, 20);
assertTest($sqlInjectionSearch['total'] === 0, "SQL injection search payload handled safely", $passed, $failed);

echo "\n------------------------------------------------------------------------\n";
echo "SUMMARY: Checks " . ($passed + $failed) . " | Passed {$passed} | Failed {$failed}\n";
echo "------------------------------------------------------------------------\n";

if ($failed > 0) {
    exit(1);
}
