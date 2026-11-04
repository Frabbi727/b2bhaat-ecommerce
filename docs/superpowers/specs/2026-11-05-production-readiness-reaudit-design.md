# B2Bhaat — Production Readiness Re-Audit, Fix & Verification

- **Date:** 2026-11-05
- **Status:** Draft, awaiting owner approval
- **Source brief:** the owner's "Complete Business System Audit, Gap Analysis, Improvement & Production Readiness" prompt (13 phases). It was given in chat. The parts the deliverables depend on are copied into §6 and §9.
- **Relation to the 2026-11-01 spec:** that plan (dynamic store settings) is fully ticked and committed. It is not redone. Its work is re-verified in Phase A like any other feature, and this spec supersedes it for production readiness.

## 1. Intent

The owner wants B2Bhaat to work as a real, client-ready business system, not a demo that only looks finished. Every claim of "working" must be backed by evidence: a passing automated test, a reproduced browser walkthrough, or a code reference. Anything unverified is labelled unverified.

This is **not a first audit**. A read-only discovery audit already exists (`documents/audit/`, 2026-10-28, risk register R-01…R-31), and about 40 commits since then claim to fix parts of it (RBAC, escrow refunds, dynamic settings, stock reservation TTL, RFQ hardening, courier tracking, customer auth, SMS/OTP). This work **re-verifies the current code**, records what is truly done, fixes what is still broken, and produces the deliverables the brief names.

## 2. Baseline (verified 2026-11-05)

- Stack: Laravel 13.39, Filament 5.11, Livewire storefront, PHP 8.5, PHPUnit (SQLite in tests).
- `php artisan test --compact`: **266 passed, 1129 assertions, 0 failures**.
- `/demo-login/{role}` returns 403 outside `local`/`testing` (R-01 appears fixed; still to be covered by a test if one doesn't exist).
- `docs/BUSINESS_AND_SYSTEM_ARCHITECTURE.md` and `CLAUDE.md` say Laravel 12 / Filament 3, which is wrong. This doc drift is a finding.
- The 2026-11-03 SMS plan has no ticked boxes even though the commits show the work is done. The plan trackers are stale.
- `.ai/rules` does not exist, so no project rule files apply.

## 3. Goals

1. **Re-audit**: rebuild the feature inventory and gap register from the current code. Mark each earlier R-item as Fixed (with evidence), Partially fixed, Open, or Superseded by owner decision.
2. **Deliverables**: the eight documents the brief names (§6).
3. **Fix**: all verified P0, then P1, then P2/P3 by value, each through test-first changes.
4. **Production readiness**: deployment checklist, env/secrets review, queue + scheduler + cache + logging setup, backup/rollback notes, and a health check.
5. **Final verification**: an end-to-end business simulation across the four personas, plus a final report covering the 15 points in the brief's Phase 13.

## 4. Non-goals (this round)

- **Open business questions, explicitly deferred by the owner (2026-11-05):** commission tiers (Q4), VAT/tax (Q5), returns & disputes policy (Q7), cancellation & refund rules (Q8), one person as both seller and buyer (Q10), wholesale buyers using retail prices (Q11), guest-checkout policy (Q12), extra admin roles (Q13), RFQ scope (Q15), API client (Q16), hosting target (Q17), language (Q18). Code paths that depend on these are **not redesigned**. They are listed in the reports as *Pending owner decision*, with current behaviour described. **Exception (builder's call):** a real defect *inside* those areas, such as a wrong amount, an escrow/ledger imbalance, a missing authorization check, or a crash, is still fixed as P0/P1, keeping the current intended behaviour. Only the policy itself (rates, windows, who may do what) waits on the owner.
- **A live payment gateway.** Per decision D8, the gateway is not chosen. The mock gateway must stay blocked in production. That gets verified, not replaced.
- No new frameworks, packages, or base folders without approval. No rewrites of working modules.

## 5. Anti-goals (failure even if "done")

- Fake success messages, mock data, or placeholder logic hiding incomplete work in a critical flow.
- Saying something works without a test or reproduced run.
- Skipped, deleted, or weakened tests to get green.
- Any change that alters money amounts, escrow balances, or ledger meaning without a test proving the amounts are correct.
- Destructive migrations or migrations that rewrite existing data without a reversible strategy.
- Secrets or credentials in code or reports.

## 6. Deliverables

Location: **`docs/audit/`** (builder's call, recorded here). `documents/audit/` stays as the historical 2026-10-28 snapshot and is not edited.

| File | Content |
|---|---|
| `PRODUCTION_READINESS_AUDIT.md` | Central gap register using the brief's fields (ID, module, description, current, expected, impact, priority, solution, dependencies, tests, status), plus the final report (§9) |
| `FEATURE_INVENTORY.md` | Every feature per persona/module: Working / Partial / Broken / Missing / Pending decision, with evidence |
| `BUSINESS_LOGIC_AUDIT.md` | Workflow-by-workflow check (cart → checkout → split → payment/COD → fulfilment → delivery → escrow → payout; wholesale approval; RFQ; cancellation) with what's wrong / why / expected / fix / affected modules |
| `SECURITY_AUDIT.md` | Per-role access checks, backend authorization, data isolation, input/upload/rate-limit/error handling |
| `DATABASE_AUDIT.md` | Constraints, FKs, indexes, nullability, transactions, idempotency, clean-migrate check |
| `UI_UX_AUDIT.md` | Storefront, seller panel, admin panel: consistency, states, mobile, formatting, destructive confirmations |
| `TESTING_REPORT.md` | Test counts before and after, what each new test proves, manual/browser runs, known gaps |
| `PRODUCTION_DEPLOYMENT_CHECKLIST.md` | Step-by-step clean deploy with nothing left undocumented, env vars (names only), workers, scheduler, backups, rollback |

New issue IDs use the prefix `PRA-` and cite the old `R-` ID where one exists.

**Required register fields (from the brief):** Issue ID, Module, Description, Current behaviour, Expected behaviour, Business impact, Priority, Recommended solution, Dependencies, Testing requirements, Implementation status.

**Priority definitions (from the brief):**
- **P0 (Critical):** security vulnerabilities, data corruption, financial calculation errors, broken core business workflows.
- **P1 (High):** important features not working, permission problems, broken integrations, serious business logic gaps.
- **P2 (Medium):** missing automation, usability problems, incomplete reporting, performance issues.
- **P3 (Low):** visual and minor usability improvements, code cleanup, optional features.

## 7. How the work is done

**Phase A: Re-audit (read-only).** Go module by module through routes, Livewire components, Filament resources/policies, services, models, migrations, jobs, and the scheduler. Re-check each R-item. Use the configurable-settings question from the brief (Phase 3) to sort hardcoded values into *belongs in settings* or *stays a constant*. Output: the eight documents in first-draft form, with a prioritized register. **Checkpoint:** the P0/P1 list is reported to the owner in chat, and fixing continues without waiting. The brief says "do not stop after identifying problems". Work pauses for owner input only before an architectural change: a new package, a new base folder, a schema change that rewrites existing data, or a change to money flow semantics.

**Phase B: Fixes, P0 → P1 → P2 → P3.** One issue per task, test-first (write a failing test that shows the defect, fix, then run the full suite). Money and escrow changes need explicit amount assertions. Run `vendor/bin/pint --dirty --format agent` after PHP edits. Use small commits referencing `PRA-` IDs. Update the register status as each item closes.

**Phase C: Production readiness.** The hosting target is undecided (Q17). The checklist is written for a **generic Linux VPS** (Nginx, PHP-FPM 8.5, Supervisor for `queue:work`, cron for `schedule:run`, PostgreSQL or MySQL), with a short section mapping each step to Laravel Cloud's managed equivalents. This is the builder's call. "Walked through on a fresh setup" means a clean local clone that follows the checklist from scratch (fresh `.env`, `composer install`, `npm run build`, migrate, seed, worker, scheduler) and reaches a working storefront and admin. It does not mean a real server. Review `.env.example`, debug and error reporting, queue worker and scheduler definitions, cache and session drivers, logging, security headers, `composer audit` / `npm audit`, migrations on a fresh database (`migrate:fresh` locally only), and a health route. Write the deployment checklist.

**Phase D: Final client simulation.** Use a fresh local database and seeders, then cover: admin setup → seller onboarding/KYC → product with variations and tier prices → retail purchase → wholesale approval and bulk purchase (MOQ across variations) → order split → courier webhook delivery and self-delivery handover code → escrow hold and 48 h release → payout → SMS notifications (mock driver) → invoices, tracking, and exports → unauthorized attempts per role. Do each step in the browser where it has UI, and in automated tests where it carries money or permissions. Write the final report with the 15 points from the brief.

**Branching:** the work happens on a branch off the current `production` branch (e.g. `audit/production-readiness`). Nothing is pushed or merged without the owner's say.

## 8. Owner decisions in force (from 2026-10-28, `documents/audit/OPEN_BUSINESS_QUESTIONS.md`)

These override the BRD and the code when they conflict:

- **D2 (COD):** the courier collects the cash and pays the platform. The seller's share is held at delivery and released 48 h later.
- **D3 (wholesale):** wholesale prices appear only after an admin approves the trade licence.
- **D4 (courier delivery):** only the courier, through a signed webhook, marks a courier shipment delivered.
- **D5 (self-delivery):** the customer gives the seller a handover code, and the seller enters it to confirm delivery.
- **D6 (delivery fees):** the admin sets default fees per zone, and a seller can override them. Whoever delivers keeps the fee.
- **D7 (MOQ):** MOQ counts the total across variations.
- **D8 (payments):** only the mock gateway exists, and it stays blocked in production.

## 9. Definition of done

- All eight documents exist and agree with the code at the final commit.
- No open P0. Every P1 is either fixed or explicitly deferred, with the reason (e.g. pending owner decision).
- The full test suite passes. The test count rises, and every fix has a test that proves it.
- A clean `migrate:fresh --seed` works locally. The deployment checklist has been walked through on a fresh setup.
- The final report says plainly what is production-ready and what is not, and lists the remaining risks.
- The final report covers the brief's 15 points: modules reviewed; features identified; features tested; working features; incomplete features; critical issues found; issues fixed; issues pending; test execution results; security assessment; performance assessment; database integrity assessment; remaining risks; production deployment readiness; final recommendations.

## 10. Left to the builder

- Order and grouping of fixes within a priority level.
- Exact test names and file layout (following the existing `tests/Feature` conventions).
- Which hardcoded values become settings, judged case by case and justified in the audit.
- UI polish choices that stay within the existing Filament/Tailwind style.
- Correcting the stale docs (`CLAUDE.md` versions, plan checkboxes) as part of Phase B.
