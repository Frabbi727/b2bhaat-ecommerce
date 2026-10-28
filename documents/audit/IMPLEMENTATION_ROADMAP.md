# B2B Haat — Implementation Roadmap

> Part of the B2B Haat discovery audit (read-only, 2026-10-28). Evidence labels: **[F]** fact · **[I]** inference · **[A]** unverified assumption · **[Q]** stakeholder decision. Cross-referenced IDs (FR-, BRL-, D-, L-, R-, W-) live in the sibling files of `documents/audit/`.

## P. Implementation Roadmap

Recommended. Each item becomes a test-first task in the writing-plans phase.

- **Phase 1: P0 blockers.** R-01, R-02 (gate mock gateway to non-production), R-03, R-04, R-05.
  - *Done when:*
    - `/demo-login` returns 404 outside local
    - a webhook without a valid signature returns 401
    - a seller cannot edit financial fields or create or delete sub-orders, proven by a test
    - COD release never reduces another order's escrow, proven by a test
- **Owner decisions (2026-10-28):** see `OPEN_BUSINESS_QUESTIONS.md` D1–D8. R-15 (seller self-marks delivered) is resolved by D4.
- **Phase 2: Core workflow completion.** Also: self-delivery with customer handover code (D5), configurable delivery fees with seller override (D6), wholesale approval gate (D3), MOQ total across variations (D7). R-06, R-07, R-08, R-09, R-10, R-11, R-17, then a real payment gateway (after the [Q] answers), then R-12 cancellation, refund and return.
- **Phase 3: Roles and governance.** Policies (R-25), wholesale approval flow, seller approval with a wallet-on-approval listener, lookup admin resources (R-20), commission tiers (R-19), payout approve and reject (R-16).
- **Phase 4: Integrations and reporting.** Authenticated API and tracker endpoints (R-13), notifications (R-24), courier integrations, invoices with tax (R-26), seller and admin dashboards, RFQ completion (R-23).
- **Phase 5: Reliability.** PG test DB, domain factories, concurrency tests (R-28), idempotency and constraints (R-18, R-27), CI, Redis, queue, scheduler and deploy (R-29).
- **Phase 6: Docs and maintainability.** Rename and dedupe the docs, a real README, refresh the tracker, move logic out of Livewire and Filament into services or actions (R-30, R-31).
