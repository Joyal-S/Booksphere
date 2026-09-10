# BookSphere — Deployment & Operations Runbook

**System**: BookSphere — Digital Book Recommendation & Social Reading Platform  
**Target Environment**: Windows 10/11 / Windows Server / Linux (Ubuntu 22.04+)  
**PHP Version**: >= 8.2  
**Database**: SQLite 3 (`database/booksphere.db`)  

---

## 1. System Requirements & Prerequisites

### 1.1 Core Runtime
- **PHP**: Version **8.2** or higher (strictly required).
- **SQLite**: SQLite 3 (bundled natively with PHP).
- **Composer**: Used solely for generating PSR-4 class autoloading mappings. Zero third-party runtime framework dependencies are required.

### 1.2 Required PHP Extensions
Ensure the following extensions are enabled in your active `php.ini`:
```ini
extension=pdo_sqlite
extension=sqlite3
extension=curl
extension=mbstring
extension=fileinfo
extension=openssl
```

To verify active extensions in Windows PowerShell:
```powershell
php -m | findstr -i "pdo_sqlite sqlite3 curl mbstring fileinfo openssl"
```

---

## 2. Windows Local Deployment (Quickstart)

Follow these steps to deploy and run BookSphere on a fresh Windows machine.

### Step 1: Clone or Extract the Project
Place the project directory in your desired path (e.g., `D:\PROJECTS\booksphere` or `C:\booksphere`).

### Step 2: Configure Environment (`.env`)
Copy `.env.example` to create `.env`:
```powershell
copy .env.example .env
```

Verify the core variables in `.env`:
```ini
APP_NAME="BookSphere"
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_TIMEZONE=UTC

DB_CONNECTION=sqlite
DB_PATH=database/booksphere.db
```
> [!NOTE]
> Leave `EMAIL_ENABLED=false` and `EMAIL_TRANSPORT=log` for local development. Emails will be captured cleanly in `storage/logs/email.log`.

### Step 3: Generate Autoloader
Run Composer to generate the autoload classmap:
```powershell
composer install
# Alternatively, if vendor directory is already bundled:
composer dump-autoload --optimize
```

### Step 4: Ensure Directory Permissions & Cache Folders
Verify the existence of the following directories. The application will create them if missing, but ensure they are writable:
- `database/cache/recommendations/`
- `database/cache/google_books/`
- `storage/logs/`
- `public/uploads/covers/`

In PowerShell:
```powershell
if (!(Test-Path "database\cache\recommendations")) { New-Item -ItemType Directory -Path "database\cache\recommendations" -Force }
if (!(Test-Path "database\cache\google_books")) { New-Item -ItemType Directory -Path "database\cache\google_books" -Force }
if (!(Test-Path "storage\logs")) { New-Item -ItemType Directory -Path "storage\logs" -Force }
if (!(Test-Path "public\uploads\covers")) { New-Item -ItemType Directory -Path "public\uploads\covers" -Force }
```

### Step 5: Verify SQLite Database State
The pre-seeded SQLite database is located at `database/booksphere.db` (535 catalogue books, 889 authors, 17 categories). Run a quick integrity verification:
```powershell
php -r "require 'bootstrap/constants.php'; require 'vendor/autoload.php'; \$db = BookSphere\App\Core\Database::instance(); echo 'Integrity: ' . \$db->query('PRAGMA integrity_check')[0]['integrity_check'] . ' | Books: ' . \$db->query('SELECT count(*) as c FROM books')[0]['c'] . PHP_EOL;"
```
Expected output:
```text
Integrity: ok | Books: 535
```

### Step 6: Start the Development Server
Launch PHP's built-in web server with document root pointing to `public/`:
```powershell
php -S localhost:8000 -t public
```

### Step 7: Access the Application
Open your web browser and navigate to:
```
http://localhost:8000
```

### Demo Accounts for Evaluators
| Role | Email | Password | Access Capabilities |
| :--- | :--- | :--- | :--- |
| **Administrator** | `admin@booksphere.test` | `Password123!` | Full admin dashboard, review moderation, community reports, catalogue management, Google Books import. |
| **Regular Reader** | `reader@booksphere.test` | `Password123!` | Dashboard shelves, personalized recommendations, personal library, reviews, and community forums. |

---

## 3. Production / Hosted Deployment

When deploying to a production server (Linux VPS, Ubuntu 22.04 LTS, Apache/Nginx), observe the following best practices:

### 3.1 Document Root
The web server's document root **must point strictly to the `public/` directory**, NOT the project root. This prevents visitors from accessing `.env`, `database/booksphere.db`, or configuration files.

### 3.2 Web Server Configurations

#### Apache (`.htaccess`)
BookSphere includes a pre-configured `public/.htaccess` file that handles clean URL routing:
```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]
</IfModule>
```
Ensure `mod_rewrite` is enabled on Apache:
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

#### Nginx Configuration
```nginx
server {
    listen 80;
    server_name booksphere.example.com;
    root /var/www/booksphere/public;

    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### 3.3 File Permissions (Linux)
```bash
sudo chown -R www-data:www-data /var/www/booksphere
sudo chmod -R 755 /var/www/booksphere
sudo chmod -R 775 /var/www/booksphere/storage
sudo chmod -R 775 /var/www/booksphere/database/cache
sudo chmod -R 775 /var/www/booksphere/public/uploads
```

### 3.4 Production `.env` Settings
```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://booksphere.example.com
```

### 3.5 OPcache Configuration
Enable OPcache in `php.ini` to compile and cache PHP scripts in shared memory:
```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
```

---

## 4. Backup & Disaster Recovery

### 4.1 Safe Backup Procedure
Because SQLite uses Write-Ahead Logging (`WAL`), the safest way to snapshot the database during operation without locking readers or writers is:

#### Option A: Windows Copy Script
```powershell
# Create timestamped backup
$date = Get-Date -Format "yyyyMMdd_HHmmss"
Copy-Item "database\booksphere.db" "database\backup_booksphere_$date.db"
```

#### Option B: Native SQLite CLI Online Backup
```bash
sqlite3 database/booksphere.db ".backup database/backup_booksphere.db"
```

### 4.2 Safe Recovery Procedure
1. Stop the active web server process.
2. Replace `database/booksphere.db` with the verified backup file.
3. Remove any stale `-shm` and `-wal` sidecar files in the `database/` directory.
4. Run integrity check:
   ```powershell
   php -r "require 'bootstrap/constants.php'; require 'vendor/autoload.php'; echo BookSphere\App\Core\Database::instance()->query('PRAGMA integrity_check')[0]['integrity_check'] . PHP_EOL;"
   ```
5. Restart the server.

---

## 5. Automated Testing Verification

BookSphere includes 52 automated regression test suites.

To execute the complete regression test suite:
```powershell
php scratch/run_all_tests.php
```
Expected output:
```text
Summary: 52 test suites | 52 PASSED | 0 FAILED
```

To run the dedicated Recommendation Library Exclusion regression test:
```powershell
php tests/RecommendationLibraryExclusionTest.php
```
Expected output:
```text
RESULT: Checks: 17 | Failed: 0
```

---

## 6. Troubleshooting Runbook

| Symptom | Probable Cause | Corrective Action |
| :--- | :--- | :--- |
| `Fatal error: Uncaught Error: Call to undefined function BookSphere\App\Core\Database::pdo_sqlite` | Missing PHP SQLite extension | Open `php.ini`, remove the leading semicolon from `;extension=pdo_sqlite`, and restart terminal/server. |
| `Failed to listen on localhost:8000 (reason: Address already in use)` | Port 8000 is occupied by another process | Launch on an alternative port: `php -S localhost:8080 -t public` and update `APP_URL` in `.env`. |
| `Permission denied` when saving reviews or uploading covers | Write permissions missing on directories | Grant write access to `storage/` and `public/uploads/` for the current user or `www-data`. |
| Blank page or HTTP 500 error | Misconfigured `.env` or syntax error | Set `APP_DEBUG=true` in `.env` to inspect the stack trace, or check `storage/logs/app.log`. |
