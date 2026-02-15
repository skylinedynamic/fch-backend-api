@extends('emails.layouts.mail')

@section('title', 'Documents Uploaded Successfully')

@section('content')
    <h4>Hello,</h4>

    <p>
        Documents have been uploaded for the following client:
    </p>

    <div class="box-light info-card-container">
        <div class="info-card-content">
            <table class="info-table compact">
                <tr>
                    <td class="label">Client Name:</td>
                    <td class="value">{{ $user->name }}</td>
                </tr>
                @if ($user->entity && $user->entity->business_name)
                    <tr>
                        <td class="label">Company:</td>
                        <td class="value">{{ $user->entity->business_name }}</td>
                    </tr>
                @endif
                @if ($documents->isNotEmpty() && $documents->first()->documentType)
                    <tr>
                        <td class="label">Document Type:</td>
                        <td class="value">{{ $documents->first()->documentType->name }}</td>
                    </tr>
                @endif
            </table>
        </div>
    </div>

    <p>Uploaded Documents:</p>

    <div class="box-light info-card-container">
        <div class="info-card-content">
            <table class="doc-table">
                <thead>
                    <tr>
                        <th>Doc No</th>
                        <th>Document Name</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($documents as $doc)
                        <tr>
                            <td>{{ $doc->document_number }}</td>
                            <td>{{ $doc->file_name }}</td>
                            <td>
                                {{ $doc->created_at->format('Y-m-d') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="btn-container">
        <a href="{{ config('app.frontend_partner_url') }}/clients/{{ $user->entity_id }}" class="btn">View Documents</a>
    </div>
@endsection
