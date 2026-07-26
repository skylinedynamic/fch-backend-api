<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Funding Application</title>

<style>
    @page { margin: 30px; }
    body {
        font-family: 'DejaVu Sans', Arial, sans-serif;
        color: #333333;
        margin: 0;
        padding: 0;
        font-size: 10px;
    }
    .text-primary { color: {{ $primaryColor }}; }
    .text-success { color: #00a65a; }
    
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; }

    /* Page Container */
    .page-container {
        border: 2px solid {{ $primaryColor }};
        position: relative;
    }
    
    /* Top Pagination */
    .page-number {
        text-align: right;
        font-size: 8px;
        color: #555;
        padding-bottom: 5px;
        padding-right: 25px;
    }

    /* Header */
    .header-table { padding: 10px 25px 20px 25px; }
    .header-table td { vertical-align: middle; }
    .doc-title {
        font-size: 20px;
        font-weight: bold;
        color: {{ $primaryColor }};
        text-align: right;
        text-transform: uppercase;
        margin-bottom: 4px;
        letter-spacing: -0.5px;
    }
    .doc-subtitle {
        font-size: 9px;
        color: {{ $secondaryColor }};
        font-weight: bold;
        text-align: right;
        text-transform: uppercase;
    }

    /* Blue Banner */
    .info-banner {
        background-color: {{ $primaryColor }};
        color: #ffffff;
        padding: 10px 25px;
    }
    .info-banner td { vertical-align: middle; color: #ffffff; }
    
    .icon-box {
        width: 22px;
        height: 22px;
        border: 1px solid #ffffff;
        border-radius: 4px;
        text-align: center;
    }
    .info-label { color: #8da4c4; font-size: 8px; }
    .info-value { font-size: 10px; font-weight: bold; }

    /* Content Area */
    .content-area { padding: 20px 25px; }
    
    /* Section Headers */
    .section-header { margin-top: 15px; margin-bottom: 5px; }
    .section-header td { vertical-align: middle; }
    
    .section-icon {
        background-color: {{ $primaryColor }};
        width: 20px;
        height: 20px;
        border-radius: 50%;
        text-align: center;
    }
    .section-title {
        color: {{ $primaryColor }};
        font-size: 10px;
        font-weight: bold;
        text-transform: uppercase;
        padding-left: 8px;
    }
    .section-line {
        border-bottom: 2px solid {{ $primaryColor }};
        margin-bottom: 10px;
    }

    /* Data Tables */
    .data-table { margin-bottom: 15px; }
    .data-table td {
        padding: 7px 0;
        border-bottom: 1px solid #eeeeee;
        font-size: 9px;
        color: #333333;
    }
    .t-label {
        color: #777777;
        width: 40%;
        font-weight: normal;
    }
    .t-colon { width: 5%; color: #aaaaaa; text-align: center; }
    .t-value {
        color: #1a1a1a;
        width: 55%;
        font-weight: 500;
    }

    /* Signature Box */
    .signature-box {
        border: 1px solid #e0e6ed;
        background-color: #fcfcfc;
        padding: 10px;
        margin-bottom: 15px;
    }
    .sig-signed-by { font-size: 7px; color: {{ $primaryColor }}; font-weight: bold; text-transform: uppercase; }
    .sig-name {
        font-family: 'Times New Roman', serif;
        font-style: italic;
        font-size: 22px;
        color: {{ $primaryColor }};
        border-bottom: 1px solid {{ $primaryColor }};
        padding-bottom: 3px;
        margin: 3px 0;
    }
    .sig-type { font-size: 7.5px; color: #555; }
    
    .status-badge { border-left: 1px solid #e0e6ed; padding-left: 10px; text-align: center; }
    .status-icon {
        background-color: #00a65a; color: #fff;
        width: 20px; height: 20px; border-radius: 50%;
        text-align: center; margin: 0 auto 3px auto;
    }
    .status-text { color: #00a65a; font-weight: bold; font-size: 9px; }

    /* Consents Table */
    .consents-table td {
        padding: 10px 8px;
        border-bottom: 1px solid #e0e6ed;
        vertical-align: middle;
        font-size: 9px;
    }
    .consents-table tr:first-child td { border-top: 1px solid #e0e6ed; }
    .check-icon {
        background-color: #00a65a;
        width: 14px; height: 14px; border-radius: 50%;
        text-align: center;
    }

    /* Final Certification */
    .final-cert-text { font-size: 8.5px; line-height: 1.6; color: #333; padding-right: 20px; text-align: justify; }
    .final-sig-box { border: 1px dashed {{ $primaryColor }}; padding: 12px; text-align: center; border-radius: 6px; }
    .final-sig-name-italic {
        font-family: 'Times New Roman', serif; font-style: italic; font-size: 20px; color: {{ $primaryColor }};
        border-bottom: 1px solid {{ $primaryColor }}; padding-bottom: 2px; margin-bottom: 5px; display: inline-block; width: 80%;
    }
    .final-sig-bold { font-weight: bold; font-size: 10px; color: #111; margin-bottom: 3px; }
    .final-sig-detail { font-size: 8px; color: #333; margin-bottom: 2px; text-align: left; padding-left: 20px; }
    .final-sig-type { font-size: 8px; color: #555; margin-bottom: 5px; }

    /* Footer */
    .footer-section { border-top: 2px solid {{ $primaryColor }}; padding: 15px 25px; }
    .footer-seal {
        width: 60px; height: 60px; border: 2px dashed {{ $primaryColor }}; border-radius: 50%; text-align: center; margin: 0 auto;
    }
    .footer-seal-inner {
        width: 50px; height: 50px; border: 2px solid {{ $primaryColor }}; border-radius: 50%; margin: 3px auto 0 auto;
        padding-top: 10px; box-sizing: border-box;
    }
    .seal-text { font-size: 4px; font-weight: bold; color: {{ $primaryColor }}; }
    
    .footer-disclaimer { font-size: 8px; color: #555; line-height: 1.4; padding: 0 15px; text-align: justify; }
    .footer-company { font-size: 8px; color: #333; line-height: 1.5; }
    
    /* Bottom Bar */
    .bottom-bar { background-color: {{ $primaryColor }}; color: #ffffff; padding: 8px 25px; }
    .bottom-bar td { font-size: 8px; color: #ffffff; }

    /* Watermark */
    .watermark {
        position: fixed;
        top: 350px;
        left: 0;
        width: 100%;
        text-align: center;
        opacity: 0.05;
        z-index: -100;
        font-size: 65px;
        font-weight: bold;
        font-family: 'Helvetica', 'Arial', sans-serif;
        color: {{ $primaryColor }};
        white-space: nowrap;
        letter-spacing: 2px;
    }

    .page-break { page-break-after: always; }
</style>
</head>
<body>



    <div class="watermark">
        <table style="width: 100%; text-align: center; border-collapse: collapse;">
            <tr>
                <td style="text-align: center; vertical-align: middle;">
                    @if($logoBase64)
                        <img src="{{ $logoBase64 }}" width="500" style="vertical-align: middle;">
                    @endif
                </td>
            </tr>
        </table>
    </div>

<!-- ================== PAGE 1 ================== -->
<div style="padding-top:10px;"></div>

<div class="page-container">
    <!-- Header -->
    <table class="header-table" style="padding: 20px 25px 15px 25px; width: 100%;">
        <tr>
            <td style="width: 30%; vertical-align: middle;">
                @if($logoBase64)
                    <img src="{{ $logoBase64 }}" width="150" alt="Logo">
                @else
                    <div style="font-size: 16px; font-weight: bold; color: {{ $primaryColor }};">{{ $companyName }}</div>
                @endif
            </td>
            <td style="width: 70%; text-align: right; vertical-align: middle; white-space: nowrap;">
                <div class="doc-title" style="font-family: 'Helvetica', 'Arial', sans-serif; font-size: 25px; letter-spacing: -0.5px; margin-bottom: 2px; color: {{ $primaryColor }}; font-weight: bold;">FUNDING APPLICATION</div>
            </td>
        </tr>
    </table>

    <!-- Blue Banner -->
    <div style="position: relative; background-color: {{ $primaryColor }}; color: #ffffff;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 48%; padding: 10px 0 10px 25px; vertical-align: middle;">
                    <table style="width: 100%;">
                        <tr>
                            <td style="width: 35px; vertical-align: middle;">
                                <div class="icon-box" style="border: 1px solid rgba(255,255,255,0.7); border-radius: 6px; width: 28px; height: 28px; line-height: 28px; text-align: center;">
                                    <img src="{{ $fa_doc_white }}" width="14" height="14" style="margin-top: 7px;">
                                </div>
                            </td>
                            <td style="vertical-align: middle;">
                                <div class="info-label" style="color: #8da4c4; font-size: 7.5px;">Document Reference:</div>
                                <div class="info-value" style="font-size: 11px; font-weight: bold; color: #ffffff;">{{ $appId }}</div>
                            </td>
                        </tr>
                    </table>
                </td>
                
                <td style="width: 2%; border-left: 1px solid rgba(255,255,255,0.3); height: 30px;"></td>
 
                <td style="width: 45%; padding: 10px 0 10px 15px; vertical-align: middle;">
                    <table style="width: 100%;">
                        <tr>
                            <td style="width: 35px; vertical-align: middle;">
                                <div class="icon-box" style="border: 1px solid rgba(255,255,255,0.7); border-radius: 6px; width: 28px; height: 28px; line-height: 28px; text-align: center;">
                                    <img src="{{ $fa_cal_white }}" width="14" height="14" style="margin-top: 7px;">
                                </div>
                            </td>
                            <td style="vertical-align: middle;">
                                <div class="info-label" style="color: #8da4c4; font-size: 7.5px;">Document Generated:</div>
                                <div class="info-value" style="font-size: 11px; font-weight: bold; color: #ffffff;">{{ $currentTime->format('M j, Y') }} &nbsp;|&nbsp; {{ $currentTime->format('H:i:s') }} {{ $timezoneAbbr }}</div>
                            </td>
                        </tr>
                    </table>
                </td>

                <td style="width: 5%; vertical-align: bottom; padding: 0; text-align: right;"></td>
            </tr>
        </table>
    </div>

    <!-- Content Area -->
    <div class="content-area">
        
        <!-- Business Information -->
        <table class="section-header">
            <tr>
                <td style="width: 25px;">
                    <div class="section-icon" style="background-color: #f0f5ff; width: 28px; height: 28px; border-radius: 6px; text-align: center;">
                        <img src="{{ $fa_doc }}" width="14" height="14" style="margin-top: 6px;">
                    </div>
                </td>
                <td class="section-title">BUSINESS INFORMATION</td>
            </tr>
        </table>
        <div class="section-line"></div>
        
        <table style="table-layout: fixed; width: 100%;">
            <tr>
                <td style="width: 48%; padding-right: 2%; vertical-align: top;">
                    <table class="data-table">
                        <tr><td class="t-label">Legal Business Name</td><td class="t-colon">:</td><td class="t-value">{{ $businessName }}</td></tr>
                        <tr><td class="t-label">Phone Number</td><td class="t-colon">:</td><td class="t-value">{{ $businessPhone }}</td></tr>
                        <tr><td class="t-label">Business Address</td><td class="t-colon">:</td><td class="t-value">{{ $businessAddress }}</td></tr>
                        <tr><td class="t-label">City</td><td class="t-colon">:</td><td class="t-value">{{ $businessCity }}</td></tr>
                        <tr><td class="t-label">Personal Credit Score</td><td class="t-colon">:</td><td class="t-value">{{ $personalCreditScore }}</td></tr>
                    </table>
                </td>
                <td style="width: 4%;"></td>
                <td style="width: 48%; padding-left: 2%; vertical-align: top;">
                    <table class="data-table">
                        <tr><td class="t-label">Business EIN</td><td class="t-colon">:</td><td class="t-value">{{ $businessEin }}</td></tr>
                        <tr><td class="t-label">Business Email</td><td class="t-colon">:</td><td class="t-value">{{ $businessEmail }}</td></tr>
                        <tr><td class="t-label">Country</td><td class="t-colon">:</td><td class="t-value">{{ $businessCountry }}</td></tr>
                        <tr><td class="t-label">State</td><td class="t-colon">:</td><td class="t-value">{{ $businessState }}</td></tr>
                        <tr><td class="t-label">Zip Code</td><td class="t-colon">:</td><td class="t-value">{{ $businessZipCode }}</td></tr>
                        <tr><td class="t-label">Business Start Date</td><td class="t-colon">:</td><td class="t-value">{{ $businessStartDate }}</td></tr>
                        <tr><td class="t-label">Industry Type</td><td class="t-colon">:</td><td class="t-value">{{ $businessIndustryType }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- Owner Information -->
        <table class="section-header" style="margin-top: 10px;">
            <tr>
                <td style="width: 25px;">
                    <div class="section-icon" style="background-color: #f0f5ff; width: 28px; height: 28px; border-radius: 6px; text-align: center;">
                        <img src="{{ $fa_users }}" width="14" height="14" style="margin-top: 6px;">
                    </div>
                </td>
                <td class="section-title">PRIMARY INFORMATION</td>
            </tr>
        </table>
        <div class="section-line"></div>
        
        <table style="table-layout: fixed; width: 100%;">
            <tr>
                <td style="width: 48%; padding-right: 2%; vertical-align: top;">
                    <table class="data-table">
                        <tr><td class="t-label">First Name</td><td class="t-colon">:</td><td class="t-value">{{ $firstName }}</td></tr>
                        <tr><td class="t-label">Email</td><td class="t-colon">:</td><td class="t-value">{{ $email }}</td></tr>
                        <tr><td class="t-label">Home Address</td><td class="t-colon">:</td><td class="t-value">{{ $homeAddress }}</td></tr>
                        <tr><td class="t-label">State</td><td class="t-colon">:</td><td class="t-value">{{ $homeState }}</td></tr>
                        <tr><td class="t-label">Zip Code</td><td class="t-colon">:</td><td class="t-value">{{ $homeZipCode }}</td></tr>
                        <tr><td class="t-label">Date of Birth</td><td class="t-colon">:</td><td class="t-value">{{ $dateOfBirth }}</td></tr>
                    </table>
                </td>
                <td style="width: 4%;"></td>
                <td style="width: 48%; padding-left: 2%; vertical-align: top;">
                    <table class="data-table">
                        <tr><td class="t-label">Last Name</td><td class="t-colon">:</td><td class="t-value">{{ $lastName }}</td></tr>
                        <tr><td class="t-label">Phone Number</td><td class="t-colon">:</td><td class="t-value">{{ $phoneNumber }}</td></tr>
                        <tr><td class="t-label">Country</td><td class="t-colon">:</td><td class="t-value">{{ $homeCountry }}</td></tr>
                        <tr><td class="t-label">City</td><td class="t-colon">:</td><td class="t-value">{{ $homeCity }}</td></tr>
                        <tr><td class="t-label">Home Phone</td><td class="t-colon">:</td><td class="t-value">{{ $homePhone }}</td></tr>
                        <tr><td class="t-label">Last 4 SSN</td><td class="t-colon">:</td><td class="t-value">{{ $last4SSN }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- Financial & Funding Details -->
        <table class="section-header" style="margin-top: 10px;">
            <tr>
                <td style="width: 25px;">
                    <div class="section-icon" style="background-color: #f0f5ff; width: 28px; height: 28px; border-radius: 6px; text-align: center;">
                        <img src="{{ $fa_dollar }}" width="14" height="14" style="margin-top: 6px;">
                    </div>
                </td>
                <td class="section-title">FINANCIAL &amp; FUNDING DETAILS</td>
            </tr>
        </table>
        <div class="section-line"></div>
        
        <table style="table-layout: fixed; width: 100%;">
            <tr>
                <td style="width: 48%; padding-right: 2%; vertical-align: top;">
                    <table class="data-table">
                        <tr><td class="t-label">Currency</td><td class="t-colon">:</td><td class="t-value">{{ $application->currency ?: 'USD' }}</td></tr>
                        <tr><td class="t-label">Monthly Revenue</td><td class="t-colon">:</td><td class="t-value">{{ !empty($application->monthly_revenue) ? '$'.number_format((float)$application->monthly_revenue, 2) : 'N/A' }}</td></tr>
                        <tr><td class="t-label">Annual Personal Income</td><td class="t-colon">:</td><td class="t-value">{{ !empty(optional($application->borrower)->annual_personal_income) ? '$'.number_format((float)optional($application->borrower)->annual_personal_income, 2) : 'N/A' }}</td></tr>
                        <tr><td class="t-label">Number of Monthly Deposits</td><td class="t-colon">:</td><td class="t-value">{{ optional($application->borrower)->monthly_deposit_count ?: 'N/A' }}</td></tr>
                        <tr><td class="t-label">Current Business Debt</td><td class="t-colon">:</td><td class="t-value">{{ !empty($application->debt_balance) ? '$'.number_format((float)$application->debt_balance, 2) : 'N/A' }}</td></tr>
                        <tr><td class="t-label">Negative Days</td><td class="t-colon">:</td><td class="t-value">{{ optional($application->borrower)->negative_days ?: 'N/A' }}</td></tr>
                    </table>
                </td>
                <td style="width: 4%;"></td>
                <td style="width: 48%; padding-left: 2%; vertical-align: top;">
                    <table class="data-table">
                        <tr><td class="t-label">Years in Business</td><td class="t-colon">:</td><td class="t-value">{{ $application->years_in_business ?: 'N/A' }}</td></tr>
                        <tr><td class="t-label">Annual Revenue</td><td class="t-colon">:</td><td class="t-value">{{ !empty($application->annual_revenue) ? '$'.number_format((float)$application->annual_revenue, 2) : 'N/A' }}</td></tr>
                        <tr><td class="t-label">Average Monthly Deposits</td><td class="t-colon">:</td><td class="t-value">{{ !empty(optional($application->borrower)->average_monthly_deposits) ? '$'.number_format((float)optional($application->borrower)->average_monthly_deposits, 2) : 'N/A' }}</td></tr>
                        <tr><td class="t-label">Average Daily Balance (ADB)</td><td class="t-colon">:</td><td class="t-value">{{ !empty(optional($application->borrower)->avg_bank_balance) ? '$'.number_format((float)optional($application->borrower)->avg_bank_balance, 2) : 'N/A' }}</td></tr>
                        <tr><td class="t-label">NSF Count</td><td class="t-colon">:</td><td class="t-value">{{ optional($application->borrower)->nsf_count ?: 'N/A' }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

    </div><!-- close content-area page 1 -->
</div><!-- close page-container page 1 -->

<!-- Page Break -->
<div class="page-break"></div>

<!-- PAGE 2 - Funding Program Details -->
<div class="page-container">
    <div class="content-area">

        <table class="section-header" style="margin-top: 10px;">
            <tr>
                <td style="width: 25px;">
                    <div class="section-icon" style="background-color: #f0f5ff; width: 28px; height: 28px; border-radius: 6px; text-align: center;">
                        <img src="{{ $fa_shield }}" width="14" height="14" style="margin-top: 6px;">
                    </div>
                </td>
                <td class="section-title">FUNDING PROGRAM DETAILS</td>
            </tr>
        </table>
        <div class="section-line"></div>
        
        <table style="table-layout: fixed; width: 100%;">
            <tr>
                <td style="width: 48%; padding-right: 2%;">
                    <table class="data-table">
                        <tr><td class="t-label">Funding Type</td><td class="t-colon">:</td><td class="t-value">{{ $application->funding_type ?: ($application->preferred_funding_type ?: 'N/A') }}</td></tr>
                        <tr><td class="t-label">Funding Sequence Name</td><td class="t-colon">:</td><td class="t-value">{{ optional($application->fundingSequence)->name ?: 'N/A' }}</td></tr>
                        <tr><td class="t-label">Terms</td><td class="t-colon">:</td><td class="t-value">{{ $application->terms ?: (optional($application->fundingSequence)->terms ?: 'N/A') }}</td></tr>
                        <tr><td class="t-label">Estimated Speed</td><td class="t-colon">:</td><td class="t-value">{{ optional($application->fundingSequence)->speed_to_funds ?: ($application->estimated_speed ?: 'N/A') }}</td></tr>
                    </table>
                </td>
                <td style="width: 4%;"></td>
                <td style="width: 48%; padding-left: 2%;">
                    <table class="data-table">
                        <tr><td class="t-label">Uses</td><td class="t-colon">:</td><td class="t-value">
                            @php
                                $usesVal = $application->uses ?: (optional($application->fundingSequence)->uses ?: 'N/A');
                                if (is_array($usesVal)) {
                                    $usesVal = implode(', ', $usesVal);
                                }
                            @endphp
                            {{ $usesVal }}
                        </td></tr>
                        <tr><td class="t-label">Use of Funds</td><td class="t-colon">:</td><td class="t-value">{{ $application->use_of_funds ?: ($application->purpose_of_funds ?: 'N/A') }}</td></tr>
                        <tr><td class="t-label">Estimated Amount</td><td class="t-colon">:</td><td class="t-value">{{ optional($application->fundingSequence)->amount_range ?: 'N/A' }}</td></tr>
                        <tr><td class="t-label">Funding Request Status</td><td class="t-colon">:</td><td class="t-value"><strong>{{ optional($application->fundingRequest)->status ?: ($application->status ?: 'N/A') }}</strong></td></tr>
                    </table>
                </td>
            </tr>
        </table>

    </div>

    <!-- Footer -->
    <div class="footer-section">
        <table>
            <tr>
                <td style="width: 60%;">
                    <div class="footer-disclaimer">
                        This document is a record of the Funding Application submitted to {{ $companyName }}. The information contained herein is considered confidential and is strictly for underwriting and evaluation purposes.
                    </div>
                </td>
                <td style="width: 40%; text-align: right;">
                    <div class="footer-company">
                        <strong>{{ $companyName }}</strong><br>
                        {{ $companyAddress }}<br>
                        {{ $companyCityState }}<br>
                        {{ $companyContact }}<br>
                        {{ $companyWebsite }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Bottom Bar -->
    <div class="bottom-bar">
        <table>
            <tr>
                <td style="text-align: left;">&copy; {{ date('Y') }} {{ $companyName }}. All Rights Reserved.</td>
                <td style="text-align: right;">Ref: {{ $appId }} &nbsp;|&nbsp; {{ $currentTime->format('M j, Y') }}</td>
            </tr>
        </table>
    </div>

</div>

</body>
</html>
