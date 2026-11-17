# Questions for the Owner (wholesale audit, 2026-11-14)

Business questions the code cannot answer. Nothing here was turned into a rule. Each lists the options and what each one means for the code.

| # | Question | Raised by | Options and code impact |
|---|---|---|---|
| 1 | Should a platform coupon apply to wholesale lines, flash-sale lines and RFQ-quoted lines, or only to retail full-price lines? | Task 2 | **Today:** every line counts towards the coupon (`CartSessionService.php:552-579`). **Only retail:** compute the coupon on the retail subtotal; a small change in `getAppliedCoupon` and the coupon minimum. **Configurable per coupon:** add an "applies to" field on `coupons`. |
| 2 | For an RFQ on a product with options (colour/size), should the buyer state the option split when asking, or when accepting? | Task 2 (WA-PRC-03) | **When asking:** the RFQ form gets a variation matrix; the seller quotes the lot. **When accepting:** the accept screen gets the matrix; the quote total must still match. |
| 3 | A cart mixing retail and wholesale lines: should the wholesale minimum basket (৳5,000) apply to the whole cart, to the wholesale lines only, or per seller? | Task 2 | **Today:** the whole cart subtotal, before coupon (`CheckoutComponent.php:321-330`). The other options change one comparison each. |
| 4 | Do you want a maximum order quantity per product, or a minimum wholesale amount per seller (not only platform-wide)? | Task 2 (brief §2) | Not built today. Each is a new column on products or seller profiles, plus a checkout check. |
| 5 | When part of a parcel is returned, should the commission given back be at that item's own rate (retail and wholesale rates differ), or in proportion to its value as today? And is commission meant to be charged on the price before the coupon (today) or after it? | Task 3 (WA-MNY-02) | **Own rate:** use the `commission_rate` already stored per line; the seller is never charged a rate they didn't agree to. **Pro rata (today):** a mixed parcel shifts commission between lines. **After-coupon base:** commission falls with every coupon the platform funds; change `OrderSplitterService.php:202-213` and the journal handlers. |
