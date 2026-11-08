# UI / UX Audit (2026-11-08)

Wave 0 is about money and security, so the UI changes it made are small.

## UI changes made in Wave 0

- **Seller billing.**
  - An unavailable plan says "This plan is not available any more".
  - A second click on "Pay with wallet" says the bill is already paid instead of charging again.
  - A low balance shows both amounts in ৳.
  - The upgrade notice says the current plan stays as it is until the bill is paid.
- **Products.**
  - Showing products over the listing limit, or while suspended, gets a plain refusal.
  - Imports above the limit are saved hidden, and the import summary lists them.
- **Storefront OTP.** The same neutral message appears whether or not a phone has an account, with a link to sign up.
- **Admin, seller billing.**
  - "Extend Grace Period" only appears for sellers who are in grace.
  - "Record Offline Payment" warns when there is no unpaid bill.
- **Seller wallet.** Read-only, with no create or edit buttons.

## Open findings (UI consistency pass, Waves 2–3)

| ID | Finding | Plan |
|---|---|---|
| UX-1 | Currency formats mix `৳`, `BDT` and `Tk` | One money formatter everywhere |
| UX-2 | Technical words in seller-facing labels: escrow, sub-order, idempotency, journal | Plain wording ("money held until delivery", "your part of the order") |
| UX-3 | Navigation groups are inconsistent between the panels | Regroup by task |
| UX-4 | Destructive actions without confirmation | Add confirmations |
| UX-5 (P1) | Sellers see a fallback commission rate, not their real one | Show the effective rate, limits, usage and next bill |
| UX-6 | Some seller tables overflow on mobile | Responsive columns |
| UX-7 | MOQ is shown only at checkout | Show it on the product page and in the cart |
| UX-ACC-1 (P1) | No manual journal form | Balanced form with confirmation |
| UX-ACC-2 to UX-ACC-5 | Unsafe reverse button, no maker-checker, inconsistent journal display | See register |
| PERF-5 | Dates shown in UTC because `APP_TIMEZONE` is unset | Set Asia/Dhaka (needs a data check first) |
