# Wholesale Buyer KYC & Trade License Verification Design Specification

**Feature:** Wholesale Buyer KYC & Trade License Verification Subsystem  
**Scope:** Storefront Application & Status Portal (`/wholesale/upgrade`), Admin Desk Upgrades (`/admin/kyc-approvals`), Document Storage, and Event-Driven Multi-Channel (SMS + Email) Notification Pipeline  
**Target Date:** 2026-11-08  
**Status:** DRAFT (Awaiting User Approval)  

---

## 1. Executive Summary & Business Objectives

In the Bangladeshi wholesale ecosystem, verifying legitimate B2B storekeepers, boutique merchants, and corporate buyers is critical to protecting factory wholesale pricing tiers and preventing retail price erosion.

This feature completes the wholesale onboarding loop:
1. **Document Verification:** Buyers provide business details and upload their Bangladeshi **Trade License** (`e-Trade License` PDF or scan, mandatory), optional **TIN/BIN Certificate**, and optional **Shopfront Photo**.
2. **Persistent Multi-State Storefront Portal (`/wholesale/upgrade`):**
   - **Guest / Unregistered:** Combined account creation and wholesale verification.
   - **Pending:** Status banner showing "Under Review (24-48 hrs)" with preview of submitted credentials.
   - **Approved:** "Verified Wholesale Buyer" badge with direct wholesale catalog access.
   - **Rejected:** Displays the admin's specific rejection reason and an unlocked, pre-filled resubmission form for immediate correction.
3. **Admin Verification Desk Upgrades (`/admin/kyc-approvals`):**
   - Document inspection modal in `ApplicationReviewActions` allowing super admins to view uploaded trade licenses, TIN documents, and shop photos.
4. **Automated Multi-Channel Notifications (SMS + Email):**
   - Instant SMS via `SendSmsJob` on both approval and rejection with reason.
   - Branded email via `WholesaleDecisionMail`.

---

## 2. Technical Architecture & Data Models

### 2.1 Database Schema Extensions

Add the following columns to the `users` table via migration `database/migrations/2026_11_08_200001_add_kyc_document_fields_to_users_table.php`:

| Column | Type | Nullable | Description |
|---|---|---|---|
| `trade_license_document` | `string(255)` | Yes | Stored file path (PDF or image) on `public` disk |
| `tax_id_document` | `string(255)` | Yes | Stored file path (PDF or image) on `public` disk |
| `shop_photo` | `string(255)` | Yes | Stored file path (image) on `public` disk |
| `business_type` | `string(64)` | Yes | Business category: `boutique`, `retailer`, `wholesaler`, `salon`, `corporate`, `other` |
| `business_city` | `string(100)` | Yes | District / city (e.g. Dhaka, Chittagong) |

Ensure clean rollback in `down()` to drop these columns.

### 2.2 Model Upgrades (`app/Models/User.php`)

Add methods and attributes:
- Add new fields to `$fillable`: `'trade_license_document'`, `'tax_id_document'`, `'shop_photo'`, `'business_type'`, `'business_city'`.
- Helper methods:
  - `hasTradeLicenseDocument(): bool`
  - `getTradeLicenseUrl(): ?string`
  - `getTaxIdDocumentUrl(): ?string`
  - `getShopPhotoUrl(): ?string`
  - `isWholesalePending(): bool` (already exists: `!$this->is_wholesale_approved && !$this->wholesale_rejected_at && ($this->trade_license_number || $this->business_name)`)
  - `isWholesaleApproved(): bool`
  - `isWholesaleRejected(): bool`

---

## 3. Storefront Portal (`/wholesale/upgrade`)

Enhance `app/Livewire/Storefront/WholesaleUpgrade.php` using Livewire's `WithFileUploads`:

### 3.1 Properties & State Machine
- State determination in `render()`:
  - If authenticated user is already `is_wholesale_approved`: render **Approved View**.
  - If authenticated user is `isWholesalePending()`: render **Pending Review View**.
  - If authenticated user is `isWholesaleRejected()`: render **Rejected View** with admin reason and editable pre-filled form.
  - If unapplied or guest: render **Application Form**.

### 3.2 Form Validation Rules
- `businessName`: `required|string|min:3|max:255`
- `contactPerson`: `required|string|min:3|max:255`
- `businessType`: `required|string|max:64`
- `city`: `required|string|max:100`
- `tradeLicense`: `required|string|max:128`
- `tradeLicenseFile`: `required_without:existingTradeLicenseDocument|nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:5120` (5MB max)
- `tinNumber`: `nullable|string|max:128`
- `tinDocumentFile`: `nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:5120`
- `shopPhotoFile`: `nullable|image|mimes:jpg,jpeg,png,webp|max:5120`
- Guest fields (if not authenticated):
  - `email`: `required|email|unique:users,email`
  - `phone`: `['required', 'string', 'regex:/^(\+?8801|01)\d{7,9}$/', 'unique:users,phone']`
  - `password`: `required|string|min:8`

### 3.3 File Storage Strategy
Files stored under `storage/app/public/kyc-documents/{userId}/` using unique hashed names, publicly resolvable via `Storage::disk('public')->url(...)`.

---

## 4. Admin KYC Verification Desk (`/admin/kyc-approvals`)

Enhance `app/Filament/Admin/Support/ApplicationReviewActions.php` and `app/Filament/Admin/Pages/KycApprovals.php`:

1. **Table View (`wholesaleTable`):**
   - Add column `business_type`: badge format.
   - Add column `has_documents`: icon badge showing whether trade license document, TIN, or shop photo are attached.
2. **Details Modal (`viewWholesaleApplication`):**
   - Infolist entries displaying:
     - Trade License document link / download button.
     - TIN document link / download button.
     - Shop Photo thumbnail preview with lightbox modal.
     - Business Type & District.
3. **Decision Pipeline (`ApplicationReviewService`):**
   - On Approval: sets `is_wholesale_approved = true`, updates role to `wholesale_buyer`, clears rejection fields, fires `WholesaleKycDecidedEvent($user, true)`.
   - On Rejection: sets `is_wholesale_approved = false`, sets `wholesale_rejected_at = now()`, records `wholesale_rejection_reason`, fires `WholesaleKycDecidedEvent($user, false, $reason)`.

---

## 5. Automated Multi-Channel Notification Pipeline

Create `App\Listeners\Sellers\SendWholesaleDecisionSmsListener`:
- Listens to `WholesaleKycDecidedEvent`.
- Registered in `EventServiceProvider` / `AppServiceProvider`.
- Resolves user phone; validates via `PhoneNormalizer::isValid()`.
- If valid, dispatches `SendSmsJob`:
  - **On Approval:**  
    `"B2Bhaat: Congratulations! Your Wholesale Buyer account for '{$businessName}' is approved. You can now access factory-direct pricing: " . route('catalog.index')`  
    Purpose: `'wholesale_kyc_approved'`
  - **On Rejection:**  
    `"B2Bhaat: Your Wholesale Buyer application for '{$businessName}' was not approved: {$reason}. Update and resubmit at: " . route('wholesale.upgrade')`  
    Purpose: `'wholesale_kyc_rejected'`
- Pairs with the existing `SendWholesaleDecisionEmailListener` to provide complete multi-channel coverage.

---

## 6. Testing Strategy & Verification Plan

1. **Schema & Model Tests (`tests/Unit/WholesaleKycModelTest.php`):**
   - Tests file URL generation and attribute casting.
   - Tests `isWholesalePending()`, `isWholesaleApproved()`, and `isWholesaleRejected()` states.
2. **Storefront Portal Tests (`tests/Feature/WholesaleUpgradePortalTest.php`):**
   - Guest can apply with trade license file upload and is registered + logged in.
   - Authenticated retail buyer can apply with document upload.
   - File validation constraints (PDF/image max 5MB).
   - Re-application flow: rejected buyer sees rejection reason and can resubmit with updated documents.
   - Multi-tenant security: buyer only sees their own application status.
3. **Admin Verification Desk Tests (`tests/Feature/Admin/WholesaleKycDeskTest.php`):**
   - Admin can view applicant details and uploaded document URLs in modal.
   - Admin can approve wholesale application $\rightarrow$ updates role and fires events.
   - Admin can reject wholesale application with required reason $\rightarrow$ records rejection reason and fires events.
4. **SMS & Multi-Channel Listener Tests (`tests/Feature/WholesaleKycNotificationTest.php`):**
   - Approval dispatches `SendSmsJob` and `WholesaleDecisionMail`.
   - Rejection dispatches `SendSmsJob` with rejection reason and `WholesaleDecisionMail`.
   - Missing/invalid phone numbers handle gracefully without throwing unhandled exceptions.
5. **Full Regression Suite:**
   - All 993 existing tests continue to pass.
