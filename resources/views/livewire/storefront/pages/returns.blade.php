<p>If something is wrong with your order, we will help you get it put right.</p>

<h2>Cancelling an order</h2>
<p>You can cancel an order from <a href="{{ route('customer.orders') }}">My Orders</a> within {{ $cancellationWindowHours }} hours of placing it, as long as the seller has not yet sent it. Once a parcel is on its way it can no longer be cancelled, but you can request a return after delivery.</p>

<h2>Returns: report a problem within {{ $returnWindowHours }} hours of delivery</h2>
<p>You can request a return within <strong>{{ $returnWindowHours }} hours</strong> of your parcel being delivered if the item:</p>
<ul>
    <li>arrived damaged or broken,</li>
    <li>is not what you ordered (wrong item, size or colour), or</li>
    <li>does not work.</li>
</ul>
<p>Open <a href="{{ route('customer.orders') }}">My Orders</a>, choose the order and select "Request Return". Describe the problem; photos help the seller decide faster. Keep the item, its packaging and accessories until the return is settled.</p>
<p>The seller reviews your request. You can ask for a <strong>refund</strong> or a <strong>replacement</strong>.</p>

<h2>Refunds</h2>
<ul>
    <li><strong>If you paid by bKash or Nagad:</strong> once the return is approved, we send the refund back to you and record the transaction reference.</li>
    <li><strong>If you paid Cash on Delivery:</strong> once the return is approved, we arrange the refund with you directly.</li>
    <li>If a parcel is returned to the seller without being delivered, any amount you paid in advance is refunded.</li>
</ul>

<h2>Why {{ $returnWindowHours }} hours?</h2>
<p>We hold your payment and do not pass it to the seller until {{ $returnWindowHours }} hours after delivery. Reporting a problem inside that time means the money is still protected while the problem is sorted out.</p>

<h2>Warranty</h2>
<p>Some products also come with a warranty from the seller or manufacturer. Where there is one, it is shown on the product page under "Warranty". Warranty claims are handled by the seller; keep your invoice.</p>
