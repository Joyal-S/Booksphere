# CHAPTER 6 — SYSTEM TESTING

## 6.1 Introduction

System testing is a critical phase in the software development lifecycle that rigorously validates the functional correctness, reliability, security, performance, and responsiveness of the application. This chapter outlines the testing methodology applied to BookSphere, encompassing Unit Testing, Integration Testing, System Testing, a formal Test Plan, an exhaustive table of verified Test Cases, and the final verified test results.

---

## 6.2 Unit Testing

Unit testing validates the smallest testable units of software in isolation from external dependencies:
- **Core Helpers & Routing Units**: Verifies URI parsing, parameter extraction, and route matching in `App\Core\Router`.
- **Scoring Formulas**: Tests mirror functions in `RecommendationScoring` (e.g., verifying that popularity formula yields expected floats for known inputs).
- **Security Utilities**: Tests CSRF token generation, password hashing, and HTML entity escaping.

---

## 6.3 Integration Testing

Integration testing verifies that separate modules and components interact correctly when assembled:
- **Controller-to-Service Integration**: Tests that `AuthController` invokes `AuthService` and receives valid session state.
- **Service-to-Repository Integration**: Validates that `RecommendationService` correctly queries `RecommendationRepository` and filters candidates based on active library records.
- **Database Transaction Integration**: Verifies that adding a review atomically updates the review table and recalculates `average_rating` on the `books` table.

---

## 6.4 System Testing

System testing evaluates the end-to-end functionality of the complete, integrated application against specified functional and non-functional requirements:
- **Full Workflow Execution**: End-to-end user journeys (registration -> login -> catalog browsing -> library addition -> review submission -> recommendation generation -> community interaction).
- **Automated CLI Test Suite**: A comprehensive suite of **64 automated test files** executed directly via PHP CLI.
- **Browser Automation (CDP Audit)**: Headless browser testing using Chrome/Edge DevTools Protocol to verify 0 JavaScript exceptions, 0 console errors, and responsive layouts.

---

## 6.5 Test Plan

| Test Phase | Scope / Objective | Execution Tool / Method | Acceptance Criteria |
| :--- | :--- | :--- | :--- |
| **Phase A: Database Integrity** | Verify schema constraints, foreign key referential integrity, and data safety | SQLite CLI / `PRAGMA integrity_check` & `foreign_key_check` | 100% `ok`, 0 foreign key violations |
| **Phase B: Automated Suite** | Execute all 64 automated test files covering all modules | Custom PHP CLI Test Runner (`scratch/run_all_tests.php`) | 64 / 64 PASS (0 failures, 0 skipped) |
| **Phase C: Rec Engine V2** | Verify candidate generation, MMR reranking, scoring, exclusions, and explanations | 12 dedicated Recommendation test suites | 12 / 12 PASS (100% compliance) |
| **Phase D: HTTP Route Audit** | Verify status codes across 150 routes (public, authenticated, admin) | Automated HTTP benchmark script (`urllib` / cURL) | 0 HTTP 500 errors; proper 200 OK and 302 redirects |
| **Phase E: Frontend & CDP** | Validate client-side JavaScript execution, network requests, and responsive widths | Headless Edge CDP automation script | 0 JS errors, 0 console errors, 0 network failures |
| **Phase F: Security Audit** | Validate CSRF, authentication guards, SQL injection immunity, and admin authorization | Automated security test scripts (`SecurityAuditTest.php`) | Strict 403 on unauthorized access, zero SQLi/XSS |

---

## 6.6 Test Cases

The table below presents representative, verified test cases executed during system testing:

| Test ID | Module | Test Description | Input Data / Action | Expected Result | Actual Result | Status |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **TC-01** | Auth | User registration with valid data | Name: "Jane Doe", Email: "jane@test.com", Password: "Password@123" | User record created, password hashed, redirect to `/` | User created with ID, hashed with Bcrypt, redirect 302 | **PASS** |
| **TC-02** | Auth | Registration with duplicate email | Email: "riya@booksphere.test" (existing) | Validation error displayed: "Email already registered" | HTTP 422 / error displayed, no record inserted | **PASS** |
| **TC-03** | Auth | User login with incorrect password | Email: "riya@booksphere.test", Password: "WrongPassword" | Authentication fails, error displayed: "Invalid credentials" | Redirect with flash error, session not initiated | **PASS** |
| **TC-04** | Auth | User login with valid credentials | Email: "riya@booksphere.test", Password: "User@123" | Authentication succeeds, session regenerated, redirect | Session ID regenerated, HTTP 302 to target page | **PASS** |
| **TC-05** | Security | CSRF token missing on review POST | POST `/reviews` without `_token` parameter | Request rejected with HTTP 403 Forbidden | HTTP 403 Forbidden returned | **PASS** |
| **TC-06** | Security | Non-admin user accessing `/admin` | Authenticated as regular user, GET `/admin` | Access denied with HTTP 403 Forbidden | HTTP 403 Forbidden returned | **PASS** |
| **TC-07** | Catalog | Search books by keyword | Query `q=gatsby` | Returns "The Great Gatsby" with accurate author and rating | Single matching book returned with 200 OK | **PASS** |
| **TC-08** | Catalog | Filter books by category | Category: "Fiction" (ID: 1) | Only books mapped to Fiction category are displayed | 200 OK, all books belong to Category 1 | **PASS** |
| **TC-09** | Library | Add book to "Currently Reading" | Book ID: 10, Status: `reading` | Record inserted in `user_library`, status badge displayed | Record created, badge rendered as "Currently Reading" | **PASS** |
| **TC-10** | Library | Library exclusion from recommendations| User has Book ID: 1 in Library | Book ID: 1 is NEVER returned on `/recommendations` | Book ID: 1 strictly excluded from candidate pool | **PASS** |
| **TC-11** | Rec Engine | Cold-start recommendation generation | User with 0 library entries, GET `/recommendations` | Surfaces popular and top-rated titles with fallback reason | Bestseller baseline returned with honest starting reason | **PASS** |
| **TC-12** | Rec Engine | MMR diversity reranking | High relevance candidates from single genre | Top recommendations reordered to introduce genre variety | Diverse genres presented across top 5 positions | **PASS** |
| **TC-13** | Community| Submit community discussion post | Title: "Great Sci-Fi Reads", Content: "Let's discuss...", Book: null | Post inserted into `community_posts`, visible on feed | Post created, rendered in community feed | **PASS** |
| **TC-14** | Admin | Admin access to administration panel | Authenticated as `admin@booksphere.test`, GET `/admin` | Admin dashboard rendered with system metrics | HTTP 200 OK, dashboard KPIs rendered | **PASS** |
| **TC-15** | Admin | Google Books API search | Search query: `isbn=9780141439518` | Returns Pride and Prejudice volume metadata from Google | Metadata returned with title, author, and cover URL | **PASS** |
| **TC-16** | Integrity| SQLite Database Foreign Key Check | Execute `PRAGMA foreign_key_check;` | Zero violations across all 31 tables | 0 violations returned | **PASS** |

---

## 6.7 Test Results

System testing confirmed flawless operational readiness across all dimensions:
- **Automated Test Suites**: **64 / 64 PASS** (100% pass rate, 0 failures, 0 skipped).
- **Recommendation Engine V2 Suites**: **12 / 12 PASS**.
- **Database Integrity**: `PRAGMA integrity_check` returned `ok`; `PRAGMA foreign_key_check` returned 0 violations.
- **Route Status Verification**: Zero HTTP 500 server errors across 150 application routes.
- **Frontend CDP Audit**: 0 uncaught JavaScript exceptions, 0 console errors, 0 failed network requests.
- **Responsive Widths**: Flawless layout without horizontal overflow across 375px, 390px, 430px, 768px, 1024px, 1280px, 1440px, and 1920px viewports.
