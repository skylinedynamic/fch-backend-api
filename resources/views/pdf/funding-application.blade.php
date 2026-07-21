<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Funding Application #{{ $application->application_number ?? $application->id }}</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 12px; color: #333; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #004b87; padding-bottom: 10px; }
        .header h1 { margin: 0; color: #004b87; font-size: 24px; }
        .section { margin-bottom: 20px; }
        .section-title { background: #f4f6f8; padding: 5px 10px; font-weight: bold; border-left: 4px solid #004b87; margin-bottom: 10px; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top; }
        th { background-color: #f9f9f9; width: 40%; color: #555; }
        .footer { text-align: center; font-size: 10px; color: #777; margin-top: 30px; border-top: 1px solid #ddd; padding-top: 10px; }
    </style>
</head>
<body>

    <div class="header">
        <h1>Funding Application Summary</h1>
        <p>Application ID: {{ $application->application_number ?? $application->id }} | Date: {{ $application->created_at->format('M d, Y') }}</p>
    </div>

    <div class="section">
        <div class="section-title">Overview</div>
        <table>
            <tr>
                <th>Status</th>
                <td>{{ ucfirst(str_replace('_', ' ', $application->status)) }}</td>
            </tr>
            <tr>
                <th>Requested Amount</th>
                <td>${{ number_format((float) $application->requested_amount, 2) }}</td>
            </tr>
            <tr>
                <th>Purpose of Funding</th>
                <td>{{ $application->purpose_of_funding }}</td>
            </tr>
            <tr>
                <th>Business Structure</th>
                <td>{{ $application->entity_type }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Borrower Details</div>
        <table>
            <tr>
                <th>Borrower Name</th>
                <td>{{ $application->first_name }} {{ $application->last_name }}</td>
            </tr>
            <tr>
                <th>Email</th>
                <td>{{ $application->email }}</td>
            </tr>
            <tr>
                <th>Phone</th>
                <td>{{ $application->phone }}</td>
            </tr>
        </table>
    </div>

    @if($application->signature_data)
    <div class="section">
        <div class="section-title">Electronic Signature Data</div>
        <table>
            <tr>
                <th>Signed Name</th>
                <td>{{ $application->signature_data['signature_name'] ?? 'N/A' }}</td>
            </tr>
            <tr>
                <th>Timestamp</th>
                <td>{{ isset($application->signature_data['timestamp']) ? \Carbon\Carbon::parse($application->signature_data['timestamp'])->format('M d, Y H:i:s T') : 'N/A' }}</td>
            </tr>
            <tr>
                <th>IP Address</th>
                <td>{{ $application->signature_data['ip_address'] ?? 'N/A' }}</td>
            </tr>
            <tr>
                <th>Browser / Device</th>
                <td>{{ $application->signature_data['browser'] ?? 'N/A' }} on {{ $application->signature_data['os'] ?? 'N/A' }}</td>
            </tr>
        </table>
    </div>
    @endif

    <div class="footer">
        Generated on {{ now()->format('M d, Y H:i:s') }} | Four Corner Funding
    </div>

</body>
</html>
