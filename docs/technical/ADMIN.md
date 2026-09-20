# BookSphere — Administration Manual

## 1. Access & Authorization
- **Access Route**: `GET /admin`
- **Security Requirement**: User account with `is_admin == 1`. Unauthorized requests are blocked with HTTP `403 Forbidden`.

## 2. Administrative Modules
- **Overview Dashboard (`/admin`)**: Key performance indicators (Total Books: 495, Authors: 448, Users: 59, Reviews: 15).
- **Author Curation (`/admin/authors`)**: Create, edit bios, upload author photos, delete author records.
- **Review Moderation (`/admin/reviews`)**: View reported reviews, hide inappropriate content, unhide approved reviews.
- **Community Moderation (`/admin/community/reports`)**: Resolve or dismiss community post/comment flags.
- **Google Books Integration (`/admin/google-books`)**: Bulk import book records, enrich bibliographic metadata, sync cover images.
- **Administration Report (`/admin/analytics/report`)**: Print-optimized report with catalog distributions and engagement statistics.
