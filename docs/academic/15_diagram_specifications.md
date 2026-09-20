# DIAGRAM SPECIFICATIONS

This document provides formal diagram specifications in both Mermaid notation and structured ASCII format for all system diagrams required in the BookSphere project report.

---

## 1. Entity-Relationship (ER) Diagram

```mermaid
erDiagram
    USERS ||--o{ USER_LIBRARY : manages
    USERS ||--o{ REVIEWS : writes
    USERS ||--o{ WISHLIST : saves
    USERS ||--o{ COMMUNITY_POSTS : authors
    USERS ||--o{ AUTHOR_FOLLOWS : follows
    USERS ||--o{ NOTIFICATIONS : receives

    BOOKS ||--o{ BOOK_AUTHORS : has
    AUTHORS ||--o{ BOOK_AUTHORS : writes
    BOOKS ||--o{ BOOK_CATEGORIES : categorized_in
    CATEGORIES ||--o{ BOOK_CATEGORIES : contains

    BOOKS ||--o{ USER_LIBRARY : tracked_in
    BOOKS ||--o{ REVIEWS : reviewed_by
    BOOKS ||--o{ WISHLIST : saved_in
    BOOKS ||--o{ BOOK_VIEWS : logged_in
    BOOKS ||--o{ RECOMMENDATION_LOGS : recommended_as

    COMMUNITY_POSTS ||--o{ COMMUNITY_COMMENTS : contains
    COMMUNITY_POSTS ||--o{ COMMUNITY_LIKES : receives
    COMMUNITY_POSTS }o--o| BOOKS : discusses

    USERS {
        int id PK
        string name
        string email UK
        string password
        string avatar_path
        boolean is_admin
        datetime created_at
    }

    BOOKS {
        int id PK
        string title
        string slug UK
        string isbn
        int published_year
        float average_rating
        int review_count
        string status
        datetime deleted_at
    }

    AUTHORS {
        int id PK
        string name
        string slug UK
        text bio
    }

    CATEGORIES {
        int id PK
        string name
        string slug UK
        string icon
    }

    USER_LIBRARY {
        int id PK
        int user_id FK
        int book_id FK
        string status
        boolean is_favorite
        int personal_rating
    }

    REVIEWS {
        int id PK
        int user_id FK
        int book_id FK
        int rating
        string title
        text content
    }
```

---

## 2. Context-Level DFD (Level 0)

```mermaid
flowchart TD
    User([General Reader])
    Admin([Administrator])
    GoogleBooks([Google Books API])
    System[0.0 BookSphere Platform System]

    User -->|Registration, Login, Search, Shelves, Reviews, Community| System
    System -->|Search Results, Personal Shelves, Recommendations, Feeds| User

    Admin -->|Curation, Moderation, Sync Trigger| System
    System -->|System KPIs, Reports, Moderation Queue| Admin

    System -->|Volume Search Query, ISBN Lookup| GoogleBooks
    GoogleBooks -->|Volume Metadata, Cover Image URLs| System
```

---

## 3. Level-1 Data Flow Diagram (DFD)

```mermaid
flowchart TD
    User([General Reader])
    Admin([Administrator])

    subgraph BookSphere System
        P1(1.0 Auth & Profile)
        P2(2.0 Catalog & Search)
        P3(3.0 Library & Shelves)
        P4(4.0 Reviews & Ratings)
        P5(5.0 Recommendation Engine V2)
        P6(6.0 Community & Social)
        P7(7.0 Admin & API Sync)
    end

    D1[(D1: Users)]
    D2[(D2: Books & Authors)]
    D3[(D3: User Library)]
    D4[(D4: Reviews)]
    D5[(D5: Recommendation Logs)]
    D6[(D6: Community Posts)]

    User --> P1
    P1 <--> D1

    User --> P2
    P2 <--> D2

    User --> P3
    P3 <--> D3

    User --> P4
    P4 <--> D4
    P4 -->|Recalculate Rating| D2

    D3 -->|Signals| P5
    D4 -->|Signals| P5
    D2 -->|Candidates| P5
    P5 <--> D5
    P5 -->|Personalized Shelf| User

    User --> P6
    P6 <--> D6

    Admin --> P7
    P7 <--> D2
```

---

## 4. Use Case Diagram

```mermaid
flowchart LR
    Guest([Guest User])
    Reader([Registered Reader])
    Admin([Administrator])

    subgraph BookSphere Use Cases
        UC1(Browse Catalog & Details)
        UC2(Search & Filter Books)
        UC3(Register & Login)
        UC4(Manage Library Shelves)
        UC5(Submit Ratings & Reviews)
        UC6(View Explainable Recommendations)
        UC7(Participate in Community)
        UC8(Follow Authors & Wishlist)
        UC9(Admin Dashboard & Curation)
        UC10(Review & Post Moderation)
        UC11(Google Books Sync)
    end

    Guest --> UC1
    Guest --> UC2
    Guest --> UC3

    Reader -.->|Extends| Guest
    Reader --> UC4
    Reader --> UC5
    Reader --> UC6
    Reader --> UC7
    Reader --> UC8

    Admin -.->|Extends| Reader
    Admin --> UC9
    Admin --> UC10
    Admin --> UC11
```

---

## 5. Sequence Diagram: Recommendation Retrieval

```mermaid
sequenceDiagram
    autonumber
    actor User as Reader
    participant Controller as RecommendationController
    participant Service as RecommendationService
    participant Repo as RecommendationRepository
    participant DB as SQLite Database

    User->>Controller: GET /recommendations
    Controller->>Service: getPersonalizedRecommendations(userId)
    Service->>Repo: userPreferences(userId)
    Repo->>DB: SELECT library, reviews, wishlist
    DB-->>Repo: Profile Signals
    Repo-->>Service: User Profile Data

    Service->>Repo: hybridCandidates(profileSignals)
    Repo->>DB: Parameterized SQL (Sources A-H)
    DB-->>Repo: Raw Candidate Books
    Repo-->>Service: Candidate Array

    Service->>Service: applyNegativePenalty()
    Service->>Service: excludeOwnLibrary()
    Service->>Service: diversityRerank(candidates, MMR)
    Service->>Service: generateExplanations()
    Service-->>Controller: RecommendationResult DTO
    Controller-->>User: 200 OK (Rendered Recommendations View)
```
