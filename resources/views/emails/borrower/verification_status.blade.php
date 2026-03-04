@extends('emails.layouts.mail')

@section('title', config('app.name') . ' - Verification Status Update')

@section('content')
    <p>Dear {{ $borrowerName }},</p>

    <p>
        Your verification request has been reviewed. Below is the detailed status summary for each of the submitted tasks.
    </p>

    {{-- Task-wise Results Table --}}
    <div class="box-light info-card-container mt-3">
        <div class="info-card-header">Task Results</div>
        <div class="info-card-content">
            <table class="doc-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Task Name</th>
                        <th>Status</th>
                        <th>Remark</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($taskResults as $index => $result)
                        @php
                            $isVerified = $result['is_verified'] ?? false;
                            $badgeClass = $isVerified ? 'badge-verified' : 'badge-rejected';
                            $badgeLabel = $isVerified ? 'Verified' : 'Not Verified';
                        @endphp
                        <tr>
                            <td class="col-num">{{ $index + 1 }}</td>
                            <td class="col-task">{{ $result['task_name'] }}</td>
                            <td class="col-status">
                                <span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span>
                            </td>
                            <td class="col-remark">{{ $result['message'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Note Section --}}
    @if (!empty($note))
        <div class="box-light info-card-container mt-3">
            <div class="info-card-header">Note</div>
            <div class="note-content">
                {{ $note }}
            </div>
        </div>
    @endif

    <p class="text-small mt-3">
        If you have any questions about this update, please contact your partner or our support team.
    </p>
@endsection
