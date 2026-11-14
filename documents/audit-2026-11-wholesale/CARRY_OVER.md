# Carry-over from the November audit

Source: `documents/audit-2026-11/ISSUE_REGISTER.md` and `IMPLEMENTATION_LOG.md`. Every fixing commit below is an ancestor of this branch (checked with `git merge-base --is-ancestor`). These items are **not** reported again. Items marked **re-verify** touch money or wholesale rules: the task named in the last column checks that the fix still holds and names the test that proves it.

| Prior ID | Area | Issue | Status / commit | Re-verify in |
|---|---|---|---|---|
| PAY-1 | Payments | Cancel during MFS verification still charged | Fixed 9e07404, 25a4e9d | **re-verify** Task 5 |
| PAY-2 | Payments | Unverified MFS orders never expire | Fixed 4787c36 (remind 12 h, cancel 72 h) | **re-verify** Task 5 |
| PAY-3 | Payments | TrxID reusable | Fixed (MfsTransactionIdUniqueTest) | **re-verify** Task 6 |
| PAY-4 | Refunds | Whole-parcel refunds only | Fixed f6c223c, daa2118 (owner rules 2026-11-10) | **re-verify** Task 3 (S4), Task 5 |
| PAY-5 | Money | Floats in cart and order totals | Fixed bf42207 (`Money::lineTotal`, poisha) | **re-verify** Task 3 |
| PAY-6 | Concurrency | Missing locks; stock locked in no fixed order | Fixed 845a999 | **re-verify** Task 4, Task 10 |
| M-1, M-2 | Orders | Lock order; expiry paging | Fixed 4b1c99d | **re-verify** Task 10 |
| M-3, M-4 | Payments | Cancelled-in-full status; TrxID release | Fixed 1b211eb, 8184914 | Task 5 |
| ORD-1 | Orders | Status from consignments; admin free edit | Fixed 235200b | **re-verify** Task 5 |
| ORD-2 | Orders | Dispatch bypassed `canTransition` | Fixed ffbf60a | **re-verify** Task 5 |
| ORD-3 | Checkout | Post-split steps not atomic; coupon over-use | Fixed 49d39df | **re-verify** Task 4 |
| ORD-4 | Checkout | Suspended seller re-check | Already handled (test in 49d39df) | Task 4 |
| INV-5 | Wholesale cart | Inactive variation sold via wholesale | Fixed 552b49c | **re-verify** Task 2 |
| INV-6 | Pricing | Price-tier validation gaps | Fixed f3131f6 | **re-verify** Task 2 |
| INV-8 | Catalog | Products/prices live on save | Fixed 5ba25b2..06e9ef4 (admin approval) | **re-verify** Task 2, Task 4 |
| UX-7 | Storefront | MOQ shown only at checkout | Fixed 56d410b | Task 9 |
| FS-1 / INV-7 | Flash sale | Flash units not released on undo paths | Fixed d5b7dea | **re-verify** Task 5 |
| FS-2 | Flash sale | Admin approve bypassed conflict checker | Fixed 719e111 | Task 7 |
| FS-3 | Flash sale | Claim did not check offer belongs to product | Fixed 1064337 | **re-verify** Task 2 |
| ACC-1 | Accounting | Shipping credited to expense | Fixed 701b9d5 (history command not run on production) | Task 3 |
| ACC-3, ACC-4 | Accounting | Self-collected COD wallet/ledger; top-up journal | Fixed 62d6d30 | **re-verify** Task 3 (S5) |
| ACC-7 | Accounting | Clearing/receivable never settled | Fixed 78b7ef4 | Task 5 |
| ACC-11 | Accounting | Commission booked at payment | Fixed 1b5acc5 (deferred until delivery) | **re-verify** Task 3 |
| ACC-15 | Accounting | Float maths in journals | Fixed da762d3 | **re-verify** Task 3 |
| ACC-12 | Accounting | Tax unbalances posting; no subscription deferral | Needs legal/tax (owner-deferred) | Not re-reported |
| MON-8, MON-13, MON-16 | Commission | Switch meaning; stored rate; category step | Fixed 2134644, 27eeeeb | **re-verify** Task 3 |
| MON-11, MON-12 | Billing | COD-deficit bill; delinquent payouts | Fixed 90ea25b…, 5193504 | Task 5 |
| R-1, R-2, R-4 | Returns | Double settlement; coupon credit; empty request | Fixed 2e1a87f, 85f5116 | **re-verify** Task 5 |
| R-5 | Concurrency | Opposite lock order stock/flash | Fixed 67ec9a5 | Task 10 |
| R-3 | Security | Seller read another seller's TrxID | Fixed 461710b | Task 6 |
| SEC-9 | Security | Seller sees other sellers' invoice lines | Fixed 718f8cd | **re-verify** Task 6 |
| SEC-10 | Security | Sensitive fields mass-assignable | Fixed a2e60e1 | **re-verify** Task 6 |
| SEC-11 | Security | Courier webhook replay | Fixed 0fb505f | **re-verify** Task 6 |
| SEC-6, M-8 | Security | Livewire actions not throttled | Fixed 94d5ffa, b7b2ac7 | Task 6 |
| SEC-8 | Security | Tracking tokens never expire | Fixed ffbf54a | Task 6 |
| PERF-1…4 | Performance | Indexes, dashboards, N+1, job failures | Fixed f0a554d, c26295d, 57fe846, 445670d | Task 10 |
| UX-ACC-3 | Payouts | Maker-checker | Needs owner decision | Task 7 |

Not relevant to wholesale and not re-checked: SEC-1, SEC-2, SEC-7, MON-1…MON-7, MON-14, MON-15, AUD-1…AUD-9, ACC-5, ACC-6, ACC-8, ACC-9, ACC-13, ACC-14, ACC-16…ACC-21, UX-5, UX-ACC-1, UX-ACC-2, UX-ACC-6, INV-3, INV-4, INV-9, INV-10, INV-11, PERF-5, M-5, M-6, M-7, I-1, R-6, R-7, R-8.
