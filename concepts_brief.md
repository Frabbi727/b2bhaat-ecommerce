# Concept Brief: B2Bhaat Whole-Platform Health Check

**Author:** Rabbi
**Date:** 2026-11-17
**Mode:** Brainstorm (7 review lenses)

| Lens | Status | Note |
|---|---|---|
| egm-design | completed | Partial: it did not read CARRY_OVER, F, H or E |
| egm-codebase-details | completed | |
| egm-zoom-out | completed | |
| egm-comprehension | completed | Storefront views only. It did not review the admin or seller panels. |
| egm-implementation-readiness | completed | |
| egm-structural | completed | |
| egm-inventive-catalyst | completed | |

All findings below come from reading code. Nothing was run against production. Speed items are **inferred from code**, not measured. The synthesizer re-read the key citations marked "(verified)".

---

## Overview

The plan is to check the parts of B2Bhaat that the wholesale audit (`documents/audit-2026-11-wholesale/`) did not cover, and turn the results into one ranked list: **before launch / first month / later**. The four areas are:

1. Retail shopping, SMS login, seller sign-up, and the invoice and tracking links.
2. Speed on the cPanel host.
3. Weak spots in the code structure.
4. Phone friction for each of the four user types.

All seven lenses agreed on three points:

1. **The owner's rule decides everything.** The audit README says "Do not deploy until every audit issue is fixed" (`documents/audit-2026-11-wholesale/README.md:49`). Read literally, every new finding, including small polish items, delays launch. A "first month" bucket has no meaning until the owner changes the rule to something like "no deploy while a money, order or login blocker is open".
2. **This should not be a separate document.** The wholesale audit's plan already covers phone walk-throughs of the storefront and seller panel, part G (architecture and performance) and part I (the roadmap). A second roadmap would compete with `I_ROADMAP.md`. The better route is one narrow extra pass whose findings go into the existing `ISSUE_REGISTER.md`.
3. **The extra pass still found real launch blockers.** It found blockers outside wholesale: SMS login depends on a background worker the host may not run, an unverified phone number can capture another person's orders, a mail password is committed in the README, and wholesale prices leak on the wishlist page.

---

## Analysis

### 1. Launch blockers found outside the wholesale audit

| # | What is wrong (plain words) | Evidence | Why it matters |
|---|---|---|---|
| B1 | **SMS login codes are only sent if a background worker is running.** The code goes into a database queue. Nothing in the schedule starts the worker, and on cPanel nothing keeps it alive. Login codes also wait in the same line as audit-log jobs. A failed send retries after 10, 60 and 180 seconds, but a code is valid for only 5 minutes. | `app/Services/Sms/OtpService.php:101`; `config/queue.php:16,42`; `routes/console.php:12-39` (no worker); `AuditService.php:115`; `SendSmsJob.php:29`; `config/sms.php:24` | If the worker is not running, no shopper can log in, register or check out, and no error is shown. |
| B2 | **Someone can claim a phone number they don't own and receive that person's future orders.** The public mobile API creates a shopper account with any phone number, no SMS check, and a password the attacker chooses. When the real owner later checks out by SMS code, checkout attaches the order to that existing account. The attacker can then sign in and see the address or cancel the order. | `app/Http/Controllers/Api/V1/AuthController.php:21-41` (verified), routes at `routes/api.php:13-15` (verified); `app/Actions/Checkout/ResolveCheckoutCustomer.php:50-61` (verified, reuses the account and marks the phone verified) | Leaks private data and lets someone else cancel the order. The same gap exists in `registerWholesale` (`:55-81`) and `registerSeller`. |
| B3 | **A real mail password is written in the README.** | `README.md:82` (verified; the value is not repeated here) | Anyone with access to the repository can send mail as `noreply@b2bhaat.com`. Change the password and remove it from the file. |
| B4 | **Wholesale prices are shown to users who aren't approved.** The wishlist checks approval in one branch but not in the fallback branch. | `resources/views/livewire/storefront/customer-wishlist.blade.php:117` (checks approval) vs `:127-131` (no check), verified | Breaks owner decision D3. One-line fix. |
| B5 | **Seller sign-up can half-fail and lock the seller out.** It creates the user, the store profile and the wallet without wrapping them in one transaction. If a later step fails, the email is already taken and the seller can't retry. The raw error text, which can include SQL, is shown to the visitor. The phone number is stored as typed and never checked by SMS. | `app/Livewire/Storefront/BecomeSeller.php:49,65-88,90-94,98` (verified); a duplicate path exists at `AuthController.php:110-133` | A lost seller lead, plus internal details shown to the public. A seller registered on a shopper's number blocks that shopper from SMS checkout (`ResolveCheckoutCustomer.php:37-38`). |

**Checked and fine, so no time is needed here:** invoice and tracking tokens are long and random (`Order.php:104-109`), rate-limited (`routes/web.php:44-49`, verified) and expire. OTP codes are stored hashed and rate-limited, and the fallback code works only in local and testing (`OtpService.php:118-127`).

**Open owner questions raised by the lenses (not yet verified by the synthesizer):**
- The invoice is titled "Commercial Tax Invoice" and shows VAT/BIN (`resources/views/storefront/invoice.blade.php:6,56,212`), but VAT is owner-deferred. Is that label legally safe to issue at launch?
- Inventive-catalyst read `business-decisions.md:21` as saying courier booking creates placeholder tracking numbers and only the courier webhook marks a parcel delivered. If so, courier-shipped parcels never complete and the seller is never paid. Does v1 launch with self-delivery only?
- The tracking link alone lets whoever holds it cancel or return the order and see the handover code (`OrderTracking.php:31,40-42,101-102,177`). The link travels in plain SMS. Should cancelling require being signed in?

### 2. Speed on the host (inferred from code, not measured)

**Fact conflict, settle first.** The hosting memory note (2026-11-11) says the limit was raised from 128M to 512M after the "Allowed memory size of 134217728 bytes exhausted" errors. The note also says command-line PHP is 8.4 and the domain may be on PHP 8.2. The project assumes PHP 8.5, and `composer.json` allows `^8.3`. The real limit and PHP version must be confirmed before ranking anything.

Likely memory and speed suspects, in order:
- **Scheduled commands load everything at once.** The escrow-release command and others use unbounded queries, and they grow with every order (`ReleaseDeliveredEscrowCommand.php:49-53`, `MonetizationProcessRenewalsCommand.php:43`).
- **Unbounded page loads.** A seller's wallet statement and CSV export load the whole ledger (`WalletStatementController.php:30-33,63-66`). The product page loads every review on every refresh (`ProductDetail.php:515-525`). The flash sale page has no limit (`FlashSaleShow.php:33-36`).
- **The home page repeats lookups.** It runs about 10 uncached queries on every filter change (`ProductCatalog.php:365-404`). Search uses a leading-wildcard LIKE, and the header search fires every 250 ms while typing (`HeaderSearch.php:60-69`).
- **The database does everything.** Sessions, cache, rate limits and the queue all use it (`.env.example:39,50,52`), so every page writes to the database.
- **Images are served full size.** No resizing library is installed. Product images can be up to 2 MB (`ProductForm.php:275`) and banners up to 4 MB (`BannerForm.php:189`).
- **The deploy builds on the live host.** It runs `npm ci && npm run build` on the server with no maintenance mode and no rollback (`README.md:64-75`, verified). It can fail partway and leave a half-deployed site.

**Cheapest first step (all lenses):** read the November `storage/logs/laravel-*.log` and `~/logs` lines that contain "Allowed memory size". They name the page and the code line. Then log peak memory per route for a week. That replaces guessing with data.

### 3. Code structure (keep it only where it causes money bugs)

- **Order placement lives in a screen component, not a service.** `CheckoutComponent.php:425-485` handles payment, escrow hold, journals and coupons. The total formula `subtotal + shipping - discount` is written four times: `CheckoutComponent.php:335,629`, `CartComponent.php:131` and `OrderSplitterService.php:147`. Several existing register rows (WA-CHK-01/02/03, WA-MNY-03, WA-PRC-02) come from here. Fix them together by moving this into one `PlaceOrderService`. That is a fix, not a separate refactor.
- **Quotes (RFQ) have no service.** The logic is spread over `RfqRequestsTable.php:130-139,196-200`, `QuotationController.php:86-104` and `RfqRequestPolicy.php:16,35`. There is also a new possible risk: a seller can re-quote an RFQ that is already quoted, and when the buyer accepts, the current database price is used. So a buyer can accept a price they never saw (not reproduced). This belongs with WA-PRC-01/03/04.
- **The shop price is worked out in five places with different rules.** `Product.php:257-263,350-356`, `FlashSaleResource.php:400-420`, `ProductsRelationManager.php:224-232` and `ProductCatalog.php:554-567`. Checkout charges the right amount, but the previews sellers and admins see can be wrong. The catalog price filter uses list price or wholesale tiers (`ProductCatalog.php:480-500`), so retail results don't match the prices shown.
- **Line totals are calculated as decimals in the views.** `cart-component.blade.php:68` and `checkout-component.blade.php:293` compute them directly instead of through `Money::lineTotal`, the cart's own rule.
- **Large files** (CheckoutComponent 656 lines, ProductCatalog 569, ProductDetail 583, the seller ProductForm 706) are not a reason to delay launch. The large services are cohesive. The large screen components are not.

### 4. Phone friction by user type

- **Retail customer and wholesale buyer:**
  - The phone and code fields open the letter keyboard and won't auto-fill the SMS code (`customer-auth.blade.php:152-158,186-193,336-342`). Checkout already does this correctly (`checkout-component.blade.php:388-390`).
  - "Resend code" has no countdown, so users tap it repeatedly and every tap is a paid SMS (`:218-224,367-373`).
  - Registration requires an email, which many phone-only users don't have (`:263-273`).
  - A "Super Admin" login link appears on the customer page (`:401`).
  - Guests in the cart or wishlist are sent to the **seller** login (`CartComponent.php:59`, `CustomerWishlist.php:24`).
  - Cancelling from a tracking link works, but the screen then shows an error, so people retry or call support (`OrderTracking.php:112-113,157,205,229,327`).
  - Tracking text is mostly 10-12 px.
  - The registration step doesn't re-check email or password on the final submit, so a duplicate email causes a 500 error (`CustomerAuth.php:46-54,350-400`).
- **Factory seller:**
  - The sign-up button has no loading state, so a double tap can create two applications (`become-seller.blade.php:192-200`).
  - The button promises "Open Store", but the store only opens after approval (`:199`).
  - The text uses jargon: "Super Admin KYC verification", TIN, escrow (`:13,21,61`).
  - No trade licence document is uploaded. KYC is a free-text number only (`BecomeSeller.php:51-52`).
- **Super admin:** the earlier decision stands that admin is a desktop office tool (`F_UI_UX.md:49`). Not reviewed for phones.
- **All users:** there is no Bangla anywhere. No `lang/` folder exists and all text is hard-coded in English.

---

## Goals vs. Non-Goals

**Goals**
- A short before-launch list that protects money, orders, private data and login. Each item should be something the owner can check is done.
- Use the existing register and roadmap. New rows get new area codes (for example WA-OTP, WA-ONB, WA-RTL, WA-PRF, WA-UX) so probe-test names stay valid.
- Get speed advice from the production log first, and only then from code reading.
- Write in plain words for the owner: what's wrong, what it costs (taka, orders, sellers), who does it, rough days, and "done when…". Code paths go in a developer appendix.

**Non-goals**
- A separate code-structure clean-up before launch. Structure work happens only as the way to fix a register row.
- Phone layout work for the admin panel.
- Redis, Horizon or any change of host before the logs show it is needed.
- VAT/tax, choosing a payment gateway, the live courier API (owner-deferred), and anything already fixed in `CARRY_OVER.md`.
- A full Bangla translation before launch (later item).

---

## Alternatives Weighed

| Option | For | Against | Verdict |
|---|---|---|---|
| A. New standalone whole-platform report (the original idea) | One fresh view of everything | Duplicates audit tasks 8-10 and 12. Two roadmaps. Every new row falls under "fix everything before deploy" | Rejected |
| B. One narrow extra pass inside the existing audit (OTP and the queue on the host, seller sign-up, retail-only paths, the 500 log), then write parts A, G and I | Reuses the register and probe tests. One roadmap. Small | Needs the owner to restate the deploy rule | **Recommended** |
| C. Don't look further; just fix the current register | Fastest route to the existing gate | Misses B1-B5, which are first-day blockers | Rejected, but its warning is kept: fix before you search more |
| D. Staged launch with on/off switches (for example RFQ off, courier off and self-delivery only) | Lets money-safe parts go live while risky ones wait. The settings service can hold the switches (`StoreConfigurationService.php:258` already holds one) | Each switch needs tests both on and off. A switch left off for months becomes dead code | Use only for RFQ and courier if the owner agrees |
| E. Pre-launch refactor to a single price service | Removes duplication | Risks new money bugs right before launch. Tier lookup is already central (`CartSessionService::resolveTierRecord`) | Rejected for pre-launch. Guard the existing choke points instead |

**Strongest counter-argument (several lenses):** two audits have already run, and the money core is hardened. The fastest safe launch comes from fixing the three open Criticals, not from looking for more. The answer is that B1-B5 sit on the first-day path (login, sign-up, privacy), so a narrow pass is worth it, but only after the deploy rule is restated. On the other side, posted journals can't be changed, so "fix everything first" is reasonable for anything that moves money. The gate must therefore treat every money row as a blocker.

**Inventive resolution:** separate the two by condition. Findings keep flowing into the register, but only rows tagged **Blocks launch = yes** gate the deploy. Also rank the roadmap by **fix unit**, not by issue. One `PlaceOrderService` item closes about six IDs and one RFQ item closes about four, so the roadmap gets shorter as the register grows.

---

## Key Decisions & Staging for Next Steps

### Decisions the owner must make first
1. **Restate the deploy rule** (`README.md:49`). Proposed wording: "No deploy while any Critical row, any High row in money, order or stock, or any login or privacy blocker is open. All other rows have a date in the first month." Add this as OWNER_QUESTIONS #6.
2. **Courier and RFQ at launch.** Launch with self-delivery only and with RFQ switched off, or fix both first?
3. **Invoice label.** Use "Invoice" instead of "Commercial Tax Invoice" until VAT is decided?
4. **Is the mobile API used yet?** If not, close the public `/api/v1/auth/register/*` routes for launch.

### Draft ranked roadmap

**Before launch** (money, order, login and privacy only, about 10 items)

| Rank | Fix | Closes | Done when |
|---|---|---|---|
| 1 | Change the mail password and remove it from the README | B3 | Old password rejected; README has no secret |
| 2 | Checkout fix unit: quantity must be ≥ 1, coupon read only from the session, block double submit, re-check wholesale approval at order time | WA-CHK-02, WA-CHK-01, WA-CHK-03, WA-PRC-02, WA-MNY-03 | Probe tests inverted and passing; the existing-order search finds no rows with quantity ≤ 0 |
| 3 | RFQ fix unit (or switch RFQ off) | WA-PRC-01, -03, -04, plus the re-quote price risk | Probe tests inverted, or RFQ hidden |
| 4 | Phone ownership: no account owns a phone until an SMS code proves it; close or fix the API register routes; store phones in one normal form | B2, part of B5 | A test shows an unverified account doesn't receive a guest's order |
| 5 | SMS on the host: cron runs `schedule:run` and a short-lived `queue:work --stop-when-empty`; login codes on their own queue (or sent immediately) | B1 | A test login code arrives within 30 s on production; `sms_logs` sent minus created is under 1 min |
| 6 | Wishlist wholesale-price check | B4 | Unapproved buyer sees no wholesale price |
| 7 | Seller sign-up in one transaction; friendly error text; button disabled while sending | B5 | A forced failure leaves no orphan user; double tap creates one application |
| 8 | Seller and admin can cancel an order | WA-ORD-01 | A seller can decline a COD parcel and the buyer gets an SMS |
| 9 | Confirm the live server facts: memory limit, PHP version, opcache, cron, rows in `jobs`/`failed_jobs`, the November memory-error log lines | Speed ranking input | A one-page checklist is filled in by the owner |
| 10 | Safer deploy: build the front end before upload, maintenance mode on and off, note the previous commit for rollback | Deploy risk | A deploy runs without `npm` on the host |

**First month**
- The remaining Medium and Low register rows: WA-MNY-02, WA-ORD-02/03, WA-ADM-01..06, WA-SEC-01, WA-PRC-06. WA-ADM-03 (time zone on invoices) goes first.
- The memory suspects confirmed by the log: limit the scheduled commands' queries, wallet statement and CSV export, product reviews, flash sale page; cache the home-page lookups.
- Logging: a request ID, peak memory per route, and a `/up` check that shows queue age, failed jobs and SMS balance.
- Phone fixes: numeric keypad and SMS auto-fill, resend countdown, guest redirect to the customer login (not the seller login), correct screen after cancelling from a tracking link, re-check of registration fields, remove the "Super Admin" link, make email optional.
- Line totals in the cart and checkout views go through `Money::lineTotal`; the catalog price filter uses the price shown.
- Clean, plain-language seller sign-up text; a trade licence upload for KYC.

**Later**
- Bangla text across the storefront and seller panel.
- A single price-display helper to replace the five copies; fix the flash-sale preview math.
- Image resizing on upload.
- Write audit records directly instead of through the queue.
- Monetization grace extension routed through its service (`SellerMonetizationResource.php:297-305`).
- Split the large screen components.
- Clean up old `otps` and `sms_logs` rows.
- Align the `composer.json` PHP version with the host.
- Admin approval by a second person (maker-checker) for payouts.
- Partial shipments (WA-ORD-04).
- Phone layout for the admin panel.
