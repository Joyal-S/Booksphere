# BookSphere — Verified System Limitations

In accordance with academic rigor, the following real-world limitations of the current BookSphere implementation are documented:

1. **User Interaction Sparsity**: The production database currently contains 15 reviews and 33 library entries across 59 users. Consequently, collaborative filtering signals rely primarily on seeded baseline profiles rather than vast crowdsourced datasets.
2. **Single-Node SQLite Concurrency**: SQLite 3 in WAL mode provides excellent read concurrency but serializes write transactions via database file locks. High-throughput distributed multi-server deployments require migration to PostgreSQL or MySQL.
3. **Recently Viewed Window Cap**: User catalog view histories are capped at a fixed window (10–20 titles) to prevent session storage bloat.
4. **No Real-Time CTR / Impression Tracking**: The current recommendation engine logs recommendations to `recommendation_logs`, but does not dynamically update scoring weights based on live click-through rates.
5. **External API Rate Limits**: Automated catalog synchronization depends on Google Books API availability and quotas.
