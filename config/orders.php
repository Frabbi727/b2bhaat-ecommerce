<?php

return [

    /*
    | SEC-8: how long the secret links sent to buyers keep working, counted from the order date. After that the
    | buyer signs in, or looks the order up by number and phone, to see it.
    */
    'tracking_link_days' => (int) env('ORDER_TRACKING_LINK_DAYS', 90),

    'invoice_link_days' => (int) env('ORDER_INVOICE_LINK_DAYS', 365),

];
