# BookSphere — Configuration Guide

## 1. Environment Configuration (`.env`)
BookSphere reads environment variables from a root-level `.env` file via `App\Core\Environment`.

| Key | Development Value | Production Value | Purpose |
| :--- | :--- | :--- | :--- |
| `APP_NAME` | `BookSphere` | `BookSphere` | Platform application name |
| `APP_ENV` | `development` | `production` | Runtime environment |
| `APP_DEBUG` | `true` | `false` | Enable/disable verbose debug errors |
| `APP_URL` | `http://localhost:8000` | `https://booksphere.example.com` | Base public URL |
| `DB_PATH` | `database/booksphere.db`| `database/booksphere.db` | Absolute or relative SQLite path |
| `SESSION_SECURE` | `false` | `true` | Enforce HTTPS-only session cookies |

## 2. Subsystem Configuration (`config/`)
- `config/app.php`: Application settings and service providers.
- `config/database.php`: SQLite PDO connection options.
- `config/recommendations.php`: Recommendation weights, MMR diversity lambda, TTL windows.
- `config/google_books.php`: API endpoints, rate limit delays, timeout settings.
