# BookSphere — Troubleshooting & Diagnostic Guide

## 1. Common Issues & Resolutions

### 1. Database Locking (`SQLSTATE[HY000]: General error: 5 database is locked`)
- **Cause**: High concurrent writes when SQLite is operating in default DELETE rollback journal mode.
- **Resolution**: Ensure Write-Ahead Logging (`WAL`) is enabled:
  ```powershell
  sqlite3 database/booksphere.db "PRAGMA journal_mode = WAL;"
  ```

### 2. Session Header Warnings (`session_start(): Session cannot be started after headers have already been sent`)
- **Cause**: Output emitted before `session_start()` invocation.
- **Resolution**: Ensure no whitespace precedes `<?php` tags and that all HTTP controllers return `Response` objects rather than using `echo`.

### 3. Missing PHP Extensions
- **Error**: `Class 'PDO' not found` or `Call to undefined function curl_init()`.
- **Resolution**: Enable `extension=pdo_sqlite` and `extension=curl` in `php.ini`.
