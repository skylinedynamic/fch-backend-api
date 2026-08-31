<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $config->form_title }} &mdash; {{ $partnerName }}</title>
    <meta name="description" content="Apply for a loan with {{ $partnerName }}. Fast, secure, and easy online application.">

    @if(!empty($faviconUrl))
        <link rel="icon" type="image/x-icon" href="{{ $faviconUrl }}">
    @endif

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    {{-- Bootstrap 5.3 CDN & Icons --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    {{-- Select2 CSS --}}
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <style>
        :root {
            --brand-primary: {{ $primaryColor ?? '#0d6efd' }};
            --brand-secondary: {{ $secondaryColor ?? '#198754' }};
            --bs-primary: var(--brand-primary);
            --bs-primary-rgb: {{ hexdec(substr($primaryColor ?? '#0d6efd', 1, 2)) }}, {{ hexdec(substr($primaryColor ?? '#0d6efd', 3, 2)) }}, {{ hexdec(substr($primaryColor ?? '#0d6efd', 5, 2)) }};
        }

        * {
            border-radius: 4px;
            box-sizing: border-box;
        }

        html, body {
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

        .input-group > .form-control {
            border-top-left-radius: 0 !important;
            border-bottom-left-radius: 0 !important;
        }

        .input-group > .input-group-text {
            border-top-right-radius: 0 !important;
            border-bottom-right-radius: 0 !important;
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
        select.is-invalid + .select2 .select2-selection--single {
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
            box-shadow: 0 4px 16px rgba(0,0,0,0.08) !important;
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
    </style>
</head>
<body>

<div class="container py-4" style="max-width: 760px;">

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
            Powered by <strong>{{ $partnerName }}</strong> &bull; <span class="badge bg-light text-secondary border badge-verified"><i class="bi bi-shield-check text-success me-1"></i>Secure Application</span>
        </p>
    </div>

    {{-- Main Form Card --}}
    <div class="card card-custom p-4 p-md-5">

        {{-- Stepper Progress with Clean Track (No Overlapping Line) --}}
        <div class="stepper-wrapper mb-4">
            <div class="stepper-track">
                <div class="stepper-track-progress" id="stepperTrackProgress" style="width: 0%;"></div>
            </div>
            <div class="stepper-item active" id="step-node-1">
                <div class="step-counter">1</div>
                <div class="step-name d-none d-sm-block">Personal</div>
            </div>
            <div class="stepper-item" id="step-node-2">
                <div class="step-counter">2</div>
                <div class="step-name d-none d-sm-block">Living</div>
            </div>
            <div class="stepper-item" id="step-node-3">
                <div class="step-counter">3</div>
                <div class="step-name d-none d-sm-block">Employment</div>
            </div>
            <div class="stepper-item" id="step-node-4">
                <div class="step-counter">4</div>
                <div class="step-name d-none d-sm-block">Documents</div>
            </div>
            <div class="stepper-item" id="step-node-5">
                <div class="step-counter">5</div>
                <div class="step-name d-none d-sm-block">Review</div>
            </div>
        </div>

        {{-- Application Form --}}
        <form id="loanAppForm" novalidate enctype="multipart/form-data">
            @csrf
            <input type="text" name="_hp_company" class="honeypot" tabindex="-1" autocomplete="off">

            {{-- STEP 1: Personal Information --}}
            <div class="tab-step active" id="tab-1">
                <h5 class="fw-bold mb-3 pb-2 border-bottom text-primary">
                    <i class="bi bi-person-circle me-2"></i>Personal Information
                </h5>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">First Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="first_name" id="first_name" placeholder="e.g. John" required>
                        <div class="invalid-feedback">First name is required.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">Last Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="last_name" id="last_name" placeholder="e.g. Doe" required>
                        <div class="invalid-feedback">Last name is required.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">Email Address <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                            <input type="email" class="form-control" name="email" id="email" placeholder="john@example.com" required>
                        </div>
                        <div class="invalid-feedback" id="email-feedback">Valid email is required.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">Phone Number</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                            <input type="tel" class="form-control" name="phone" id="phone" placeholder="(555) 000-0000">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">Date of Birth</label>
                        <input type="date" class="form-control" name="birthday" id="birthday">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">Social Security Number (SSN)</label>
                        <input type="text" class="form-control" name="ssn" id="ssn" placeholder="XXX-XX-XXXX" maxlength="11">
                        <div class="form-text small text-muted"><i class="bi bi-lock me-1"></i>Encrypted with 256-bit AES</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">Estimated Credit Score</label>
                        <input type="number" class="form-control" name="credit_score" id="credit_score" placeholder="e.g. 720" min="300" max="850">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">Desired Funding Amount ($)</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" name="desired_funding_amount" id="desired_funding_amount" placeholder="e.g. 25000" min="0">
                        </div>
                    </div>
                </div>
            </div>

            {{-- STEP 2: Living Information --}}
            <div class="tab-step" id="tab-2">
                <h5 class="fw-bold mb-3 pb-2 border-bottom text-primary">
                    <i class="bi bi-house-door me-2"></i>Living Information
                </h5>

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold small mb-1">Home Address</label>
                        <input type="text" class="form-control" name="home_address" id="home_address" placeholder="123 Main Street, Apt 4B">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold small mb-1">City</label>
                        <input type="text" class="form-control" name="city" id="city" placeholder="New York">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small mb-1">State</label>
                        <input type="text" class="form-control" name="state" id="state" placeholder="NY">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small mb-1">Postal / Zip Code</label>
                        <input type="text" class="form-control" name="postal_code" id="postal_code" placeholder="10001">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">Housing Status</label>
                        <select class="form-select select2" name="housing_status" id="housing_status" data-placeholder="Select housing status...">
                            <option value=""></option>
                            <option value="Rent">Rent</option>
                            <option value="Own">Own</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">Monthly Housing Payment ($)</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" name="monthly_housing_payment" id="monthly_housing_payment" placeholder="e.g. 1500" min="0">
                        </div>
                    </div>
                </div>
            </div>

            {{-- STEP 3: Employment Information --}}
            <div class="tab-step" id="tab-3">
                <h5 class="fw-bold mb-3 pb-2 border-bottom text-primary">
                    <i class="bi bi-briefcase me-2"></i>Employment Information
                </h5>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">Employer / Company Name</label>
                        <input type="text" class="form-control" name="employer_name" id="employer_name" placeholder="Acme Corporation">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">Employer Phone</label>
                        <input type="tel" class="form-control" name="employer_phone" id="employer_phone" placeholder="(555) 000-0000">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small mb-1">Employer Address</label>
                        <input type="text" class="form-control" name="employer_address" id="employer_address" placeholder="456 Corporate Plaza">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">Job Title / Occupation</label>
                        <input type="text" class="form-control" name="occupation" id="occupation" placeholder="Software Engineer">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">Estimated Annual Income ($)</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" name="annual_income" id="annual_income" placeholder="e.g. 75000" min="0">
                        </div>
                    </div>
                </div>
            </div>

            {{-- STEP 4: Documents Upload --}}
            <div class="tab-step" id="tab-4">
                <h5 class="fw-bold mb-3 pb-2 border-bottom text-primary">
                    <i class="bi bi-file-earmark-arrow-up me-2"></i>Upload Documents
                </h5>

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold small mb-1">Recent Paystub <small class="text-muted">(Last 30 Days)</small></label>
                        <div class="upload-dropzone" id="zone-paystub" ondragover="dragOver(event, 'paystub')" ondragleave="dragLeave('paystub')" ondrop="dropFiles(event, 'paystub')">
                            <input type="file" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.jpg,.jpeg,.png" onchange="fileInputChanged(event, 'paystub')">
                            <i class="bi bi-cloud-arrow-up fs-2 text-primary"></i>
                            <div class="fw-semibold text-dark mt-1">Click or drag paystubs here</div>
                            <div class="text-muted small">PDF, DOC, XLS, PNG, JPG up to 10MB</div>
                        </div>
                        <div id="list-paystub" class="mt-2"></div>
                    </div>

                    <div class="col-12 mt-3">
                        <label class="form-label fw-semibold small mb-1">Credit Report <small class="text-muted">(Experian / TransUnion / Equifax)</small></label>
                        <div class="upload-dropzone" id="zone-credit" ondragover="dragOver(event, 'credit')" ondragleave="dragLeave('credit')" ondrop="dropFiles(event, 'credit')">
                            <input type="file" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.jpg,.jpeg,.png" onchange="fileInputChanged(event, 'credit')">
                            <i class="bi bi-file-earmark-bar-graph fs-2 text-primary"></i>
                            <div class="fw-semibold text-dark mt-1">Click or drag credit report files here</div>
                            <div class="text-muted small">PDF, DOC, XLS, PNG, JPG up to 10MB</div>
                        </div>
                        <div id="list-credit" class="mt-2"></div>
                    </div>
                </div>
            </div>

            {{-- STEP 5: Review & Submit --}}
            <div class="tab-step" id="tab-5">
                <h5 class="fw-bold mb-3 pb-2 border-bottom text-primary">
                    <i class="bi bi-check2-circle me-2"></i>Review & Submit
                </h5>

                <div class="card bg-light border-0 p-3 mb-4" id="reviewSummaryCard">
                    <div class="row g-2 small" id="reviewDetailsGrid">
                        {{-- Injected dynamically --}}
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">Sales Representative (if assigned)</label>
                        <input type="text" class="form-control form-control-sm" name="sales_rep" id="sales_rep" placeholder="Sales Rep Name">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">Referral Source</label>
                        <input type="text" class="form-control form-control-sm" name="referral_source" id="referral_source" placeholder="How did you hear about us?">
                    </div>
                </div>

                <div class="alert alert-info d-flex align-items-center small py-2" role="alert">
                    <i class="bi bi-shield-lock-fill me-2 fs-5"></i>
                    <div>
                        By clicking <strong>Submit Application</strong>, you certify that all information submitted is accurate and you authorize <strong>{{ $partnerName }}</strong> to review your request.
                    </div>
                </div>
            </div>

            {{-- Navigation Buttons --}}
            <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                <button type="button" class="btn btn-outline-secondary px-4" id="btnPrev" onclick="navigateStep(-1)" style="display: none;">
                    <i class="bi bi-arrow-left me-1"></i> Back
                </button>
                <div class="ms-auto d-flex gap-2">
                    <button type="button" class="btn btn-primary px-4 fw-semibold" id="btnNext" onclick="navigateStep(1)">
                        Continue <i class="bi bi-arrow-right ms-1"></i>
                    </button>
                    <button type="submit" class="btn btn-success px-4 fw-bold" id="btnSubmit" style="display: none;">
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
                Thank you for applying with <strong>{{ $partnerName }}</strong>. Our team has received your application and will contact you shortly.
            </p>
            <div class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 fs-6 mb-3" id="successRefBadge">
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
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

{{-- Select2 JS --}}
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    const TOTAL_STEPS = 5;
    let currentStep = 1;
    const files = {
        paystub: new DataTransfer(),
        credit: new DataTransfer()
    };

    const API_BASE = '{{ $apiBase }}';
    const PUBLIC_KEY = '{{ $publicKey }}';

    // Initialize Select2 on dropdowns
    $(document).ready(function() {
        $('.select2').select2({
            minimumResultsForSearch: 10,
            width: '100%',
            dropdownAutoWidth: false
        });

        // Clear validation on select2 change
        $('.select2').on('change', function() {
            $(this).removeClass('is-invalid');
            $(this).next('.select2-container').removeClass('is-invalid');
        });
    });

    function showStep(step) {
        document.querySelectorAll('.tab-step').forEach(tab => tab.classList.remove('active'));
        document.getElementById(`tab-${step}`)?.classList.add('active');

        // Update Stepper track progress
        const pct = ((step - 1) / (TOTAL_STEPS - 1)) * 100;
        const progressBar = document.getElementById('stepperTrackProgress');
        if (progressBar) progressBar.style.width = pct + '%';

        // Update Stepper nodes
        for (let i = 1; i <= TOTAL_STEPS; i++) {
            const node = document.getElementById(`step-node-${i}`);
            if (node) {
                node.classList.remove('active', 'completed');
                if (i < step) node.classList.add('completed');
                else if (i === step) node.classList.add('active');
            }
        }

        // Update Buttons
        document.getElementById('btnPrev').style.display = step > 1 ? 'inline-flex' : 'none';
        document.getElementById('btnNext').style.display = step < TOTAL_STEPS ? 'inline-flex' : 'none';
        document.getElementById('btnSubmit').style.display = step === TOTAL_STEPS ? 'inline-flex' : 'none';

        if (step === TOTAL_STEPS) {
            renderReviewSummary();
        }
    }

    function navigateStep(dir) {
        if (dir === 1) {
            if (!validateCurrentStep(currentStep)) return;
            if (currentStep < TOTAL_STEPS) {
                currentStep++;
                showStep(currentStep);
            }
        } else if (dir === -1) {
            if (currentStep > 1) {
                currentStep--;
                showStep(currentStep);
            }
        }
    }

    function validateCurrentStep(step) {
        if (step === 1) {
            const fn = document.getElementById('first_name');
            const ln = document.getElementById('last_name');
            const em = document.getElementById('email');

            function markInvalid(el) {
                el.classList.add('is-invalid');
                const grp = el.closest('.input-group');
                if (grp) grp.classList.add('is-invalid');
            }
            function markValid(el) {
                el.classList.remove('is-invalid');
                const grp = el.closest('.input-group');
                if (grp) grp.classList.remove('is-invalid');
            }

            let valid = true;
            if (!fn.value.trim()) { markInvalid(fn); valid = false; } else { markValid(fn); }
            if (!ln.value.trim()) { markInvalid(ln); valid = false; } else { markValid(ln); }

            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!em.value.trim() || !emailRegex.test(em.value.trim())) {
                markInvalid(em);
                valid = false;
            } else {
                markValid(em);
            }
            return valid;
        }
        return true;
    }

    // Input blur / input listener to clear validation
    document.querySelectorAll('.form-control, .form-select').forEach(input => {
        input.addEventListener('input', function() {
            if (this.value.trim()) {
                this.classList.remove('is-invalid');
                const grp = this.closest('.input-group');
                if (grp) grp.classList.remove('is-invalid');
            }
        });
    });

    // SSN Formatter
    document.getElementById('ssn').addEventListener('input', function (e) {
        let val = e.target.value.replace(/\D/g, '');
        if (val.length > 5) {
            val = val.slice(0, 3) + '-' + val.slice(3, 5) + '-' + val.slice(5, 9);
        } else if (val.length > 3) {
            val = val.slice(0, 3) + '-' + val.slice(3);
        }
        e.target.value = val;
    });

    // File Drag & Drop
    function dragOver(e, type) {
        e.preventDefault();
        document.getElementById(`zone-${type}`).classList.add('drag-over');
    }
    function dragLeave(type) {
        document.getElementById(`zone-${type}`).classList.remove('drag-over');
    }
    function dropFiles(e, type) {
        e.preventDefault();
        document.getElementById(`zone-${type}`).classList.remove('drag-over');
        Array.from(e.dataTransfer.files).forEach(f => files[type].items.add(f));
        renderFileList(type);
    }
    function fileInputChanged(e, type) {
        Array.from(e.target.files).forEach(f => files[type].items.add(f));
        renderFileList(type);
    }
    function removeFile(type, index) {
        const dt = new DataTransfer();
        Array.from(files[type].files).forEach((f, i) => {
            if (i !== index) dt.items.add(f);
        });
        files[type] = dt;
        renderFileList(type);
    }
    function renderFileList(type) {
        const list = document.getElementById(`list-${type}`);
        list.innerHTML = '';
        Array.from(files[type].files).forEach((f, i) => {
            const chip = document.createElement('span');
            chip.className = 'file-chip';
            chip.innerHTML = `<i class="bi bi-file-earmark me-1"></i> ${f.name} <button type="button" onclick="removeFile('${type}', ${i})">&times;</button>`;
            list.appendChild(chip);
        });
    }

    function renderReviewSummary() {
        const getVal = id => document.getElementById(id)?.value?.trim() || '&mdash;';
        const grid = document.getElementById('reviewDetailsGrid');

        const fields = [
            { label: 'Applicant Name', val: `${getVal('first_name')} ${getVal('last_name')}` },
            { label: 'Email', val: getVal('email') },
            { label: 'Phone', val: getVal('phone') },
            { label: 'Date of Birth', val: getVal('birthday') },
            { label: 'Desired Amount', val: getVal('desired_funding_amount') !== '&mdash;' ? '$' + Number(getVal('desired_funding_amount')).toLocaleString() : '&mdash;' },
            { label: 'Credit Score', val: getVal('credit_score') },
            { label: 'Address', val: `${getVal('home_address')}, ${getVal('city')} ${getVal('state')} ${getVal('postal_code')}` },
            { label: 'Housing', val: `${getVal('housing_status')} ($${getVal('monthly_housing_payment')}/mo)` },
            { label: 'Employer', val: getVal('employer_name') },
            { label: 'Occupation', val: getVal('occupation') },
            { label: 'Annual Income', val: getVal('annual_income') !== '&mdash;' ? '$' + Number(getVal('annual_income')).toLocaleString() : '&mdash;' },
            { label: 'Uploaded Documents', val: `${files.paystub.files.length} paystub(s), ${files.credit.files.length} credit report(s)` }
        ];

        grid.innerHTML = fields.map(f => `
            <div class="col-md-6 mb-2">
                <span class="text-muted d-block fw-medium">${f.label}:</span>
                <span class="fw-semibold text-dark">${f.val}</span>
            </div>
        `).join('');
    }

    // Submit handler
    document.getElementById('loanAppForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const submitBtn = document.getElementById('btnSubmit');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Submitting...';
        document.getElementById('errorScreen').style.display = 'none';

        const formData = new FormData(this);

        // Append custom files
        Array.from(files.paystub.files).forEach(f => formData.append('paystub_document[]', f));
        Array.from(files.credit.files).forEach(f => formData.append('credit_report_document[]', f));

        try {
            const res = await fetch(`${API_BASE}/v1/embed/submit/${PUBLIC_KEY}`, {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json'
                }
            });

            const json = await res.json();

            if (res.ok && json.status) {
                document.getElementById('loanAppForm').style.display = 'none';
                document.querySelector('.stepper-wrapper').style.display = 'none';
                document.getElementById('successScreen').style.display = 'block';
                if (json.data?.submission_uid) {
                    document.getElementById('successRefBadge').textContent = 'Reference ID: ' + json.data.submission_uid;
                }
            } else {
                let err = json.message || 'There was a problem submitting your application.';
                if (json.errors) {
                    err = Object.values(json.errors).flat().join('<br>');
                    // Highlight invalid fields if mapped
                    Object.keys(json.errors).forEach(key => {
                        const el = document.getElementById(key);
                        if (el) el.classList.add('is-invalid');
                    });
                }
                document.getElementById('errorMessageText').innerHTML = err;
                document.getElementById('errorScreen').style.display = 'block';
            }
        } catch (err) {
            document.getElementById('errorMessageText').textContent = 'Network error. Please check your connection and try again.';
            document.getElementById('errorScreen').style.display = 'block';
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-send-check me-1"></i> Submit Application';
        }
    });

    // Initialize
    showStep(1);
</script>

</body>
</html>
