<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
</head>

<body>
    <h3>Hello {{ $invoice->customer_name }},</h3>
    <p>Attached is your invoice <strong>#{{ $invoice->invoice_id }}</strong> for the amount of <strong>${{ number_format($invoice->payment_amount, 2) }}</strong>.</p>
    <p>You can also view and pay your invoice online at the following link:</p>
    <p><a href="{{ config('app.frontend_partner_url') }}/invoices/{{ $invoice->id }}">View Invoice</a></p>
    <br>
    <p>Thank you for your business!</p>
    <p>Best regards,<br>Four Corner Holdings Team</p>
</body>

</html>