# BookSphere — Relational Database Specification

## 1. Overview
- **Engine**: SQLite 3.39+
- **Location**: `database/booksphere.db` (1.61 MB)
- **Journal Mode**: Write-Ahead Logging (`PRAGMA journal_mode = WAL;`)
- **Foreign Key Enforcement**: `PRAGMA foreign_keys = ON;`
- **Total Tables**: 31 normalized relational tables

---

## 2. Table Schema Details & Counts

| Table Name | Row Count | Primary Key | Key Foreign Keys | Purpose |
| :--- | :--- | :--- | :--- | :--- |
| `users` | 59 | `id` | None | User accounts and credentials (Bcrypt) |
| `books` | 495 | `id` | None | Master bibliographic catalog (490 active, 5 soft-deleted) |
| `authors` | 448 | `id` | None | Author profiles, biographies, and slugs |
| `categories` | 17 | `id` | None | Primary literary genres/categories |
| `book_authors` | 598 | `(book_id, author_id)`| `book_id -> books.id`, `author_id -> authors.id` | Many-to-many book-author relationships |
| `book_categories`| 498 | `(book_id, category_id)`| `book_id -> books.id`, `category_id -> categories.id` | Many-to-many book-category relationships |
| `user_library` | 33 | `id` | `user_id -> users.id`, `book_id -> books.id` | Personal reading shelves (`want_to_read`, `reading`, `finished`) |
| `reviews` | 15 | `id` | `user_id -> users.id`, `book_id -> books.id` | User book ratings (1–5) and written reviews |
| `wishlist` | 6 | `id` | `user_id -> users.id`, `book_id -> books.id` | Saved books collection |
| `recommendation_logs`| 3,019 | `id` | `user_id -> users.id`, `book_id -> books.id` | Log of served recommendations with scoring & strategy |
| `community_posts` | 11 | `id` | `user_id -> users.id`, `book_id -> books.id` | Literary discussion threads |
| `community_comments`| 0 | `id` | `post_id -> community_posts.id`, `user_id -> users.id`| Threaded post comments |
| `community_likes` | 1 | `(post_id, user_id)` | `post_id -> community_posts.id`, `user_id -> users.id`| Post like records |
| `community_follows`| 1 | `(follower_id, followed_id)` | `follower_id -> users.id`, `followed_id -> users.id` | User-to-user social follows |
| `author_follows` | 1 | `(user_id, author_id)` | `user_id -> users.id`, `author_id -> authors.id` | Reader-to-author follow subscriptions |
| `notifications` | 70 | `id` | `user_id -> users.id` | In-app user notifications |
| `book_views` | 103 | `id` | `book_id -> books.id`, `user_id -> users.id` | User catalog browsing view history |
| `search_history` | 13 | `id` | `user_id -> users.id` | Recent user search terms |
| `migrations` | 38 | `id` | None | Migration tracking table |

---

## 3. Indexing & Optimization
- `idx_books_status_rating` on `books(status, average_rating DESC)`
- `idx_book_authors_author` on `book_authors(author_id, book_id)`
- `idx_book_categories_cat` on `book_categories(category_id, book_id)`
- `idx_user_library_user_status` on `user_library(user_id, status)`
- `idx_recommendation_logs_user` on `recommendation_logs(user_id, shown_at)`
