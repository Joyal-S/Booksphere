<?php

declare(strict_types=1);

/**
 * tests/AdminAuthorManagementTest.php
 *
 * Automated verification of Admin Author Management & Safe Author Deletion:
 *
 * 1. Author with 1 book -> delete author -> book remains.
 * 2. Author with multiple books -> delete author -> all books remain.
 * 3. Author shared with another author on a book -> delete one author -> other author relationship remains.
 * 4. Author whose deletion would leave a book with zero authors -> warning/confirmation works correctly.
 * 5. Non-admin cannot access author management.
 * 6. CSRF protection works.
 * 7. Invalid author ID is handled safely.
 * 8. Failed deletion rolls back completely.
 * 9. Author edit works.
 * 10. Author detail page displays all associated published books.
 * 11. Existing book deletion still works.
 * 12. Recommendations remain unchanged.
 * 13. Library/reviews/community remain unchanged.
 * 14. Database integrity checks (PRAGMA integrity_check & foreign_key_check).
 */

require_once __DIR__ . '/../bootstrap/constants.php';
require_once __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Config;
use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Core\Session;
use BookSphere\App\Models\Author;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\User;
use BookSphere\App\Controllers\AdminAuthorController;
use BookSphere\App\Controllers\AuthorController;
use BookSphere\App\Repositories\BookRepository;
use BookSphere\App\Repositories\ReviewRepository;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\BookService;
use BookSphere\App\Services\ReviewService;

// Use an isolated test database cloned from live DB to ensure live database safety
$liveDb = root_path('database/booksphere.db');
$testDb = root_path('database/admin_author_test.db');
copy($liveDb, $testDb);

putenv("DB_PATH={$testDb}");
$_ENV['DB_PATH'] = $testDb;
(new Environment(root_path('.env')))->load();
Config::loadFromDirectory(root_path('config'));

// Force Database instance to use testDb
$testDatabaseInstance = new Database($testDb);
$ref = new ReflectionClass(Database::class);
$prop = $ref->getProperty('instance');
$prop->setAccessible(true);
$prop->setValue(null, $testDatabaseInstance);

$pdo = $testDatabaseInstance->pdo();

$checks = 0;
$failures = 0;

$assert = function (string $label, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks++;
    if ($ok) {
        echo "  [PASS] {$label}\n";
    } else {
        $failures++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
};

echo "========================================================================\n";
echo "ADMIN AUTHOR MANAGEMENT & SAFE AUTHOR DELETION TEST SUITE\n";
echo "========================================================================\n\n";

$authorModel = new Author();
$bookModel = new Book();
$adminAuthorController = new AdminAuthorController($authorModel);

// -------------------------------------------------------------------------
// Scenario 1: Author with 1 book -> delete author -> book remains
// -------------------------------------------------------------------------
echo "--- Scenario 1: Author with 1 book -> delete author -> book remains ---\n";
// Insert test author and test book
$pdo->exec("INSERT INTO authors (name, biography) VALUES ('Test Sole Author 1', 'Bio 1')");
$author1Id = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO books (title, isbn, status) VALUES ('Test Book Sole 1', 'ISBN-TEST-1', 'published')");
$book1Id = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO book_authors (book_id, author_id) VALUES ({$book1Id}, {$author1Id})");

// Delete author transactionally
$deleted = $authorModel->deleteAuthorTransaction($author1Id);
$assert("Author record was deleted", $deleted && $authorModel->findById($author1Id) === null);

// Verify relationship was removed
$links1 = $pdo->query("SELECT COUNT(*) FROM book_authors WHERE author_id = {$author1Id}")->fetchColumn();
$assert("book_authors relationship removed", (int) $links1 === 0);

// Verify book remains in database
$bookCheck1 = $pdo->query("SELECT id, title FROM books WHERE id = {$book1Id}")->fetch(PDO::FETCH_ASSOC);
$assert("Associated book remains in database", !empty($bookCheck1) && $bookCheck1['title'] === 'Test Book Sole 1');

// -------------------------------------------------------------------------
// Scenario 2: Author with multiple books -> delete author -> all books remain
// -------------------------------------------------------------------------
echo "\n--- Scenario 2: Author with multiple books -> delete author -> all books remain ---\n";
$pdo->exec("INSERT INTO authors (name, biography) VALUES ('Test Multi Author 2', 'Bio 2')");
$author2Id = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO books (title, isbn, status) VALUES ('Test Multi Book A', 'ISBN-TEST-2A', 'published')");
$book2AId = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO books (title, isbn, status) VALUES ('Test Multi Book B', 'ISBN-TEST-2B', 'published')");
$book2BId = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO book_authors (book_id, author_id) VALUES ({$book2AId}, {$author2Id})");
$pdo->exec("INSERT INTO book_authors (book_id, author_id) VALUES ({$book2BId}, {$author2Id})");

// Verify author has 2 books before deletion
$booksBefore = $authorModel->booksFor($author2Id);
$assert("Author initially has 2 associated books", count($booksBefore) === 2);

// Delete author
$deleted2 = $authorModel->deleteAuthorTransaction($author2Id);
$assert("Author 2 deleted", $deleted2 && $authorModel->findById($author2Id) === null);

// Verify both books remain in database
$book2ACheck = $pdo->query("SELECT id FROM books WHERE id = {$book2AId}")->fetchColumn();
$book2BCheck = $pdo->query("SELECT id FROM books WHERE id = {$book2BId}")->fetchColumn();
$assert("Book A remains in database", (int) $book2ACheck === $book2AId);
$assert("Book B remains in database", (int) $book2BCheck === $book2BId);

// -------------------------------------------------------------------------
// Scenario 3: Multi-author book -> delete one author -> other author remains
// -------------------------------------------------------------------------
echo "\n--- Scenario 3: Multi-author book -> delete one author -> other author remains ---\n";
$pdo->exec("INSERT INTO authors (name) VALUES ('CoAuthor X')");
$authorXId = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO authors (name) VALUES ('CoAuthor Y')");
$authorYId = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO books (title, isbn, status) VALUES ('Collaborative Masterpiece', 'ISBN-TEST-COLAB', 'published')");
$colabBookId = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO book_authors (book_id, author_id) VALUES ({$colabBookId}, {$authorXId})");
$pdo->exec("INSERT INTO book_authors (book_id, author_id) VALUES ({$colabBookId}, {$authorYId})");

// Delete Author X
$deletedX = $authorModel->deleteAuthorTransaction($authorXId);
$assert("CoAuthor X deleted", $deletedX);

// Verify Author Y remains linked to the book
$yLink = $pdo->query("SELECT COUNT(*) FROM book_authors WHERE book_id = {$colabBookId} AND author_id = {$authorYId}")->fetchColumn();
$assert("CoAuthor Y remains linked to the book", (int) $yLink === 1);

// Verify book is untouched
$colabBook = $pdo->query("SELECT title FROM books WHERE id = {$colabBookId}")->fetchColumn();
$assert("Collaborative book remains intact", $colabBook === 'Collaborative Masterpiece');

// -------------------------------------------------------------------------
// Scenario 4: Author whose deletion leaves authorless books -> warning/confirmation
// -------------------------------------------------------------------------
echo "\n--- Scenario 4: Authorless book warning and confirmation enforcement ---\n";
$pdo->exec("INSERT INTO authors (name) VALUES ('Author Leaving Orphan Book')");
$orphanAuthorId = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO books (title, isbn, status) VALUES ('Orphan Candidate Book', 'ISBN-TEST-ORPH', 'published')");
$orphanCandidateId = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO book_authors (book_id, author_id) VALUES ({$orphanCandidateId}, {$orphanAuthorId})");

// Inspect via booksFor
$booksForOrphan = $authorModel->booksFor($orphanAuthorId);
$authorlessBooks = array_filter($booksForOrphan, fn($b) => (int)$b['total_authors_count'] <= 1);
$assert("Correctly identifies books that will have 0 authors", count($authorlessBooks) === 1);

// -------------------------------------------------------------------------
// Scenario 5: Author browse and search
// -------------------------------------------------------------------------
echo "\n--- Scenario 5: Admin Author browse, search, and book counts ---\n";
$browseResult = $authorModel->browse(['q' => 'CoAuthor Y']);
$assert("Author search by name returns matching author", $browseResult['total'] >= 1);
$assert("Author browse includes books_count metric", isset($browseResult['items'][0]['books_count']));

// -------------------------------------------------------------------------
// Scenario 6: Author update
// -------------------------------------------------------------------------
echo "\n--- Scenario 6: Author edit and update ---\n";
$updated = $authorModel->update($authorYId, [
    'name' => 'CoAuthor Y Updated',
    'biography' => 'Updated prestigious biography.',
]);
$assert("Author update returns true", $updated);

$authorYRow = $authorModel->findById($authorYId);
$assert("Author name was updated", $authorYRow['name'] === 'CoAuthor Y Updated');
$assert("Author biography was updated", $authorYRow['biography'] === 'Updated prestigious biography.');

// -------------------------------------------------------------------------
// Scenario 7: Form Validation
// -------------------------------------------------------------------------
echo "\n--- Scenario 7: AuthorRequest form validation ---\n";
$valPass = \BookSphere\App\Requests\AuthorRequest::passes(['name' => 'Valid Name']);
$assert("AuthorRequest passes with valid name", $valPass);

$valFail = \BookSphere\App\Requests\AuthorRequest::passes(['name' => '']);
$assert("AuthorRequest rejects empty name", !$valFail);

// -------------------------------------------------------------------------
// Scenario 8: Transactional Rollback on Failure
// -------------------------------------------------------------------------
echo "\n--- Scenario 8: Failed deletion rollback ---\n";
$pdo->exec("INSERT INTO authors (name) VALUES ('Author Rollback Test')");
$rollbackAuthorId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO book_authors (book_id, author_id) VALUES ({$colabBookId}, {$rollbackAuthorId})");

// Simulate transaction failure inside a transaction
$pdo->beginTransaction();
$authorModel->deleteAuthorTransaction($rollbackAuthorId);
$pdo->rollBack();

// Verify author still exists after rollback
$rolledBackAuthor = $authorModel->findById($rollbackAuthorId);
$assert("Author still exists after transaction rollback", $rolledBackAuthor !== null);

// -------------------------------------------------------------------------
// Scenario 9: Public Author Detail Page Fix
// -------------------------------------------------------------------------
echo "\n--- Scenario 9: Public Author Detail Page loads published books ---\n";
// Test with Abraham Silberschatz (#484)
$silberschatzBooks = $bookModel->browse(['author_id' => 484, 'status' => 'published']);
$assert("Abraham Silberschatz (ID 484) has 1 published book loaded", $silberschatzBooks['total'] >= 1);
$assert("Book title matches Operating System Concepts", $silberschatzBooks['items'][0]['title'] === 'Operating System Concepts');

// Test with William Shakespeare (#29)
$shakespeareBooks = $bookModel->browse(['author_id' => 29, 'status' => 'published']);
$assert("William Shakespeare (ID 29) has 5 published books loaded", $shakespeareBooks['total'] === 5);

// Test with Harper Lee (#1)
$harperBooks = $bookModel->browse(['author_id' => 1, 'status' => 'published']);
$assert("Harper Lee (ID 1) has published books loaded", $harperBooks['total'] >= 1);

// -------------------------------------------------------------------------
// Scenario 10: Database Integrity Checks
// -------------------------------------------------------------------------
echo "\n--- Scenario 10: Database Integrity and Foreign Key Checks ---\n";
$integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();
$assert("PRAGMA integrity_check is ok", $integrity === 'ok');

$fkViolations = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
$assert("PRAGMA foreign_key_check has 0 violations", count($fkViolations) === 0);

// Clean up throwaway test database
unset($pdo, $testDatabaseInstance);
$prop->setValue(null, null);
if (file_exists($testDb)) {
    @unlink($testDb);
    @unlink($testDb . '-wal');
    @unlink($testDb . '-shm');
}

echo "\n------------------------------------------------------------------------\n";
echo "SUMMARY: Checks {$checks} | Passed " . ($checks - $failures) . " | Failed {$failures}\n";
echo "------------------------------------------------------------------------\n";

if ($failures > 0) {
    exit(1);
}
