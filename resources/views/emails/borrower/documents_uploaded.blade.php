@extends('emails.layouts.mail')

@section('title', 'Documents Uploaded Successfully')

@section('content')
    <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello,</h4>

    <p style="line-height: 1.5; margin-bottom: 16px;">
        Documents have been uploaded for the following client:
    </p>

    <div class="box-light info-card-container" style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden;">
        <div class="info-card-content" style="padding: 20px;">
            <table class="info-table compact" style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td class="label" style="padding: 4px; border-bottom: none; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%;">Client Name:</td>
                    <td class="value" style="padding: 4px; border-bottom: none; font-size: 14px; font-weight: 600; color: #0f172a; text-align: right;">{{ $user->name }}</td>
                </tr>
                @if ($user->entity && $user->entity->business_name)
                    <tr>
                        <td class="label" style="padding: 4px; border-bottom: none; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%;">Company:</td>
                        <td class="value" style="padding: 4px; border-bottom: none; font-size: 14px; font-weight: 600; color: #0f172a; text-align: right;">{{ $user->entity->business_name }}</td>
                    </tr>
                @endif
                @if ($documents->isNotEmpty() && $documents->first()->documentType)
                    <tr>
                        <td class="label" style="padding: 4px; border-bottom: none; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%;">Document Type:</td>
                        <td class="value" style="padding: 4px; border-bottom: none; font-size: 14px; font-weight: 600; color: #0f172a; text-align: right;">{{ $documents->first()->documentType->name }}</td>
                    </tr>
                @endif
            </table>
        </div>
    </div>

    <p style="line-height: 1.5; margin-bottom: 16px;">Uploaded Documents:</p>

    <div class="box-light info-card-container" style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden;">
        <div class="info-card-content" style="padding: 20px;">
            <table class="doc-table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="text-align: left; padding: 10px 8px; border-bottom: 1px solid #e9ebec; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #878a99; letter-spacing: 0.5px;">Doc No</th>
                        <th style="text-align: left; padding: 10px 8px; border-bottom: 1px solid #e9ebec; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #878a99; letter-spacing: 0.5px;">Document Name</th>
                        <th style="text-align: left; padding: 10px 8px; border-bottom: 1px solid #e9ebec; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #878a99; letter-spacing: 0.5px;">Date</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($documents as $doc)
                        <tr>
                            <td style="padding: 8px; border-bottom: 1px solid #eee; font-size: 13px; color: #495057;">{{ $doc->document_number }}</td>
                            <td style="padding: 8px; border-bottom: 1px solid #eee; font-size: 13px; color: #495057;">{{ $doc->file_name }}</td>
                            <td style="padding: 8px; border-bottom: 1px solid #eee; font-size: 13px; color: #495057;">
                                {{ $doc->created_at->format('Y-m-d') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="btn-container" style="margin: 30px 0; text-align: center;">
        <a href="{{ concatUrl(getPartnerDomain($user->partner_id ?? null), 'clients/' . $user->entity_id, config('app.frontend_partner_url')) }}" class="btn" style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">View Documents</a>
    </div>
@endsection
