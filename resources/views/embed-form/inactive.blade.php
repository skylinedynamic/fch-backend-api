<!DOCTYPE html>
<html lang="en">

{{--
    R3-BUG-014: an embed form switched off ("Disabled - Public form will show an
    inactive message"). In the partner's name, with nothing to fill in or send,
    so a visitor - or the site around the iframe - can tell the form is paused
    rather than gone.
--}}
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>{{ $config->form_title }} &mdash; {{ $partnerName }}</title>
    @if(!empty($faviconUrl))
        <link rel="icon" type="image/x-icon" href="{{ $faviconUrl }}">
    @endif
    <style>
        :root { --primary: {{ $primaryColor }}; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            background: #f8fafc;
            color: #0f172a;
            font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .card {
            width: 100%;
            max-width: 480px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-top: 4px solid var(--primary);
            border-radius: 12px;
            padding: 32px 28px;
            text-align: center;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.06);
        }
        .logo { max-height: 56px; max-width: 200px; margin-bottom: 20px; }
        .initial {
            width: 56px; height: 56px; margin: 0 auto 20px;
            border-radius: 50%; background: var(--primary); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; font-weight: 700;
        }
        h1 { font-size: 20px; margin: 0 0 12px; }
        p { margin: 0; color: #475569; line-height: 1.6; }
        .footer { margin-top: 24px; font-size: 13px; color: #94a3b8; }
    </style>
</head>

<body>
    <main class="card">
        @if(!empty($logoUrl))
            <img src="{{ $logoUrl }}" alt="{{ $partnerName }} Logo" class="logo">
        @else
            <div class="initial" aria-hidden="true">{{ strtoupper(substr($partnerName, 0, 1)) }}</div>
        @endif

        <h1>{{ __('embed_form.inactive_title') }}</h1>
        <p>{{ __('embed_form.inactive_message', ['partner' => $partnerName]) }}</p>

        <div class="footer">{{ $config->form_title }} &bull; {{ $partnerName }}</div>
    </main>
</body>

</html>
