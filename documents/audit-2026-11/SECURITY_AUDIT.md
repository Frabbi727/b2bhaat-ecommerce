# Security Audit (2026-11-08)

## Fixed in Wave 0

| ID | Problem | Fix | Commit |
|---|---|---|---|
| SEC-1 | The seller panel's Wallet list had a Create button. Its form let a seller pick any user and type any balance, then withdraw that money through a payout | Create, edit and delete actions removed; `SellerWalletPolicy` (sellers view their own wallet only; writes are super admin only). Stray create or delete actions removed from the seller sub-orders and RFQ pages | e580e46 |
| SEC-2 | The stock-sheet paste box called `file_exists` and `file_get_contents` on pasted text. Pasting `/var/www/.env` read the file and echoed its cells in errors | Pasted text is always parsed as CSV; uploads are read from the upload disk only, max 2 MB | dd3e2bb |
| SEC-7 | Storefront OTP login accepted any account, including super admins and sellers: an SMS code alone was enough. No per-IP limit. Replies revealed which phones have accounts | OTP login is for customers only. Per-IP hourly and per-phone daily caps (`SMS_OTP_MAX_PER_IP_PER_HOUR`, `SMS_OTP_MAX_PER_PHONE_PER_DAY`, default 10). Same reply for known and unknown phones | 8d173bb |
| SEC-3 | Plan upgrade accepted any plan id, including retired plans | Only active plans are accepted | d8460de |
| SEC-4 | Wallet invoice payment had no lock or status check, so it could be paid twice | Invoice and wallet are row-locked; the invoice must be unpaid | d8460de |

## Open (Wave 1+)

| ID | Finding | Plan |
|---|---|---|
| SEC-5 / PAY-3 | A bKash/Nagad TrxID can be reused on several orders | Unique index per gateway |
| SEC-6 | Order tracking and OTP Livewire actions are not throttled | `RateLimiter` inside the actions |
| SEC-8 | Tracking tokens never expire | Expiry or rotation |
| SEC-9 | Sellers can see other sellers' lines on a shared invoice | Scope the invoice view |
| SEC-10 | Sensitive fields are mass-assignable | Tighten `$fillable` |
| SEC-11 | Courier webhook replay | Timestamp and nonce check |
| AUD-2 | Audit rows can be edited or deleted | Model guard plus DB trigger |
| AUD-7 | Tracking tokens and TrxIDs are not redacted in audit diffs | Extend `AuditRedactor` |

## Notes

- Panel access, the SubOrder policy and seller scoping in Filament resources were reviewed and are sound, apart from the items above.
- No credentials were read or exposed during the audit. `.env` was not opened.
