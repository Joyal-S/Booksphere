# CHAPTER 4 — SYSTEM ENVIRONMENT

## 4.1 Introduction

The system environment defines the technical foundation, hardware resources, software platforms, runtime dependencies, and development tools required to develop, test, deploy, and operate BookSphere. A rigorously specified system environment ensures software reproducibility, operational stability, maintainability, and optimal performance across different deployment configurations.

---

## 4.2 Software Requirements Specification (SRS)

The software components required to run and maintain BookSphere are categorized into server runtime, database engine, development tools, and client runtime:

### 1. Server-Side Runtime & Extensions
- **PHP Version**: PHP 8.2.0 or higher (Strict typing enabled, modern match expressions, readonly properties, and constructor property promotion).
- **Core PHP Extensions Required**:
  - `pdo_sqlite`: Enables SQLite 3 database connectivity and prepared statement execution.
  - `curl`: Powers HTTP requests to external bibliographic APIs (Google Books API).
  - `mbstring`: Handles multibyte UTF-8 string operations for international book titles and author names.
  - `json`: Provides native JSON serialization for API responses and recommendation caching.
  - `session`: Manages secure HTTP session state and user authentication.
  - `openssl` or native `random_bytes()`: Generates cryptographically secure CSRF tokens and remember-me tokens.
  - `fileinfo`: Validates MIME types of uploaded avatar images.
  - `gd` (optional): Supports server-side image resizing and thumbnail generation.

### 2. Database Management System
- **Database Engine**: **SQLite 3.39+**.
- **Storage Mode**: Embedded serverless file database (`database/booksphere.db`).
- **Journal Mode**: Write-Ahead Logging (`PRAGMA journal_mode = WAL;`) for high-concurrency read operations.
- **Foreign Key Enforcement**: `PRAGMA foreign_keys = ON;` strictly enforced on every connection.

### 3. Client-Side Software Requirements
- **Web Browser**: Any modern web browser with HTML5 and ES6 JavaScript support:
  - Google Chrome 100+
  - Microsoft Edge 100+
  - Mozilla Firefox 100+
  - Apple Safari 15+
  - Mobile Chrome / Mobile Safari on iOS and Android.

---

## 4.3 Hardware Requirements Specification

Due to its lightweight, dependency-free architecture, BookSphere operates efficiently on standard hardware without requiring high-performance computing clusters or dedicated database servers.

### 1. Development & Testing Hardware Requirements
| Component | Minimum Specification | Recommended Specification |
| :--- | :--- | :--- |
| **Processor (CPU)** | Intel Core i3 (2.0 GHz) or AMD equivalent | Intel Core i5 / AMD Ryzen 5 (2.5 GHz+) multi-core |
| **Random Access Memory (RAM)**| 4 GB RAM | 8 GB or 16 GB RAM |
| **Hard Disk Storage** | 2 GB free disk space (HDD or SSD) | 10 GB free NVMe/SATA SSD space |
| **Network Interface** | Standard Broadband / Wi-Fi | High-speed Broadband (for Google Books API sync) |

### 2. Production Server Hosting Hardware Requirements
| Component | Minimum Specification | Recommended Specification |
| :--- | :--- | :--- |
| **Virtual CPU (vCPU)** | 1 vCPU (1.0 GHz+) | 2 vCPU (2.0 GHz+) |
| **RAM** | 512 MB – 1 GB RAM | 2 GB RAM |
| **Storage** | 1 GB SSD storage | 5 GB SSD storage |
| **Network Bandwidth** | 100 Mbps network port | 1 Gbps port with unlimited/high data transfer |

---

## 4.4 Tools and Platforms

### 4.4.1 Front-End Tools
- **HTML5**: Semantic document markup utilizing modern tags (`<header>`, `<nav>`, `<main>`, `<section>`, `<article>`, `<aside>`, `<footer>`).
- **Vanilla CSS3**:
  - CSS Custom Properties (CSS Variables) for unified design tokens (color palettes, typography scale, spacing units, elevation shadows).
  - Modern layout modules: CSS Flexbox and CSS Grid for fluid, responsive card grids and dashboard viewports.
  - Media Queries for responsive breakpoints (320px, 375px, 390px, 430px, 768px, 1024px, 1280px, 1440px, 1920px).
  - Zero third-party CSS framework dependencies (eliminating Tailwind or Bootstrap runtime overhead).
- **Vanilla JavaScript (ES6+)**:
  - Native DOM manipulation and asynchronous Fetch API for AJAX interactions (rating submissions, post likes, comment drawers).
  - Client-side input validation and micro-animations.
- **FontAwesome 6**: Scalable vector icons for navigational elements, rating stars, and interactive action buttons.

### 4.4.2 Back-End Tools
- **Composer**: Dependency manager utilized strictly for PSR-4 class autoloading (`vendor/autoload.php`) and development testing packages.
- **Custom MVC Engine**: Handcrafted routing engine (`BookSphere\App\Core\Router`), environment manager (`Environment`), and database wrapper (`Database`).
- **Google Books REST API**: Authoritative external bibliographic data provider for bulk catalog enrichment and high-resolution cover retrieval.

### 4.4.3 Operating System
- **Development Operating System**: Microsoft Windows 11 / Windows 10 (PowerShell 7+, Windows Subsystem for Linux).
- **Production Operating System**: Ubuntu Linux 22.04 / 24.04 LTS or Debian 12 (POSIX-compliant Linux distributions).
- **Cross-Platform Compatibility**: BookSphere utilizes OS-agnostic path resolvers (`root_path()`, `DIRECTORY_SEPARATOR`) ensuring seamless cross-platform execution between Windows and Linux environments.
