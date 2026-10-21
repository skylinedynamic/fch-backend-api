@extends('emails.layouts.mail')

@section('title', 'Your lead was converted to a borrower')

@section('content')
    <div class="description-card" style="margin-bottom: 30px; text-align: left;">
        <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello {{ $partnerName }},</h4>
        <p style="line-height: 1.5; margin-bottom: 24px; color: #495057;">
            <strong>{{ $hostName }}</strong> converted your lead to a borrower on your behalf.
            {{ $leadName }} is now your borrower and has been added to your Clients.
        </p>

        <div class="box-light info-card-container"
            style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 4px; margin-bottom: 24px; text-align: left; overflow: hidden;">
            <div class="info-card-header"
                style="background-color: #f0f7ff; padding: 16px 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 15px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">
                Converted Lead
            </div>
            <div style="padding: 20px;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; width: 45%; padding-left: 10px;">Lead</td>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">{{ $lead->contact_person }}</td>
                    </tr>
                    @if($lead->company_name)
                    <tr>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; padding-left: 10px;">Company</td>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #0f172a; text-align: right; padding-right: 10px;">{{ $lead->company_name }}</td>
                    </tr>
                    @endif
                    @if($ownerName)
                    <tr>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; padding-left: 10px;">Lead Owner</td>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #0f172a; text-align: right; padding-right: 10px;">{{ $ownerName }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td style="padding: 9px 0; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; padding-left: 10px;">Converted By</td>
                        <td style="padding: 9px 0; font-size: 13px; font-weight: 600; color: #0e9f6e; text-align: right; padding-right: 10px;">{{ $hostName }}</td>
                    </tr>
                </table>
            </div>
        </div>

        <p style="line-height: 1.5; color: #495057; font-size: 13px;">
            You can review their details and take the next step from Clients in your portal.
        </p>
    </div>
@endsection
