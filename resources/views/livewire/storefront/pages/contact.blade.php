<p>We are happy to help with orders, deliveries, returns and seller questions.</p>

@if ($identity['helpline'] || $identity['email'] || $identity['address'])
    <ul>
        @if ($identity['helpline'])
            <li><strong>Phone:</strong> <a href="tel:{{ preg_replace('/[^0-9+]/', '', $identity['helpline']) }}">{{ $identity['helpline'] }}</a></li>
        @endif
        @if ($identity['email'])
            <li><strong>Email:</strong> <a href="mailto:{{ $identity['email'] }}">{{ $identity['email'] }}</a></li>
        @endif
        @if ($identity['address'])
            <li><strong>Office:</strong> {{ $identity['address'] }}</li>
        @endif
    </ul>
@else
    <p>Our contact details will be published here shortly.</p>
@endif

<h2>Faster answers</h2>
<ul>
    <li>Where is my order? Use <a href="{{ route('tracking.index') }}">Track Order</a> with your order number and phone number.</li>
    <li>Problem with an item? Open <a href="{{ route('customer.orders') }}">My Orders</a> and request a return within {{ $returnWindowHours }} hours of delivery.</li>
    <li>Want to sell with us? <a href="{{ route('seller.register') }}">Register as a seller</a>.</li>
</ul>
