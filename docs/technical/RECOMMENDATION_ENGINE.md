# BookSphere — Recommendation Engine V2 Specification

## 1. Core Architecture
Recommendation Engine V2 is a deterministic, multi-source hybrid recommendation engine designed to deliver transparent, explainable recommendations without black-box machine learning overhead.

```text
[User Profile Signals] (Library, Ratings, Wishlist, Views, Follows)
          |
          v
[Candidate Generation] (Sources A through H)
          |
          v
[Parameterized Scoring] (SQL & PHP Mirrored Formulas)
          |
          v
[Hard Exclusions] (Exclude books in user library & soft-deleted books)
          |
          v
[MMR Diversity Reranking] (Balancing relevance and genre diversity)
          |
          v
[Explanation Generation] (Human-readable reason strings)
          |
          v
[RecommendationResult DTO] -> Serialized Cache / Rendered View
```

---

## 2. Candidate Generation Sources (A–H)

| Source | Signal Origin | Mechanics |
| :--- | :--- | :--- |
| **Source A** | Direct Category Affinity | Books sharing categories with user's finished or 4+ star rated books |
| **Source B** | Author Affinity | Unread books written by authors user has rated highly |
| **Source C** | Wishlist Associations | Books frequently co-occurring in wishlists with user's saved titles |
| **Source D** | High Rating / Review Affinity | Books with positive reviews matching user's reading taste |
| **Source E** | Recently Viewed Affinity | Books similar to titles recently inspected by the user |
| **Source F** | Followed Author Network | Newly published or popular titles by authors the user follows |
| **Source G** | Community Discussion Signals | Titles actively discussed in community discussion threads |
| **Source H** | Global Quality Baseline | Popularity fallback: `(Avg Rating / 5 * 0.50) + (Review Count * 0.20) + (Wishlist Count * 0.30)` |

---

## 3. Maximal Marginal Relevance (MMR) Diversity Reranking
To prevent recommendations from being dominated by a single genre (e.g., 10 consecutive Fantasy books), MMR reranking balances candidate relevance against similarity to already selected books:
$$MMR = \arg\max_{d_i \in R \setminus S} \left[ \lambda \cdot \text{Sim}_1(d_i, q) - (1 - \lambda) \cdot \max_{d_j \in S} \text{Sim}_2(d_i, d_j) \right]$$
- $\lambda = 0.70$ (70% weight on personal relevance, 30% penalty on genre redundancy).
- Genre similarity is computed via Jaccard similarity across category sets.

---

## 4. Cold-Start Handling & Explanations
- **Cold-Start**: Users with 0 library entries or ratings receive high-quality titles from Source H with an honest explanation: *"A popular starting point among BookSphere readers."*
- **Explainability**: Every recommendation card renders an exact reason:
  - *"Because you read The Hobbit and enjoy Fantasy books."*
  - *"Recommended because you follow J.K. Rowling."*
