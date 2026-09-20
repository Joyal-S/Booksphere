# CHAPTER 7 — SYSTEM MAINTENANCE

## 7.1 Introduction

Software maintenance is the systematic modification of a software product after delivery to correct faults, improve performance, adapt to changing environmental requirements, or enhance operational capabilities. In accordance with standard software engineering practices (IEEE/ISO/IEC 14764), BookSphere incorporates structured strategies across all four classic maintenance dimensions: Corrective, Adaptive, Perfective, and Preventive maintenance.

---

## 7.2 Maintenance

### 1. Corrective Maintenance
Corrective maintenance involves diagnosing and rectifying residual bugs, runtime errors, or unexpected behavior identified during system operation.
- **Application to BookSphere**:
  - Centralized exception and error handling routes uncaught exceptions to secure server logs (`storage/logs/`) without exposing sensitive stack traces to end-users.
  - Phase 11 and Phase 12 audits isolated and resolved historical defects, including updating legacy test assertions to align with Recommendation Engine V2 behavior and standardizing session header handling.

### 2. Adaptive Maintenance
Adaptive maintenance modifies the system to ensure continued operational compatibility in response to evolving hardware, software environments, or external APIs.
- **Application to BookSphere**:
  - **PHP Version Upgrades**: Strict typing and adherence to modern PHP standards guarantee seamless compatibility with future PHP releases (PHP 8.3 and PHP 8.4).
  - **External API Evolution**: The Google Books API client encapsulates HTTP request headers, query construction, and error handling in a dedicated `GoogleBooksService`, allowing API protocol changes to be adapted in a single isolated service without affecting the rest of the application.
  - **Cross-Platform Hosting**: The use of OS-agnostic path resolvers ensures seamless portability between Windows development environments and Linux production hosting.

### 3. Perfective Maintenance
Perfective maintenance enhances system performance, improves maintainability, refines user experience, or expands functional capabilities based on user feedback.
- **Application to BookSphere**:
  - **Recommendation Engine Evolution**: Transitioned from a rudimentary category lookup (V1) to the sophisticated, multi-source Recommendation Engine V2 equipped with MMR diversity reranking.
  - **Phase 12 Complexity Reduction**: Executed a safe, evidence-based cleanup that eliminated 8,359 obsolete temporary files and browser profiles (reclaiming 606.41 MB of disk space) while preserving 100% of runtime code and database records.
  - **Responsive Layout Polish**: Refined CSS media queries and container grids to eliminate horizontal overflow across mobile viewports down to 375px.

### 4. Preventive Maintenance
Preventive maintenance involves proactive activities to detect and resolve latent faults before they manifest as operational failures, thereby improving long-term software maintainability.
- **Application to BookSphere**:
  - **Database Integrity Audits**: Automated test scripts periodically execute `PRAGMA integrity_check;` and `PRAGMA foreign_key_check;` to verify database health and detect potential SQLite corruption early.
  - **Static Analysis & Linting**: Periodic execution of automated linting scripts (`scratch/lint_all.php`) verifies syntax validity across all PHP files.
  - **Automated Regression Suite**: The 64-suite automated test suite acts as an automated regression guard, immediately flagging unintended side effects caused by code modifications.
