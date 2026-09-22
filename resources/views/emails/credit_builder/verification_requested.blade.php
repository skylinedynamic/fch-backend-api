@extends('emails.layouts.mail')

@section('title', $siteName . ' - New Verification Request')

@section('content')
    {{-- Greeting, then the summary - the same opening the profile change request
         mail uses. The old heading repeated the subject line verbatim, which
         already names the borrower and the task count. --}}
    <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">
        Hello {{ $hostName ?? __('borrower.host_partner') }},</h4>

    <p style="line-height: 1.5; margin-bottom: 24px;">
        <strong>{{ $borrowerName }}</strong>@if ($partnerName) of <strong>{{ $partnerName }}</strong>@endif
        has submitted {{ trans_choice('verification_center.task_count', count($tasks), ['count' => count($tasks)]) }}
        for verification and requires your review.
    </p>

    {{-- Every task in the submission, grouped by tier. A bulk submission is one
         email, so the whole list has to be here. --}}
    <div class="box-light info-card-container mt-3"
        style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden; margin-top: 16px;">
        <div class="info-card-header"
            style="background-color: #f0f7ff; padding: 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 16px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">
            Tasks Awaiting Review</div>
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
                            Task</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tasks as $index => $task)
                        <tr>
                            <td
                                style="padding: 10px 8px; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #495057;">
                                {{ $index + 1 }}</td>
                            <td
                                style="padding: 10px 8px; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #1e3a8a; white-space: nowrap;">
                                {{ $task['tier_name'] }}</td>
                            <td
                                style="padding: 10px 8px; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #212529;">
                                {{ $task['task_name'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Who to look up in the Verification Center. --}}
    <div class="box-light info-card-container"
        style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden;">
        <div class="info-card-content" style="padding: 20px;">
            <table class="info-table" style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Borrower:</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">
                        {{ $borrowerName }}</td>
                </tr>
                @if ($borrowerEmail)
                    <tr>
                        <td class="label"
                            style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                            Email:</td>
                        <td class="value"
                            style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                            {{ $borrowerEmail }}</td>
                    </tr>
                @endif
                @if ($partnerName)
                    <tr>
                        <td class="label"
                            style="padding: 10px 0; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                            Partner:</td>
                        <td class="value"
                            style="padding: 10px 0; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                            {{ $partnerName }}</td>
                    </tr>
                @endif
            </table>
        </div>
    </div>

    {{-- Straight to this borrower's requests, so the reviewer does not have to
         find them in the Verification Center's full list.

         Guarded: a queue worker holds the mailable class in memory from the
         moment it booted, while Blade recompiles per render. A worker that has
         not been restarted since a deploy therefore renders this template
         against the previous class, and an unguarded {{ $reviewUrl }} aborts the
         whole send. Losing the button beats losing the email. --}}
    @isset($reviewUrl)
        <div class="btn-container" style="margin: 30px 0 8px 0; text-align: center;">
            <a href="{{ $reviewUrl }}" class="btn"
                style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">Review
                in Verification Center</a>
        </div>

        <p style="line-height: 1.5; margin-bottom: 0; font-size: 12px; color: #94a3b8; text-align: center;">
            Or paste this into your browser:<br>
            <span style="color: #64748b; word-break: break-all;">{{ $reviewUrl }}</span>
        </p>
    @else
        <p style="line-height: 1.5; margin-bottom: 0; font-size: 13px; color: #64748b;">
            Review these in the Verification Center.
        </p>
    @endisset
@endsection
