# B2Bhaat — Security & Role Audit

- **Date:** 2026-11-05
- **Branch:** `audit/production-readiness`
- **Audited commit:** `fadb690`
- **Spec:** [`docs/superpowers/specs/2026-11-05-production-readiness-reaudit-design.md`](../superpowers/specs/2026-11-05-production-readiness-reaudit-design.md)
- **Evidence labels:** see [Evidence legend](PRODUCTION_READINESS_AUDIT.md#evidence-legend)


## Summary

| Area | Verdict | Register |
|---|---|---|
| Panel access (admin / seller) | **Working**: `User::canAccessPanel` requires `is_active` + `super_admin` for `/admin`, and `seller` + an approved profile for `/seller` [T-probe C.3, C.4] | — |
| Seller-to-seller isolation | **Working** for view/edit URLs and the print controllers: every seller resource scopes `getEloquentQuery()` by `seller_id` [T-probe C.2] | — |
| Seller powers on their own records | **Broken**: sub-order financials editable, deletable, mark-delivered | PRA-004, PRA-005, PRA-012 |
| Authentication | **Broken**: the fallback OTP `123456` logs in as any OTP-registered user in production [T-probe C.1] | **PRA-035** |
| Wholesale authorization | **Broken**: self-approval on web and API [T-probe C.7] | PRA-008 |
| Payments | **Broken**: unverified "paid" | PRA-006 |
| Webhooks | **Broken**: unsigned | PRA-003 |
| Personal data | **Partial**: token invoice/tracking OK; order-number invoice open to guests; guest accounts guessable | PRA-009, PRA-007 |
| Rate limiting | **Missing** except storefront password login (`CustomerAuth::login`, 5/min) and OTP send/verify (`OtpService`) [T-probe C.5, C.6] | **PRA-036** |
| Policies | **Missing**: `app/Policies` empty; authorization relies on panel gating + query scoping | **PRA-043** |
| Error handling | **Partial**: 11 places show `$e->getMessage()` to users | **PRA-038** |
| Uploads | **OK** for the one upload: stock CSV `file|mimes:csv,txt` (`ProductStockBulkController.php:52`). There is no image or trade-licence file upload anywhere (R-22) | — |
| Headers | **Partial**: `SecurityHeadersMiddleware` (global, `bootstrap/app.php:18`) sets X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy; no HSTS or CSP | **PRA-040** |
| Dependencies | `composer audit`: **1 medium** (`symfony/mime` CVE-2026-45070, email header injection). `npm audit --omit=dev`: **0** [C] | **PRA-039** |
| Demo switcher | **Fixed** (R-01) [T] | — |
| `.env.example` | Development defaults: `APP_DEBUG=true`, `LOG_LEVEL=debug`, `DB_CONNECTION=sqlite`, `SESSION_ENCRYPT=false` | PRA-041 (Phase C) |

## Role × capability matrix

Allowed / Denied / **LEAK** (allowed but shouldn't be). Evidence in brackets.

| Capability | super_admin | seller (own) | seller (other's) | wholesale (approved) | wholesale (self-applied) | customer | guest |
|---|---|---|---|---|---|---|---|
| Open `/admin/*` | Allowed | Denied [C.3] | — | Denied | Denied | Denied | redirect to login |
| Open `/seller/*` | Denied by design (not seller) | Allowed | — | Denied | Denied | Denied [C.4] | redirect |
| View/edit sub-order | Allowed (admin orders) | Allowed | Denied 404 [C.2] | — | — | — | — |
| Change sub-order money fields / delete | Allowed | **LEAK** [A.7] | Denied | — | — | — | — |
| Mark courier shipment delivered | — | **LEAK** [A.5] | Denied | — | — | — | **LEAK** via webhook [A.4] |
| Print label / challan | Allowed | Allowed | Denied 403 [C.2] | — | — | — | 401 |
| Edit product | — | Allowed | Denied 404 [C.2] | — | — | — | — |
| Wholesale prices & bulk matrix | Allowed | — | — | Allowed | **LEAK** [A.10] | Denied [T `ChannelVisibilityTest`] | Denied |
| Invoice by order number | Allowed | Allowed (assigned) | Denied 403 [T `ProductionReadinessAndSecurityAuditTest::test_invoice_idor_prevention_across_unrelated_accounts`] | own | own | own | **LEAK** [A.12] |
| Log in as another user | — | — | — | — | — | — | **LEAK**: OTP `123456` [C.1] |
| Accept RFQ quotation | Allowed | — | — | buyer only (`QuotationController.php:69`) [R] | — | — | 401 |
| Request payout | — | Allowed (own wallet) | Denied (wallet scoped) [R] | — | — | — | — |

## Findings detail

- **OTP fallback (PRA-035):** `OtpService::verify` (`app/Services/Sms/OtpService.php:97`) accepts `SmsSetting default_otp`, falling back to `config('sms.default_otp')`, which falls back to `env('SMS_DEFAULT_OTP','123456')`. This happens in every environment, without any OTP having been sent. `CustomerAuth::verifyLoginOtp` can be called directly with any phone. Under `APP_ENV=production` the probe logged in as another seller [T-probe C.1]. Admin-created and seeded users are stored as `+880…` while the normalizer produces `880…`, so they are accidentally safe and also **can't use OTP login** (PRA-037).
- **Rate limiting (PRA-036):** 12 bad API logins → still 422 (no 429) [C.5]; 70 tracking lookups → still 404 [C.6]. No `throttle` middleware is registered on any route.
- **API registration:** the seller API correctly leaves the seller unapproved [T-probe C.8 PASS]. The wholesale API grants the role at once [C.7 FAIL] (PRA-008).
- **Sanctum tokens:** `config/sanctum.php` `expiration` = null (tokens never expire), and no API route uses `auth:sanctum` (R-13). **Pending owner decision** (Q16, whether an API client is planned).

Probe source: [Appendix C](PRODUCTION_READINESS_AUDIT.md#appendix-c-security-probes).
