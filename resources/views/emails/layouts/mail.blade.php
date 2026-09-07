@php
    // Supplied by App\Mail\Traits\MailableBranding. The defaults keep any mailable
    // that does not use the trait rendering exactly as it did before.
    $bandBg = $brandPrimary ?? '#001F3F';
    $bandText = $brandOnPrimary ?? '#FFFFFF';
    $bandLink = $brandPrimaryInk ?? '#001F3F';
@endphp
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no,address=no,email=no,date=no">
    <title>@yield('title', $siteName ?? null)</title>
    <style>
        /* Reset for email clients */
        body,
        table,
        td,
        a,
        p,
        span,
        div {
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }

        table,
        td {
            mso-table-lspace: 0pt;
            mso-table-rspace: 0pt;
        }

        table {
            max-width: 100%;
        }

        img {
            -ms-interpolation-mode: bicubic;
            border: 0;
            height: auto;
            line-height: 100%;
            outline: none;
            text-decoration: none;
            max-width: 100%;
        }

        /* Long values (URLs, emails, ids) must never force horizontal scroll */
        .value,
        .detail-value,
        .highlight-value,
        .temp-password,
        .amount-value,
        .info-card-content,
        .invoice-summary-content,
        a {
            word-break: break-word;
            overflow-wrap: break-word;
        }

        /* Desktop base */
        .email-wrapper {
            width: 100%;
            background-color: #f3f3f9;
            padding: 40px 0;
        }

        .email-container {
            max-width: 600px;
            width: 100%;
            margin: 0 auto;
            background-color: #ffffff;
            box-shadow: 0 1px 2px rgba(56, 65, 74, 0.15);
        }

        .header-logo {
            background-color: {{ $bandBg }};
            padding: 24px 20px;
            text-align: center;
        }

        /* Logos are per-partner uploads with unrestricted aspect ratios
           (seen in the wild: 5.26:1 wide through 1:1 square), so cap BOTH
           dimensions - a width-only cap renders a square logo as a huge block. */
        .header-logo img {
            display: block;
            margin: 0 auto;
            width: auto;
            height: auto;
            max-width: 200px;
            max-height: 60px;
        }

        .email-content {
            padding: 0 30px;
            text-align: left;
            font-size: 14px;
            line-height: 1.6;
            color: #495057;
        }

        .bottom-footer {
            background-color: {{ $bandBg }};
            padding: 16px 20px;
            text-align: center;
            font-size: 13px;
            color: {{ $bandText }};
            line-height: 1.6;
        }

        .bottom-footer p {
            margin: 0;
            color: {{ $bandText }};
        }

        /* Tablet (max-width: 768px) */
        @media only screen and (max-width: 768px) {
            .email-wrapper {
                padding: 30px 0 !important;
            }

            .email-container {
                width: 100% !important;
                max-width: 100% !important;
            }

            .header-logo {
                padding: 18px !important;
            }

            .email-content {
                padding: 0 25px !important;
                font-size: 14px !important;
            }

            .bottom-footer {
                padding: 14px 18px !important;
                font-size: 12px !important;
            }
        }

        /* Mobile (max-width: 600px) */
        @media only screen and (max-width: 600px) {
            .email-wrapper {
                padding: 16px 0 !important;
            }

            .email-container {
                width: 100% !important;
                max-width: 100% !important;
                box-shadow: none !important;
            }

            .header-logo {
                padding: 15px !important;
                margin-top: 10px !important;
            }

            .header-logo img,
            .header-logo .logo {
                width: auto !important;
                height: auto !important;
                max-width: 170px !important;
                max-height: 48px !important;
            }

            .email-content {
                padding: 0 16px !important;
                font-size: 14px !important;
            }

            /* Cards and boxes: tighter padding so content keeps its width */
            .info-card-header,
            .invoice-summary-header {
                padding: 14px 12px !important;
                font-size: 14px !important;
            }

            .info-card-content,
            .invoice-summary-content {
                padding: 14px 12px !important;
            }

            .box-primary,
            .box-light,
            .box-note,
            .note-box,
            .alert {
                padding: 16px 14px !important;
            }

            /* Label/value tables stack into single-column rows */
            .info-table td.label,
            .detail-table td.label,
            .details-table td.label,
            .invoice-summary-table td.label,
            .info-table td.value,
            .detail-table td.value,
            .details-table td.value,
            .invoice-summary-table td.value {
                display: block !important;
                width: 100% !important;
                text-align: left !important;
                padding-left: 0 !important;
                padding-right: 0 !important;
            }

            .info-table td.label,
            .detail-table td.label,
            .details-table td.label,
            .invoice-summary-table td.label {
                padding-top: 12px !important;
                padding-bottom: 2px !important;
                border-bottom: none !important;
            }

            .info-table td.value,
            .detail-table td.value,
            .details-table td.value,
            .invoice-summary-table td.value {
                padding-top: 0 !important;
                padding-bottom: 12px !important;
                font-size: 14px !important;
            }

            /* Two-up detail grids stack */
            .detail-grid td.detail-item {
                display: block !important;
                width: 100% !important;
                box-sizing: border-box !important;
                padding: 12px 0 !important;
            }

            /* Data tables: tighten instead of overflowing */
            .doc-table th,
            .doc-table td,
            .doc-table td.col-num,
            .doc-table td.col-task,
            .doc-table td.col-status,
            .doc-table td.col-remark {
                padding: 10px 8px !important;
                font-size: 12px !important;
                word-break: break-word !important;
            }

            /* Full-width tap targets */
            .btn {
                display: block !important;
                width: 100% !important;
                box-sizing: border-box !important;
                text-align: center !important;
                padding: 14px 16px !important;
            }

            .btn-container {
                margin: 24px 0 !important;
            }

            /* Oversized display numbers */
            .invoice-summary-amount {
                font-size: 22px !important;
            }

            .amount-value,
            .highlight-value {
                font-size: 18px !important;
            }

            .temp-password {
                font-size: 18px !important;
                letter-spacing: 0.5px !important;
                padding: 10px 14px !important;
            }

            .bottom-footer {
                padding: 12px 15px !important;
                font-size: 12px !important;
            }
            
            /* Responsive tables for mobile */
            .info-table tr {
                display: block !important;
                padding-bottom: 10px !important;
                border-bottom: 1px solid #f1f5f9 !important;
            }
            .info-table tr:last-child {
                border-bottom: none !important;
                padding-bottom: 0 !important;
            }
            .info-table td.label {
                display: block !important;
                width: 100% !important;
                border-bottom: none !important;
                padding-bottom: 2px !important;
            }
            .info-table td.value {
                display: block !important;
                width: 100% !important;
                text-align: left !important;
                padding-top: 0 !important;
                border-bottom: none !important;
                word-break: break-word !important;
            }
        }

        /* Small mobile (max-width: 480px) */
        @media only screen and (max-width: 480px) {
            .email-wrapper {
                padding: 10px 0 !important;
            }

            .header-logo {
                padding: 12px !important;
            }

            .header-logo img,
            .header-logo .logo {
                width: auto !important;
                height: auto !important;
                max-width: 150px !important;
                max-height: 42px !important;
            }

            .email-content {
                padding: 0 14px !important;
                font-size: 13px !important;
            }

            .info-card-header,
            .invoice-summary-header {
                padding: 12px 10px !important;
                font-size: 13px !important;
            }

            .info-card-content,
            .invoice-summary-content {
                padding: 12px 10px !important;
            }

            .doc-table th,
            .doc-table td {
                padding: 8px 6px !important;
                font-size: 11px !important;
            }

            .invoice-summary-amount {
                font-size: 20px !important;
            }

            .bottom-footer {
                padding: 10px 12px !important;
                font-size: 11px !important;
            }
        }

        /* Outlook-specific fixes */
        .ExternalClass,
        .ReadMsgBody {
            width: 100%;
        }

        .ExternalClass,
        .ExternalClass p,
        .ExternalClass span,
        .ExternalClass font,
        .ExternalClass td,
        .ExternalClass div {
            line-height: 100%;
        }

        #outlook a {
            padding: 0;
        }
    </style>
    <!--[if mso]>
    <style type="text/css">
        /* Word engine ignores max-width/max-height, so it would draw the logo
           at its native pixel size - pin a width for Outlook desktop only. */
        .header-logo img { width: 200px !important; height: auto !important; }
    </style>
    <![endif]-->
</head>

<body
    style="margin: 0; padding: 0; width: 100%; background-color: #f3f3f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; font-size: 14px; color: #495057; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;">
    <div class="wrapper email-wrapper" style="width: 100%; background-color: #f3f3f9; padding: 40px 0;">
        <div class="container email-container"
            style="max-width: 600px; width: 100%; margin: 0 auto; background-color: #ffffff; box-shadow: 0 1px 2px rgba(56, 65, 74, 0.15); border-radius: 0; overflow: hidden;">

            <!-- Logo Section: a brand-coloured band. Uses <table bgcolor> rather than a
                 div background because Outlook drops background-color on block elements. -->
            <table class="header-logo" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $bandBg }}"
                style="width: 100%; background-color: {{ $bandBg }}; padding: 24px 20px; text-align: center;">
                <tr>
                    <td align="center" style="padding: 0; text-align: center;">
                        @if(!empty($logoUrl))
                            <img src="{{ $logoUrl }}" alt="{{ $siteName ?? null }}" class="logo"
                                style="display: block; margin: 0 auto; width: auto; height: auto; max-width: 200px; max-height: 60px; border: 0;">
                        @else
                            <h2 style="margin: 0; color: {{ $bandText }}; font-size: 22px;">
                                {{ $siteName ?? config('app.name') }}
                            </h2>
                        @endif
                        @if(!empty($siteSlogan))
                            <p style="margin: 8px 0 0; color: {{ $bandText }}; font-size: 12px; opacity: 0.85;">
                                {{ $siteSlogan }}
                            </p>
                        @endif
                    </td>
                </tr>
            </table>

            <!-- Content Area -->
            <div class="content email-content" style="padding: 0 30px; text-align: left;">
                @yield('content')
            </div>

            <!-- Bottom Footer -->
            <table class="bottom-footer" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $bandBg }}"
                style="width: 100%; background-color: {{ $bandBg }}; padding: 16px 20px; text-align: center; font-size: 13px; color: {{ $bandText }}; line-height: 1.6;">
                <tr>
                    <td style="padding: 0;">
                        <p style="margin: 0; color: {{ $bandText }}; font-size: 13px;">&copy; {{ date('Y') }}
                            {{ $siteName ?? config('app.name') }}. All rights reserved.
                        </p>
                    </td>
                </tr>
            </table>
        </div>
    </div>
</body>

</html>
