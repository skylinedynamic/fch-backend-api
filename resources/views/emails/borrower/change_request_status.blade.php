@extends('emails.layouts.mail')

@section('title', config('app.name') . ' - Change Request Update')

@section('content')
    <p>Dear {{ $borrowerName }},</p>

    <p>
        The request to update your profile information has been reviewed.
    </p>

    <div class="box-light info-card-container mt-3">
        <div class="info-card-header">Change Request Details</div>
        <div class="info-card-content">
            <table class="doc-table">
                <thead>
                    <tr>
                        <th>Field Name</th>
                        <th>Status</th>
                        <th>Requested Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="col-task">{{ ucwords(str_replace('_', ' ', $fieldName)) }}</td>
                        <td class="col-status">
                            <span class="badge {{ $status === \App\Models\Borrower\BorrowerChangeRequest::STATUS_APPROVED ? 'badge-verified' : 'badge-rejected' }}">
                                {{ $status }}
                            </span>
                        </td>
                        <td class="col-remark">{{ $newValue }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    @if (!empty($note))
        <div class="box-light info-card-container mt-3">
            <div class="info-card-header">Note from Reviewer</div>
            <div class="note-content">
                {{ $note }}
            </div>
        </div>
    @endif


    <p class="text-small mt-3">
        If you have any questions about this update, please contact our support team.
    </p>
@endsection
