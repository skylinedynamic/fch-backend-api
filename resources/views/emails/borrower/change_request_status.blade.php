@extends('emails.layouts.mail')

@section('title', $siteName . ' - Change Request Update')

@section('content')
    <p style="line-height: 1.5; margin-bottom: 16px;">Dear {{ $borrowerName }},</p>

    <p style="line-height: 1.5; margin-bottom: 24px;">
        The request to update your profile information has been reviewed.
    </p>

    <div class="box-light info-card-container mt-3"
        style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden; margin-top: 16px;">
        <div class="info-card-header"
            style="background-color: #f0f7ff; padding: 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 16px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">
            Change Request Details</div>
        <div class="info-card-content" style="padding: 20px;">
            <table class="doc-table"
                style="width: 100%; border-collapse: collapse; margin-bottom: 0; border: 1px solid #e2e8f0;">
                <thead>
                    <tr style="background-color: #f8fafc;">
                        <th
                            style="padding: 12px 15px; text-align: left; border-bottom: 2px solid #e2e8f0; font-size: 12px; font-weight: 700; color: #495057; text-transform: uppercase;">
                            Field Name</th>
                        <th
                            style="padding: 12px 15px; text-align: left; border-bottom: 2px solid #e2e8f0; font-size: 12px; font-weight: 700; color: #495057; text-transform: uppercase;">
                            Status</th>
                        <th
                            style="padding: 12px 15px; text-align: left; border-bottom: 2px solid #e2e8f0; font-size: 12px; font-weight: 700; color: #495057; text-transform: uppercase;">
                            Requested Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="col-task"
                            style="padding: 12px 15px; border-bottom: 1px solid #e2e8f0; font-size: 13px; color: #495057; font-weight: 500;">
                            {{ ucwords(str_replace('_', ' ', $fieldName)) }}</td>
                        <td class="col-status"
                            style="padding: 12px 15px; border-bottom: 1px solid #e2e8f0; font-size: 13px; color: #495057;">
                            @php
                                $isApproved = $status === \App\Models\Borrower\BorrowerChangeRequest::STATUS_APPROVED;
                                $badgeStyle = $isApproved
                                    ? 'background-color: #0ab39c; color: #ffffff;'
                                    : 'background-color: #ef4444; color: #ffffff;';
                            @endphp
                            <span class="badge"
                                style="display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; {{ $badgeStyle }}">
                                {{ $status }}
                            </span>
                        </td>
                        <td class="col-remark"
                            style="padding: 12px 15px; border-bottom: 1px solid #e2e8f0; font-size: 13px; color: #495057;">
                            {{ $newValue }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    @if (!empty($note))
        <div class="box-light info-card-container mt-3"
            style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden; margin-top: 16px;">
            <div class="info-card-header"
                style="background-color: #f0f7ff; padding: 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 16px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">
                Note from Reviewer</div>
            <div class="note-content" style="padding: 20px; font-size: 14px; line-height: 1.6; color: #495057;">
                {{ $note }}
            </div>
        </div>
    @endif


    <p class="text-small mt-3" style="font-size: 12px; color: #878a99; line-height: 1.5; margin-top: 16px;">
        If you have any questions about this update, please contact our support team.
    </p>
@endsection
