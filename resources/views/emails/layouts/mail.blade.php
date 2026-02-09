<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', config('app.name'))</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        {!! file_get_contents(public_path('css/email.css')) !!}
    </style>
</head>

<body>
    <div class="wrapper">
        <div class="container">
            <!-- Logo Section -->
            <div class="header-logo">
                {{-- Logo retrieval logic --}}
                @php
                    $logoPath = public_path('logo-mail.png');
                    $logoSrc = '';
                    if (file_exists($logoPath)) {
                        try {
                            $logoSrc = $message->embed($logoPath);
                        } catch (\Exception $e) {
                            $logoSrc = asset('logo_mail.jpeg');
                        }
                    } else {
                        $logoSrc = asset('logo_mail.jpeg');
                    }
                @endphp
                <img src="{{ $logoSrc }}" alt="{{ config('app.name') }}" class="logo">
            </div>

            <!-- Content Area -->
            <div class="content">
                @yield('content')
            </div>

            <!-- Bottom Footer -->
            <div class="bottom-footer">
                @section('footer')
                    <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
                @show
            </div>
        </div>
    </div>
</body>

</html>
