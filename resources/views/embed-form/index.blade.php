<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $config->form_title }} &mdash; {{ $partnerName }}</title>
    <meta name="description"
        content="Apply for a loan with {{ $partnerName }}. Fast, secure, and easy online application.">

    @if(!empty($faviconUrl))
        <link rel="icon" type="image/x-icon" href="{{ $faviconUrl }}">
    @endif

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">

    {{-- Bootstrap 5.3 CDN & Icons --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    {{-- Select2 CSS --}}
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    {{-- Flatpickr: the same date picker the portal uses, so a date is entered
    and displayed identically on both sides. The native control differs per
    browser and per OS locale, which made the expected format unguessable. --}}
    <link href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" rel="stylesheet" />

    <style>
        :root {
            --brand-primary:
                {{ $primaryColor ?? '#0d6efd' }}
            ;
            --brand-secondary:
                {{ $secondaryColor ?? '#198754' }}
            ;
            --bs-primary: var(--brand-primary);
            --bs-primary-rgb:
                {{ hexdec(substr($primaryColor ?? '#0d6efd', 1, 2)) }}
                ,
                {{ hexdec(substr($primaryColor ?? '#0d6efd', 3, 2)) }}
                ,
                {{ hexdec(substr($primaryColor ?? '#0d6efd', 5, 2)) }}
            ;
        }

        * {
            border-radius: 4px;
            box-sizing: border-box;
        }

        html,
        body {
            overflow-x: hidden;
            max-width: 100vw;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: #f4f6f9;
            min-height: 100vh;
            color: #1e293b;
            padding-bottom: 40px;
        }

        .brand-logo-img {
            max-height: 68px;
            max-width: 280px;
            object-fit: contain;
            border-radius: 4px !important;
        }

        .brand-avatar {
            width: 60px;
            height: 60px;
            border-radius: 4px !important;
            background: var(--brand-primary);
            color: #fff;
            font-weight: 700;
            font-size: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .card-custom {
            border: 1px solid #e2e8f0;
            border-radius: 4px !important;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
            background: #ffffff;
        }

        /* Form Inputs — 4px radius, no border change on normal hover/focus */
        .form-control,
        .form-select,
        textarea {
            border-radius: 4px !important;
            border: 1px solid #e2e8f0 !important;
            background-color: #f8fafc;
            color: #1e293b;
            font-size: 13.5px;
            padding: 9px 13px;
            box-shadow: none !important;
            outline: none !important;
            transition: background-color 0.15s ease;
        }

        .form-control:hover,
        .form-select:hover,
        textarea:hover,
        .form-control:focus,
        .form-select:focus,
        textarea:focus {
            border: 1px solid #e2e8f0 !important;
            background-color: #ffffff !important;
            box-shadow: none !important;
            outline: none !important;
        }

        .form-control::placeholder {
            color: #94a3b8;
            font-size: 13px;
        }

        /* Red Border for Validation States - Only when explicitly marked is-invalid */
        .form-control.is-invalid,
        .form-select.is-invalid,
        textarea.is-invalid {
            border: 1px solid #dc3545 !important;
            background-color: #fff8f8 !important;
            background-image: none !important;
        }

        .form-control.is-invalid:hover,
        .form-select.is-invalid:hover,
        textarea.is-invalid:hover,
        .form-control.is-invalid:focus,
        .form-select.is-invalid:focus,
        textarea.is-invalid:focus {
            border: 1px solid #dc3545 !important;
            background-color: #ffffff !important;
            box-shadow: none !important;
            outline: none !important;
        }

        .input-group-text {
            border-radius: 4px !important;
            border: 1px solid #e2e8f0 !important;
            background-color: #f1f5f9;
            color: #64748b;
            font-size: 13px;
            padding: 9px 12px;
        }

        /* Outline whole input group including icon in red ONLY on validation error */
        .input-group.is-invalid .input-group-text,
        .input-group:has(>.form-control.is-invalid) .input-group-text {
            border-color: #dc3545 !important;
            border-right-color: transparent !important;
            background-color: #fff8f8 !important;
            color: #dc3545 !important;
        }

        .input-group.is-invalid .form-control,
        .input-group:has(>.form-control.is-invalid) .form-control {
            border-left-color: #dc3545 !important;
        }

        .input-group>.form-control {
            border-top-left-radius: 0 !important;
            border-bottom-left-radius: 0 !important;
        }

        .input-group>.input-group-text {
            border-top-right-radius: 0 !important;
            border-bottom-right-radius: 0 !important;
        }

        /* A select2 inside an input-group has to stay on the group's row.
           .select2-container below is width:100% !important, which is right for
           a select that owns its row and wrong here: it fills the flex line by
           itself and pushes the field beside it onto a second one. That is what
           the country picker was doing to the business phone number.

           The width mirrors the inline one on the <select>, which is what the
           browser shows before select2 initialises and if its script never
           loads. Only one field in this form pairs a select2 with an input;
           every other input-group here uses .input-group-text. */
        .input-group>.select2-container {
            flex: 0 0 auto;
            width: 132px !important;
        }

        /* Join the two into one control: square the inner corners and pull the
           borders together so the seam reads as a single line, the way
           Bootstrap does for its own input-group children. */
        .input-group>.select2-container .select2-selection--single {
            border-top-right-radius: 0 !important;
            border-bottom-right-radius: 0 !important;
        }

        .input-group>.select2-container+.form-control {
            margin-left: -1px;
        }

        /* Select2 Custom Styling matching 4px radius & zero focus/hover border */
        .select2-container {
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }

        .select2-container--default .select2-selection--single {
            height: 40px !important;
            border-radius: 4px !important;
            border: 1px solid #e2e8f0 !important;
            background-color: #f8fafc !important;
            padding: 5px 12px !important;
            outline: none !important;
            box-shadow: none !important;
            transition: background-color 0.15s ease;
        }

        .select2-container--default .select2-selection--single:hover,
        .select2-container--default.select2-container--open .select2-selection--single,
        .select2-container--default.select2-container--focus .select2-selection--single {
            border: 1px solid #e2e8f0 !important;
            background-color: #ffffff !important;
            outline: none !important;
            box-shadow: none !important;
        }

        .select2-container.is-invalid .select2-selection--single,
        select.is-invalid+.select2 .select2-selection--single {
            border: 1px solid #dc3545 !important;
            background-color: #fff8f8 !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: #1e293b !important;
            font-size: 13.5px !important;
            line-height: 28px !important;
            padding-left: 0 !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__placeholder {
            color: #94a3b8 !important;
            font-size: 13px !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 38px !important;
            right: 8px !important;
        }

        .select2-dropdown {
            border: 1px solid #e2e8f0 !important;
            border-radius: 4px !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08) !important;
            font-size: 13.5px !important;
            overflow: hidden;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }

        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: var(--brand-primary) !important;
            color: #fff !important;
        }

        .select2-container--default .select2-results__option[aria-selected=true] {
            background-color: #f1f5f9 !important;
            color: #1e293b !important;
        }

        .select2-search--dropdown {
            padding: 8px !important;
        }

        .select2-search--dropdown .select2-search__field {
            border: 1px solid #e2e8f0 !important;
            border-radius: 4px !important;
            padding: 6px 10px !important;
            outline: none !important;
            box-shadow: none !important;
            font-size: 13px !important;
        }

        /* Buttons & Badges — 4px radius, no on-hover color changes */
        .btn,
        .badge,
        .alert,
        .card {
            border-radius: 4px !important;
        }

        .btn-primary,
        .btn-primary:hover,
        .btn-primary:focus,
        .btn-primary:active {
            background-color: var(--brand-primary) !important;
            border-color: var(--brand-primary) !important;
            color: #ffffff !important;
            box-shadow: none !important;
            outline: none !important;
        }

        .btn-success,
        .btn-success:hover,
        .btn-success:focus,
        .btn-success:active {
            background-color: var(--brand-secondary) !important;
            border-color: var(--brand-secondary) !important;
            color: #ffffff !important;
            box-shadow: none !important;
            outline: none !important;
        }

        .btn-outline-secondary,
        .btn-outline-secondary:hover,
        .btn-outline-secondary:focus,
        .btn-outline-secondary:active {
            background-color: transparent !important;
            border-color: #cbd5e1 !important;
            color: #64748b !important;
            box-shadow: none !important;
            outline: none !important;
        }

        /* Stepper styles with clean underlying track to prevent line bleed */
        .stepper-wrapper {
            display: flex;
            justify-content: space-between;
            position: relative;
            margin-bottom: 28px;
            padding: 0 10px;
        }

        .stepper-track {
            position: absolute;
            top: 18px;
            left: 10%;
            right: 10%;
            height: 2px;
            background: #e2e8f0;
            z-index: 1;
        }

        .stepper-track-progress {
            height: 100%;
            background: var(--brand-primary);
            transition: width 0.3s ease;
            width: 0%;
        }

        .stepper-item {
            position: relative;
            z-index: 3;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .step-counter {
            position: relative;
            z-index: 4;
            display: flex;
            justify-content: center;
            align-items: center;
            width: 36px;
            height: 36px;
            border-radius: 4px !important;
            background: #f1f5f9;
            border: 3px solid #ffffff;
            box-shadow: 0 0 0 1px #cbd5e1;
            color: #64748b;
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 6px;
            transition: all 0.25s ease;
        }

        .stepper-item.active .step-counter {
            background: var(--brand-primary);
            color: #ffffff;
            box-shadow: 0 0 0 1px var(--brand-primary);
        }

        .stepper-item.completed .step-counter {
            background: var(--brand-secondary);
            color: #ffffff;
            box-shadow: 0 0 0 1px var(--brand-secondary);
        }

        .step-name {
            font-size: 12px;
            font-weight: 500;
            color: #64748b;
        }

        .stepper-item.active .step-name {
            color: var(--brand-primary);
            font-weight: 700;
        }

        .stepper-item.completed .step-name {
            color: var(--brand-secondary);
            font-weight: 600;
        }

        /* Upload zone */
        .upload-dropzone {
            border: 2px dashed #cbd5e1;
            border-radius: 4px !important;
            padding: 22px 16px;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: background-color 0.2s ease;
            position: relative;
        }

        .upload-dropzone:hover,
        .upload-dropzone.drag-over {
            background: #f1f5f9;
        }

        .upload-dropzone input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }

        .file-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #e2e8f0;
            color: #1e293b;
            padding: 4px 10px;
            border-radius: 4px !important;
            font-size: 12px;
            font-weight: 500;
            margin: 4px;
        }

        .file-chip button {
            background: none;
            border: none;
            color: #64748b;
            padding: 0;
            cursor: pointer;
            font-weight: bold;
        }

        /* Step Visibility */
        .tab-step {
            display: none;
        }

        .tab-step.active {
            display: block;
        }

        .honeypot {
            display: none !important;
            visibility: hidden !important;
        }

        .badge-verified {
            font-size: 11px;
            letter-spacing: 0.3px;
        }

        /* Yes / No answer pairs used by the financial and compliance steps */
        .answer-group {
            display: inline-flex;
            gap: 8px;
        }

        .answer-option {
            position: relative;
        }

        .answer-option input {
            position: absolute;
            opacity: 0;
            inset: 0;
            cursor: pointer;
        }

        .answer-option span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 62px;
            padding: 6px 14px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #475569;
            font-size: 13px;
            font-weight: 600;
            border-radius: 4px !important;
            cursor: pointer;
        }

        .answer-option input:checked+span {
            background: var(--brand-primary);
            border-color: var(--brand-primary);
            color: #fff;
        }

        .answer-group.is-invalid span {
            border-color: #dc3545;
        }

        /* Per-field message, inserted by the validator under the control */
        .field-error {
            color: #dc3545;
            font-size: 12px;
            margin-top: 4px;
        }

        .field-error:empty {
            display: none;
        }

        /* Disclosure and consent rows */
        .consent-row {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            padding: 12px 14px;
            border-radius: 4px !important;
            margin-bottom: 10px;
        }

        .consent-row.is-invalid {
            border-color: #dc3545;
            background: #fff8f8;
        }

        .consent-row input[type="checkbox"] {
            margin-top: 2px;
            flex-shrink: 0;
        }

        .consent-row label {
            font-size: 13px;
            color: #334155;
            cursor: pointer;
        }

        /* Compliance question rows */
        .compliance-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 10px 0;
            border-bottom: 1px dashed #e2e8f0;
        }

        .compliance-row:last-of-type {
            border-bottom: none;
        }

        .compliance-row p {
            margin: 0;
            font-size: 13px;
            color: #334155;
        }

        /* Co-borrower cards */
        .co-borrower-card {
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            padding: 14px;
            border-radius: 4px !important;
            margin-bottom: 12px;
        }

        .co-borrower-card__head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .co-borrower-card__head h6 {
            margin: 0;
            font-size: 13px;
            font-weight: 700;
            color: #1e293b;
        }

        .signature-input {
            font-family: 'Brush Script MT', 'Segoe Script', cursive;
            font-size: 22px !important;
            padding: 12px 14px !important;
        }

        .review-group__title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--brand-primary);
            margin: 14px 0 6px;
        }

        .review-group:first-child .review-group__title {
            margin-top: 0;
        }

        /* The stepper carries eight nodes now, so the labels only fit on wide
           screens - the counters alone carry the progress below that. */
        .step-name {
            font-size: 10px;
        }

        @media (max-width: 991.98px) {
            .step-name {
                display: none !important;
            }
        }
    </style>
</head>

<body>

    <div class="container py-4" style="max-width: 860px;">

        {{-- Header with Partner Branding --}}
        <div class="card card-custom mb-3 text-center p-4">
            <div class="d-flex justify-content-center align-items-center mb-3">
                @if(!empty($logoUrl))
                    <img src="{{ $logoUrl }}" alt="{{ $partnerName }} Logo" class="brand-logo-img">
                @else
                    <div class="brand-avatar">
                        {{ strtoupper(substr($partnerName, 0, 1)) }}
                    </div>
                @endif
            </div>
            <h5 class="fw-bold mb-1 text-dark fs-17">{{ $config->form_title }}</h5>
            <p class="text-muted mb-0 small">
                Powered by <strong>{{ $partnerName }}</strong> &bull; <span
                    class="badge bg-light text-secondary border badge-verified"><i
                        class="bi bi-shield-check text-success me-1"></i>Secure Application</span>
            </p>
        </div>

        {{-- Main Form Card --}}
        <div class="card card-custom p-4 p-md-5">

            {{-- Stepper Progress, one node per funding application step --}}
            <div class="stepper-wrapper mb-4">
                <div class="stepper-track">
                    <div class="stepper-track-progress" id="stepperTrackProgress" style="width: 0%;"></div>
                </div>
                @foreach([
                        'Business',
                        'Ownership',
                        'Financial',
                        'Funding',
                        'Documents',
                        'Disclosures',
                        'Compliance',
                        'Sign',
                    ] as $index => $stepName)
                    <div class="stepper-item {{ $index === 0 ? 'active' : '' }}" id="step-node-{{ $index + 1 }}">
                        <div class="step-counter">{{ $index + 1 }}</div>
                        <div class="step-name d-none d-lg-block">{{ $stepName }}</div>
                    </div>
                @endforeach
            </div>

            {{-- Application Form --}}
            <form id="loanAppForm" novalidate enctype="multipart/form-data">
                @csrf
                <input type="text" name="_hp_company" class="honeypot" tabindex="-1" autocomplete="off">

                {{-- ═══ STEP 1: Business Information ═══ --}}
                <div class="tab-step active" id="tab-1">
                    <h5 class="fw-bold mb-3 pb-2 border-bottom text-primary">
                        <i class="bi bi-building me-2"></i>Business Information
                    </h5>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Business Contact Full Name <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="full_name" id="full_name" data-req
                                placeholder="e.g. John Doe">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Legal Business Name <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="business_name" id="business_name" data-req
                                placeholder="e.g. Acme Holdings LLC">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Business Email <span
                                    class="text-danger">*</span></label>
                            <input type="email" class="form-control" name="business_email" id="business_email" data-req
                                data-type="email" placeholder="accounts@acme.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Business Phone <span
                                    class="text-danger">*</span></label>
                            <div class="input-group">
                                <select class="form-select select2 flex-grow-0" id="business_country_select"
                                    style="width: 132px;" data-req>
                                    <option value=""></option>
                                </select>
                                <input type="tel" class="form-control" name="business_phone_no" id="business_phone_no"
                                    data-req data-type="phone" placeholder="(555) 000-0000">
                            </div>
                            {{-- Country name and its dialling code, mirroring what the
                            portal's business step posts. --}}
                            <input type="hidden" name="business_country" id="business_country">
                            <input type="hidden" name="business_country_code" id="business_country_code">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small mb-1">Business Street Address <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="business_address" id="business_address"
                                data-req placeholder="123 Commerce Way, Suite 200">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small mb-1">State <span
                                    class="text-danger">*</span></label>
                            <select class="form-select select2" name="business_state" id="business_state" data-req
                                data-placeholder="Select state...">
                                <option value=""></option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small mb-1">City <span
                                    class="text-danger">*</span></label>
                            <select class="form-select select2" name="business_city_select" id="business_city_select"
                                data-req data-placeholder="Select city...">
                                <option value=""></option>
                            </select>
                            {{-- The value actually posted: either the picked city or,
                            when "Others" is chosen, whatever was typed below. --}}
                            <input type="hidden" name="business_city" id="business_city">
                        </div>
                        <div class="col-md-4" id="businessOtherCityWrap" style="display: none;">
                            <label class="form-label fw-semibold small mb-1">Other City <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="business_other_city" placeholder="Enter city">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small mb-1">Postal Code</label>
                            <input type="text" class="form-control" name="business_postal_code"
                                id="business_postal_code" data-type="postal" inputmode="numeric"
                                maxlength="{{ $postalCodeLength }}" placeholder="10001">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Industry Type <span
                                    class="text-danger">*</span></label>
                            <select class="form-select select2" name="industry_type" id="industry_type" data-req
                                data-placeholder="Select industry...">
                                <option value=""></option>
                                @foreach($options['industry_types'] as $industry)
                                    <option value="{{ $industry }}">{{ $industry }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Entity Type <span
                                    class="text-danger">*</span></label>
                            <select class="form-select select2" name="entity_type" id="entity_type" data-req
                                data-placeholder="Select entity type...">
                                <option value=""></option>
                                @foreach($options['entity_types'] as $entity)
                                    <option value="{{ $entity }}">{{ $entity }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Years in Business <span
                                    class="text-danger">*</span></label>
                            <select class="form-select select2" name="years_in_business" id="years_in_business" data-req
                                data-placeholder="Select...">
                                <option value=""></option>
                                @foreach($options['years_in_business'] as $years)
                                    <option value="{{ $years }}">{{ $years }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Number of Employees</label>
                            <input type="number" class="form-control" name="number_of_employees"
                                id="number_of_employees" min="0" placeholder="e.g. 12">
                        </div>
                    </div>
                </div>

                {{-- ═══ STEP 2: Ownership Information ═══ --}}
                <div class="tab-step" id="tab-2">
                    <h5 class="fw-bold mb-3 pb-2 border-bottom text-primary">
                        <i class="bi bi-person-badge me-2"></i>Ownership Information
                    </h5>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">First Name <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="first_name" id="first_name" data-req
                                placeholder="e.g. John">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Last Name <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="last_name" id="last_name" data-req
                                placeholder="e.g. Doe">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Email Address <span
                                    class="text-danger">*</span></label>
                            <input type="email" class="form-control" name="email" id="email" data-req data-type="email"
                                placeholder="john@example.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Mobile Phone <span
                                    class="text-danger">*</span></label>
                            <input type="tel" class="form-control" name="phone" id="phone" data-req data-type="phone"
                                placeholder="(555) 000-0000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Date of Birth <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="date_of_birth" id="date_of_birth" data-req
                                data-datepicker="dob" placeholder="YYYY-MM-DD" autocomplete="off">
                            <div class="form-text fs-12 text-muted">Applicants must be at least {{ $minimumAge }}.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Social Security Number <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="ssn" id="ssn" data-req data-type="ssn"
                                placeholder="XXX-XX-XXXX" maxlength="11" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Citizenship Status <span
                                    class="text-danger">*</span></label>
                            <select class="form-select select2" name="citizenship_status" id="citizenship_status"
                                data-req data-placeholder="Select status...">
                                <option value=""></option>
                                @foreach($options['citizenship_statuses'] as $status)
                                    <option value="{{ $status }}">{{ $status }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Ownership Percentage <span
                                    class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="ownership" id="ownership" data-req
                                    min="0" max="100" step="0.01" placeholder="e.g. 100">
                                <span class="input-group-text bg-light text-muted">%</span>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small mb-1">Home Street Address <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="street_address" id="street_address" data-req
                                placeholder="123 Main Street, Apt 4B">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small mb-1">Country</label>
                            <select class="form-select select2" name="country" id="country"
                                data-placeholder="Select country...">
                                <option value=""></option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small mb-1">State <span
                                    class="text-danger">*</span></label>
                            <select class="form-select select2" name="state" id="state" data-req
                                data-placeholder="Select state...">
                                <option value=""></option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small mb-1">City <span
                                    class="text-danger">*</span></label>
                            <select class="form-select select2" name="city_select" id="city_select" data-req
                                data-placeholder="Select city...">
                                <option value=""></option>
                            </select>
                            <input type="hidden" name="city" id="city">
                        </div>
                        <div class="col-12" id="ownerOtherCityWrap" style="display: none;">
                            <label class="form-label fw-semibold small mb-1">Other City <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="other_city" placeholder="Enter city">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small mb-1">ZIP Code</label>
                            <input type="text" class="form-control" name="zip_code" id="zip_code" data-type="postal"
                                inputmode="numeric" maxlength="{{ $postalCodeLength }}" placeholder="10001">
                        </div>
                    </div>

                    {{-- Co-borrowers --}}
                    <div class="mt-4 pt-3 border-top">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <h6 class="fw-bold mb-0 fs-13">Additional Owners / Co-Borrowers</h6>
                                <span class="text-muted" style="font-size: 12px;">Add anyone else with an ownership
                                    stake or who will guarantee this funding.</span>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary fw-semibold"
                                onclick="addCoBorrower()">
                                <i class="bi bi-plus-lg me-1"></i> Add
                            </button>
                        </div>
                        <div id="coBorrowerList"></div>
                        <input type="hidden" name="has_co_borrower" id="has_co_borrower" value="0">
                    </div>
                </div>

                {{-- ═══ STEP 3: Financial Information ═══ --}}
                <div class="tab-step" id="tab-3">
                    <h5 class="fw-bold mb-3 pb-2 border-bottom text-primary">
                        <i class="bi bi-graph-up-arrow me-2"></i>Financial Information
                    </h5>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Average Monthly Revenue <span
                                    class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted">$</span>
                                <input type="number" class="form-control" name="monthly_revenue" id="monthly_revenue"
                                    data-req min="0" step="0.01" placeholder="e.g. 45000">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Annual Revenue</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted">$</span>
                                <input type="number" class="form-control" name="annual_revenue" id="annual_revenue"
                                    min="0" step="0.01" placeholder="e.g. 540000">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Business Bank Name</label>
                            <input type="text" class="form-control" name="business_bank_name" id="business_bank_name"
                                placeholder="e.g. Chase">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Business Checking Opened <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="business_checking_start_date"
                                id="business_checking_start_date" data-req data-datepicker="past"
                                placeholder="YYYY-MM-DD" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">
                                Estimated Credit Score
                                <span class="badge bg-light text-secondary border ms-1"
                                    style="font-weight: 500;">Self-reported</span>
                            </label>
                            <input type="number" class="form-control" name="credit_score" id="credit_score"
                                data-type="score" min="{{ $creditScore['min'] }}" max="{{ $creditScore['max'] }}"
                                step="1" placeholder="e.g. 720">
                            {{-- Band resolved live from the server's table, so the
                            applicant sees the same label the portal will. --}}
                            <div class="form-text fs-12" id="creditScoreBandHint"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Business Credit Score</label>
                            <select class="form-select select2" name="self_reported_business_credit_score"
                                id="self_reported_business_credit_score" data-placeholder="Select range...">
                                <option value=""></option>
                                @foreach($creditScore['business_score_options'] ?? config('funding_application.credit_score.business_score_options', []) as $score)
                                    <option value="{{ $score }}">{{ $score }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="compliance-row">
                                <p class="fw-semibold">Do you have existing business debt? <span
                                        class="text-danger">*</span></p>
                                <div class="answer-group" data-req-group="has_business_debt">
                                    <label class="answer-option">
                                        <input type="radio" name="has_business_debt" value="1"
                                            onchange="toggleDebtBalance(true)">
                                        <span>Yes</span>
                                    </label>
                                    <label class="answer-option">
                                        <input type="radio" name="has_business_debt" value="0"
                                            onchange="toggleDebtBalance(false)">
                                        <span>No</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6" id="debtBalanceWrap" style="display: none;">
                            <label class="form-label fw-semibold small mb-1">Outstanding Debt Balance <span
                                    class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted">$</span>
                                <input type="number" class="form-control" name="debt_balance" id="debt_balance" min="0"
                                    step="0.01" placeholder="e.g. 25000">
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ═══ STEP 4: Funding Request ═══ --}}
                <div class="tab-step" id="tab-4">
                    <h5 class="fw-bold mb-3 pb-2 border-bottom text-primary">
                        <i class="bi bi-cash-coin me-2"></i>Funding Request
                    </h5>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Requested Amount <span
                                    class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted">$</span>
                                <input type="number" class="form-control" name="requested_amount" id="requested_amount"
                                    data-req min="0" step="0.01" placeholder="e.g. 150000">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Preferred Funding Type <span
                                    class="text-danger">*</span></label>
                            <select class="form-select select2" name="preferred_funding_type"
                                id="preferred_funding_type" data-req data-placeholder="Select funding type...">
                                <option value=""></option>
                                @foreach($options['funding_types'] as $type)
                                    <option value="{{ $type }}">{{ $type }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Purpose of Funds <span
                                    class="text-danger">*</span></label>
                            <select class="form-select select2" name="purpose_of_funds" id="purpose_of_funds" data-req
                                data-placeholder="Select purpose...">
                                <option value=""></option>
                                @foreach($options['purposes_of_funds'] as $purpose)
                                    <option value="{{ $purpose }}">{{ $purpose }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Timeframe Needed</label>
                            <select class="form-select select2" name="timeframe_needed" id="timeframe_needed"
                                data-placeholder="Select timeframe...">
                                <option value=""></option>
                                @foreach($options['timeframes'] as $timeframe)
                                    <option value="{{ $timeframe }}">{{ $timeframe }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12" id="purposeNoteWrap" style="display: none;">
                            <label class="form-label fw-semibold small mb-1">Tell us more about the purpose <span
                                    class="text-danger">*</span></label>
                            <textarea class="form-control" name="purpose_note" id="purpose_note" rows="2"
                                maxlength="500" placeholder="Describe what the funds will be used for"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Collateral Available</label>
                            <select class="form-select select2" name="collateral_available" id="collateral_available"
                                data-placeholder="Select collateral...">
                                <option value=""></option>
                                @foreach($options['collateral'] as $collateral)
                                    <option value="{{ $collateral }}">{{ $collateral }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                {{-- ═══ STEP 5: Document Upload ═══ --}}
                <div class="tab-step" id="tab-5">
                    <h5 class="fw-bold mb-3 pb-2 border-bottom text-primary">
                        <i class="bi bi-file-earmark-arrow-up me-2"></i>Upload Documents
                    </h5>

                    <p class="text-muted small mb-3">
                        Upload whatever you have ready. Nothing here is required to submit &mdash; anything missing can
                        be added later.
                    </p>

                    <div class="row g-3">
                        @foreach($docTypes as $docType)
                            <div class="col-12">
                                <label class="form-label fw-semibold small mb-1">{{ $docType['name'] }}</label>
                                <div class="upload-dropzone" id="zone-{{ $docType['id'] }}"
                                    ondragover="dragOver(event, '{{ $docType['id'] }}')"
                                    ondragleave="dragLeave('{{ $docType['id'] }}')"
                                    ondrop="dropFiles(event, '{{ $docType['id'] }}')">
                                    <input type="file" multiple
                                        accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.jpg,.jpeg,.png,.gif"
                                        onchange="fileInputChanged(event, '{{ $docType['id'] }}')">
                                    <i class="bi bi-cloud-arrow-up fs-4 text-primary"></i>
                                    <div class="fw-semibold text-dark mt-1">Click or drag files here</div>
                                    <div class="text-muted small">PDF, DOC, XLS, CSV, PNG, JPG &mdash; up to 10MB each</div>
                                </div>
                                <div id="list-{{ $docType['id'] }}" class="mt-2"></div>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- ═══ STEP 6: Disclosures and Consent ═══ --}}
                <div class="tab-step" id="tab-6">
                    <h5 class="fw-bold mb-3 pb-2 border-bottom text-primary">
                        <i class="bi bi-file-earmark-text me-2"></i>Disclosures and Consent
                    </h5>

                    <div class="consent-row" id="row-credit_pull_authorization">
                        <input type="checkbox" class="form-check-input" name="credit_pull_authorization"
                            id="credit_pull_authorization" value="1" data-req data-type="consent">
                        <label for="credit_pull_authorization">
                            <strong>Credit Pull Authorization.</strong>
                            I authorize {{ $partnerName }} and its funding partners to obtain my personal and business
                            credit
                            reports from one or more consumer reporting agencies in connection with this application,
                            and to
                            re-access those reports for as long as this application is being considered.
                        </label>
                    </div>

                    <div class="consent-row" id="row-terms_conditions_acceptance">
                        <input type="checkbox" class="form-check-input" name="terms_conditions_acceptance"
                            id="terms_conditions_acceptance" value="1" data-req data-type="consent">
                        <label for="terms_conditions_acceptance">
                            <strong>Terms &amp; Conditions Acceptance.</strong>
                            I have read and accept the terms and conditions and the privacy policy governing this
                            application,
                            and I confirm that the information I am providing is complete and accurate.
                        </label>
                    </div>
                </div>

                {{-- ═══ STEP 7: Federal Compliance Disclosures ═══ --}}
                <div class="tab-step" id="tab-7">
                    <h5 class="fw-bold mb-3 pb-2 border-bottom text-primary">
                        <i class="bi bi-bank me-2"></i>Federal Compliance Disclosures
                    </h5>

                    <p class="text-muted small mb-3">
                        Answer every question. A <strong>Yes</strong> to any of them does not disqualify you, but it
                        does need an explanation below.
                    </p>

                    @foreach([
                            'filed_for_bankruptcy' => 'Have you or the business ever filed for bankruptcy?',
                            'any_pending_lawsuits' => 'Are there any pending lawsuits against you or the business?',
                            'has_federal_loan' => 'Have you ever defaulted on a federal loan?',
                            'has_federal_debt' => 'Are you currently delinquent on any federal debt?',
                            'has_criminal_offense' => 'Have you been charged with or convicted of a criminal offense?',
                            'has_parole_or_probation' => 'Are you currently on parole or probation?',
                            'suspended_or_debarred' => 'Have you been suspended or debarred from federal programs?',
                            'any_outstanding_judgments_business' => 'Are there outstanding judgments or liens against the business?',
                            'any_outstanding_judgments_owner' => 'Are there outstanding judgments or liens against any owner?',
                        ] as $field => $question)
                        <div class="compliance-row">
                            <p>{{ $question }} <span class="text-danger">*</span></p>
                            <div class="answer-group" data-req-group="{{ $field }}">
                                <label class="answer-option">
                                    <input type="radio" name="{{ $field }}" value="1"
                                        onchange="syncComplianceExplanation()">
                                    <span>Yes</span>
                                </label>
                                {{-- Defaulted to No: these are all exception questions,
                                and No is the answer for the large majority of
                                applicants. A Yes is still a deliberate click. --}}
                                <label class="answer-option">
                                    <input type="radio" name="{{ $field }}" value="0" onchange="syncComplianceExplanation()"
                                        checked>
                                    <span>No</span>
                                </label>
                            </div>
                        </div>
                    @endforeach

                    <div class="mt-3" id="complianceExplanationWrap" style="display: none;">
                        <label class="form-label fw-semibold small mb-1">
                            Explanation <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" name="federal_compliance_disclosures_explanation"
                            id="federal_compliance_disclosures_explanation" rows="3" maxlength="5000"
                            placeholder="Please explain each item you answered Yes to."></textarea>
                    </div>
                </div>

                {{-- ═══ STEP 8: Attestation and Authorization ═══ --}}
                <div class="tab-step" id="tab-8">
                    <h5 class="fw-bold mb-3 pb-2 border-bottom text-primary">
                        <i class="bi bi-check2-circle me-2"></i>Review, Attestation and Authorization
                    </h5>

                    <div class="card bg-light border-0 p-3 mb-4" id="reviewSummaryCard">
                        <div id="reviewDetailsGrid">
                            {{-- Injected by renderReviewSummary() --}}
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Sales Representative (if assigned)</label>
                            <select class="form-select select2" name="sales_rep_user_id" id="sales_rep_user_id"
                                data-placeholder="Select representative...">
                                <option value=""></option>
                                @foreach($salesReps as $rep)
                                    <option value="{{ $rep['id'] }}">{{ $rep['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Referral Source</label>
                            <select class="form-select select2" name="referral_source" id="referral_source"
                                data-placeholder="How did you hear about us?">
                                <option value=""></option>
                                @foreach($referralSources as $source)
                                    <option value="{{ $source }}">{{ $source }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12" id="referralOtherWrap" style="display: none;">
                            <label class="form-label fw-semibold small mb-1">Please tell us more <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" name="referral_source_other"
                                id="referral_source_other" maxlength="255" placeholder="Where did you hear about us?">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small mb-1">Type your full legal name as your electronic
                            signature <span class="text-danger">*</span></label>
                        <input type="text" class="form-control signature-input" name="signature_name"
                            id="signature_name" data-req data-type="signature" placeholder="John A. Doe">
                    </div>

                    <div class="consent-row" id="row-esign_consent">
                        <input type="checkbox" class="form-check-input" name="esign_consent" id="esign_consent"
                            value="1" data-req data-type="consent">
                        <label for="esign_consent">
                            I consent to sign this application electronically, and I agree that my typed name above is
                            my
                            legal signature.
                        </label>
                    </div>

                    <div class="consent-row" id="row-terms_consent">
                        <input type="checkbox" class="form-check-input" name="terms_consent" id="terms_consent"
                            value="1" data-req data-type="consent">
                        <label for="terms_consent">
                            I certify that every statement in this application is true, accurate and complete to the
                            best of
                            my knowledge.
                        </label>
                    </div>

                    <div class="consent-row" id="row-credit_consent">
                        <input type="checkbox" class="form-check-input" name="credit_consent" id="credit_consent"
                            value="1" data-req data-type="consent">
                        <label for="credit_consent">
                            I authorize {{ $partnerName }} to verify the information provided, including obtaining
                            credit,
                            banking and business records as needed to evaluate this request.
                        </label>
                    </div>

                    <div class="consent-row" id="row-legal_binding_consent">
                        <input type="checkbox" class="form-check-input" name="legal_binding_consent"
                            id="legal_binding_consent" value="1" data-req data-type="consent">
                        <label for="legal_binding_consent">
                            I understand that this electronically signed application is legally binding, and that
                            knowingly
                            submitting false information may carry civil or criminal penalties.
                        </label>
                    </div>

                    {{-- Timestamps for each consent above, plus the applicant's
                    timezone, which together make the signature auditable. --}}
                    <input type="hidden" name="consents_data" id="consents_data">
                    <input type="hidden" name="timezone" id="timezone">
                    <input type="hidden" name="application_version" id="application_version"
                        value="{{ $config->updated_at?->format('Y.m.d') ?? '1.0' }}">

                    <div class="alert alert-info d-flex align-items-center small py-2 mt-3" role="alert">
                        <i class="bi bi-shield-lock-fill me-2 fs-5"></i>
                        <div>
                            Submitting sends this application directly to <strong>{{ $partnerName }}</strong> over an
                            encrypted connection.
                        </div>
                    </div>
                </div>

                {{-- Navigation Buttons --}}
                <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                    <button type="button" class="btn btn-outline-secondary px-4" id="btnPrev" onclick="navigateStep(-1)"
                        style="display: none;">
                        <i class="bi bi-arrow-left me-1"></i> Back
                    </button>
                    <div class="ms-auto d-flex gap-2">
                        <button type="button" class="btn btn-primary px-4 fw-semibold" id="btnNext"
                            onclick="navigateStep(1)">
                            Continue <i class="bi bi-arrow-right ms-1"></i>
                        </button>
                        <button type="submit" class="btn btn-success px-4 fw-bold" id="btnSubmit"
                            style="display: none;">
                            <i class="bi bi-send-check me-1"></i> Submit Application
                        </button>
                    </div>
                </div>
            </form>

            {{-- Success Message Screen --}}
            <div id="successScreen" class="text-center py-5" style="display: none;">
                <div class="mb-3">
                    <i class="bi bi-check-circle-fill text-success" style="font-size: 3.5rem;"></i>
                </div>
                <h3 class="fw-bold text-dark mb-2">Application Submitted!</h3>
                <p class="text-muted mb-4">
                    Thank you for applying with <strong>{{ $partnerName }}</strong>. Our team has received your
                    application and will contact you shortly.
                </p>
                <div class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 fs-6 mb-3"
                    id="successRefBadge">
                    Ref ID: #
                </div>
            </div>

            {{-- Error Banner --}}
            <div id="errorScreen" class="alert alert-danger mt-3" style="display: none;">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <span id="errorMessageText">Submission failed. Please check the fields and try again.</span>
            </div>

        </div>

        {{-- Footer --}}
        <div class="text-center mt-3 text-muted small">
            &copy; {{ date('Y') }} {{ $partnerName }}. All rights reserved.
        </div>

    </div>

    {{-- jQuery CDN & Bootstrap 5.3 JS Bundle --}}
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"
        integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>

    {{-- Select2 JS --}}
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    {{-- Flatpickr --}}
    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13"></script>

    <script>
        const TOTAL_STEPS = 8;
        let currentStep = 1;

        const API_BASE = '{{ $apiBase }}';
        const PUBLIC_KEY = '{{ $publicKey }}';

        // Country / state / city data, and the document buckets, both rendered from
        // the same server-side source the portal's own application uses.
        const LOCATIONS = @json($locations);
        const DOC_TYPES = @json($docTypes);
        const CO_BORROWER_ROLES = @json($options['co_borrower_roles']);
        const PURPOSE_OTHER = @json($options['purpose_other']);
        const REFERRAL_OTHER = @json($referralSourceOther);
        // The server's band table, so the form and the portal can never disagree
        // about what a score is called.
        const CREDIT_SCORE = @json($creditScore);
        const OTHER_CITY = 'Others';
        const POSTAL_LENGTH = @json($postalCodeLength);
        const MIN_AGE = @json($minimumAge);

        // One DataTransfer per document type id, so a file can be removed from a
        // bucket without clearing the whole input.
        const files = {};
        DOC_TYPES.forEach(t => { files[t.id] = new DataTransfer(); });

        // The moment each consent checkbox was ticked. Sent as consents_data so the
        // signature can be audited later.
        const consentTimestamps = {};

        let coBorrowerIndex = 0;

        /* ────────────────────────────── Setup ────────────────────────────── */

        $(document).ready(function () {
            populateCountries();
            initSelect2();
            initDatePickers();

            document.getElementById('timezone').value =
                Intl.DateTimeFormat().resolvedOptions().timeZone || '';

            // The compliance questions default to No, so the explanation box starts
            // hidden and stays that way until something is switched to Yes.
            syncComplianceExplanation();

            showStep(1);
        });

        /**
         * Attach the date picker to anything marked data-datepicker.
         *
         * Two flavours: `dob`, capped at the minimum applying age, and `past`, capped
         * at today. Both post YYYY-MM-DD, which is what the API validates - the
         * native control rendered per browser locale, so an applicant could not tell
         * what format was expected.
         */
        function initDatePickers(scope) {
            const root = scope || document;
            const today = new Date();
            const adultCutoff = new Date(
                today.getFullYear() - MIN_AGE, today.getMonth(), today.getDate()
            );

            root.querySelectorAll('[data-datepicker]').forEach(el => {
                if (el._flatpickr) return;

                const isDob = el.dataset.datepicker === 'dob';

                flatpickr(el, {
                    dateFormat: 'Y-m-d',
                    allowInput: true,
                    maxDate: isDob ? adultCutoff : today,
                    // A date of birth is decades back, so opening on the current
                    // month would mean a long scroll on every single application.
                    defaultDate: null,
                    onChange: () => validateField(el),
                    // Opening and dismissing the calendar counts as visiting it.
                    onClose: () => {
                        el.dataset.dirty = '1';
                        el.dataset.visited = '1';
                        validateField(el);
                    },
                });
            });
        }

        function initSelect2(scope) {
            $(scope || document).find('.select2').each(function () {
                if ($(this).hasClass('select2-hidden-accessible')) return;
                $(this).select2({
                    minimumResultsForSearch: 10,
                    width: '100%',
                    dropdownAutoWidth: false,
                    placeholder: $(this).data('placeholder') || '',
                    allowClear: !$(this).is('[data-req]')
                });
            });

            // select2 replaces the element, so its own events drive validation -
            // the delegated 'input'/'focusout' listeners never fire. A pick is
            // checked at once; closing the dropdown without one counts as leaving
            // the field. Programmatic refills trigger 'change.select2' only, so
            // repopulating a city list does not flag it.
            $(scope || document).find('.select2')
                .off('change.embed select2:close.embed')
                .on('change.embed', function () {
                    validateField(this);
                })
                .on('select2:close.embed', function () {
                    validateField(this);
                });
        }

        function populateCountries() {
            const businessSelect = document.getElementById('business_country_select');
            const ownerSelect = document.getElementById('country');

            LOCATIONS.forEach(country => {
                businessSelect.add(new Option(`${country.code} ${country.phone_code}`, country.code));
                ownerSelect.add(new Option(country.name, country.name));
            });

            // The business phone's country defaults to the United States. This is a
            // US lending product, and the picker's two entries both read "+1" - USA
            // and Canada share the dialling code - so leaving it empty asked every
            // applicant to make a choice that looks like it has no consequence but
            // decides which state list they get.
            //
            // Set before initSelect2() runs (see the init order above), so select2
            // renders the selection rather than needing to be told about it after the
            // fact. A deployment whose config/locations.php has no United States
            // entry falls back to whatever leads the list.
            const defaultBusinessCountry = findCountryByCode('USA') || LOCATIONS[0];

            if (defaultBusinessCountry) {
                businessSelect.value = defaultBusinessCountry.code;
            }

            // A single-country deployment should not make the applicant pick it.
            // Left as it was: the owner's country is its own field on a later step,
            // and nothing here says which country an owner lives in.
            if (LOCATIONS.length === 1) {
                ownerSelect.value = LOCATIONS[0].name;
            }

            onBusinessCountryChange();
            onOwnerCountryChange();
        }

        function findCountryByCode(code) {
            return LOCATIONS.find(c => c.code === code) || null;
        }

        function findCountryByName(name) {
            return LOCATIONS.find(c => c.name === name) || null;
        }

        function fillStates(selectEl, country, citySelectId) {
            const previous = selectEl.value;
            selectEl.innerHTML = '<option value=""></option>';

            // "Unknown" already leads the list in config/locations.php, so the
            // states are rendered exactly as the data gives them.
            (country?.states || []).forEach(state => {
                selectEl.add(new Option(state.name, state.name));
            });

            if (previous && Array.from(selectEl.options).some(o => o.value === previous)) {
                selectEl.value = previous;
            }

            $(selectEl).trigger('change.select2');
            fillCities(country, selectEl.value, citySelectId);
        }

        /**
         * Populate a city dropdown from the selected state.
         *
         * `Others` is always offered: config/locations.php lists the larger cities
         * of each state, not all of them, so without an escape hatch an applicant
         * from a small town could not complete the form at all.
         */
        function fillCities(country, stateName, citySelectId) {
            const select = document.getElementById(citySelectId);
            if (!select) return;

            const previous = select.value;
            const state = (country?.states || []).find(s => s.name === stateName);

            select.innerHTML = '<option value=""></option>';
            // "Unknown" already leads every city list in config/locations.php.
            // "Others" is appended here instead of living in the data, because it is
            // not a place - it is the control that reveals the free-text box.
            (state?.cities || []).forEach(city => select.add(new Option(city, city)));
            select.add(new Option(OTHER_CITY, OTHER_CITY));

            if (previous && Array.from(select.options).some(o => o.value === previous)) {
                select.value = previous;
            } else {
                select.value = '';
            }

            $(select).trigger('change.select2');
            syncCity(citySelectId);
        }

        // Which hidden input and free-text box each city dropdown drives.
        const CITY_FIELDS = {
            business_city_select: { hidden: 'business_city', other: 'business_other_city', wrap: 'businessOtherCityWrap' },
            city_select: { hidden: 'city', other: 'other_city', wrap: 'ownerOtherCityWrap' },
        };

        /**
         * Keep the posted city in step with the dropdown.
         *
         * The server only ever sees one `city` value; whether it came from the list
         * or the free-text box is the form's business, not the API's.
         */
        function syncCity(citySelectId) {
            const map = CITY_FIELDS[citySelectId];
            if (!map) return;

            const select = document.getElementById(citySelectId);
            const hidden = document.getElementById(map.hidden);
            const other = document.getElementById(map.other);
            const wrap = document.getElementById(map.wrap);

            const isOther = select.value === OTHER_CITY;
            wrap.style.display = isOther ? 'block' : 'none';

            if (isOther) {
                hidden.value = other.value.trim();
            } else {
                hidden.value = select.value;
                other.value = '';
                clearInvalid(other);
            }
        }

        $(document).on('change', '#business_city_select', () => syncCity('business_city_select'));
        $(document).on('change', '#city_select', () => syncCity('city_select'));
        document.addEventListener('input', function (e) {
            if (e.target.id === 'business_other_city') syncCity('business_city_select');
            if (e.target.id === 'other_city') syncCity('city_select');
        });

        function onBusinessCountryChange() {
            const country = findCountryByCode(document.getElementById('business_country_select').value);

            // The portal posts the country's NAME and its dialling code, not the
            // ISO code shown in the picker.
            document.getElementById('business_country').value = country?.name || '';
            document.getElementById('business_country_code').value = country?.phone_code || '';

            fillStates(document.getElementById('business_state'), country, 'business_city_select');
        }

        function onOwnerCountryChange() {
            const country = findCountryByName(document.getElementById('country').value);
            fillStates(document.getElementById('state'), country, 'city_select');
        }

        $(document).on('change', '#business_country_select', onBusinessCountryChange);
        $(document).on('change', '#country', onOwnerCountryChange);
        $(document).on('change', '#business_state', function () {
            fillCities(findCountryByCode(document.getElementById('business_country_select').value), this.value, 'business_city_select');
        });
        $(document).on('change', '#state', function () {
            fillCities(findCountryByName(document.getElementById('country').value), this.value, 'city_select');
        });

        /* ──────────────────────────── Navigation ─────────────────────────── */

        function showStep(step) {
            document.querySelectorAll('.tab-step').forEach(tab => tab.classList.remove('active'));
            document.getElementById(`tab-${step}`)?.classList.add('active');

            const pct = ((step - 1) / (TOTAL_STEPS - 1)) * 100;
            const progressBar = document.getElementById('stepperTrackProgress');
            if (progressBar) progressBar.style.width = pct + '%';

            for (let i = 1; i <= TOTAL_STEPS; i++) {
                const node = document.getElementById(`step-node-${i}`);
                if (node) {
                    node.classList.remove('active', 'completed');
                    if (i < step) node.classList.add('completed');
                    else if (i === step) node.classList.add('active');
                }
            }

            document.getElementById('btnPrev').style.display = step > 1 ? 'inline-flex' : 'none';
            document.getElementById('btnNext').style.display = step < TOTAL_STEPS ? 'inline-flex' : 'none';
            document.getElementById('btnSubmit').style.display = step === TOTAL_STEPS ? 'inline-flex' : 'none';

            if (step === TOTAL_STEPS) {
                renderReviewSummary();
            }

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        let stepCheckPending = false;

        async function navigateStep(dir) {
            if (dir === 1) {
                if (stepCheckPending) return;
                // Instant client-side pass first, then the server has the final word.
                if (!validateStep(currentStep)) return;
                if (!(await validateStepOnServer(currentStep))) return;
                if (currentStep < TOTAL_STEPS) {
                    currentStep++;
                    showStep(currentStep);
                }
            } else if (dir === -1 && currentStep > 1) {
                currentStep--;
                showStep(currentStep);
            }
        }

        /**
         * Ask the server to validate the page being left, using the same rules as
         * the final submission. Resolves true only when the page is accepted.
         */
        async function validateStepOnServer(step) {
            const nextBtn = document.getElementById('btnNext');
            const label = nextBtn.innerHTML;
            stepCheckPending = true;
            nextBtn.disabled = true;
            nextBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Checking...';
            document.getElementById('errorScreen').style.display = 'none';

            try {
                const res = await fetch(`${API_BASE}/v1/embed/validate-step/${PUBLIC_KEY}/${step}`, {
                    method: 'POST',
                    body: buildFormData(step === 5),
                    headers: { 'Accept': 'application/json' }
                });

                if (res.ok) return true;

                let json = {};
                try { json = await res.json(); } catch (e) { /* non-JSON error body */ }
                applyStepErrors(json, step);
                return false;
            } catch (err) {
                document.getElementById('errorMessageText').textContent = 'Network error. Please check your connection and try again.';
                document.getElementById('errorScreen').style.display = 'block';
                return false;
            } finally {
                stepCheckPending = false;
                nextBtn.disabled = false;
                nextBtn.innerHTML = label;
            }
        }

        /** The control a server error key (`email`, `co_borrowers.0.email`) belongs to. */
        function fieldForErrorKey(key) {
            const parts = key.split('.');
            const name = parts[0] + parts.slice(1).map(p => `[${p}]`).join('');
            return document.querySelector(`[name="${name}"]`) || document.getElementById(parts[0]);
        }

        /** Draw the server's messages on the current page; anything unplaceable goes in the banner. */
        function applyStepErrors(json, step) {
            const errors = json.errors || {};
            const tab = document.getElementById(`tab-${step}`);
            const unplaced = [];
            let first = null;

            Object.keys(errors).forEach(key => {
                const message = [].concat(errors[key])[0];
                const el = fieldForErrorKey(key);

                if (!el || !tab || !tab.contains(el)) {
                    unplaced.push(message);
                    return;
                }
                if (el.type === 'radio') {
                    const group = el.closest('[data-req-group]');
                    if (group) {
                        group.classList.add('is-invalid');
                        errorSlot(group, true).textContent = message;
                        first = first || group;
                        return;
                    }
                }
                markInvalid(el, message);
                first = first || el;
            });

            if (first) {
                errorAnchor(first).scrollIntoView({ behavior: 'smooth', block: 'center' });
            }

            if (unplaced.length || !first) {
                const text = unplaced.length ? unplaced.join('<br>') : (json.message || 'Please review this page and try again.');
                document.getElementById('errorMessageText').innerHTML = text;
                document.getElementById('errorScreen').style.display = 'block';
            }
        }

        /** Everything the server validates, shared by the step checks and the final submit. */
        function buildFormData(includeDocuments) {
            const formData = new FormData(document.getElementById('loanAppForm'));

            // Unchecked boxes are absent from FormData, but the server validates
            // every disclosure as a boolean - so send an explicit 0 for each.
            ['credit_pull_authorization', 'terms_conditions_acceptance',
                'esign_consent', 'terms_consent', 'credit_consent', 'legal_binding_consent']
                .forEach(name => {
                    if (!formData.has(name)) formData.append(name, '0');
                });

            if (includeDocuments) {
                // One field per document type, keyed by its id.
                Object.entries(files).forEach(([docTypeId, dt]) => {
                    Array.from(dt.files).forEach(f => formData.append(`documents[${docTypeId}][]`, f));
                });
            }

            return formData;
        }

        /* ──────────────────────────── Validation ─────────────────────────── */

        // Required only while their wrapper is on screen. Each is revealed by
        // another answer (a Yes, an "Other", the "Others" city), and the wrapper
        // being hidden is what says the answer does not call for it.
        const CONDITIONAL_REQ = [
            'debt_balance',
            'business_other_city',
            'other_city',
            'purpose_note',
            'referral_source_other',
            'federal_compliance_disclosures_explanation',
        ];

        const VALIDATABLE = '[data-req], [data-co-req], [data-type], '
            + CONDITIONAL_REQ.map(id => `#${id}`).join(', ');

        function isRequired(el) {
            return el.hasAttribute('data-req')
                || el.hasAttribute('data-co-req')
                || CONDITIONAL_REQ.includes(el.id);
        }

        // select2 hides the real <select>, so its container is what is on screen.
        function isVisible(el) {
            if (el.type === 'hidden') return true;
            if (el.classList.contains('select2-hidden-accessible')) {
                const container = $(el).next('.select2-container')[0];
                return !!container && container.offsetParent !== null;
            }
            return el.offsetParent !== null;
        }

        /**
         * Where a field's error is drawn: below the whole control, so an
         * input-group, a select2 box, a consent row or a Yes/No row keeps its
         * layout and the message sits under all of it.
         */
        function errorAnchor(el) {
            const wrapper = el.closest('.compliance-row, .consent-row, .input-group');
            if (wrapper) return wrapper;
            if (el.classList.contains('select2-hidden-accessible')) {
                return $(el).next('.select2-container')[0] || el;
            }
            return el;
        }

        function errorSlot(el, create) {
            const anchor = errorAnchor(el);
            let slot = anchor.nextElementSibling;
            if (slot && slot.classList.contains('field-error')) return slot;
            if (!create) return null;

            slot = document.createElement('div');
            slot.className = 'field-error';
            anchor.after(slot);
            return slot;
        }

        function markInvalid(el, message) {
            el.classList.add('is-invalid');
            el.setAttribute('aria-invalid', 'true');
            const grp = el.closest('.input-group');
            if (grp) grp.classList.add('is-invalid');
            if (el.classList.contains('select2-hidden-accessible')) {
                $(el).next('.select2-container').addClass('is-invalid');
            }
            const consentRow = el.closest('.consent-row');
            if (consentRow) consentRow.classList.add('is-invalid');
            if (message) errorSlot(el, true).textContent = message;
        }

        function clearInvalid(el) {
            el.classList.remove('is-invalid');
            el.removeAttribute('aria-invalid');
            const grp = el.closest('.input-group');
            if (grp) grp.classList.remove('is-invalid');
            if (el.classList.contains('select2-hidden-accessible')) {
                $(el).next('.select2-container').removeClass('is-invalid');
            }
            const consentRow = el.closest('.consent-row');
            if (consentRow) consentRow.classList.remove('is-invalid');
            const slot = errorSlot(el, false);
            if (slot) slot.textContent = '';
        }

        /**
         * The message for a field's current value, or '' when it is acceptable.
         *
         * An optional field (credit score, zip code) is only judged once something
         * has been typed into it: blank is a choice, a partial value is a typo.
         */
        function fieldError(el) {
            const type = el.dataset.type;
            const value = (el.value || '').trim();

            if (type === 'consent') return el.checked ? '' : 'Please check this box to continue.';

            if (!value) {
                if (!isRequired(el)) return '';
                return el.tagName === 'SELECT' ? 'Please select an option.' : 'This field is required.';
            }

            // Plus-addressing (name+tag@example.com) must keep working, so the
            // local part is deliberately permissive about '+'.
            if (type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value)) {
                return 'Enter a valid email address.';
            }
            if (type === 'ssn' && value.replace(/\D/g, '').length !== 9) return 'SSN must be 9 digits.';
            if (type === 'signature' && value.length < 3) return 'Type your full legal name (at least 3 characters).';
            if (type === 'phone' && !isValidUsPhone(value)) return 'Enter a valid 10-digit US phone number.';
            if (type === 'score' && !isValidCreditScore(value)) {
                return `Credit score must be a whole number from ${CREDIT_SCORE.min} to ${CREDIT_SCORE.max}.`;
            }
            if (type === 'postal' && !new RegExp(`^\\d{${POSTAL_LENGTH}}$`).test(value)) {
                return `ZIP code must be ${POSTAL_LENGTH} digits.`;
            }

            if (el.type === 'number') {
                const n = Number(value);
                if (!Number.isFinite(n)) return 'Enter a valid number.';
                if (el.min !== '' && n < Number(el.min)) return `Must be at least ${el.min}.`;
                if (el.max !== '' && n > Number(el.max)) return `Must be no more than ${el.max}.`;
            }

            return '';
        }

        function fieldIsValid(el) {
            return fieldError(el) === '';
        }

        /** Check one field and draw (or clear) its message. */
        function validateField(el) {
            if (!isVisible(el)) {
                clearInvalid(el);
                return true;
            }

            const message = fieldError(el);
            if (message) markInvalid(el, message);
            else clearInvalid(el);
            return !message;
        }

        /** A Yes/No pair has no single element to hang the state off. */
        function validateGroup(group) {
            const answered = !!group.querySelector('input[type="radio"]:checked');
            group.classList.toggle('is-invalid', !answered);
            const slot = errorSlot(group, !answered);
            if (slot) slot.textContent = answered ? '' : 'Please choose Yes or No.';
            return answered;
        }

        /**
         * Same NANP rules the server applies: ten digits, and neither the area code
         * nor the exchange starting with 0 or 1. Checked here only so the applicant
         * is told before submitting - the server decides.
         */
        function isValidUsPhone(value) {
            let digits = String(value || '').replace(/\D/g, '');
            if (digits.length === 11 && digits.startsWith('1')) digits = digits.slice(1);
            if (digits.length !== 10) return false;
            return !'01'.includes(digits[0]) && !'01'.includes(digits[3]);
        }

        function isValidCreditScore(value) {
            const score = Number(String(value).trim());
            return Number.isInteger(score) && score >= CREDIT_SCORE.min && score <= CREDIT_SCORE.max;
        }

        function creditScoreBand(value) {
            const score = Number(String(value).trim());
            if (!Number.isFinite(score)) return null;
            return CREDIT_SCORE.bands.find(b => score >= Number(b.min) && score <= Number(b.max)) || null;
        }

        /**
         * Validate one step.
         *
         * Everything marked data-req is checked generically, so a field added to the
         * markup is validated without touching this function.
         */
        function validateStep(step) {
            const tab = document.getElementById(`tab-${step}`);
            if (!tab) return true;

            let firstInvalid = null;

            tab.querySelectorAll(VALIDATABLE).forEach(el => {
                if (!validateField(el)) firstInvalid = firstInvalid || el;
            });

            tab.querySelectorAll('[data-req-group]').forEach(group => {
                if (!validateGroup(group)) firstInvalid = firstInvalid || group;
            });

            if (firstInvalid) {
                errorAnchor(firstInvalid).scrollIntoView({ behavior: 'smooth', block: 'center' });
                if (firstInvalid.matches('input:not([type="hidden"]):not([type="checkbox"]), textarea')) {
                    firstInvalid.focus({ preventScroll: true });
                }
            }

            return !firstInvalid;
        }

        /*
         * Real-time validation. A field is first judged when the applicant leaves
         * it, not on the first keystroke - nobody wants "invalid email" after
         * typing one letter. From then on (or once Continue has flagged it) it is
         * re-checked on every keystroke, so the message goes away the moment the
         * value is fixed and comes back if it is broken again.
         */
        function isValidatable(el) {
            return el instanceof Element && el.matches(VALIDATABLE);
        }

        document.addEventListener('input', function (e) {
            const el = e.target;
            if (!isValidatable(el)) return;
            el.dataset.dirty = '1';
            if (el.dataset.visited || el.classList.contains('is-invalid')) validateField(el);
        });

        document.addEventListener('focusout', function (e) {
            const el = e.target;
            if (!isValidatable(el) || el.type === 'checkbox') return;
            // Tabbing past an untouched field is not an error yet; Continue catches it.
            if (!el.dataset.dirty) return;
            el.dataset.visited = '1';
            validateField(el);
        });

        document.addEventListener('change', function (e) {
            const el = e.target;
            if (el.matches('input[type="checkbox"][data-type="consent"]')) {
                if (el.checked) consentTimestamps[el.name] = new Date().toISOString();
                else delete consentTimestamps[el.name];
                validateField(el);
            }
            if (el.matches('input[type="radio"]')) {
                const group = el.closest('[data-req-group]');
                if (group) validateGroup(group);
            }
            // Plain (non-select2) selects, e.g. a co-borrower's role.
            if (el.tagName === 'SELECT' && isValidatable(el)
                && !el.classList.contains('select2-hidden-accessible')) {
                validateField(el);
            }
        });

        /* ─────────────────────── Conditional field logic ─────────────────── */

        function toggleDebtBalance(show) {
            document.getElementById('debtBalanceWrap').style.display = show ? 'block' : 'none';
            if (!show) {
                const balance = document.getElementById('debt_balance');
                balance.value = '';
                clearInvalid(balance);
            }
        }

        function complianceHasYes() {
            return Array.from(
                document.querySelectorAll('#tab-7 input[type="radio"][value="1"]')
            ).some(radio => radio.checked);
        }

        function syncComplianceExplanation() {
            const show = complianceHasYes();
            document.getElementById('complianceExplanationWrap').style.display = show ? 'block' : 'none';
            if (!show) {
                const explanation = document.getElementById('federal_compliance_disclosures_explanation');
                explanation.value = '';
                clearInvalid(explanation);
            }
        }

        $(document).on('change', '#referral_source', function () {
            const isOther = this.value === REFERRAL_OTHER;
            document.getElementById('referralOtherWrap').style.display = isOther ? 'block' : 'none';
            if (!isOther) {
                const other = document.getElementById('referral_source_other');
                other.value = '';
                clearInvalid(other);
            }
        });

        // Live band label, straight off the server's table.
        document.addEventListener('input', function (e) {
            if (e.target.id !== 'credit_score') return;
            const hint = document.getElementById('creditScoreBandHint');
            const band = isValidCreditScore(e.target.value) ? creditScoreBand(e.target.value) : null;
            hint.textContent = band ? `${e.target.value} - ${band.label}` : '';
            hint.className = `form-text fs-12 ${band ? 'text-' + (band.tone || 'muted') : 'text-muted'}`;
        });

        $(document).on('change', '#purpose_of_funds', function () {
            const isOther = this.value === PURPOSE_OTHER;
            document.getElementById('purposeNoteWrap').style.display = isOther ? 'block' : 'none';
            if (!isOther) {
                const note = document.getElementById('purpose_note');
                note.value = '';
                clearInvalid(note);
            }
        });

        /* ───────────────────────────  Co-borrowers ───────────────────────── */

        function addCoBorrower() {
            const index = coBorrowerIndex++;
            const wrapper = document.createElement('div');
            wrapper.className = 'co-borrower-card';
            wrapper.dataset.index = index;

            const roleOptions = CO_BORROWER_ROLES
                .map(role => `<option value="${role}">${role}</option>`)
                .join('');

            wrapper.innerHTML = `
            <div class="co-borrower-card__head">
                <h6><i class="bi bi-person-plus me-1"></i> Co-Borrower ${index + 1}</h6>
                <button type="button" class="btn btn-sm btn-link text-danger p-0 text-decoration-none fw-semibold"
                        onclick="removeCoBorrower(${index})">
                    <i class="bi bi-trash3 me-1"></i>Remove
                </button>
            </div>
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label fw-semibold small mb-1">First Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" data-co-req name="co_borrowers[${index}][first_name]">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small mb-1">Last Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" data-co-req name="co_borrowers[${index}][last_name]">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small mb-1">Email <span class="text-danger">*</span></label>
                    <input type="email" class="form-control form-control-sm" data-co-req data-type="email" name="co_borrowers[${index}][email]">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small mb-1">Title / Role</label>
                    <select class="form-select form-select-sm" name="co_borrowers[${index}][title_role]">
                        <option value=""></option>
                        ${roleOptions}
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small mb-1">Ownership %</label>
                    <input type="number" class="form-control form-control-sm" min="0" max="100" step="0.01" name="co_borrowers[${index}][ownership_percentage]">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small mb-1">Mobile Phone</label>
                    <input type="tel" class="form-control form-control-sm" name="co_borrowers[${index}][mobile_phone]">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small mb-1">Home Phone</label>
                    <input type="tel" class="form-control form-control-sm" name="co_borrowers[${index}][home_phone]">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small mb-1">Date of Birth</label>
                    <input type="text" class="form-control form-control-sm" data-datepicker="dob" placeholder="YYYY-MM-DD" autocomplete="off" name="co_borrowers[${index}][date_of_birth]">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small mb-1">SSN</label>
                    <input type="text" class="form-control form-control-sm co-ssn" maxlength="11" placeholder="XXX-XX-XXXX" name="co_borrowers[${index}][ssn]">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold small mb-1">Street Address</label>
                    <input type="text" class="form-control form-control-sm" name="co_borrowers[${index}][street_address]">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small mb-1">City</label>
                    <input type="text" class="form-control form-control-sm" name="co_borrowers[${index}][city]">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small mb-1">State</label>
                    <input type="text" class="form-control form-control-sm" name="co_borrowers[${index}][state]">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small mb-1">ZIP Code</label>
                    <input type="text" class="form-control form-control-sm co-zip" inputmode="numeric" maxlength="${POSTAL_LENGTH}" name="co_borrowers[${index}][zip_code]">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small mb-1">Country</label>
                    <input type="text" class="form-control form-control-sm" name="co_borrowers[${index}][country]">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small mb-1">Driver's License Number</label>
                    <input type="text" class="form-control form-control-sm" name="co_borrowers[${index}][drivers_license_number]">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small mb-1">Driver's License State</label>
                    <input type="text" class="form-control form-control-sm" name="co_borrowers[${index}][drivers_license_state]">
                </div>
            </div>
        `;

            document.getElementById('coBorrowerList').appendChild(wrapper);
            initDatePickers(wrapper);
            syncCoBorrowerFlag();
        }

        function removeCoBorrower(index) {
            document.querySelector(`.co-borrower-card[data-index="${index}"]`)?.remove();
            syncCoBorrowerFlag();
        }

        function syncCoBorrowerFlag() {
            const count = document.querySelectorAll('.co-borrower-card').length;
            document.getElementById('has_co_borrower').value = count > 0 ? '1' : '0';
        }

        /* ────────────────────────────  SSN masking ───────────────────────── */

        function maskSsn(value) {
            const digits = value.replace(/\D/g, '').slice(0, 9);
            if (digits.length > 5) return `${digits.slice(0, 3)}-${digits.slice(3, 5)}-${digits.slice(5)}`;
            if (digits.length > 3) return `${digits.slice(0, 3)}-${digits.slice(3)}`;
            return digits;
        }

        document.addEventListener('input', function (e) {
            if (e.target.id === 'ssn' || e.target.classList.contains('co-ssn')) {
                e.target.value = maskSsn(e.target.value);
            }
            if (e.target.type === 'tel') {
                e.target.value = maskPhone(e.target.value);
            }
            if (e.target.dataset.type === 'postal' || e.target.classList.contains('co-zip')) {
                // Digits only - the server takes exactly five, and letting letters
                // through only to reject them on submit helps nobody.
                e.target.value = e.target.value.replace(/\D/g, '').slice(0, POSTAL_LENGTH);
            }
        });

        /**
         * Format as (XXX) XXX-XXXX while typing. The server stores E.164; this is
         * only how the number is shown back to the person entering it.
         */
        function maskPhone(value) {
            const digits = String(value || '').replace(/\D/g, '').slice(0, 10);
            if (digits.length > 6) return `(${digits.slice(0, 3)}) ${digits.slice(3, 6)}-${digits.slice(6)}`;
            if (digits.length > 3) return `(${digits.slice(0, 3)}) ${digits.slice(3)}`;
            if (digits.length > 0) return `(${digits}`;
            return '';
        }

        /* ───────────────────────────  File uploads ───────────────────────── */

        function dragOver(e, key) {
            e.preventDefault();
            document.getElementById(`zone-${key}`).classList.add('drag-over');
        }

        function dragLeave(key) {
            document.getElementById(`zone-${key}`).classList.remove('drag-over');
        }

        function dropFiles(e, key) {
            e.preventDefault();
            document.getElementById(`zone-${key}`).classList.remove('drag-over');
            Array.from(e.dataTransfer.files).forEach(f => files[key].items.add(f));
            renderFileList(key);
        }

        function fileInputChanged(e, key) {
            Array.from(e.target.files).forEach(f => files[key].items.add(f));
            // Clearing the input keeps the DataTransfer the single source of truth,
            // so re-picking the same file still registers.
            e.target.value = '';
            renderFileList(key);
        }

        function removeFile(key, index) {
            const dt = new DataTransfer();
            Array.from(files[key].files).forEach((f, i) => {
                if (i !== index) dt.items.add(f);
            });
            files[key] = dt;
            renderFileList(key);
        }

        function renderFileList(key) {
            const list = document.getElementById(`list-${key}`);
            list.innerHTML = '';
            Array.from(files[key].files).forEach((f, i) => {
                const chip = document.createElement('span');
                chip.className = 'file-chip';
                chip.innerHTML = `<i class="bi bi-file-earmark me-1"></i> ${escapeHtml(f.name)} <button type="button" onclick="removeFile('${key}', ${i})">&times;</button>`;
                list.appendChild(chip);
            });
        }

        function totalFileCount() {
            return Object.values(files).reduce((sum, dt) => sum + dt.files.length, 0);
        }

        /* ────────────────────────────  Review step ───────────────────────── */

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            })[c]);
        }

        function val(id) {
            const el = document.getElementById(id);
            const value = el?.value?.trim();
            return value ? value : null;
        }

        function money(id) {
            const raw = val(id);
            return raw ? '$' + Number(raw).toLocaleString() : null;
        }

        // The visible label of a select, for the review summary - an id means
        // nothing to the person checking their own answers.
        function selectedText(id) {
            const el = document.getElementById(id);
            if (!el || !el.value) return null;
            return el.options[el.selectedIndex]?.text?.trim() || null;
        }

        function yesNo(name) {
            const checked = document.querySelector(`input[name="${name}"]:checked`);
            if (!checked) return null;
            return checked.value === '1' ? 'Yes' : 'No';
        }

        function renderReviewSummary() {
            const groups = [
                ['Business', [
                    ['Business Name', val('business_name')],
                    ['Contact', val('full_name')],
                    ['Business Email', val('business_email')],
                    ['Business Phone', [val('business_country_code'), val('business_phone_no')].filter(Boolean).join(' ') || null],
                    ['Address', [val('business_address'), val('business_city'), val('business_state'), val('business_postal_code')].filter(Boolean).join(', ') || null],
                    ['Industry', val('industry_type')],
                    ['Entity Type', val('entity_type')],
                    ['Years in Business', val('years_in_business')],
                    ['Employees', val('number_of_employees')],
                ]],
                ['Ownership', [
                    ['Owner', [val('first_name'), val('last_name')].filter(Boolean).join(' ') || null],
                    ['Email', val('email')],
                    ['Phone', val('phone')],
                    ['Date of Birth', val('date_of_birth')],
                    ['Ownership', val('ownership') ? val('ownership') + '%' : null],
                    ['Citizenship', val('citizenship_status')],
                    ['Home Address', [val('street_address'), val('city'), val('state'), val('zip_code')].filter(Boolean).join(', ') || null],
                    ['Co-Borrowers', String(document.querySelectorAll('.co-borrower-card').length)],
                ]],
                ['Financial', [
                    ['Monthly Revenue', money('monthly_revenue')],
                    ['Annual Revenue', money('annual_revenue')],
                    ['Bank', val('business_bank_name')],
                    ['Checking Opened', val('business_checking_start_date')],
                    ['Estimated Credit Score', (() => {
                        const score = val('credit_score');
                        if (!score) return null;
                        const band = creditScoreBand(score);
                        return band ? `${score} - ${band.label} (self-reported)` : `${score} (self-reported)`;
                    })()],
                    ['Business Credit', val('self_reported_business_credit_score')],
                    ['Existing Debt', yesNo('has_business_debt')],
                    ['Debt Balance', money('debt_balance')],
                ]],
                ['Funding Request', [
                    ['Requested Amount', money('requested_amount')],
                    ['Funding Type', val('preferred_funding_type')],
                    ['Purpose', val('purpose_of_funds')],
                    ['Purpose Note', val('purpose_note')],
                    ['Timeframe', val('timeframe_needed')],
                    ['Collateral', val('collateral_available')],
                ]],
                ['Documents & Disclosures', [
                    ['Files Uploaded', String(totalFileCount())],
                    ['Credit Pull Authorized', document.getElementById('credit_pull_authorization').checked ? 'Yes' : 'No'],
                    ['Terms Accepted', document.getElementById('terms_conditions_acceptance').checked ? 'Yes' : 'No'],
                    ['Compliance Disclosures', complianceHasYes() ? 'One or more answered Yes' : 'All answered No'],
                ]],
            ];

            document.getElementById('reviewDetailsGrid').innerHTML = groups.map(([title, rows]) => {
                const cells = rows
                    .filter(([, value]) => value !== null && value !== '')
                    .map(([label, value]) => `
                    <div class="col-md-6 mb-2">
                        <span class="text-muted d-block fw-medium small">${escapeHtml(label)}:</span>
                        <span class="fw-semibold text-dark small">${escapeHtml(value)}</span>
                    </div>
                `).join('');

                return `
                <div class="review-group">
                    <div class="review-group__title">${escapeHtml(title)}</div>
                    <div class="row g-2">${cells || '<div class="col-12 text-muted small">Not provided</div>'}</div>
                </div>
            `;
            }).join('');
        }

        /* ──────────────────────────────  Submit ──────────────────────────── */

        document.getElementById('loanAppForm').addEventListener('submit', async function (e) {
            e.preventDefault();

            if (!validateStep(TOTAL_STEPS)) return;

            const submitBtn = document.getElementById('btnSubmit');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Submitting...';
            document.getElementById('errorScreen').style.display = 'none';

            document.getElementById('consents_data').value = JSON.stringify(consentTimestamps);

            const formData = buildFormData(true);

            try {
                const res = await fetch(`${API_BASE}/v1/embed/submit/${PUBLIC_KEY}`, {
                    method: 'POST',
                    body: formData,
                    headers: { 'Accept': 'application/json' }
                });

                const json = await res.json();

                if (res.ok && json.status) {
                    document.getElementById('loanAppForm').style.display = 'none';
                    document.querySelector('.stepper-wrapper').style.display = 'none';
                    document.getElementById('successScreen').style.display = 'block';
                    if (json.data?.submission_uid) {
                        document.getElementById('successRefBadge').textContent = 'Reference ID: ' + json.data.submission_uid;
                    }
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                } else {
                    showSubmitErrors(json);
                }
            } catch (err) {
                document.getElementById('errorMessageText').textContent = 'Network error. Please check your connection and try again.';
                document.getElementById('errorScreen').style.display = 'block';
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-send-check me-1"></i> Submit Application';
            }
        });

        /**
         * Surface server-side errors and jump back to the step that owns the first
         * one - otherwise an applicant sits on the signature step reading about a
         * business field seven steps back.
         */
        function showSubmitErrors(json) {
            let message = json.message || 'There was a problem submitting your application.';

            if (json.errors) {
                message = Object.values(json.errors).flat().join('<br>');

                let jumpTo = null;
                Object.keys(json.errors).forEach(key => {
                    const el = document.getElementById(key.split('.')[0]);
                    if (!el) return;
                    markInvalid(el, [].concat(json.errors[key])[0]);
                    const tab = el.closest('.tab-step');
                    if (tab) {
                        const step = Number(tab.id.replace('tab-', ''));
                        jumpTo = jumpTo === null ? step : Math.min(jumpTo, step);
                    }
                });

                if (jumpTo !== null && jumpTo !== currentStep) {
                    currentStep = jumpTo;
                    showStep(currentStep);
                }
            }

            document.getElementById('errorMessageText').innerHTML = message;
            document.getElementById('errorScreen').style.display = 'block';
        }
    </script>

</body>

</html>