# B2Bhaat Production Readiness: Phase A Re-Audit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Produce the eight audit documents in `docs/audit/` with an evidence-backed, prioritized `PRA-` gap register that reflects the code at the branch's current commit.

**Architecture:** This is a read-only audit. No product code, migrations, or committed tests change in this plan. Evidence comes from code references (`path:line`), existing tests, commands run, and browser walkthroughs. A defect counts as **Confirmed** only when it has been reproduced: by a throwaway test run and then deleted, by a browser run, or by a command. Otherwise it is **Suspected**. Each confirmed defect's register entry carries the reproduction test as a code block, so Phase B can use it as its failing first step. Phase B (fixes) and Phase C/D (deploy + final simulation) each get their own plan, written from this plan's register.

**Tech Stack:** Laravel 13.39, Filament 5.11, Livewire storefront, PHP 8.5, PHPUnit on SQLite, claude-in-chrome for UI walkthroughs.

**Spec:** `docs/superpowers/specs/2026-11-05-production-readiness-reaudit-design.md`

## Global Constraints

- Branch: `audit/production-readiness`. Never commit to `production` or `main`. Never push.
- Deliverables live in `docs/audit/`. `documents/audit/` (the 2026-10-28 snapshot) is read but never edited.
- Issue IDs: `PRA-NNN` (zero-padded, sequential), citing the old `R-NN` where one exists.
- Register fields, in this order: Issue ID, Module, Description, Current behaviour, Expected behaviour, Business impact, Priority, Recommended solution, Dependencies, Testing requirements, Implementation status.
- Priority: **P0** security vulnerability / data corruption / financial calculation error / broken core workflow. **P1** important feature not working / permission problem / broken integration / serious business-logic gap. **P2** missing automation / usability / incomplete reporting / performance. **P3** visual / minor usability / cleanup / optional.
- Evidence labels on every claim: **[T]** test (name it), **[B]** browser run, **[C]** command output, **[R]** code read (`path:line`). Status words: Working, Partial, Broken, Missing, Pending owner decision.
- Owner decisions D2–D8 (spec §8) are the expected behaviour where they apply. The deferred questions Q4, Q5, Q7, Q8, Q10–Q13, Q15–Q18 are recorded as *Pending owner decision*, never as defects, unless the current behaviour is internally broken (wrong amount, ledger imbalance, missing auth, crash). Those are defects (spec §4 exception).
- No `migrate:fresh` / `db:wipe` / seeding against the developer's configured database. Schema checks use a throwaway SQLite file: `DB_CONNECTION=sqlite DB_DATABASE=<scratchpad>/audit.sqlite`.
- Throwaway reproduction tests go in `tests/Feature/AuditProbe/`. Run them, copy them into the register entry, then delete them before each commit. `git status` must show no `tests/Feature/AuditProbe` at commit time.
- Never paste secrets, `.env` values, or tokens into documents. Name env vars only.

## Review Focus

1. **Trusting commit messages.** An R-item is marked Fixed only with [T] or [C]/[B] evidence, never because a commit title says so. Task 3 requires a named test or a probe for every Fixed claim.
2. **Filament default actions.** Resources expose Edit/Delete/Create/bulk actions by default, and `app/Policies/` is empty. A seller or admin action hidden in the table may still be reachable by URL or a Livewire call. Task 5 probes each seller resource's create/edit/delete URLs as another seller.
3. **Cross-tenant data.** Seller A reading or changing seller B's sub-orders, products, wallet, RFQs, or return requests. Task 5 probes each with two sellers.
4. **Scheduler and queue assumptions.** Escrow release, reservation expiry, campaign sync, and SMS only run if a worker and scheduler exist. Task 4 records whether each works when run manually and what breaks if it doesn't run (e.g. a double run when it overlaps).
5. **Money rounding and repeat calls.** Hold, release, refund, and payout called twice, or on partially cancelled orders. Task 4 probes calling each `EscrowLedgerService` entry point twice on the same order.

---

### Task 1: Audit scaffold and baseline

**Files:**
- Create: `docs/audit/PRODUCTION_READINESS_AUDIT.md`, `FEATURE_INVENTORY.md`, `BUSINESS_LOGIC_AUDIT.md`, `SECURITY_AUDIT.md`, `DATABASE_AUDIT.md`, `UI_UX_AUDIT.md`, `TESTING_REPORT.md`, `PRODUCTION_DEPLOYMENT_CHECKLIST.md` (all under `docs/audit/`)

**Interfaces:**
- Produces: the register table header in `PRODUCTION_READINESS_AUDIT.md` (exact field order from Global Constraints), and an "Evidence legend" section that every other doc links to. Later tasks append rows and sections. They don't redefine the format.

- [ ] **Step 1:** Create the eight files. Each starts with a title, date `2026-11-05`, branch `audit/production-readiness`, commit SHA (`git rev-parse --short HEAD`), and a link to the evidence legend. `PRODUCTION_DEPLOYMENT_CHECKLIST.md` gets only a header and "Drafted in Phase C". Phase A only records findings that will feed it.
- [ ] **Step 2:** Run `php artisan test --compact` and record the result in `TESTING_REPORT.md` under "Baseline". Expected: `266 passed, 1129 assertions`. If it differs, record the actual numbers and failing test names. Don't fix them.
- [ ] **Step 3:** Run `php artisan route:list --except-vendor --json` and `php artisan schedule:list`. Store the route count and schedule entries in `TESTING_REPORT.md` → "Baseline surface". They are the checklist for Tasks 2 and 4.
- [ ] **Step 4:** Commit: `git add docs/audit && git commit -m "docs(audit): scaffold phase A audit documents and baseline"`

### Task 2: Feature inventory

**Files:**
- Modify: `docs/audit/FEATURE_INVENTORY.md`

**Interfaces:**
- Consumes: the route list from Task 1.
- Produces: feature IDs `F-NNN` with persona, entry point (route / Livewire / Filament resource/page / command / job), status, and evidence. Tasks 3–7 cite them.

- [ ] **Step 1:** Inventory every entry point, grouped by persona (Super Admin, Seller, Wholesale Buyer, Retail Customer, Guest, System/automation). Sources: `routes/web.php`, `routes/api.php`, `app/Livewire/Storefront/*`, `app/Filament/Admin/{Resources,Pages,Widgets}/*`, `app/Filament/Seller/Resources/*`, `app/Http/Controllers/**`, `app/Console/Commands/*`, `app/Jobs/*`. Every route from the Task 1 route list maps to at least one F-ID.
- [ ] **Step 2:** For each F-ID, set an initial status from the existing tests (grep `tests/Feature` for the class or route). Mark [T] with the test name where one covers it. Features with no covering test are marked "Untested". That doesn't make them Broken.
- [ ] **Step 3:** Add a section listing mock data, hardcoded demo values, and placeholder logic found (grep for `demo`, `mock`, `fake`, `TODO`, `FIXME`, `dummy`, `lorem`, hardcoded `৳`/Tk amounts, hardcoded phone/email) with `path:line`, each tagged as a settings candidate, constant, or demo-only.
- [ ] **Step 4:** Commit: `git commit -am "docs(audit): feature inventory"`

### Task 3: Re-verify the 2026-10-28 risk register (R-01…R-31)

**Files:**
- Modify: `docs/audit/PRODUCTION_READINESS_AUDIT.md` (section "Prior register re-verification")

**Interfaces:**
- Consumes: `documents/audit/GAP_ANALYSIS.md` §O, `documents/audit/OPEN_BUSINESS_QUESTIONS.md`.
- Produces: one row per R-item: `R-ID | Old title | Status (Fixed / Partially fixed / Open / Superseded by D# / Pending owner decision) | Evidence | New PRA-ID if not Fixed`.

- [ ] **Step 1:** For each R-item, find the code path and the test that would prove the fix. Fixed requires a named passing test [T] or a probe test [T-probe] run in this task.
- [ ] **Step 2:** For items with no existing test, write the probe in `tests/Feature/AuditProbe/R{NN}ProbeTest.php`, run `php artisan test --compact tests/Feature/AuditProbe`, and record pass/fail. Must-probe set (they carry money or permissions): R-01, R-03, R-04, R-05, R-06, R-07, R-14, R-15, R-16, R-18.
- [ ] **Step 3:** Every R-item that isn't Fixed becomes a `PRA-` row in the main register, with all eleven fields filled in. Probe code for confirmed defects goes in the row's "Testing requirements" as a fenced code block.
- [ ] **Step 4:** Delete `tests/Feature/AuditProbe/`, confirm with `git status --short tests`, and commit: `git commit -am "docs(audit): re-verify prior risk register"`

### Task 4: Business logic, configurability, and automation audit

**Files:**
- Modify: `docs/audit/BUSINESS_LOGIC_AUDIT.md`, `docs/audit/PRODUCTION_READINESS_AUDIT.md` (new PRA rows)

**Interfaces:**
- Consumes: F-IDs (Task 2). Services under `app/Services/{Cart,Orders,Escrow,Logistics,Inventory,Settings,Sms}`.
- Produces: workflow sections W1–W10. Each has a numbered step trace, the rule it should obey (D-decision or BRD), a verdict, and the PRA-IDs it raised. Each defect is recorded as *what's wrong / why it matters / what should happen / recommended fix / modules affected*.

- [ ] **Step 1: Trace workflows.**
  - **W1** Cart and tier pricing: `CartSessionService::resolveTierRecord`, MOQ counted across variations (D7).
  - **W2** Checkout: `CheckoutComponent`, minimum order amount, COD ceiling, shipping fee from `ShippingCalculatorService` with seller override (D6).
  - **W3** Order split: `OrderSplitterService`. The sum of sub-order totals, shipping, and commission must equal the parent order total.
  - **W4** Payment / COD: mock gateway blocked in production (D8), COD hold at delivery (D2).
  - **W5** Courier fulfilment: `CourierBookingService`, `CourierStatusService`, signed webhook only (D4).
  - **W6** Self-delivery: `SelfDeliveryService` handover code (D5).
  - **W7** Escrow: `EscrowLedgerService` hold → 48 h → release (`escrow:release-delivered`) → payout. Wallet balance must equal the sum of `wallet_transactions`.
  - **W8** Wholesale approval: `WholesaleUpgrade`, admin `KycApprovals`, price visibility gate (D3).
  - **W9** RFQ / quotation lifecycle.
  - **W10** Cancellation, disputes, returns: `OrderDisputeService`, `ReturnRequest`. Record the policy as Pending owner decision. Record arithmetic or auth defects as defects.
- [ ] **Step 2: Probe the money paths.** Write probes in `tests/Feature/AuditProbe/` for:
  - W3: sub-order totals sum to the parent, using a 2-seller cart with mixed retail and wholesale lines.
  - W7: hold and release called twice each on the same sub-order leaves the balance unchanged after the first call.
  - W7: release before 48 h does nothing.
  - W7: the ledger sum equals `seller_wallets` balance after hold, release, refund, and payout.
  - W10: cancelling one sub-order of a two-seller order refunds only that sub-order's amount.

  Record pass/fail and the probe code in the register.
- [ ] **Step 3: Configurability (brief Phase 3).** Make a table of every remaining hardcoded business value (from Task 2 Step 3 plus anything found in the services). Columns: `value | location | belongs in settings? (yes/no) | reason`. Values governed by deferred questions (commission %, VAT) are marked Pending owner decision.
- [ ] **Step 4: Automation (brief Phase 4).** For each scheduled command and `SendSmsJob`, run it manually against a seeded throwaway SQLite database. Prefix every command with `DB_CONNECTION=sqlite DB_DATABASE=<scratchpad>/audit.sqlite`, after `php artisan migrate --seed` into that file. Record what changed. Also record overlap protection (`withoutOverlapping`), retry/backoff, and failure logging. List the manual operations that should be automated (with the triggering event) as P2 rows.
- [ ] **Step 5:** Delete the probes, then commit: `git commit -am "docs(audit): business logic, configuration and automation audit"`

### Task 5: Security and role audit

**Files:**
- Modify: `docs/audit/SECURITY_AUDIT.md`, `docs/audit/PRODUCTION_READINESS_AUDIT.md`

**Interfaces:**
- Consumes: F-IDs, the route list.
- Produces: a role × capability matrix (rows = F-IDs that change or reveal data; columns = super_admin, admin, seller (own), seller (other's), wholesale_buyer (approved / unapproved), customer, guest) with each cell marked Allowed / Denied / **Leak** and its evidence.

- [ ] **Step 1:** Record how each panel gates access: `canAccessPanel` on `User`, each resource's `canViewAny/canCreate/canEdit/canDelete`, `getEloquentQuery` scoping, and the absence of `app/Policies` classes.
- [ ] **Step 2:** Write probes in `tests/Feature/AuditProbe/` for every seller resource (`Products`, `SubOrders`, `SellerWallets`, `ReturnRequests`, `RfqRequests`, `ProductReviews`). Seller B requests seller A's record view/edit URL and calls the Livewire edit/delete action, and gets 403/404. Probe the admin resources as a seller. Probe `/invoice/{token}`, `/track/{token}`, controller routes under `/seller/*`, and every `routes/api.php` endpoint unauthenticated and as the wrong role.
- [ ] **Step 3:** Check, citing `path:line`:
  - courier webhook signature verification (D4)
  - rate limiting on OTP, login, and checkout
  - file upload validation (KYC trade licence, product images, CSV stock import: mime, size, storage disk visibility)
  - mass-assignment guards on models touched by Livewire
  - `SecurityHeadersMiddleware` coverage
  - `APP_DEBUG` and error-page behaviour
  - OTP fallback `SMS_DEFAULT_OTP`: must not work in production. Probe with `app()->detectEnvironment(fn () => 'production')`.
  - the demo switcher (R-01)
- [ ] **Step 4:** Run `composer audit` and `npm audit --omit=dev`. Record the counts per severity.
- [ ] **Step 5:** Delete the probes, then commit: `git commit -am "docs(audit): security and role audit"`

### Task 6: Database, integrity, and performance audit

**Files:**
- Modify: `docs/audit/DATABASE_AUDIT.md`, `docs/audit/PRODUCTION_READINESS_AUDIT.md`

**Interfaces:**
- Produces: a per-table section (columns, FKs + on-delete, indexes, unique constraints, soft deletes, money column types) and a list of slow or N+1 pages with query counts.

- [ ] **Step 1:** Run `DB_CONNECTION=sqlite DB_DATABASE=<scratchpad>/audit.sqlite php artisan migrate:fresh --seed --no-interaction` (creating the file first). Expected: exit 0. Record any failure. Also check that every migration has a working `down()`: `php artisan migrate:rollback --step=26` on the same file must exit 0.
- [ ] **Step 2:** Read all 26 migrations in `database/migrations/`. Flag:
  - money stored as float/double instead of `decimal`
  - missing FKs on `*_id` columns
  - missing indexes on columns used in `where`/`orderBy` by services (`status`, `seller_id`, `order_id`, `token` columns, `created_at` on ledgers)
  - missing unique constraints (order numbers, invoice/tracking tokens, wallet per seller, one OTP per phone/purpose)
  - nullable columns the code assumes are non-null
- [ ] **Step 3:** Check transaction boundaries: every multi-row money or stock write (`OrderSplitterService`, `EscrowLedgerService`, `StockReservationService`, `CouponService`) runs inside `DB::transaction` with row locks where concurrent updates are possible. Cite `path:line`.
- [ ] **Step 4:** Query-count probe. For the homepage, catalog, product detail, cart, checkout, customer orders, seller SubOrders list, and admin Orders list, record `DB::getQueryLog()` counts with 20 seeded rows vs 40. A count that grows with rows is N+1 → P2 row.
- [ ] **Step 5:** Delete the probes and the scratch database, then commit: `git commit -am "docs(audit): database integrity and performance audit"`

### Task 7: UI/UX audit (browser)

**Files:**
- Modify: `docs/audit/UI_UX_AUDIT.md`, `docs/audit/PRODUCTION_READINESS_AUDIT.md`

**Interfaces:**
- Consumes: a running local app (resolve the URL with the Boost `get-absolute-url` tool; if nothing is serving, ask the owner to run `composer run dev`). Seeded demo users via `/demo-login/{retail|wholesale|seller|admin}` (local only).

- [ ] **Step 1:** Walk through each persona's main journey in Chrome at 1440 px and at 390 px width. Screenshot each screen:
  - retail: home → search/filter → product → cart → checkout → track → invoice
  - wholesale: upgrade request → tier prices → MOQ across variations → RFQ
  - seller: dashboard → products/variations → sub-order fulfilment → challan → wallet
  - admin: dashboard → KYC approvals → orders → settings → SMS
- [ ] **Step 2:** For each screen, score the brief's 14 Phase-5 checks (consistency, spacing, navigation, forms/validation, loading/empty/error states, responsive, dashboard usefulness, search/sort/filter/pagination/reset, accessibility, unnecessary steps, duplicates, destructive-action confirmation, date/currency/number/status formatting). Only failures become PRA rows, with the screenshot reference and `resources/views/...` or component path.
- [ ] **Step 3:** Dashboards and reports (brief Phase 10): check that each KPI widget matches a direct DB count/sum for the same period (Boost `database-query`), and that exports and print views (invoice, challan, wallet statement CSV) open and show real data.
- [ ] **Step 4:** Commit: `git commit -am "docs(audit): UI/UX and reporting audit"`

### Task 8: Consolidate register and report to owner

**Files:**
- Modify: `docs/audit/PRODUCTION_READINESS_AUDIT.md`, `docs/audit/TESTING_REPORT.md`

- [ ] **Step 1:**
  - Sort the register by priority, then module.
  - Remove duplicates and cross-link merged IDs.
  - Every row needs all eleven fields filled in and Implementation status = `Open`, `Pending owner decision`, or `Fixed before audit`.
- [ ] **Step 2:**
  - Add an executive summary: modules reviewed, F-IDs total, counts by status, PRA counts by priority, and the deferred-decision list.
  - Add a "Phase C inputs" section (env, queue, scheduler, logging, backup and header findings) that the deployment checklist plan will consume.
- [ ] **Step 3:** Record in `TESTING_REPORT.md` every probe run in Tasks 3–6 (name, what it proved, pass/fail). Probes are not part of the suite.
- [ ] **Step 4:**
  - Verify `git status --short` shows only `docs/audit/` changes and no `tests/Feature/AuditProbe`.
  - Run `php artisan test --compact`. It must still be 266 passed, since the audit touched no code.
  - Commit: `git commit -am "docs(audit): consolidated production readiness register"`
- [ ] **Step 5:** Report the P0 and P1 lists to the owner in chat (ID, one-line description, evidence label). Then write the Phase B fix plan from the register (`docs/superpowers/plans/2026-11-05-production-readiness-phase-b-fixes.md`): one task per PRA row (or tight group), each starting with that row's reproduction test.
