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
];
