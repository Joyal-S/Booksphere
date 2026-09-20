# BookSphere — Testing & Quality Assurance Guide

## 1. Overview
BookSphere features a comprehensive suite of **64 automated CLI test files** covering all domain repositories, recommendation algorithms, controller workflows, and database integrity.

---

## 2. Test Suite Execution

### Run Complete Test Suite:
```powershell
php scratch/run_all_tests.php
```
*Expected Output*:
```text
==========================================
Total: 64 | Passed: 64 | Failed: 0
==========================================
```

### Run Recommendation Engine V2 Tests:
```powershell
php tests/RecommendationArchitectureTest.php
php tests/RecommendationCandidateGenerationR8Test.php
php tests/RecommendationDiversityRerankingTest.php
php tests/RecommendationLibraryExclusionTest.php
```

### Run Database Integrity Checks:
```powershell
php -r "require 'bootstrap/constants.php'; require 'vendor/autoload.php'; \$db = BookSphere\App\Core\Database::instance(); var_dump(\$db->pdo()->query('PRAGMA integrity_check;')->fetchAll());"
```
