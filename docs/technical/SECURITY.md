# BookSphere — Security Architecture & Controls

## 1. Security Architecture Summary
BookSphere implements enterprise-grade security controls aligned with the OWASP Top 10 web application security standards.

---

## 2. Implemented Security Controls

### 2.1 Authentication & Password Security
- **Bcrypt Hashing**: Passwords hashed via `password_hash($password, PASSWORD_BCRYPT)` with auto-generated salts.
- **Session Regeneration**: `session_regenerate_id(true)` executed upon login to eliminate session fixation.
- **Secure Cookie Flags**: Session cookies set with `HttpOnly = true`, `SameSite = Lax`, and `Secure = true` (in production HTTPS).

### 2.2 Cross-Site Request Forgery (CSRF)
- **Synchronizer Token Pattern**: Cryptographically random 32-byte tokens generated via `random_bytes(32)`.
- **Validation**: `CsrfMiddleware` enforces token presence and performs constant-time string comparison (`hash_equals()`) on all state-mutating requests (POST, PUT, DELETE).

### 2.3 SQL Injection (SQLi) Defense
- **100% Prepared Statements**: All database operations in `App\Repositories` utilize PDO prepared statements with bound parameters (`bindValue()`). Zero string concatenation in SQL queries.

### 2.4 Cross-Site Scripting (XSS) Defense
- **Context-Aware Output Escaping**: View templates consistently escape dynamic output using `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')`.

### 2.5 Role-Based Authorization & IDOR
- **Admin Authorization**: `AdminMiddleware` protects all `/admin/*` routes, verifying `is_admin == 1`. Non-admins receive `403 Forbidden`.
- **Resource Ownership Validation**: Shelf updates and review modifications verify that `user_id` matches the authenticated session user.

### 2.6 File Upload Security
- **Avatar Uploads**: Restricted to `image/jpeg`, `image/png`, `image/webp`.
- **Size Limit**: Enforced 2MB maximum file size.
- **Filename Sanitization**: Randomized unique hashes (`bin2hex(random_bytes(16))`) prevent directory traversal and overwrite attacks.
