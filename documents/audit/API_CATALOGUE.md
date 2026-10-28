# B2B Haat — API Catalogue

> Part of the B2B Haat discovery audit (read-only, 2026-10-28). Evidence labels: **[F]** fact · **[I]** inference · **[A]** unverified assumption · **[Q]** stakeholder decision. Cross-referenced IDs (FR-, BRL-, D-, L-, R-, W-) live in the sibling files of `documents/audit/`.

## J. API & Integration Catalogue

| Method & path | Auth | Purpose | Status / issues |
|---|---|---|---|
| POST `/api/v1/auth/register/retail` | none | Register retail | OK. No throttle |
| POST `/api/v1/auth/register/wholesale` | none | Register B2B | Business fields dropped; immediate role grant |
| POST `/api/v1/auth/register/seller` | none | Register seller and profile | Not in a DB transaction (partial records on failure) |
| POST `/api/v1/auth/login` | none | Get token | No `is_active` check, no throttle, tokens never expire, no logout |
| GET `/api/v1/catalog/products` | none | List (15/page) | `ilike` is PG-only; `$request->user()` is always null for bearer tokens because no `auth:sanctum` is set, so **wholesale users can never see wholesale prices via API** (the test bypasses HTTP: `ChannelVisibilityTest` uses `setUserResolver`) |
| GET `/api/v1/catalog/products/{slug}` | none | Detail | Shows **inactive** products |
| GET `/api/v1/orders/track` | none | Public tracking | Phone optional; partial match; no throttle |
| POST `/api/v1/webhooks/courier` | **none** | Courier status ingestion | No signature, IP allow-list or idempotency; any caller can set `delivered` |
| GET `/up` | none | Health | Default |

**Documented in the tracker but missing:**
- `POST /seller/products`
- `POST /cart/items`
- `POST /checkout/pay` and the payment callback
- `POST /seller/sub-orders/{id}/fulfill`
- `POST /seller/payouts`

There are no API Resources except `ProductResource`. Other endpoints build arrays by hand, so response envelopes are inconsistent (`{data}`, `{success,message}`, `{message,user,token}`).

**Integrations:**
- bKash, SSLCommerz and Nagad appear only as labels. They are **not integrated**.
- Courier companies appear only as a dropdown list.
- No SMS or email provider is set up (mail = log).
