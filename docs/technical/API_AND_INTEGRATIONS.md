# BookSphere — External API & Integrations Guide

## 1. Google Books REST API Integration
BookSphere integrates with the Google Books API (`https://www.googleapis.com/books/v1/volumes`) to enrich catalog metadata and resolve cover artwork.

### Integration Components:
- **Service**: `BookSphere\App\Services\GoogleBooksService`
- **Controller**: `BookSphere\App\Controllers\GoogleBooksController`
- **Configuration**: `config/google_books.php`

### Operations:
1. **Keyword Search**: Queries Google's bibliographic database for volume titles.
2. **ISBN Lookup**: Resolves exact bibliographic records using 10 or 13-digit ISBNs.
3. **Bulk Import**: Imports volume titles, authors, categories, descriptions, and cover URLs directly into SQLite.
4. **Cover Image Resolution**: Downloads high-resolution cover artwork and caches it in `public/uploads/covers/`.
