# CHAPTER 3 — SYSTEM DESIGN

## 3.1 Introduction

System design is the creative and technical process of defining the architecture, components, modules, interfaces, and data schemas necessary to satisfy the requirements identified during system analysis. This chapter provides a comprehensive specification of BookSphere's system design, covering database schema design, entity-relationship modeling, process modeling via Data Flow Diagrams (DFDs), object-oriented structural and behavioral modeling (Use Case, Activity, and Sequence Diagrams), as well as detailed input and output design specifications.

---

## 3.2 Database Design

BookSphere utilizes a normalized, relational database architecture implemented on **SQLite 3**. The database schema comprises **31 relational tables** designed to support user authentication, catalog management, personal library tracking, recommendation logging, reviews, community engagement, author follows, and administrative operations. Foreign key constraints are strictly enforced via `PRAGMA foreign_keys = ON`, ensuring referential integrity across all relationships.

### 3.2.1 Entity Relationship Model

The relational schema is structured into several core functional clusters:
1. **Identity & Authentication**: `users`, `password_resets`, `user_preferences`.
2. **Bibliographic Catalog**: `books`, `authors`, `categories`, `book_authors`, `book_categories`, `book_views`.
3. **Personal Library & Interactions**: `user_library`, `wishlist`, `reviews`, `review_helpful_votes`, `review_reports`.
4. **Recommendation Engine**: `recommendations`, `recommendation_logs`.
5. **Community Hub**: `community_posts`, `community_comments`, `community_likes`, `community_follows`, `community_reports`.
6. **Social & Notifications**: `author_follows`, `notifications`, `notification_preferences`, `notification_deliveries`.
7. **Email & Rate Limiting**: `email_queue`, `email_logs`, `email_preferences`, `rate_limits`, `search_history`, `migrations`.

#### Primary Entity Attribute Details:
- **`users`**: `id` (PK, INTEGER), `name` (VARCHAR), `email` (VARCHAR, UNIQUE), `password` (VARCHAR), `avatar_path` (VARCHAR), `is_admin` (BOOLEAN), `remember_token` (VARCHAR), `created_at`, `updated_at`.
- **`books`**: `id` (PK, INTEGER), `title` (VARCHAR), `slug` (VARCHAR, UNIQUE), `description` (TEXT), `cover_image` (VARCHAR), `cover_source` (VARCHAR), `isbn` (VARCHAR), `published_year` (INTEGER), `page_count` (INTEGER), `language` (VARCHAR), `publisher` (VARCHAR), `average_rating` (FLOAT), `review_count` (INTEGER), `status` (VARCHAR), `deleted_at` (DATETIME), `created_at`, `updated_at`.
- **`authors`**: `id` (PK, INTEGER), `name` (VARCHAR), `slug` (VARCHAR, UNIQUE), `bio` (TEXT), `photo_url` (VARCHAR), `created_at`, `updated_at`.
- **`categories`**: `id` (PK, INTEGER), `name` (VARCHAR), `slug` (VARCHAR, UNIQUE), `description` (TEXT), `icon` (VARCHAR), `created_at`, `updated_at`.
- **`book_authors`**: `book_id` (FK -> books.id), `author_id` (FK -> authors.id), `is_primary` (BOOLEAN). Composite PK (`book_id`, `author_id`).
- **`book_categories`**: `book_id` (FK -> books.id), `category_id` (FK -> categories.id). Composite PK (`book_id`, `category_id`).
- **`user_library`**: `id` (PK, INTEGER), `user_id` (FK -> users.id), `book_id` (FK -> books.id), `status` (VARCHAR: want_to_read, reading, finished), `is_favorite` (BOOLEAN), `personal_rating` (INTEGER), `reading_progress` (INTEGER), `started_at` (DATETIME), `finished_at` (DATETIME), `created_at`, `updated_at`.
- **`reviews`**: `id` (PK, INTEGER), `user_id` (FK -> users.id), `book_id` (FK -> books.id), `rating` (INTEGER), `title` (VARCHAR), `content` (TEXT), `helpful_count` (INTEGER), `is_hidden` (BOOLEAN), `created_at`, `updated_at`.
- **`recommendation_logs`**: `id` (PK, INTEGER), `user_id` (FK -> users.id), `book_id` (FK -> books.id), `score` (FLOAT), `reason` (VARCHAR), `strategy` (VARCHAR), `shown_at` (DATETIME), `converted_at` (DATETIME).

```text
+-----------------------------------------------------------------------------------+
|                        ENTITY-RELATIONSHIP (ER) DIAGRAM                           |
+-----------------------------------------------------------------------------------+

     +------------------+                    +--------------------+
     |      USERS       | 1                * |    USER_LIBRARY    |
     +------------------+------------------->+--------------------+
     | PK  id           |                    | PK  id             |
     |     name         |                    | FK  user_id        |
     |     email        |                    | FK  book_id        |
     |     password     |                    |     status         |
     |     is_admin     |                    |     personal_rating|
     +--------+---------+                    +---------+----------+
              | 1                                      | *
              |                                        |
              | *                                      v 1
     +--------v---------+                    +--------------------+
     |     REVIEWS      | *                1 |       BOOKS        |
     +------------------+------------------->+--------------------+
     | PK  id           |                    | PK  id             |
     | FK  user_id      |                    |     title          |
     | FK  book_id      |                    |     published_year |
     |     rating       |                    |     average_rating |
     |     content      |                    |     status         |
     +------------------+                    +----+----------+----+
                                                  | 1        | 1
                                                  | *        | *
                                                  v          v
                                         +--------+---+  +---+--------+
                                         |BOOK_AUTHORS|  |BOOK_CATEG. |
                                         +------------+  +------------+
                                         |FK book_id  |  |FK book_id  |
                                         |FK author_id|  |FK categ_id |
                                         +-----+------+  +-----+------+
                                               | *             | *
                                               | 1             | 1
                                               v               v
                                         +-----+------+  +-----+------+
                                         |  AUTHORS   |  | CATEGORIES |
                                         +------------+  +------------+
                                         |PK id       |  |PK id       |
                                         |   name     |  |   name     |
                                         |   bio      |  |   slug     |
                                         +------------+  +------------+
```

---

## 3.3 Process Design

Process design models the flow of data through the system, illustrating how inputs are transformed into outputs via logical processing entities.

### 3.3.1 Data Flow Diagram (DFD)

#### Context-Level DFD (Level 0)
The Context-Level DFD represents BookSphere as a single centralized process interacting with external entities: General User, Administrator, and the Google Books API.

```text
                     +---------------------------------------+
                     |             GENERAL USER              |
                     +---------------------------------------+
                       | Registration, Login,     ^
                       | Search Query, Library,   | Search Results, Shelves,
                       | Ratings, Reviews, Posts  | Recs, Notifications
                       v                          |
                +-----------------------------------------------+
                |                                               |
                |                   0.0                         |
                |               BOOKSPHERE                      |
                |            PLATFORM SYSTEM                    |
                |                                               |
                +-----------------------------------------------+
                       ^                          |
                       | Admin Actions, Curation, | System Metrics, Reports,
                       | Review/Post Moderation   | Moderation Queue
                       v                          |
                     +---------------------------------------+
                     |             ADMINISTRATOR             |
                     +---------------------------------------+
                                      |
                                      | Search Query / Sync Request
                                      v
                     +---------------------------------------+
                     |           GOOGLE BOOKS API            |
                     +---------------------------------------+
                                      | Volume Metadata / Cover URL
                                      v
                                (To System)
```

#### Level-1 DFD
The Level-1 DFD decomposes the system into its primary functional subsystems and data stores:

```text
 [User] --> (1.0 Authentication & Profile) <--> [D1: Users Store]
               |
               v
 [User] --> (2.0 Catalog & Search Engine)  <--> [D2: Books & Authors Store]
               |
               v
 [User] --> (3.0 Library & Shelf Manager) <--> [D3: User Library Store]
               |
               v
 [User] --> (4.0 Reviews & Ratings Engine) <--> [D4: Reviews Store]
               |
               +----------------------+
                                      |
                                      v
            (5.0 Recommendation Engine V2) <--> [D5: Recommendation Logs]
               ^          ^          ^
               | Signals  | Signals  | Signals
               |          |          |
          [D3: Library] [D4: Reviews] [D2: Books]
               |
               v (Generates Personalized Recommendations)
            [User Dashboard]

 [User] --> (6.0 Community & Social Hub)  <--> [D6: Community Posts/Comments]
                                           <--> [D7: Notifications Store]

 [Admin]--> (7.0 Admin & API Sync Engine) <--> [Google Books API]
                                           <--> [D2: Books & Authors Store]
```

---

## 3.4 Object-Oriented Design

Object-oriented design specifies the structural and behavioral characteristics of BookSphere using standard Unified Modeling Language (UML) notation.

### 3.4.1 Use Case Diagram

The primary actors are:
1. **Guest / Visitor**: Unauthenticated user.
2. **Registered Reader (User)**: Authenticated general member.
3. **Administrator**: Authenticated user with elevated curation and moderation privileges.

```text
+-------------------------------------------------------------------------------+
|                             USE CASE SPECIFICATION                            |
+-------------------------------------------------------------------------------+

       [Guest]
          |
          +---> (Browse Catalog & View Book Details)
          +---> (Search Books by Keyword/Genre)
          +---> (Register Account / Log In)

       [Registered Reader] (Inherits Guest capabilities)
          |
          +---> (Manage Personal Library Shelves)
          +---> (Submit Ratings & Book Reviews)
          +---> (View Personalized Recommendations & Explanations)
          +---> (Follow Authors & Manage Wishlist)
          +---> (Create Community Posts & Comments)
          +---> (Receive Notifications)
          +---> (Customize Profile & Avatar Upload)

       [Administrator] (Inherits Registered Reader capabilities)
          |
          +---> (Access Administration Dashboard)
          +---> (Curate Books & Author Profiles)
          +---> (Moderate Reviews & Community Reports)
          +---> (Execute Google Books API Bulk Import & Sync)
          +---> (View System Analytics & Reading Reports)
```

### 3.4.2 Activity Diagram: Book Discovery & Recommendation Workflow

The activity diagram below illustrates the operational flow when an authenticated user accesses their personalized discovery dashboard:

```text
   (*)
    |
    v
[User Requests /recommendations Dashboard]
    |
    v
[Check User Authentication Session]
    |
   <Is Authenticated?>
   /                 (No)               (Yes)
  |                   |
[Redirect to /login]  v
                     [Load User Profile Signals (Library, Ratings, Wishlist, Follows)]
                      |
                     <Profile Has Historical Signals?>
                     /                                                 (No)                             (Yes)
                    |                                 |
           [Select Cold-Start Pool]          [Execute Candidate Sources A-H]
           [Popular & Top-Rated Books]                |
                    |                        [Score Candidates via Parameterized SQL]
                    |                                 |
                    |                        [Apply Strict Library Exclusions]
                    |                                 |
                    |                        [Execute MMR Diversity Reranking]
                    |                                 |
                    |                        [Generate Explainable Reason Text]
                    \                                /
                     \                              /
                      v                            v
                     [Assemble Personalized Recommendation DTOs]
                      |
                     [Render Recommendation Views with Reason Cards]
                      |
                     (*)
```

### 3.4.3 Sequence Diagram: Personal Review Submission & Recommendation Invalidation

The sequence diagram depicts the interaction between UI, Controller, Service, Repository, and Database when a user writes a review:

```text
User            ReviewController     ReviewService      ReviewRepository     Database
 |                     |                   |                   |                |
 |-- POST /reviews --->|                   |                   |                |
 |   (book_id, rating, |                   |                   |                |
 |    title, content)  |                   |                   |                |
 |                     |-- validate() ---->|                   |                |
 |                     |   (CSRF, rating,  |                   |                |
 |                     |    length checks) |                   |                |
 |                     |                   |-- createReview()->|                |
 |                     |                   |                   |-- INSERT ----->|
 |                     |                   |                   |   INTO reviews |
 |                     |                   |                   |<-- success ----|
 |                     |                   |<-- review_id -----|                |
 |                     |                   |                   |                |
 |                     |                   |-- updateBookAvg()->|               |
 |                     |                   |                   |-- UPDATE ----->|
 |                     |                   |                   |   books rating |
 |                     |                   |                   |<-- success ----|
 |                     |                   |                   |                |
 |                     |                   |-- invalidateRecs()|                |
 |                     |                   |   (User cache     |                |
 |                     |                   |    flushed)       |                |
 |                     |<-- success -------|                   |                |
 |<- 302 Redirect -----|                   |                   |                |
 |   (with flash msg)  |                   |                   |                |
```

---

## 3.5 Input Design

Input design ensures that data submitted by users is accurately captured, properly validated, sanitized against security threats, and processed efficiently.

| Input Interface | Input Fields | Data Type & Constraints | Validation & Security Rules | Output / Result |
| :--- | :--- | :--- | :--- | :--- |
| **User Registration** | `name`, `email`, `password`, `password_confirmation` | Name: 2–50 chars; Email: valid format, unique; Password: min 8 chars | CSRF token validation; email uniqueness query; Bcrypt hashing | New user record created; automatic session authentication |
| **User Login** | `email`, `password`, `remember` | Email: format check; Password: non-empty string; Remember: boolean | Rate limiting check; CSRF check; `password_verify()`; session regeneration | Active session initiated; redirect to requested or home URL |
| **Book Search** | `q`, `category_id`, `author_id`, `year_from`, `year_to`, `sort` | Search query: trimmed string; IDs: integers; Sort: whitelist enum | Stripped of malicious HTML; bound via prepared statements | Filtered book listing with match counts |
| **Library Status Update** | `book_id`, `status`, `personal_rating` | Book ID: integer; Status: enum (`want_to_read`, `reading`, `finished`); Rating: 1–5 | Auth check; valid foreign key verification; upsert operation | Shelf status badge updated; recommendation cache invalidated |
| **Review Submission** | `book_id`, `rating`, `title`, `content` | Rating: 1–5 integer; Title: 3–100 chars; Content: 10–2000 chars | CSRF check; XSS sanitization; single review per user-book constraint | Review record stored; book average rating recomputed |
| **Community Post** | `title`, `content`, `book_id` (optional) | Title: 5–150 chars; Content: 10–5000 chars; Book ID: nullable integer | CSRF check; HTML entity escaping; foreign key validation | New discussion thread published to community feed |
| **Avatar Image Upload** | `avatar` (file) | Image file: JPEG, PNG, WEBP; Max size: 2MB | MIME-type validation; extension whitelist; random filename generation | Avatar stored in `public/uploads/avatars/`; user profile updated |

---

## 3.6 Output Design

Output design defines the visual, structural, and interactive presentation of system information to end-users and administrators.

| Screen / Output | Target Audience | Primary Visual Components | Output Format & Behavior |
| :--- | :--- | :--- | :--- |
| **Landing Page (`/`)** | Public / Guests | Hero banner, featured books carousel, category grid, community highlights | Responsive HTML/CSS, zero layout shift, clear CTA to register/login |
| **Book Catalog (`/books`)** | Authenticated Users | Search bar, faceted filter sidebar, responsive book card grid (cover, title, author, rating) | Server-rendered pagination (12–24 items/page), instant filter submission |
| **Book Details (`/books/{id}`)** | Authenticated Users | High-res cover, bibliographic metadata, reading shelf selector, review breakdown, "More Like This" shelf | Tabbed reviews section, interactive star-rating modal, related titles carousel |
| **Personal Library (`/library`)** | Authenticated Users | Shelf tabs ("Want to Read", "Currently Reading", "Finished"), reading progress counters, favorite flags | Sortable grid/table, quick status toggle dropdown, reading stats summary |
| **Recommendations (`/recommendations`)**| Authenticated Users | Hero recommendation card, "Because You Read...", "Discover New Authors", transparent reason badges | Cards displaying exact match signals ("Because you liked Fantasy"), MMR-diversified |
| **Community Hub (`/community`)** | Authenticated Users | Community post feed, post creation card, trending discussion sidebar, like counters, comment drawers | Threaded comments, instant like toggle via AJAX, author link integration |
| **Admin Dashboard (`/admin`)** | Administrators | Metric cards (total books, users, reviews, pending reports), rapid curation shortcuts | High-level KPI dashboard, moderation queue alerts |
| **Reading Analytics Report (`/admin/analytics/report`)**| Administrators | Print-optimized administration report, catalog distribution charts, user engagement metrics | Clean CSS print styling (`@media print`), structured data tables |
