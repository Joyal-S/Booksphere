# CHAPTER 2 — SYSTEM ANALYSIS

## 2.1 Introduction

System analysis is a fundamental phase in software engineering that involves studying an existing system, identifying operational inefficiencies, gathering user requirements, and defining the scope and feasibility of a proposed software solution. This chapter examines the limitations inherent in existing book cataloging and discovery systems, details the architecture and capabilities of the proposed BookSphere platform, evaluates its technical, operational, and economic feasibility, and explains the software engineering paradigm applied during its development lifecycle.

---

## 2.2 Existing System

Existing book management and literary discovery systems predominantly fall into two categories:
1. **Commercial E-Commerce Platforms (e.g., Amazon Books, Barnes & Noble)**: Commercial web applications designed primarily for book sales and distribution.
2. **Legacy Social Book Cataloging Networks (e.g., Goodreads, LibraryThing)**: Community-oriented platforms founded in the early 2000s aimed at cataloging personal reading lists and sharing book reviews.

In these existing systems, the typical user workflow involves:
- Searching for a book title using basic keyword queries.
- Manually browsing pre-computed bestseller lists or sponsored product recommendations.
- Adding a book to an unsegmented "read" or "to-read" shelf.
- Rating a book on a 1–5 scale and submitting free-form text reviews.
- Navigating external forums or comment sections to discuss literary works.

### 2.2.1 Limitations of Existing System

A rigorous analysis of existing systems reveals several acute limitations:

1. **Opaque and Commercially Biased Recommendations**:
   - Existing recommendation algorithms prioritize commercial profitability and publisher sponsorships over genuine user preference alignment.
   - Traditional collaborative filtering models frequently fail due to the "cold-start" problem, offering negligible personalization to new users.
   - Recommendations lack explainability; users are never informed why a book appears on their dashboard, leading to skepticism and low engagement.
2. **Fragmented Reading and Social Workflows**:
   - In existing platforms, personal reading management (tracking reading goals and shelf statuses) is completely severed from community discussions.
   - Community interactions are often buried in outdated discussion forums with poor mobile responsiveness and no direct linkage to real-time user reading activities.
3. **Severe Architectural Bloat and Poor Performance**:
   - Modern iterations of legacy platforms rely heavily on complex, multi-megabyte client-side JavaScript single-page application (SPA) bundles.
   - High memory consumption, excessive network requests (often exceeding 80–120 HTTP calls per page view), and noticeable layout shift degrade the user experience on standard mobile and desktop devices.
4. **Data Isolation and Stale Bibliographic Metadata**:
   - Existing systems maintain proprietary, siloed databases that do not integrate cleanly with external authoritative APIs (such as the Google Books API) for cover resolution and bibliographic synchronization.
   - Catalog management tools for administrators are cumbersome, lacking automated bulk-import or metadata synchronization facilities.
5. **Inadequate Security and Privacy Controls**:
   - Many platforms engage in aggressive tracking and data harvesting for third-party advertising.
   - Legacy systems frequently exhibit vulnerabilities such as insecure direct object references (IDOR), absent cross-site request forgery (CSRF) protections on auxiliary forms, and weak session regeneration practices.

---

## 2.3 Proposed System

The proposed **BookSphere** system is an integrated, high-performance web platform engineered from first principles to overcome the deficiencies of existing solutions. BookSphere unites literary discovery, structured personal library tracking, transparent recommendations, and literary community discussions into a cohesive, lightweight MVC application.

### Core Capabilities of BookSphere:
- **Intelligent Catalog Discovery**: Rapid full-text search and multi-criteria faceted filtering (by genre/category, author, publication era, and rating) across 495 verified titles and 448 authors.
- **Unified Personal Library Management**: Comprehensive reading status tracking ("Want to Read", "Currently Reading", "Finished"), private reading goal progress metrics, personal star ratings, and wishlist management.
- **Recommendation Engine V2**: A multi-source deterministic hybrid recommendation engine combining eight signal sources (Sources A through H) with Maximal Marginal Relevance (MMR) diversity reranking and transparent, human-readable explanations.
- **Integrated Literary Community**: Rich community discussion hubs, book-linked posts, threaded comments, post likes, author follows, and real-time notification tracking.
- **Administrative Operations & API Integration**: Comprehensive administration dashboard for book/author management, review moderation, community oversight, and automated Google Books API bulk importation and cover synchronization.

### 2.3.1 Advantages of Proposed System

The proposed BookSphere platform offers substantial advantages over existing systems:

| Feature Dimension | Existing Systems | Proposed BookSphere System |
| :--- | :--- | :--- |
| **Recommendation Model** | Opaque black-box / commercial bias; severe cold-start failures | Deterministic hybrid engine (8 signal sources) with transparent human-readable explanations |
| **Recommendation Diversity** | High genre concentration; repetitive bestselling titles | Maximal Marginal Relevance (MMR) reranking balancing relevance and genre diversity |
| **Personal Library Integration** | Separate from discovery; static shelfs | Deeply integrated: reading statuses directly feed real-time recommendation signals |
| **Software Architecture** | Heavy framework bloat, complex SPAs, high latency | Clean, lightweight PHP 8.2+ MVC, zero framework overhead, sub-160ms response times |
| **Database & Concurrency** | Resource-heavy database servers requiring complex maintenance | Highly optimized SQLite relational database with WAL mode and compiled indexes |
| **Community Engagement** | Isolated forum threads, poor mobile UX | Book-linked community feeds, author follows, and real-time notifications |
| **Security Architecture** | Variable; often vulnerable to CSRF, IDOR, or session fixation | Enterprise-grade: Bcrypt hashing, CSRF synchronizer tokens, secure cookies, PDO prepared statements |

---

## 2.4 Feasibility Study

Before proceeding with system implementation, a comprehensive feasibility study was conducted to evaluate the viability of BookSphere across technical, operational, and economic dimensions.

### 2.4.1 Technical Feasibility

The technical feasibility evaluates whether the proposed system can be successfully engineered using available technologies, development tools, and technical competencies:
- **Programming Language & Runtime**: PHP 8.2+ provides modern object-oriented features, strict typing, native JSON support, and superior execution performance via PHP OPcache.
- **Database Engine**: SQLite 3 is natively supported across all PHP environments, provides full ACID compliance, requires zero server configuration, and easily sustains the application's transaction volume using Write-Ahead Logging (WAL) mode.
- **Frontend Technologies**: Standard HTML5, vanilla CSS3 (with CSS Custom Properties for responsive theming), and modern vanilla JavaScript eliminate heavy build steps (e.g., Webpack/Vite) while guaranteeing 100% browser compatibility.
- **External Integration**: The Google Books REST API utilizes standard HTTP/JSON protocols, seamlessly supported by PHP's cURL extension.
- **System Constraints**: The entire application runs smoothly on standard web servers (Apache, Nginx, or PHP's built-in development server) without specialized hardware or external caching daemons.

**Conclusion**: The project is **technically feasible**.

### 2.4.2 Operational Feasibility

Operational feasibility assesses how well the proposed system satisfies user requirements and whether users can easily operate the platform:
- **User Interface Usability**: The user interface follows modern, intuitive design principles featuring consistent typography, clear visual hierarchy, accessible contrast ratios, and responsive navigation across mobile, tablet, and desktop viewports.
- **Workflow Efficiency**: Readers can complete primary actions (searching for books, updating reading statuses, writing reviews, viewing recommendations) within 1–2 clicks.
- **Administrative Usability**: The administration panel provides streamlined workflows for reviewing reported content, managing author records, and triggering catalog synchronization without requiring database administration knowledge.
- **User Acceptance**: The presence of transparent recommendation explanations directly addresses the primary frustration readers express with algorithmic discovery platforms.

**Conclusion**: The project is **operationally feasible**.

### 2.4.3 Economic Feasibility

Economic feasibility analyzes the cost-benefit proposition of the software project:
- **Development Costs**: BookSphere was developed entirely using open-source tools and platforms (PHP, SQLite, Visual Studio Code, Git, Composer, PHPUnit). No proprietary software licenses, commercial SDKs, or paid IDE subscriptions were required.
- **Infrastructure & Hosting Costs**: Due to its lightweight architecture and reliance on SQLite, BookSphere can be hosted on minimal, low-cost virtual private servers (VPS) or shared hosting environments (costing under $5–$10/month), avoiding expensive dedicated database clusters or cloud microservice bills.
- **Maintenance Costs**: The modular MVC structure, self-contained SQLite database, and comprehensive automated test suite (64 test files) drastically minimize ongoing maintenance and bug-fixing expenditures.

**Conclusion**: The project is **economically feasible**.

---

## 2.5 Software Engineering Paradigm Applied

To accommodate the structured, multi-stage evolution of BookSphere—spanning database normalization, recommendation engine design, community integration, security hardening, and performance optimization—an **Iterative Development Model** was applied throughout the project lifecycle.

### Justification for the Iterative Model:
1. **Incremental Feature Delivery**: Rather than attempting a monolithic single-pass delivery (as in the Waterfall model), BookSphere was partitioned into distinct, progressive phases (Phases 1 through 13). Each phase produced a fully verified, working baseline.
2. **Continuous Verification and Testing**: Each iteration concluded with automated regression testing and architectural audits (e.g., Phase 11 Regression Audit and Phase 12 Complexity Reduction), ensuring that defects were isolated and remediated before advancing to subsequent stages.
3. **Refinement of Complex Algorithms**: The Recommendation Engine underwent systematic refinement across iterations—advancing from basic category lookups (V1) to a calibrated, multi-source hybrid architecture with MMR diversity reranking (V2).

```text
+-------------------------------------------------------------------------------+
|                    ITERATIVE DEVELOPMENT LIFECYCLE MODEL                      |
+-------------------------------------------------------------------------------+
|                                                                               |
|  [Iteration 1: Foundation]    --> Architecture, Schema, Core MVC, Auth        |
|            |                                                                  |
|            v                                                                  |
|  [Iteration 2: Catalog & Lib] --> 500-Book Dataset, Personal Library Shelves  |
|            |                                                                  |
|            v                                                                  |
|  [Iteration 3: Community]     --> Posts, Comments, Follows, Notifications     |
|            |                                                                  |
|            v                                                                  |
|  [Iteration 4: Rec Engine V2] --> Multi-Source Signals, MMR, Explanations     |
|            |                                                                  |
|            v                                                                  |
|  [Iteration 5: Audit & Clean] --> Full Regression, Pre-Deployment Cleanup    |
|            |                                                                  |
|            v                                                                  |
|  [Iteration 6: Documentation] --> Academic Report & Technical Package         |
|                                                                               |
+-------------------------------------------------------------------------------+
```
