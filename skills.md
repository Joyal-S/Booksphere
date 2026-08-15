# BookSphere — Agent Skills & Engineering Rules

> This file is the persistent operating contract for any AI coding agent working on BookSphere.
>
> Read this file BEFORE modifying the repository.
>
> These rules apply across all phases unless a later phase explicitly overrides a specific rule.

---

# 1. PROJECT IDENTITY

BookSphere is an intelligent digital book discovery and reading platform.

Core product capabilities include:

- Book discovery
- Search
- Filtering
- Categories
- Authors
- Book details
- Reviews and ratings
- Personal library
- Wishlist
- Reading shelves/status
- Following authors
- Personalized recommendations
- Notifications
- Personal analytics
- Book/catalog analytics
- Reports
- Administration
- Google Books integration/import

BookSphere should feel like a real production-grade product.

It must NOT feel like:

- a college CRUD application
- a generic Bootstrap dashboard
- an admin template
- a generic SaaS starter
- an AI-generated UI collection
- a collection of unrelated pages

The entire application must feel like ONE coherent product.

---

# 2. PRIMARY AGENT ROLE

When working on BookSphere, behave as a combination of:

- Senior software architect
- Senior PHP engineer
- Senior frontend engineer
- Product designer
- UI/UX designer
- Accessibility engineer
- Performance engineer
- Security engineer
- QA engineer

Do not optimize for simply completing the requested code.

Optimize for:

1. Correctness
2. Maintainability
3. User experience
4. Architectural integrity
5. Accessibility
6. Performance
7. Security
8. Visual consistency
9. Production readiness

---

# 3. SOURCE OF TRUTH

When making changes, use this priority order:

1. Existing repository code
2. Existing architecture
3. Existing database/schema
4. Existing routes
5. Existing tests
6. Existing documentation
7. Current running application
8. Phase-specific instructions
9. This file
10. General assumptions

Never invent application behavior when the repository can answer the question.

If uncertain:

- inspect the code
- inspect the route
- inspect the relevant service
- inspect the repository
- inspect the view
- inspect tests
- run the application if necessary

Do not guess.

---

# 4. EXISTING ARCHITECTURE

BookSphere uses a custom PHP MVC architecture.

Current architectural flow:

HTTP Request
    ↓
public/index.php
    ↓
bootstrap/app.php
    ↓
Core\Application
    ↓
Core\Router
    ↓
MiddlewarePipeline
    ↓
Controllers
    ↓
Services
    ↓
Repositories
    ↓
Core\Database
    ↓
SQLite / PDO

Respect this architecture.

Do NOT replace it with:

- Laravel
- Symfony
- Next.js
- React
- Vue
- Angular
- another MVC framework

unless a future phase explicitly authorizes an architectural migration.

UI improvements must work WITH the existing architecture.

---

# 5. ARCHITECTURAL BOUNDARIES

## Core

Contains application primitives such as:

- Router
- Request
- Response
- MiddlewarePipeline
- Database
- Session
- CSRF
- RateLimiter
- ErrorHandler
- Logger
- View

Do not put business logic here.

---

## Middleware

Responsible for request-level protection.

Examples:

- AuthMiddleware
- GuestMiddleware
- AdminMiddleware
- CsrfMiddleware
- SecureHeadersMiddleware

Do not bypass middleware for convenience.

---

## Controllers

Controllers should:

- receive HTTP requests
- validate/coordinate input
- call appropriate services
- return responses/views

Controllers should NOT become giant business-logic containers.

---

## Services

Services contain:

- business rules
- orchestration
- calculations
- recommendation logic
- caching orchestration
- domain behavior

Do not move domain logic into templates simply to make implementation easier.

---

## Repositories

Repositories are responsible for:

- SQL
- persistence
- database queries
- data retrieval

Use prepared statements.

Never construct unsafe SQL using raw user input.

---

## Views

Views should focus on:

- presentation
- semantic HTML
- rendering
- UI interaction hooks

Do not place substantial business logic inside views.

---

# 6. DATABASE RULES

BookSphere currently uses:

- SQLite
- PDO
- migrations
- prepared statements

Database behavior must remain stable unless the current phase explicitly concerns database changes.

Never:

- delete production data
- rewrite migrations casually
- change schema unnecessarily
- introduce duplicate data structures
- bypass repositories without a strong architectural reason

Before changing a query:

- understand existing indexes
- inspect related repositories
- consider existing tests
- consider query performance

---

# 7. BUSINESS LOGIC PROTECTION

The UI redesign must NOT accidentally change:

- recommendation calculations
- recommendation attribution
- rating calculations
- analytics metrics
- search behavior
- library status behavior
- review rules
- authorization
- ownership rules
- notification behavior
- search history
- Google Books synchronization
- admin permissions

A visual redesign is not permission to rewrite backend logic.

---

# 8. SECURITY RULES

Security has priority over visual convenience.

Always preserve:

- CSRF protection
- XSS escaping
- prepared SQL statements
- authentication
- authorization
- ownership policies
- secure headers
- upload validation
- rate limiting
- session security
- password hashing
- safe redirects
- safe JSON output

Never disable security because:

> "It is only for the frontend."

Never expose:

- passwords
- session tokens
- CSRF tokens unnecessarily
- API keys
- database credentials
- private configuration
- internal stack traces

---

# 9. UI/UX PHILOSOPHY

BookSphere must have a distinct product identity.

The visual direction should communicate:

- Intelligent
- Literary
- Premium
- Editorial
- Curated
- Modern
- Sophisticated
- Calm
- Trustworthy

The UI should feel like a product designed specifically around reading and discovery.

Do NOT make it look like a generic business dashboard.

---

# 10. DESIGN PRINCIPLE

Every UI decision should answer:

> Why does this exist?

Avoid decorative UI that has no functional or emotional purpose.

Prefer:

- strong hierarchy
- intentional whitespace
- meaningful typography
- clear actions
- readable content
- visual rhythm
- useful motion
- meaningful imagery

Avoid:

- visual noise
- excessive cards
- excessive borders
- excessive shadows
- excessive gradients
- unnecessary animations
- arbitrary decorative elements

---

# 11. BRAND LANGUAGE

BookSphere's existing identity uses:

- indigo/purple
- editorial serif typography
- modern sans-serif typography
- subtle gradients
- strong typography
- restrained visual depth

Preserve the brand DNA.

However:

DO NOT simply duplicate the landing page design everywhere.

The authenticated product should evolve naturally from the brand.

---

# 12. COLOR SYSTEM

Use semantic design tokens.

Prefer tokens such as:

```css
--color-primary
--color-primary-hover
--color-secondary
--color-accent
--color-background
--color-surface
--color-surface-raised
--color-text
--color-text-muted
--color-border
--color-success
--color-warning
--color-danger
--color-info