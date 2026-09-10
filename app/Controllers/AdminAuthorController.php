<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Models\Author;
use BookSphere\App\Requests\AuthorRequest;
use BookSphere\App\Services\RecommendationService;

/**
 * AdminAuthorController
 *
 * Full Author management for administrators (Admin-only):
 * - Directory with search, book count metrics, and pagination.
 * - Author editing (name, biography).
 * - Safe transactional deletion with multi-author awareness and
 *   explicit warning/confirmation if any book would become authorless.
 *
 * All routes are protected by AdminMiddleware.
 */
final class AdminAuthorController extends Controller
{
    public function __construct(
        private readonly Author $authors,
        private readonly ?RecommendationService $recommendations = null,
    ) {}

    /**
     * Display the admin author management directory.
     */
    public function index(Request $request, array $params = []): void
    {
        $q       = trim((string) $request->input('q', ''));
        $sort    = (string) $request->input('sort', 'name_asc');
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = (int) $request->input('per_page', 20);

        $result = $this->authors->browse([
            'q'       => $q,
            'sort'    => $sort,
            'page'    => $page,
            'perPage' => $perPage,
        ]);

        $this->view('admin.authors.index', [
            'title'      => 'Author Management',
            'active'     => 'admin-authors',
            'result'     => $result,
            'q'          => $q,
            'sort'       => $sort,
            'pagination' => [
                'base'       => '/admin/authors',
                'params'     => array_filter(['q' => $q !== '' ? $q : null, 'sort' => $sort !== 'name_asc' ? $sort : null]),
                'page'       => (int) $result['page'],
                'pages'      => (int) $result['pages'],
                'total'      => (int) $result['total'],
                'perPage'    => (int) $result['perPage'],
                'perPages'   => [10, 20, 50, 100],
                'label'      => 'author',
                'pagerLabel' => 'Author pages',
            ],
        ]);
    }

    /**
     * Show the edit form for an author.
     */
    public function edit(Request $request, array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $author = $this->authors->findById($id);

        if ($author === null) {
            Response::error(404, 'Author not found.');
        }

        $books = $this->authors->booksFor($id);
        $flashInput = session()->getFlash('input') ?? [];
        $flashErrors = session()->getFlash('errors') ?? [];

        $this->view('admin.authors.edit', [
            'title'  => 'Edit Author — ' . $author['name'],
            'active' => 'admin-authors',
            'author' => $author,
            'books'  => $books,
            'input'  => array_merge([
                'name'      => (string) ($author['name'] ?? ''),
                'biography' => (string) ($author['biography'] ?? ''),
            ], $flashInput),
            'errors' => $flashErrors,
        ]);
    }

    /**
     * Save updates to an author.
     */
    public function update(Request $request, array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $author = $this->authors->findById($id);

        if ($author === null) {
            Response::error(404, 'Author not found.');
        }

        $input = [
            'name'      => trim((string) $request->input('name', '')),
            'biography' => trim((string) $request->input('biography', '')),
        ];

        $validator = AuthorRequest::validate($input);

        if (!$validator->passes()) {
            session()->flash('errors', $validator->errors());
            session()->flash('input', $input);
            Response::redirect('/admin/authors/' . $id . '/edit');

            return;
        }

        $this->authors->update($id, $input);

        session()->flash('success', 'Author "' . $input['name'] . '" was updated successfully.');
        Response::redirect('/admin/authors');
    }

    /**
     * Show the explicit delete confirmation page.
     *
     * Evaluates all associated books to determine if any book will have
     * 0 remaining authors upon deletion.
     */
    public function deleteConfirm(Request $request, array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $author = $this->authors->findById($id);

        if ($author === null) {
            Response::error(404, 'Author not found.');
        }

        $books = $this->authors->booksFor($id);
        $authorlessBooks = array_values(array_filter(
            $books,
            fn (array $b): bool => (int) ($b['total_authors_count'] ?? 1) <= 1,
        ));

        $this->view('admin.authors.delete', [
            'title'           => 'Delete Author — ' . $author['name'],
            'active'          => 'admin-authors',
            'author'          => $author,
            'books'           => $books,
            'authorlessBooks' => $authorlessBooks,
        ]);
    }

    /**
     * Process author deletion transactionally.
     *
     * CRITICAL SAFETY:
     * - Verifies author exists.
     * - Never deletes books, reviews, ratings, library, or user data.
     * - Multi-author safety: preserves books with remaining co-authors.
     * - Requires explicit checkbox confirmation if any book will have 0 authors.
     * - Executes atomically in a transaction (rolls back on failure).
     */
    public function destroy(Request $request, array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $author = $this->authors->findById($id);

        if ($author === null) {
            Response::error(404, 'Author not found.');
        }

        $books = $this->authors->booksFor($id);
        $authorlessBooks = array_values(array_filter(
            $books,
            fn (array $b): bool => (int) ($b['total_authors_count'] ?? 1) <= 1,
        ));

        // If any book will become authorless, require explicit confirmation
        if ($authorlessBooks !== [] && $request->input('confirm_authorless') !== '1') {
            session()->flash(
                'error',
                'Please check the confirmation box acknowledging that ' . count($authorlessBooks) . ' book(s) will be left without an author.',
            );
            Response::redirect('/admin/authors/' . $id . '/delete');

            return;
        }

        $authorName = (string) $author['name'];
        $deleted = $this->authors->deleteAuthorTransaction($id);

        if (!$deleted) {
            Response::error(500, 'Could not delete the author record.');
        }

        // Flush personalization cache so recommendations reflect the change
        $this->recommendations?->flushPersonalization();

        $bookCount = count($books);
        session()->flash(
            'success',
            'Author "' . $authorName . '" was deleted. All ' . $bookCount . ' associated book(s) remain intact in the catalogue.',
        );

        Response::redirect('/admin/authors');
    }
}
