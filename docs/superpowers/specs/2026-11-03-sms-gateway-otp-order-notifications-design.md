# System Design: BulkSMSBD Gateway, OTP Verification & Order Notifications

**Project:** B2Bhaat E-commerce  
**Framework:** Laravel 12 (PHP 8.5)  
**Date:** 2026-11-03  
**Status:** Approved for Implementation Planning  

---

## 1. System Overview & Objectives
This specification outlines the end-to-end design for integrating the **BulkSMSBD** gateway into the B2Bhaat e-commerce platform. It provides:
1. A modular, driver-based SMS infrastructure with queue processing, logging, and retry logic.
2. A hardened OTP verification system for customer registration, mobile number verification, "Login with OTP", and password resets.
3. Automated transactional SMS notifications for order confirmations and order status progression.
4. Token-protected, mobile-responsive order voucher/invoice and tracking URLs without forcing guest customer authentication.
5. A full Filament Admin management interface for SMS settings, live BulkSMSBD account balance queries, customizable templates, and delivery audit logs with manual retry.

---

## 2. BulkSMSBD Provider Integration

### 2.1 Provider Details
- **Provider Name:** BulkSMSBD (`bulksmsbd.net`)
- **API Endpoints:**
  - **Single & Multiple SMS:** `POST http://bulksmsbd.net/api/smsapi`
  - **Many-to-Many SMS:** `POST http://bulksmsbd.net/api/smsapimany`
  - **Balance Inquiry:** `GET http://bulksmsbd.net/api/getBalanceApi?api_key={api_key}`
- **Authentication Parameters:**
  - `api_key`: Secret API Key stored in `.env` (`BULKSMSBD_API_KEY`) with admin override.
  - `senderid`: Approved Sender ID stored in `.env` (`BULKSMSBD_SENDER_ID`) with admin override.
  - `type`: `text`
- **Phone Number Normalization:**
  - Validates and standardizes Bangladeshi mobile numbers into the standard `8801[3-9]XXXXXXXX` 13-digit format.
  - Strips leading `+`, spaces, dashes, and prepends `88` if given in `01XXXXXXXXX` format.

### 2.2 Response Code Handling
| Response Code | Meaning | Action Taken |
|---|---|---|
| `202` | SMS Submitted Successfully | Mark log `sent`, record provider message ID. |
| `1001` | Invalid Number | Mark log `failed`, do not retry (permanent client error). |
| `1002` | Sender ID disabled/incorrect | Mark log `failed`, alert admin in logs. |
| `1006` / `1007` | Balance Validity Expired / Balance Insufficient | Mark log `failed`, log critical system warning, notify admin. |
| `1011` - `1021` | Account/Gateway Routing Error | Mark log `failed`, log error. |
| `1031` / `1032` | Account unverified / IP not whitelisted | Mark log `failed`, log IP configuration warning. |
| Network Timeout | cURL / HTTP client timeout | Queue worker retries up to 3 times with exponential backoff. |

---

## 3. Architecture & Core Components

### 3.1 Driver-Based SMS Subsystem
Located under `App\Services\Sms\`:
- **`Contracts\SmsGatewayInterface`**:
  ```php
  namespace App\Services\Sms\Contracts;

  use App\Services\Sms\DTOs\SmsResponse;

  interface SmsGatewayInterface
  {
      public function send(string $to, string $message, ?string $purpose = null): SmsResponse;
      public function sendBulk(array $recipients, string $message): array;
      public function checkBalance(): float|string;
  }
  ```
- **`Drivers\BulkSmsBdDriver`**: Connects via Laravel `Http::timeout(10)->asForm()->post(...)` with SSL peer verification and error mapping.
- **`Drivers\LogDriver`**: Writes outgoing SMS to `storage/logs/laravel.log` for development.
- **`Drivers\MockDriver`**: In-memory test driver with assertions (`assertSentTo`, `assertCount`, etc.) for PHPUnit tests.
- **`SmsManager`**: Extends Laravel's `Illuminate\Support\Manager` to dynamically instantiate and switch drivers (`bulksmsbd`, `log`, `mock`).
- **`DTOs\SmsResponse`**: Immutable value object containing `isSuccessful()`, `responseCode()`, `messageId()`, `errorMessage()`, and `rawPayload()`.

### 3.2 Asynchronous Queuing & Deduplication
- **`App\Jobs\SendSmsJob`**:
  - Implements `ShouldQueue`, `SerializesModels`.
  - Queue: `sms` (falls back to default `database` or `redis` queue).
  - Tries: 3 with backoff: `[10, 60, 180]`.
  - **Deduplication Guard:** Uses cache lock `sms_lock:{phone}:{md5(message)}` with a 60-second TTL to prevent double submission from rapid user clicks.
  - **Master Switch Guard:** If SMS is disabled in settings, job safely records `status = 'skipped'` in `sms_logs` and exits without error.

---

## 4. Database Schema & Models

### 4.1 New Tables

#### `otps` Table
| Column | Type | Modifiers | Description |
|---|---|---|---|
| `id` | bigint | unsigned, auto_increment | Primary Key |
| `phone` | varchar(32) | index | Normalized phone number (`8801XXXXXXXXX`) |
| `code_hash` | varchar(64) | index | SHA-256 hash of the generated OTP code |
| `purpose` | varchar(32) | index | `registration`, `login`, `password_reset`, `verification` |
| `attempts` | smallint | default 0 | Failed verification attempt counter |
| `max_attempts` | smallint | default 5 | Allowed attempts before invalidation |
| `expires_at` | timestamp | index | Timestamp when OTP expires (e.g. now + 5 min) |
| `verified_at` | timestamp | nullable | Timestamp when OTP was successfully consumed |
| `ip_address` | varchar(45) | nullable | Client IP address for rate limiting |
| `user_agent` | text | nullable | Client user agent |
| `created_at` | timestamp | | Creation timestamp |
| `updated_at` | timestamp | | Update timestamp |

#### `sms_logs` Table
| Column | Type | Modifiers | Description |
|---|---|---|---|
| `id` | bigint | unsigned, auto_increment | Primary Key |
| `recipient_phone` | varchar(32) | index | Normalized recipient phone number |
| `message` | text | | Masked SMS message (OTPs masked as `******`) |
| `purpose` | varchar(64) | index | Categorical purpose (`otp`, `order_confirmation`, etc.) |
| `driver` | varchar(32) | | Active driver (`bulksmsbd`, `log`, `mock`) |
| `status` | varchar(20) | index | `pending`, `sent`, `failed`, `skipped` |
| `provider_message_id` | varchar(128) | nullable | BulkSMSBD response ID |
| `provider_response_code` | varchar(32) | nullable | BulkSMSBD raw code (`202`, `1001`, etc.) |
| `response_payload` | json | nullable | Raw JSON response from provider |
| `error_message` | text | nullable | Readable error description |
| `retries_count` | smallint | default 0 | Retries attempted |
| `sent_at` | timestamp | nullable | Dispatch timestamp |
| `created_at` | timestamp | index | Log creation timestamp |

#### `sms_templates` Table
| Column | Type | Modifiers | Description |
|---|---|---|---|
| `id` | bigint | unsigned, auto_increment | Primary Key |
| `key` | varchar(64) | unique | `otp_registration`, `otp_login`, `order_confirmation`, etc. |
| `name` | varchar(128) | | Human-readable name |
| `content_en` | text | | English template with `{placeholders}` |
| `content_bn` | text | | Bengali template with `{placeholders}` |
| `placeholders` | json | | Array of supported placeholder keys |
| `is_active` | boolean | default true | Enable/disable this template |
| `created_at`, `updated_at` | timestamp | | Timestamps |

#### `sms_settings` Table
Key-value store for dynamic SMS settings:
- `sms_enabled`: Boolean (`true` / `false`)
- `sms_driver`: String (`bulksmsbd`, `log`, `mock`)
- `bulksmsbd_api_key`: String (encrypted)
- `bulksmsbd_sender_id`: String
- `bulksmsbd_base_url`: String
- `otp_expiry_minutes`: Integer (default `5`)
- `otp_length`: Integer (default `6`)
- `otp_cooldown_seconds`: Integer (default `60`)
- `otp_max_attempts`: Integer (default `5`)

### 4.2 Table Modifications
- **`orders` Table**:
  - Add `invoice_token` (`varchar(64)`, unique, indexed)
  - Add `tracking_token` (`varchar(64)`, unique, indexed)
  - Add `sms_confirmation_sent_at` (`timestamp`, nullable)
- **`users` Table**:
  - Add `phone_verified_at` (`timestamp`, nullable)

---

## 5. OTP Verification System

### 5.1 OTP Generation & Verification Logic (`App\Services\Sms\OtpService`)
1. **Generation:**
   - Generates cryptographically secure random numeric digits (length configurable, default 6 digits).
   - Generates SHA-256 hash `hash('sha256', $code)`.
   - Expires any prior non-verified OTP for the given phone + purpose.
   - Enforces 60-second cooldown period before issuing another OTP.
   - Saves to `otps` table.
   - Dispatches `SendSmsJob` with rendered template (e.g. `B2Bhaat OTP is 123456. Valid for 5 minutes.`).
2. **Verification:**
   - Looks up unexpired, unverified record matching `phone` and `purpose`.
   - Compares SHA-256 hash of submitted code with stored `code_hash`.
   - Increments `attempts`. If attempts exceed `max_attempts` (5), the OTP is immediately invalidated.
   - On match, sets `verified_at = now()`.
   - Returns boolean success with descriptive error codes if failed (`EXPIRED`, `INVALID`, `MAX_ATTEMPTS_EXCEEDED`, `NOT_FOUND`).

### 5.2 Storefront Customer Authentication Flows (`CustomerAuth.php`)
- **Customer Registration:**
  1. User fills register form (Name, Phone, Email, Password).
  2. Submits -> system validates inputs, generates pending registration token in session, dispatches registration OTP via SMS.
  3. UI transitions to OTP verification step with 60s cooldown timer.
  4. User enters 6-digit OTP -> system verifies OTP.
  5. User record created in `users` with `phone_verified_at = now()`, automatically logged in, redirected.
- **Login with OTP:**
  1. On login tab, user clicks "Login with OTP".
  2. Enters mobile number.
  3. System confirms user exists with that phone number, generates and sends login OTP.
  4. User enters OTP -> verified -> session authenticated via `Auth::login($user)`.
- **Password Reset:**
  1. User enters mobile number on "Forgot Password" screen.
  2. Receives and verifies OTP.
  3. Enters new password. Password updated and authenticated.

---

## 6. Order Confirmation, Secure Invoice & Tracking

### 6.1 Order Confirmation SMS
- **Event:** `OrderPlaced` event dispatched in checkout completion.
- **Listener:** `SendOrderConfirmationSmsListener`.
- **Deduplication:** Ensures `order.sms_confirmation_sent_at` is null before dispatching. Sets timestamp upon queue dispatch.
- **Message Content:**
  > *"Dear {customer_name}, your order #{order_number} has been placed successfully. Total: BDT {order_total}. View invoice: {invoice_url}. Tracking: {tracking_url}. Thank you for shopping with B2Bhaat."*

### 6.2 Tokenized Order Invoice / Voucher (`/invoice/{invoice_token}`)
- When an order is created, an `invoice_token` (e.g. `Str::random(40)`) is generated.
- Route: `Route::get('/invoice/{token}', [InvoiceController::class, 'showByToken'])->name('order.invoice.token');`
- Security:
  - Accessible without login via the unique token.
  - Rate-limited to prevent brute-force token enumeration.
  - Does not expose payment credentials or unrelated orders.
  - Printable and optimized for mobile screens.
- Filament Admin Order Resource has an action: **"Resend Invoice SMS"**.

### 6.3 Tokenized Order Tracking (`/track/{tracking_token}`)
- Unique `tracking_token` generated on order creation.
- Route: `Route::get('/track/{token}', [OrderTracking::class, 'showByToken'])->name('order.track.token');`
- Status transitions that trigger SMS updates:
  - `Confirmed`
  - `Processing`
  - `Shipped` (includes Courier name & Courier Tracking No.)
  - `Out for Delivery`
  - `Delivered`
  - `Cancelled`
- Only dispatches when the new status is distinct from previous logged status.

---

## 7. Filament Admin SMS Management

### 7.1 SMS Configuration Page (`/admin/sms-settings`)
- Driver selection (`BulkSMSBD`, `Log`, `Mock`).
- API Key, Sender ID, Base URL with test connection action.
- Live Balance Card: Live query to `checkBalance()` displaying remaining balance in BDT.
- Global SMS master switch.
- OTP parameters (length, expiry, cooldown, max attempts).

### 7.2 SMS Templates Manager
- Pre-seeded templates:
  - `otp_registration`
  - `otp_login`
  - `otp_password_reset`
  - `order_confirmation`
  - `order_status_update`
  - `order_invoice_link`
- Editor supporting placeholder validation and English/Bangla text.

### 7.3 SMS Delivery Logs
- Filament Resource `SmsLogResource`:
  - Columns: Recipient, Purpose, Status Badge (`Sent`, `Failed`, `Pending`, `Skipped`), Provider Code, Sent At.
  - Filters: Date range, Status, Purpose.
  - View modal showing message text (with masked OTP), provider raw response payload.
  - Row Action: **"Retry Send"** to re-dispatch a failed SMS.

---

## 8. Automated Testing Strategy

Automated test suite (`tests/Feature/Sms/...` and `tests/Unit/Sms/...`):
1. **`BulkSmsBdDriverTest`**: Tests HTTP payload formatting, response code mapping (202, 1001, 1007), and exception handling using Laravel `Http::fake()`.
2. **`OtpServiceTest`**: Tests OTP generation, hashing, verification, expiration, cooldown enforcement, and max attempt lockout.
3. **`CustomerAuthOtpTest`**: Tests registration with OTP, Login with OTP, resend cooldown, and rate limiting.
4. **`OrderNotificationSmsTest`**: Tests order confirmation SMS dispatch, duplicate prevention, and status update triggers.
5. **`InvoiceAndTrackingTokenTest`**: Tests token-based invoice view and order tracking access.
6. **`FilamentSmsManagementTest`**: Tests admin permissions, settings update, template editing, and SMS log retry.

All tests utilize `MockDriver` or `Http::fake()` — no real SMS credits will ever be consumed during testing.

---

## 9. Verification & Safety Guards
- **Pint Style Enforcement:** `vendor/bin/pint --dirty --format agent`
- **PHP 8.5 Strict Typing:** `declare(strict_types=1);` on all classes.
- **Sensitive Data Protection:** OTP codes are hashed with SHA-256 in the database; masked in audit logs.
- **Zero Regression:** Existing email, demo personas, seller portals, and checkout operations remain untouched and intact.
