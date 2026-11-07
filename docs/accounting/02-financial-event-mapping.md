# 02. Financial Event Mapping & Double-Entry Journal Templates

> **Platform:** B2Bhaat E-Commerce  
> **Currency:** BDT (৳)  
> **Accounting Standard:** Double-Entry Bookkeeping ($Total Debit = Total Credit$)  
> **Fundamental Equation:** $\text{Assets} = \text{Liabilities} + \text{Equity}$  

---

## 1. System Account Code & Slug Registry

The following Chart of Accounts slugs are registered in `accounting_accounts` for automated journal resolution:

| Code | System Slug | Name | Type | Normal Balance | Description |
|---|---|---|---|---|---|
| **1010** | `cash_on_hand` | Cash in Hand / Operating Cash | Asset | Debit | Platform physical liquid cash. |
| **1020** | `bank_operating` | Bank Account (Platform Operating) | Asset | Debit | Main platform operating bank account. |
| **1030** | `gateway_clearing` | Payment Gateway Clearing (bKash/Nagad/SSL) | Asset | Debit | Custodial clearing account holding funds collected from customer online checkouts before bank settlement. |
| **1040** | `courier_cod_receivable` | Courier COD Receivable (Steadfast/Pathao/RedX) | Asset | Debit | Cash collected by couriers from customers, awaiting weekly remittance payout to the platform. |
| **1050** | `seller_receivable` | Seller Negative Balance Receivable | Asset | Debit | Receivables due from sellers when returns or commissions exceed their wallet balance. |
| **2010** | `seller_escrow_payable` | Seller Escrow Held Payable (Liability) | Liability | Credit | Custodial funds held by platform during shipment and the 48-hour return dispute window. |
| **2020** | `seller_wallet_payable` | Seller Available Wallet Balance (Liability) | Liability | Credit | Matured seller earnings available for withdrawal or fee offset. |
| **2030** | `reserved_payout_payable` | Reserved Payout Liabilities | Liability | Credit | Seller funds locked during payout approval/disbursement processing. |
| **2040** | `customer_refund_payable` | Customer Refund Payable | Liability | Credit | Amounts owed back to buyers for cancelled or returned orders. |
| **2050** | `deferred_subscription_revenue` | Deferred Subscription Revenue | Liability | Credit | Unearned seller plan subscription fees collected in advance. |
| **2060** | `vat_payable` | VAT / Tax Payable | Liability | Credit | Platform VAT collected awaiting government remittance. |
| **3010** | `owner_equity` | Owner Capital / Retained Earnings | Equity | Credit | Cumulative retained marketplace earnings. |
| **4010** | `commission_revenue` | Marketplace Commission Revenue | Revenue | Credit | Earned platform commission on completed consignments. |
| **4020** | `subscription_revenue` | Seller Subscription Revenue | Revenue | Credit | Earned revenue from monthly/annual seller membership plans. |
| **4030** | `listing_pack_revenue` | Product Listing Addon Revenue | Revenue | Credit | Revenue from extra product listing quota packs. |
| **4040** | `shipping_service_revenue` | Platform Logistics / Shipping Fee Margin | Revenue | Credit | Net margin on courier shipping charges if retained by platform. |
| **5010** | `promotional_discount_expense` | Platform-Funded Promotional Discounts | Expense | Debit | Marketing expense for platform-subsidized discount vouchers. |
| **5020** | `gateway_fee_expense` | Payment Gateway Processing Fees | Expense | Debit | 1.5%–2.5% MFS/gateway transaction processing charges. |
| **5030** | `courier_delivery_expense` | Courier Shipping Expenses | Expense | Debit | Delivery fees paid to 3rd-party logistics couriers. |

---

## 2. Event Journal Templates & Lifecycle Mappings

### Event 1: Online Customer Payment Received (Prepaid Checkout)
- **Trigger:** `PaymentVerificationService::markVerified($order)` (or future automated gateway webhook).
- **Preconditions:** `$order->paymentStatus->slug === 'awaiting_verification'`, verified by admin/webhook.
- **Business Reality:** Customer transferred total order amount (goods + platform shipping - coupon). The platform holds these funds in Gateway Clearing. At this exact moment, seller net earnings are committed to Escrow Liability, platform commission is earned (or unearned escrow), and platform shipping is collected.
- **Journal Template:**
  | Line | Account Slug | Account Name | Debit (৳) | Credit (৳) | Dimension |
  |---|---|---|---|---|---|
  | 1 | `gateway_clearing` | Payment Gateway Clearing | `order.total_amount` | `0.00` | Customer ID, Order ID |
  | 2 | `promotional_discount_expense` | Promotional Discount Expense (if platform-funded) | `order.discount_amount` | `0.00` | Order ID |
  | 3 | `seller_escrow_payable` | Seller Escrow Held Payable | `0.00` | $\sum \text{sellerPayable}$ | Seller ID, SubOrder ID |
  | 4 | `commission_revenue` | Marketplace Commission Revenue | `0.00` | $\sum \text{commission\_amount}$ | Seller ID, SubOrder ID |
  | 5 | `courier_delivery_expense` / Clearing | Shipping Fees Collected | `0.00` | `order.shipping_amount` | Order ID |
- **Balancing Proof:**
  $$\text{Debit: } (\text{total\_amount} + \text{discount}) = (\text{Items Subtotal} + \text{Shipping})$$
  $$\text{Credit: } (\text{sellerPayable} + \text{commission} + \text{shipping}) = (\text{Items Subtotal} + \text{Shipping})$$
  $$\mathbf{Total\ Debit = Total\ Credit}$$
- **Idempotency Key:** `order_prepaid_received_{order.id}`
- **Wallet Impact:** `seller_wallets.escrow_held_balance` increments by `sellerPayableAmount()`.

---

### Event 2: Third-Party Courier COD Delivered
- **Trigger:** `CourierStatusService::updateStatus($subOrder, 'delivered')` on a COD order.
- **Preconditions:** `$subOrder->order->paymentMethod->slug === 'cod'`, `!$subOrder->is_self_delivered`.
- **Business Reality:** Courier collected cash at buyer's door. The courier now owes this money to the platform (`courier_cod_receivable`). The platform owes net earnings to seller in escrow (`seller_escrow_payable`) and recognizes its commission.
- **Journal Template:**
  | Line | Account Slug | Account Name | Debit (৳) | Credit (৳) | Dimension |
  |---|---|---|---|---|---|
  | 1 | `courier_cod_receivable` | Courier COD Receivable | `subOrder.paidAmount()` | `0.00` | Courier, SubOrder ID |
  | 2 | `promotional_discount_expense` | Promotional Discount (if platform-funded) | `subOrder.allocatedDiscount()` | `0.00` | SubOrder ID |
  | 3 | `seller_escrow_payable` | Seller Escrow Held Payable | `0.00` | `subOrder.net_earnings` | Seller ID, SubOrder ID |
  | 4 | `commission_revenue` | Marketplace Commission Revenue | `0.00` | `subOrder.commission_amount` | Seller ID, SubOrder ID |
  | 5 | `courier_delivery_expense` / Clearing | Shipping Fee Allocated | `0.00` | `subOrder.shipping_amount` | SubOrder ID |
- **Balancing Proof:**
  $$\text{Debit: } (\text{subtotal} + \text{shipping} - \text{discount}) + \text{discount} = \text{subtotal} + \text{shipping}$$
  $$\text{Credit: } (\text{subtotal} - \text{commission}) + \text{commission} + \text{shipping} = \text{subtotal} + \text{shipping}$$
  $$\mathbf{Total\ Debit = Total\ Credit}$$
- **Idempotency Key:** `suborder_cod_delivered_{subOrder.id}`
- **Wallet Impact:** Increments `seller_wallets.escrow_held_balance` by `net_earnings`.

---

### Event 3: Self-Delivered COD Consignment Delivered
- **Trigger:** `CourierStatusService::handleDeliveredSettlement($subOrder)` where `$subOrder->is_self_delivered === true`.
- **Preconditions:** COD order delivered directly by seller's truck/van. Handover code confirmed.
- **Business Reality:** The seller collected 100% of the customer's cash at the door. The platform holds no money. The seller owes the platform its commission (`commission_amount`).
- **Journal Template:**
  | Line | Account Slug | Account Name | Debit (৳) | Credit (৳) | Dimension |
  |---|---|---|---|---|---|
  | 1 | `seller_wallet_payable` (or `seller_receivable`) | Seller Available Wallet Liability | `subOrder.commission_amount` | `0.00` | Seller ID, SubOrder ID |
  | 2 | `commission_revenue` | Marketplace Commission Revenue | `0.00` | `subOrder.commission_amount` | Seller ID, SubOrder ID |
- **Balancing Proof:**
  $$\text{Debit: } \text{commission\_amount} = \text{Credit: } \text{commission\_amount}$$
  $$\mathbf{Total\ Debit = Total\ Credit}$$
- **Idempotency Key:** `suborder_self_cod_delivered_{subOrder.id}`
- **Wallet Impact:** Decrements `seller_wallets.available_balance` by commission amount.

---

### Event 4: Escrow Release to Available Balance (Holding Window Matured)
- **Trigger:** `EscrowLedgerService::releaseFromEscrow($subOrder)` (scheduled 48h job).
- **Preconditions:** `$subOrder` delivered $\ge 48$ hours ago, no open dispute/return request.
- **Business Reality:** Custodial liability transitions from restricted escrow to available on-demand seller liability.
- **Journal Template:**
  | Line | Account Slug | Account Name | Debit (৳) | Credit (৳) | Dimension |
  |---|---|---|---|---|---|
  | 1 | `seller_escrow_payable` | Seller Escrow Held Payable | `heldAmount` | `0.00` | Seller ID, SubOrder ID |
  | 2 | `seller_wallet_payable` | Seller Available Wallet Payable | `0.00` | `heldAmount` | Seller ID, SubOrder ID |
- **Balancing Proof:**
  $$\text{Debit: } \text{heldAmount} = \text{Credit: } \text{heldAmount}$$
  $$\mathbf{Total\ Debit = Total\ Credit}$$
- **Idempotency Key:** `suborder_escrow_release_{subOrder.id}`
- **Wallet Impact:** Decrements `escrow_held_balance`, increments `available_balance`.

---

### Event 5: Order Cancellation & Refund (Prepaid Order Before Dispatch)
- **Trigger:** Customer/Admin cancels order before shipment.
- **Preconditions:** Order was paid online, money in `gateway_clearing` or `bank_operating`.
- **Business Reality:** Platform reverses unearned commission and seller escrow hold; acknowledges refund payable to buyer.
- **Journal Template:**
  | Line | Account Slug | Account Name | Debit (৳) | Credit (৳) | Dimension |
  |---|---|---|---|---|---|
  | 1 | `seller_escrow_payable` | Seller Escrow Held Payable | `sellerPayable` | `0.00` | Seller ID, SubOrder ID |
  | 2 | `commission_revenue` | Commission Revenue (Reversal) | `commission` | `0.00` | Seller ID, SubOrder ID |
  | 3 | `courier_delivery_expense` / Clearing | Shipping Fee (Reversal) | `shipping` | `0.00` | SubOrder ID |
  | 4 | `promotional_discount_expense` | Promotional Discount (Reversal) | `0.00` | `discount` | Order ID |
  | 5 | `customer_refund_payable` | Customer Refund Payable | `0.00` | `subOrder.paidAmount()` | Customer ID, Order ID |
- **Balancing Proof:**
  $$\mathbf{Total\ Debit = Total\ Credit}$$
- **Idempotency Key:** `suborder_cancel_refund_{subOrder.id}`
- **Wallet Impact:** Decrements `seller_wallets.escrow_held_balance`.

---

### Event 6: Seller Payout Processing & Disbursement
- **Stage A: Payout Request & Reservation:**
  - Seller requests withdrawal $\ge$ ৳500.
  - Journal:
    - **Debit:** `seller_wallet_payable` (৳Amount)
    - **Credit:** `reserved_payout_payable` (৳Amount)
  - Result: Protects seller funds from concurrent double-withdrawal while keeping liability on the balance sheet.
- **Stage B: Payout Disbursement (Admin transfers via Bank / bKash / Nagad):**
  - Admin enters bank/MFS TrxID.
  - Journal:
    - **Debit:** `reserved_payout_payable` (৳Amount)
    - **Credit:** `bank_operating` (৳Amount)
  - Result: Liability extinguished; money exits platform bank account.
- **Stage C: Payout Rejection (if rejected by Admin):**
  - Reverses Stage A:
    - **Debit:** `reserved_payout_payable` (৳Amount)
    - **Credit:** `seller_wallet_payable` (৳Amount)

---

### Event 7: Seller Subscription & Listing Add-on Payment
- **Trigger:** Seller pays monthly/annual plan or buys listing pack.
- **Preconditions:** `seller_payments` verified by admin or paid online.
- **Business Reality:** Platform receives cash into bank/gateway; recognizes subscription revenue or deferred revenue.
- **Journal Template:**
  | Line | Account Slug | Account Name | Debit (৳) | Credit (৳) | Dimension |
  |---|---|---|---|---|---|
  | 1 | `bank_operating` / `gateway_clearing` | Operating Bank / Gateway | `payment.amount` | `0.00` | Seller ID |
  | 2 | `subscription_revenue` (or `listing_pack_revenue`) | Earned Monetization Revenue | `0.00` | `payment.amount` | Seller ID |
- **Balancing Proof:**
  $$\mathbf{Total\ Debit = Total\ Credit}$$
- **Idempotency Key:** `seller_monetization_payment_{sellerPayment.id}`

---

*End of 02-financial-event-mapping.md*
