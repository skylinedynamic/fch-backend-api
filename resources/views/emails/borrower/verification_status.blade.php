@extends('emails.layouts.mail')

@section('title', $siteName . ' - Verification Status Update')

@section('content')
    <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Dear {{ $borrowerName }},</h4>

    <p>
        Your verification request has been reviewed. Below is the detailed status summary for each of the submitted tasks.
    </p>

    {{-- Task-wise Results Table --}}
    <div class="box-light info-card-container mt-3"
        style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden; margin-top: 16px;">
        <div class="info-card-header"
            style="background-color: #f0f7ff; padding: 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 16px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">
            Task Results</div>
        <div class="info-card-content" style="padding: 20px;">
            <table class="doc-table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th
                            style="text-align: left; padding: 10px 8px; border-bottom: 1px solid #e9ebec; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #878a99; letter-spacing: 0.5px;">
                            #</th>
                        <th
                            style="text-align: left; padding: 10px 8px; border-bottom: 1px solid #e9ebec; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #878a99; letter-spacing: 0.5px;">
                            Tier</th>
                        <th
                            style="text-align: left; padding: 10px 8px; border-bottom: 1px solid #e9ebec; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #878a99; letter-spacing: 0.5px;">
                            Task Name</th>
                        <th
                            style="text-align: left; padding: 10px 8px; border-bottom: 1px solid #e9ebec; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #878a99; letter-spacing: 0.5px;">
                            Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($taskResults as $index => $result)
                        @php
                            $isVerified = $result['is_verified'] ?? false;
                            $badgeStyles = $isVerified
                                ? 'background-color: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0;'
                                : 'background-color: #fee2e2; color: #dc2626; border: 1px solid #fecaca;';
                            $badgeLabel = $isVerified ? 'Verified' : 'Not Verified';
                        @endphp
                        <tr>
                            <td class="col-num"
                                style="padding: 8px; border-bottom: 1px solid #eee; color: #64748b; width: 32px;">
                                {{ $index + 1 }}</td>
                            <td class="col-tier"
                                style="padding: 8px; border-bottom: 1px solid #eee; font-weight: 600; color: #1e3a8a; font-size: 13px; white-space: nowrap;">
                                {{ $result['tier_name'] ?? __('verification_center.unknown_tier') }}</td>
                            <td class="col-task"
                                style="padding: 8px; border-bottom: 1px solid #eee; font-weight: 500; color: #0f172a; font-size: 13px;">
                                {{ $result['task_name'] }}</td>
                            <td class="col-status"
                                style="padding: 8px; border-bottom: 1px solid #eee; white-space: nowrap;">
                                <span class="badge"
                                    style="display: inline-block; padding: 3px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; letter-spacing: 0.3px; {{ $badgeStyles }}">{{ $badgeLabel }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Note Section --}}
    @if (!empty($note))
        <div class="box-light info-card-container mt-3"
            style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden; margin-top: 16px;">
            <div class="info-card-header"
                style="background-color: #f0f7ff; padding: 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 16px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">
                Note</div>
            <div class="note-content" style="padding: 12px 20px; color: #374151; font-size: 14px; line-height: 1.7;">
                {{ $note }}
            </div>
        </div>
    @endif

    <p class="text-small mt-3" style="font-size: 13px; color: #94a3b8; margin-top: 16px;">
        If you have any questions about this update, please contact your partner or our support team.
    </p>
@endsection
