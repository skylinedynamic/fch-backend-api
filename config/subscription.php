<?php

return [
    /**
     * Number of days before the due date to generate an invoice.
     */
    'generate_before_days' => 5,

    /**
     * How far ahead the "Upcoming Billings" screen looks.
     *
     * Deliberately wider than generate_before_days so staff can see what is coming
     * before the cron actually raises the invoice. Keep it >= generate_before_days,
     * otherwise invoices would appear on the list only after they were generated.
     */
    'upcoming_window_days' => 10,

    /**
     * Failed automatic charges an invoice gets before the scheduler stops
     * auto-charging it (BUG-005). The cron runs twice a day, so 6 is three days.
     * The payer - and a client's partner - are told once; the invoice stays
     * Unpaid and can still be paid by hand.
     */
    'auto_charge_max_failed_attempts' => 6,
];
