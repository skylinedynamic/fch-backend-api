@extends('emails.layouts.mail')

@section('title', 'System Health Alert')

@section('content')
    <h2 style="color: #ef4444; font-size: 20px; font-weight: 700; margin: 0 0 16px 0;">⚠️ System Health Alert</h2>
    <p style="line-height: 1.5; margin-bottom: 24px;">
        One or more server resources on <strong>{{ $siteName }}</strong> have crossed their alert threshold.
    </p>

    {{-- The breach itself, first and loudest. --}}
    <div class="box-light info-card-container"
        style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden;">
        <div class="info-card-header"
            style="background-color: #fef2f2; padding: 20px; text-align: center; border-bottom: 1px solid #fee2e2; font-size: 16px; font-weight: 700; color: #ef4444; text-transform: uppercase; letter-spacing: 0.5px;">
            Thresholds Exceeded
        </div>
        <div class="info-card-content" style="padding: 20px;">
            <table class="info-table" style="width: 100%; border-collapse: collapse; margin-bottom: 0;">
                @foreach ($alerts as $alert)
                    <tr>
                        <td class="label"
                            style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                            {{ $alert['metric'] }}:</td>
                        <td class="value"
                            style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 700; color: #ef4444; text-align: right; padding-right: 10px;">
                            {{ number_format($alert['usage'] * 100, 1) }}%
                            <span style="font-weight: 500; color: #64748b;">
                                (limit {{ number_format($alert['threshold'] * 100, 0) }}%)
                            </span>
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>

    {{-- Every reading, breaching or not: one resource at 96% while the rest idle
         is a different problem from the whole box being saturated. --}}
    @if (!empty($metrics))
        <div class="box-light info-card-container"
            style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden;">
            <div class="info-card-header"
                style="background-color: #f1f5f9; padding: 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 16px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">
                All Readings
            </div>
            <div class="info-card-content" style="padding: 20px;">
                <table class="info-table" style="width: 100%; border-collapse: collapse; margin-bottom: 0;">
                    @foreach ($metrics as $name => $ratio)
                        <tr>
                            <td class="label"
                                style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                                {{ $name }}:</td>
                            <td class="value"
                                style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">
                                {{ number_format($ratio * 100, 1) }}%</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
    @endif

    {{-- Which machine, so a multi-server deployment knows where to look. --}}
    <div class="box-light info-card-container"
        style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden;">
        <div class="info-card-content" style="padding: 20px;">
            <table class="info-table" style="width: 100%; border-collapse: collapse; margin-bottom: 0;">
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Server:</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                        {{ $host }}</td>
                </tr>
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Environment:</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                        {{ $environment }}</td>
                </tr>
                <tr>
                    <td class="label"
                        style="padding: 10px 0; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Detected At:</td>
                    <td class="value"
                        style="padding: 10px 0; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                        {{ $timestamp }}</td>
                </tr>
            </table>
        </div>
    </div>

    <p style="line-height: 1.5; margin-bottom: 0; font-size: 13px; color: #64748b;">
        This is an automated message from the scheduled health monitor. It is sent only while a
        threshold is breached, so a repeat means the condition has not cleared.
    </p>
@endsection
