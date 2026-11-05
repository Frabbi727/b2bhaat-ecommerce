<p>These terms apply when you use {{ $identity['name'] }} (the "platform") to browse, buy or sell. By creating an account or placing an order you agree to them.</p>

<h2>1. The marketplace</h2>
<p>Products are listed and sold by independent sellers. {{ $identity['name'] }} runs the platform, checks sellers' business documents, holds payments until delivery and helps settle problems. The contract for each item is between you and the seller named on the product page and invoice.</p>

<h2>2. Your account</h2>
<ul>
    <li>Give accurate details, including a mobile number you can receive SMS on. We send one-time codes to it to confirm it is yours.</li>
    <li>Keep your password and one-time codes private. You are responsible for orders placed from your account.</li>
    <li>We may suspend accounts used for fraud, fake orders or abuse.</li>
</ul>

<h2>3. Prices and orders</h2>
<ul>
    <li>Prices are in Bangladeshi Taka (৳). The delivery fee for your area is shown before you confirm your order.</li>
    <li>An order with items from several sellers is sent as separate parcels, one per seller, each with its own delivery fee.</li>
    <li>If a price or stock level is clearly wrong, the seller may cancel the order; anything you paid is refunded.</li>
</ul>

<h2>4. Payment</h2>
<p>We currently accept {{ implode(', ', $paymentMethodNames) }}. bKash and Nagad payments are checked against the transaction ID before your order is sent. Your payment is held by the platform and released to the seller {{ $returnWindowHours }} hours after delivery.</p>

<h2>5. Delivery</h2>
<ul>
    @foreach ($deliveryTimes as $area => $time)
        <li>{{ $area }}: usually {{ $time }}.</li>
    @endforeach
</ul>
<p>Some sellers deliver themselves. In that case you will see a handover code on your order page; give it to the delivery person only when you receive your parcel.</p>

<h2>6. Cancellations and returns</h2>
<p>See <a href="{{ route('pages.returns') }}">Returns & Refunds</a>.</p>

<h2>7. Wholesale buyers</h2>
<p>Wholesale prices and bulk ordering are available to businesses whose trade licence we have approved. Minimum order quantities apply. Quotations agreed with a seller apply to the quoted product, price and quantity.</p>

<h2>8. Sellers</h2>
<p>Sellers must list accurate descriptions, prices, stock and warranty information, ship on time and handle returns fairly. The platform deducts its commission from each sale before paying the seller.</p>

<h2>9. Reviews and content</h2>
<p>Reviews must be honest and about your own purchase. We may remove content that is false, offensive or illegal.</p>

<h2>10. Law</h2>
<p>These terms are governed by the laws of Bangladesh, including the Digital Commerce Operation Guidelines. If we change these terms, the new version applies to orders placed after it is published here.</p>

<h2>11. Contact</h2>
<p>See <a href="{{ route('pages.contact') }}">Contact Us</a>.</p>
