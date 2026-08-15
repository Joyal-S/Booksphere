# BookSphere — Community Feature
## PHASE C5: COMMUNITY MODERATION & REPORTING — COMPLETE

---

### 1. Core Objective

Phase C5 delivers a complete, production-grade safety and moderation system for the Community module:
1. **User Reporting**: Authenticated users can report posts and comments for policy violations via interactive modals with radio button reason selection.
2. **Duplicate Report Prevention**: Application-level check prevents duplicate active (`pending` or `reviewed`) reports from the same user for the same post or comment.
3. **Admin Moderation Queue**: Dedicated admin interface at `/admin/community/reports` with status tab filters, report statistics, detailed content previews, and one-click actions.
4. **Report Detail View**: Dedicated report inspection page at `/admin/community/reports/{id}` displaying full report context, content body, author, reporter, and timeline.
5. **Content Moderation**: Administrators can hide or restore posts and comments (`status = 'hidden'`). Hidden content is immediately filtered out of public community feeds and comment threads.

---

### 2. Architecture & Data Flow

```
[ Reader / User ]
       │
       ├─► Modal Report Form (post / comment)
       │       │
       │       ▼
       │   POST /community/posts/{id}/report
       │   POST /community/comments/{id}/report
       │       │
       │       ▼
       │   CommunityController :: reportPost / reportComment
       │       │
       │       ▼
       │   CommunityPolicy :: canReport ($content, $actorId)
       │       │
       │       ▼
       │   CommunityService :: reportPost / reportComment
       │       ├── validateReason($reason)  [422 if invalid]
       │       ├── existsByReporter()      [409 if duplicate active report]
       │       └── CommunityReportRepository :: create()
       │
[ Administrator ]
       │
       ├─► Moderation Queue: GET /admin/community/reports
       ├─► Report Detail:    GET /admin/community/reports/{id}
       ├─► Moderate Status:  POST /admin/community/reports/{id}/resolve | /dismiss | /review
       └─► Content Control:  POST /admin/community/posts/{id}/hide | /unhide
                             POST /admin/community/comments/{id}/hide | /unhide
```

---

### 3. Key Components Implemented / Updated

#### Backend Core
- **`app/Exceptions/CommunityException.php`**: Added `alreadyReported()` factory method (maps to HTTP 409).
- **`app/Repositories/CommunityReportRepository.php`**: Added `existsByReporter()`, `findAll()`, `countAll()`, and `findWithContext()`.
- **`app/Models/CommunityReport.php`**: Exposed new repository methods via thin facade methods.
- **`app/Services/CommunityService.php`**:
  - Validates input format before state checks.
  - Enforces single active report per user per target via `existsByReporter()`.
  - Added admin moderation methods: `listReports()`, `getReportWithContext()`, `moderateReport()`, `hidePost()`, `unhidePost()`, `hideComment()`, `unhideComment()`.
- **`app/Controllers/CommunityController.php`**: Updated `reportPost()` and `reportComment()` to support browser HTML form POSTs with flash messages and redirects while retaining structured JSON responses for fetch callers.
- **`app/Controllers/AdminCommunityController.php`**: New dedicated controller for admin moderation queue, detail page, status transitions, and content hiding/restoring. Protected by `AdminMiddleware` and `CsrfMiddleware`.
- **`routes/web.php`**: Registered isolated admin moderation routes under `/admin/community/*`.

#### Frontend Views & Navigation
- **`app/Views/community/show.php`**:
  - Added "Report" button in engagement controls (visible to authenticated non-authors).
  - Added "Report" link per comment (visible to non-comment-owners).
  - Included Bootstrap modals with `Spam`, `Harassment`, `Offensive Content`, `False Information`, `Duplicate`, `Other` radio options, optional description, CSRF token, and target IDs.
- **`app/Views/admin/community-reports.php`**: Admin moderation queue table with status tab filters (`Pending`, `Under Review`, `Dismissed`, `Resolved`), report type badges, content previews, and action buttons.
- **`app/Views/admin/community-report-detail.php`**: Comprehensive report inspection page showing full content body, reporter info, reason, timeline, and moderation controls.
- **`app/Views/partials/sidebar.php`**: Added "Community Reports" navigation link (`fa-flag`) under admin section, active when `$active === 'admin-community'`.

---

### 4. Verification & Testing

#### Automated Test Suite
- **`tests/CommunityC5Test.php`**: 49 assertions covering:
  - `alreadyReported()` exception factory
  - Reporting posts & comments with valid/invalid reasons
  - Duplicate report rejection (409)
  - Non-author policy enforcement
  - `CommunityReportRepository` queries (`existsByReporter`, `findAll`, `countAll`, `findWithContext`)
  - Report status transitions (`pending` → `reviewed` → `resolved` | `dismissed`)
  - Post and comment hiding/unhiding and content filtering from public views
- **Full Test Suite Execution**: 38 test suites passed, 1 pre-existing failure (`LandingTest.php` copy checks). Zero regression.

---

### 5. Files Created & Modified

#### Files Created
- [`app/Controllers/AdminCommunityController.php`](file:///d:/PROJECTS/booksphere/app/Controllers/AdminCommunityController.php)
- [`app/Views/admin/community-reports.php`](file:///d:/PROJECTS/booksphere/app/Views/admin/community-reports.php)
- [`app/Views/admin/community-report-detail.php`](file:///d:/PROJECTS/booksphere/app/Views/admin/community-report-detail.php)
- [`tests/CommunityC5Test.php`](file:///d:/PROJECTS/booksphere/tests/CommunityC5Test.php)
- [`docs/PHASE_C5_COMMUNITY_MODERATION.md`](file:///d:/PROJECTS/booksphere/docs/PHASE_C5_COMMUNITY_MODERATION.md)

#### Files Modified
- [`app/Exceptions/CommunityException.php`](file:///d:/PROJECTS/booksphere/app/Exceptions/CommunityException.php)
- [`app/Repositories/CommunityReportRepository.php`](file:///d:/PROJECTS/booksphere/app/Repositories/CommunityReportRepository.php)
- [`app/Models/CommunityReport.php`](file:///d:/PROJECTS/booksphere/app/Models/CommunityReport.php)
- [`app/Services/CommunityService.php`](file:///d:/PROJECTS/booksphere/app/Services/CommunityService.php)
- [`app/Controllers/CommunityController.php`](file:///d:/PROJECTS/booksphere/app/Controllers/CommunityController.php)
- [`app/Views/community/show.php`](file:///d:/PROJECTS/booksphere/app/Views/community/show.php)
- [`app/Views/partials/sidebar.php`](file:///d:/PROJECTS/booksphere/app/Views/partials/sidebar.php)
- [`routes/web.php`](file:///d:/PROJECTS/booksphere/routes/web.php)

---

### 6. Known Issues

- `LandingTest.php` pre-existing failure remains untouched.
