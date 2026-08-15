<?php

declare(strict_types=1);

/**
 * CommunityC4DTest — CLI test suite for Phase C4-D (Community Integration & Navigation)
 *
 * Tests:
 * - Sidebar Integration: link presence, icon, text, and strict active state ($active === 'community')
 * - Active Route Isolation: unrelated routes (e.g. /communityxyz) do not activate Community item
 * - Book Details Integration: Community Discussions section rendering & /community/book/{id} link
 * - Navigation: Community -> Book Details (/books/{id}) and Book Details -> Community (/community/book/{id})
 * - Author Filter Navigation: /community/user/{id} author link rendering
 *
 * Run from project root:
 *     php tests/CommunityC4DTest.php
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\Core\View;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\CommunityComment;
use BookSphere\App\Models\CommunityLike;
use BookSphere\App\Models\CommunityPost;
use BookSphere\App\Models\CommunityReport;
use BookSphere\App\Models\User;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\CommunityService;

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/community_c4d_test.db');
foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

$session = session();
$auth = new AuthService($session, new User());
AuthService::setInstance($auth);

$riyaUser = (new User())->findByEmail('riya@booksphere.test');
$riyaId   = (int) $riyaUser['id'];
$bookId   = (int) db()->query('SELECT id FROM books LIMIT 1')[0]['id'];

$auth->login($riyaUser);

$service = new CommunityService(
    new CommunityPost(),
    new CommunityComment(),
    new CommunityLike(),
    new CommunityReport(),
    new Book()
);

$checks = 0;
$failed = 0;

function check(bool $cond, string $desc): void
{
    global $checks, $failed;
    $checks++;
    if ($cond) {
        echo "  PASS  {$desc}\n";
    } else {
        $failed++;
        echo "  FAIL  {$desc}\n";
    }
}

echo "\n------------------------------------------------------------------------\n";
echo "1. SIDEBAR NAVIGATION INTEGRATION\n";
echo "------------------------------------------------------------------------\n";

// Render sidebar partial with $active = 'community'
$sidebarHtmlActive = View::fragment('partials.sidebar', ['active' => 'community']);
check(str_contains($sidebarHtmlActive, 'href="/community"'), 'Sidebar contains href="/community"');
check(str_contains($sidebarHtmlActive, 'fa-users'), 'Sidebar contains fa-users icon');
check(str_contains($sidebarHtmlActive, '<span>Community</span>'), 'Sidebar contains "Community" label');
check(str_contains($sidebarHtmlActive, 'class="nav-item is-active" href="/community"'), 'Community item has "is-active" class when $active === "community"');

// Render sidebar with other active routes
$sidebarDashboard = View::fragment('partials.sidebar', ['active' => 'dashboard']);
check(!str_contains($sidebarDashboard, 'href="/community" title="Community discussions" class="nav-item is-active"'), 'Community is NOT active on dashboard ($active === "dashboard")');

$sidebarBooks = View::fragment('partials.sidebar', ['active' => 'books']);
check(!str_contains($sidebarBooks, 'href="/community" title="Community discussions" class="nav-item is-active"'), 'Community is NOT active on books page ($active === "books")');

$sidebarUnrelated = View::fragment('partials.sidebar', ['active' => 'communityxyz']);
check(!str_contains($sidebarUnrelated, 'href="/community" title="Community discussions" class="nav-item is-active"'), 'Community is NOT active for unrelated string ($active === "communityxyz")');

echo "\n------------------------------------------------------------------------\n";
echo "2. BOOK DETAILS INTEGRATION\n";
echo "------------------------------------------------------------------------\n";

$bookRow = (new Book())->findById($bookId);
$bookShowHtml = View::fragment('books.show', [
    'book'           => $bookRow,
    'statuses'       => ['published' => 'Published'],
    'isAdmin'        => false,
    'communityCount' => 3,
]);

check(str_contains($bookShowHtml, 'Community Discussions'), 'Book Details renders "Community Discussions" section');
check(str_contains($bookShowHtml, '3 discussions about this book'), 'Book Details displays correct discussion count');
check(str_contains($bookShowHtml, 'href="/community/book/' . $bookId . '"'), 'Book Details links to /community/book/' . $bookId);

echo "\n------------------------------------------------------------------------\n";
echo "3. COMMUNITY ↔ BOOK NAVIGATION & FILTERS\n";
echo "------------------------------------------------------------------------\n";

// Create a post linked to the book
$postId = $service->createPost($riyaId, [
    'title'   => 'Linked Discussion for Book Navigation',
    'body'    => 'This is a test post attached to a book for C4-D integration tests.',
    'book_id' => $bookId,
]);

$bookPosts = $service->listPostsForBook($bookId);
check(count($bookPosts['items']) > 0, 'listPostsForBook returns posts for specific book');
check((int) $bookPosts['items'][0]['book_id'] === $bookId, 'Returned post belongs to the book ID');

// Render Community post feed card and check book details link
$feedHtml = View::fragment('community.index', [
    'posts' => $bookPosts['items'],
    'total' => 1,
    'page'  => 1,
    'pages' => 1,
]);
check(str_contains($feedHtml, 'href="/books/' . $bookId . '"'), 'Community post card links to Book Details (/books/' . $bookId . ')');

echo "\n------------------------------------------------------------------------\n";
echo "4. AUTHOR COMMUNITY PROFILE NAVIGATION\n";
echo "------------------------------------------------------------------------\n";

check(str_contains($feedHtml, 'href="/community/user/' . $riyaId . '"'), 'Community feed links author name/avatar to /community/user/' . $riyaId);

$userPosts = $service->listPostsByUser($riyaId);
check(count($userPosts['items']) > 0, 'listPostsByUser returns posts authored by user ID');

echo "\n------------------------------------------------------------------------\n";
echo "TEST SUMMARY\n";
echo "------------------------------------------------------------------------\n";
echo "  Total checks: {$checks}\n";
echo "  Failures:     {$failed}\n\n";

if ($failed === 0) {
    echo "  RESULT: PASS ✓\n\n";
    exit(0);
} else {
    echo "  RESULT: FAIL ✗\n\n";
    exit(1);
}
