# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Sellers (primary for the Seller Center, `/seller`):** owners and staff of small Bangladeshi factories and importers. Not very technical. They mostly use a phone, in the workshop or warehouse, between other work. Their jobs: see new orders, pack them, hand them to a courier or deliver themselves, collect cash on delivery, and see what they earn.
- **Wholesale buyers:** retail storekeepers and bulk buyers with a trade licence. After an admin approves the licence they see wholesale tier prices and minimum order quantities, and can ask for quotes.
- **Retail customers:** ordinary shoppers on the storefront (`/`, `/cart`, `/checkout`), buying single pieces. They sign in with an SMS code and track parcels by link.
- **Admins (`/admin`):** B2B Haat staff who approve sellers, products, prices and trade licences, check payments, settle couriers and pay sellers.

## Product Purpose

B2B Haat (`b2bhaat.com`) is a marketplace for Bangladesh that lets factories and importers sell directly, both wholesale and retail, with the buyer's money held safely until the goods arrive. Success for a seller is: every order understood at a glance, shipped on time, and paid out without disputes.

## Positioning

- **Factory-direct wholesale:** buyers buy straight from verified factories and importers at bulk tier prices (for example 50–99 pcs at ৳110, 100–249 at ৳95, 250+ at ৳85).
- **One listing, two channels:** a seller lists a product once and sells it both as retail single pieces and as wholesale bulk, with separate prices and minimum quantities.
- **Money held safely:** the buyer's payment is held after delivery (48 hours by default, set by admin) so the buyer can report a problem, then moves to the seller's balance.
- **Verified businesses:** sellers and wholesale buyers are checked with trade licences before wholesale opens.

## Operating Context

- Currency is Bangladeshi taka (৳). Cash on delivery is common; online payment is by mobile money (bKash, Nagad and similar), checked by an admin.
- One customer order is split into one parcel per seller. Each parcel goes: received → packed → shipped → out for delivery → delivered, or is cancelled or returned.
- A parcel goes by a local courier (Steadfast, Pathao, RedX, Paperfly, Sundarban), whose report marks it delivered, or by the seller's own person, confirmed by a 6-digit code the customer gives at the door. The seller never sees that code.
- The buyer can cancel online only until the seller marks the parcel packed.
- Sellers print 4×6 shipping labels and A4 delivery challans. Customers get SMS messages with links to their invoice and tracking page.

## Capabilities and Constraints

- Built with Laravel and Filament 5 (admin and seller panels) on Tailwind; the storefront is Livewire. Production is shared cPanel hosting with a tight memory limit.
- The language is plain, simple English everywhere. Bangla is not planned for now.
- Business rules confirmed by the owner (cash on delivery flow, who confirms delivery, fees, returns, cancellations) live in the project's audit documents and override older generated specs when they conflict.
- Open product decisions: payment gateway not chosen; live courier booking deferred until the owner has a courier account; VAT/BIN deferred.

## Brand Commitments

- Name: **B2B Haat** (domain `b2bhaat.com`); panels are "B2B Haat Seller Center" and the admin panel. Logo at `public/favicon-32x32.png` and the brand logo view.
- Voice: plain, direct English for people who are busy and not very technical. Name the action and the amount ("Collect ৳2,810 in cash"), never internal terms.

## Evidence on Hand

- Demo catalogue and demo orders in `database/seeders/DemoCatalogSeeder.php` (nails and beauty factory). No real customer testimonials, sales figures or press exist yet; do not invent them.

## Product Principles

1. **Say the next step.** Every screen a seller opens should tell them where things stand and the one thing to do next.
2. **Phone first for sellers.** Seller screens must work one-handed on a phone in a warehouse, not only on a desktop.
3. **Money is always explicit.** Show what the seller receives, what is held and until when, and what cash to collect, in taka.
4. **Trust is the product.** Never show something that weakens the money-held and verified-business promise, such as a buyer's code, another user's data, or an action the rules do not allow.
5. **Plain words over system words.** "Packed", "On the way", "You receive", not status codes or IDs.
