<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', $siteName ?? config('app.name'))</title>
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
                <img src="{{ $logoUrl }}" alt="{{ $siteName }}" class="logo"
                    style="display: block; margin: 0 auto; width: 200px; height: auto;">
            </div>
            <!-- Content Area -->
            <div class="content" style="padding: 0 30px; text-align: left;">
                @yield('content')
            </div>

            <!-- Bottom Footer -->
            <div class="bottom-footer"
                style="background-color: #1a2e44; padding: 16px 20px; text-align: center; font-size: 13px; color: #fff; line-height: 1.6;">

                <p style="margin: 0; color: #fff;">&copy; {{ date('Y') }} {{ $siteName ?? config('app.name') }}. All
                    rights reserved.
                </p>

            </div>
        </div>
    </div>
</body>

</html>