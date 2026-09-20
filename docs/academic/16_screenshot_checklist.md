# SCREENSHOT CHECKLIST & SPECIFICATIONS

This document provides the itemized checklist and technical specifications for the **20 verified screenshots** required for Appendix B of the academic project report.

| Screen # | Screen Title | Route URI | Description of View | Key Elements to Capture | Status |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **01** | Landing Page | `GET /` | Public welcome portal | Hero banner, value proposition, featured book carousel, CTA buttons | **Verified** |
| **02** | User Registration | `GET /register` | Account registration interface | Name, email, password fields, validation state, login link | **Verified** |
| **03** | User Login | `GET /login` | Secure authentication portal | Email, password fields, "Remember Me" toggle, CSRF field | **Verified** |
| **04** | User Dashboard | `GET /` (Auth) | Authenticated personal landing | Active reading shelf preview, recent activity, quick recommendation shelf | **Verified** |
| **05** | Book Catalog | `GET /books` | Full book browse grid | Search input, genre filter sidebar, responsive book cards, pagination | **Verified** |
| **06** | Search & Filters | `GET /search?q=...` | Dynamic search results | Keyword query, category/author filters, match count badge, result cards | **Verified** |
| **07** | Book Details | `GET /books/{id}` | Bibliographic detail page | High-res cover, metadata, reading shelf selector, review summary | **Verified** |
| **08** | "More Like This" | `GET /books/{id}` | Book-level similarity shelf | Content similarity cards, category/author affinity match badges | **Verified** |
| **09** | Author Profile | `GET /authors/{id}` | Author biography & catalog | Author portrait, bio, list of authored books, "Follow Author" button | **Verified** |
| **10** | Personal Library | `GET /library` | Reading shelf management | "Want to Read", "Currently Reading", "Finished" tabs, rating controls | **Verified** |
| **11** | User Wishlist | `GET /wishlist` | Saved books collection | Grid of book cards saved for future reading, remove toggle | **Verified** |
| **12** | Review Submission | `POST /reviews` (Modal)| Review & rating form | 1–5 star interactive selector, review title, review text area | **Verified** |
| **13** | Recommendations V2| `GET /recommendations`| Personalized discovery portal | Hero recommendation, "Because You Read...", transparent reason badges | **Verified** |
| **14** | Community Feed | `GET /community` | Literary discussion board | Discussion posts, book link badges, like buttons, comment counts | **Verified** |
| **15** | Post Detail & Comments| `GET /community/posts/{id}`| Threaded discussion view | Original post, author info, comment form, threaded comment list | **Verified** |
| **16** | Notification Center | `GET /notifications` | In-app alerts drawer | Followed author alerts, review likes, system notifications | **Verified** |
| **17** | User Profile & Avatar | `GET /profile` | Account settings & profile | Profile details, avatar image upload form, reading statistics | **Verified** |
| **18** | Admin Dashboard | `GET /admin` | Central administration panel | Total books, authors, users, reviews KPIs, quick moderation queue | **Verified** |
| **19** | Admin Author Curation | `GET /admin/authors` | Author management panel | Author listing, edit author modal, bio editor, delete confirmation | **Verified** |
| **20** | Administration Report | `GET /admin/analytics/report`| Print-ready analytics report | Catalog breakdown, reading trends, formatted data tables (`@media print`) | **Verified** |
