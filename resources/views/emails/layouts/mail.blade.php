<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <title>@yield('title', $siteName ?? config('app.name'))</title>
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

        img {
            -ms-interpolation-mode: bicubic;
            border: 0;
            height: auto;
            line-height: 100%;
            outline: none;
            text-decoration: none;
        }

        /* Desktop base */
        .email-wrapper {
            width: 100%;
            background-color: #f3f3f9;
            padding: 40px 0;
        }

        .email-container {
            max-width: 600px;
            width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            box-shadow: 0 1px 2px rgba(56, 65, 74, 0.15);
        }

        .header-logo {
            background-color: #ffffff;
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid #e9ebec;
        }

        .header-logo img {
            display: block;
            margin: 0 auto;
            height: 60px;
            width: auto;
            max-width: 100%;
        }

        .email-content {
            padding: 0 30px;
            text-align: left;
            font-size: 14px;
            line-height: 1.6;
            color: #495057;
        }

        .bottom-footer {
            background-color: #1a2e44;
            padding: 16px 20px;
            text-align: center;
            font-size: 13px;
            color: #ffffff;
            line-height: 1.6;
        }

        .bottom-footer p {
            margin: 0;
            color: #ffffff;
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

            .header-logo img {
                height: 55px !important;
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
                padding: 20px 0 !important;
            }

            .email-container {
                width: 100% !important;
                max-width: 100% !important;
                box-shadow: none !important;
            }

            .header-logo {
                padding: 15px !important;
            }

            .header-logo img {
                height: 50px !important;
            }

            .email-content {
                padding: 0 20px !important;
                font-size: 14px !important;
            }

            .bottom-footer {
                padding: 12px 15px !important;
                font-size: 12px !important;
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

            .header-logo img {
                height: 45px !important;
            }

            .email-content {
                padding: 0 15px !important;
                font-size: 13px !important;
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
</head>

<body
    style="margin: 0; padding: 0; background-color: #f3f3f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; font-size: 14px; color: #495057; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;">
    <div class="wrapper" style="width: 100%; background-color: #f3f3f9; padding: 40px 0;">
        <div class="container"
            style="max-width: 600px; margin: 0 auto; background-color: #ffffff; box-shadow: 0 1px 2px rgba(56, 65, 74, 0.15); border-radius: 0; overflow: hidden;">
            <!-- Top Bar: Dark Blue -->

            <!-- Logo Section -->
            <div class="header-logo"
                style="background-color: #ffffff; padding: 20px; text-align: center; border-bottom: 1px solid #e9ebec; margin-top: 20px;">
                @if(!empty($logoUrl))
                    <img src="{{ $logoUrl }}" alt="{{ $siteName ?? config('app.name') }}" class="logo"
                        style="display: block; margin: 0 auto; width: 200px; height: auto;">
                @else
                    <h2 style="margin: 0; color: #1a2e44;">{{ $siteName ?? config('app.name') }}</h2>
                @endif
            </div>
            <!-- Content Area -->
            <div class="content" style="padding: 0 30px; text-align: left;">
                @yield('content')
            </div>

            <!-- Bottom Footer -->
            <table class="bottom-footer" width="100%" ; cellpadding="0" ;cellspacing="0" ; border="0"
                style="background-color: #1a2e44; padding: 16px 20px; text-align: center; font-size: 13px; color: #ffffff; line-height: 1.6;">
                <tr>
                    <td style="padding: 0;">
                        <p style="margin: 0; color: #ffffff; font-size: 13px;">&copy; {{ date('Y') }}
                            {{ $siteName ?? config('app.name') }}. All rights reserved.
                        </p>
                    </td>
                </tr>
            </table>
            </td>
            </tr>
            </table>
            </td>
            </tr>
            </table>
</body>

</html>