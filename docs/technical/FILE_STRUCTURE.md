# BookSphere — Repository File Structure

```text
booksphere/
├── app/                        # Application Source Code
│   ├── Controllers/            # HTTP Request Controllers (20)
│   ├── Core/                   # Application Core (App, Router, Database, Session, Request, Response)
│   ├── DTO/                    # Data Transfer Objects (RecommendationResult, etc.)
│   ├── Middleware/             # HTTP Middleware (Auth, Admin, Csrf)
│   ├── Models/                 # Domain Entity Models (Book, User, Author, Review)
│   ├── Repositories/           # Data Access Layer (BookRepository, RecommendationRepository)
│   ├── Services/               # Domain Business Logic (RecommendationService, CommunityService)
│   └── Views/                  # Native PHP View Templates & Components (170)
├── bootstrap/                  # Application Bootstrap (app.php, constants.php)
├── config/                     # Subsystem Configuration Files (9)
├── database/                   # Database Persistence
│   ├── booksphere.db           # Live Production SQLite Database (1.61 MB)
│   ├── migrations/             # Database Migration Scripts (38)
│   ├── seeds/                  # Database Seed Scripts (5)
│   └── migrate.php             # CLI Database Migration Runner
├── docs/                       # Project Documentation
│   ├── academic/               # College Academic Project Report (17 files)
│   └── technical/              # Developer Technical Documentation (14 files)
├── public/                     # Public Document Root
│   ├── index.php               # Front Controller Entrypoint
│   ├── assets/                 # Static Assets (css, js, fonts, img)
│   └── uploads/                # User Content Uploads (avatars, covers)
├── scratch/                    # Developer & Audit Tooling
│   ├── test_runner.php         # Automated CLI Test Suite Runner
│   ├── run_all_tests.php       # Canonical Test Runner Wrapper
│   └── *.py / *.php            # Auxiliary Database Inspection & Audit Tools
├── storage/                    # Storage Directory (logs, session files)
├── tests/                      # Automated CLI Test Suites (64 files)
├── tools/                      # Smoke Test Utilities
├── .env                        # Local Environment Configuration
├── .env.example                # Example Environment Template
├── .env.production.example     # Production Environment Template
├── .gitattributes              # Deployment Packaging Exclusion Rules
├── .gitignore                  # Git Ignore Rules
├── composer.json               # Composer Package Configuration (PSR-4 Autoload)
└── README.md                   # Project Overview & Quickstart
```
