# BookSphere — Feature Inventory

## 1. Authentication & User Identity
- **Registration**: Full validation, Bcrypt password encryption, automatic session initiation.
- **Login**: Credential verification, session fixation defense, remember-me token handling.
- **Profile Management**: Bio editing, reading statistics summary, avatar image upload (JPEG/PNG/WEBP, 2MB max).

## 2. Catalog & Discovery
- **Catalog Browsing**: 495 titles, 448 authors, 17 genres.
- **Search Engine**: Full-text keyword matching across titles, author names, and descriptions.
- **Faceted Filters**: Genre multi-select, publication era sliders, rating thresholds, sort options.
- **Book Details**: High-resolution covers, full bibliographic metadata, reading shelf selector, "More Like This" shelf.

## 3. Personal Library Management
- **Reading Shelves**: Three-state reading status workflow (`want_to_read`, `reading`, `finished`).
- **Reading Progress**: Start/finish dates, private star ratings, favorites toggle.
- **Wishlist**: Quick-save book collection with one-click toggling.

## 4. Recommendation Engine V2
- **Multi-Source Hybrid Candidate Generation**: Sources A–H (Categories, Authors, Wishlist, Reviews, Views, Follows, Community, Popularity).
- **Maximal Marginal Relevance (MMR)**: Diversity reranking balancing score against genre over-concentration.
- **Deterministic Explanations**: Human-readable explanation strings generated for every recommendation card.
- **Hard Exclusions**: Strict exclusion of books already present in the user's library and soft-deleted titles.

## 5. Literary Community Hub
- **Discussion Posts**: Create discussion threads with optional book attachments.
- **Threaded Comments**: Interactive comment drawers for book discussions.
- **Social Interactions**: Post likes, author following, and user-to-user follows.
- **Notification Center**: In-app notifications for followed author activity, likes, and replies.

## 6. Administration & API Synchronization
- **Admin Dashboard**: System KPIs (books, authors, users, reviews, pending reports).
- **Author & Book Curation**: Full CRUD for author bios and book details.
- **Content Moderation**: Review reporting queue and community post moderation.
- **Google Books API Sync**: Bulk volume search, ISBN metadata enrichment, and cover image retrieval.
