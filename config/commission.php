<?php

// config/commission.php

return [
    // Snapshotted onto every order at creation time — never read again for
    // an existing order. Changing this only affects orders placed after
    // the change.
    'rate' => (float) env('COMMISSION_RATE', 0.03),

    // Days after delivery before an unconfirmed, undisputed order's farmer
    // payout auto-releases.
    'auto_release_days' => (int) env('COMMISSION_AUTO_RELEASE_DAYS', 3),

    // Minutes an order may sit in 'awaiting_payment' before the checkout
    // is considered abandoned and safely expired.
    'awaiting_payment_timeout_minutes' => (int) env('AWAITING_PAYMENT_TIMEOUT_MINUTES', 30),
];
