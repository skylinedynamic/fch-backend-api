<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Funding Application</title>
@include('pdf.partials.styles')
</head>
<body>
@php
    $title = 'Funding Application';
    $borrower = $application->borrower;

    // An amount as money, or null for "N/A". The requested amount is in the
    // application's own currency; the rest are the borrower's dollar figures.
    $money = fn ($value, $symbol = '$') => ($value === null || $value === '') ? null : $symbol . number_format((float) $value, 2);

    $additionalTypes = $application->additional_funding_types;
@endphp

@include('pdf.partials.footer')
@include('pdf.partials.header')

@include('pdf.partials.fields', ['title' => 'Business Information', 'fields' => [
    'Legal Business Name' => $businessName,
    'Business EIN' => $businessEin,
    'Phone Number' => $businessPhone,
    'Business Email' => $businessEmail,
    'Business Address' => $businessAddress,
    'Country' => $businessCountry,
    'City' => $businessCity,
    'State' => $businessState,
    'Zip Code' => $businessZipCode,
    'Business Start Date' => $businessStartDate,
    'Industry Type' => $businessIndustryType,
    'Entity Type' => $application->entity_type ?: optional($borrower)->business_entity_type,
    // Worked out from the business start date.
    'Time in Business' => $timeInBusinessCount,
]])

@include('pdf.partials.fields', ['title' => 'Primary Information', 'fields' => [
    'First Name' => $firstName,
    'Last Name' => $lastName,
    'Email' => $email,
    'Phone Number' => $phoneNumber,
    'Home Address' => $homeAddress,
    'Country' => $homeCountry,
    'State' => $homeState,
    'City' => $homeCity,
    'Zip Code' => $homeZipCode,
    'Home Phone' => $homePhone,
    'Date of Birth' => $dateOfBirth,
    'SSN' => $ssn,
    'Ownership Percentage' => ($application->ownership !== null && $application->ownership !== '') ? rtrim(rtrim(number_format((float) $application->ownership, 2), '0'), '.') . '%' : null,
]])

@include('pdf.partials.fields', ['title' => 'Financial Details', 'fields' => [
    'Currency' => $application->currency ?: 'USD',
    'Monthly Revenue' => $money($application->monthly_revenue),
    'Annual Revenue' => $money($application->annual_revenue),
    'Annual Personal Income' => $money(optional($borrower)->annual_personal_income),
    'Number of Monthly Deposits' => optional($borrower)->monthly_deposit_count,
    'Average Monthly Deposits' => $money(optional($borrower)->average_monthly_deposits),
    'Average Daily Balance' => $money(optional($borrower)->avg_bank_balance),
    'Personal Credit Score' => optional($borrower)->fico_score,
    'Business Credit Score' => optional($borrower)->paydex_score,
]])

@include('pdf.partials.fields', ['title' => 'Funding Request', 'fields' => [
    'Requested Amount' => $money($application->requested_amount, $application->currency_symbol ?: '$'),
    'Funding Type' => $application->funding_type ?: $application->preferred_funding_type,
    'Additional Funding Types' => !empty($additionalTypes) ? implode(', ', $additionalTypes) : null,
    'Use of Funds' => $application->use_of_funds ?: $application->purpose_of_funds,
    'Purpose Note' => $application->purpose_note,
    'Timeframe Needed' => $application->timeframe_needed,
    'Collateral Available' => $application->collateral_available,
    'Funding Request Status' => optional($application->fundingRequest)->status ?: $application->status,
]])

<div class="section">
    <div class="section-title">{{ $companyName }}</div>
    <table style="margin-top: 6px;">
        <tr>
            <td style="width: 62%; padding-right: 20px;" class="note">
                This document is a record of the Funding Application submitted to {{ $companyName }}.
                The information contained herein is confidential and is strictly for underwriting
                and evaluation purposes.
            </td>
            <td style="width: 38%; text-align: right;" class="muted">
                {{ $companyName }}
                @if($companyAddress)<br>{{ $companyAddress }}@endif
                @if($companyCityState)<br>{{ $companyCityState }}@endif
                @if($companyContact)<br>{{ $companyContact }}@endif
                @if($companyWebsite)<br>{{ $companyWebsite }}@endif
            </td>
        </tr>
    </table>
</div>

</body>
</html>
