{{--
    Email-change landing pages (confirm / cancel / undo), laid out like the
    portals' sign-in page: brand-coloured band with a wave, logo on the band,
    and the card below it.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('email_change.page_title') }} · {{ $siteName }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand: {{ $brandPrimary }};
            --brand-ink: {{ $brandPrimaryInk }};
            --on-brand: {{ $brandOnPrimary }};
            --body-bg: #f3f3f9;
            --text: #212529;
            --muted: #878a99;
            --border: #ced4da;
            --danger: #f06548;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; }
        body { min-height: 100vh; display: flex; flex-direction: column; background: var(--body-bg); color: var(--text);
               font-family: Poppins, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 13px; }

        /* The band behind the top of the page. */
        .band { position: absolute; top: 0; left: 0; right: 0; height: 380px; overflow: hidden;
                background: linear-gradient(to right, var(--brand-ink), var(--brand)); }
        .band svg { position: absolute; bottom: 0; left: 0; width: 100%; height: auto; fill: var(--body-bg); }

        .content { position: relative; z-index: 2; flex: 1; width: 100%; max-width: 480px; margin: 0 auto; padding: 88px 16px 60px; }
        .logo-wrap { text-align: center; margin-bottom: 24px; }
        .logo { display: inline-block; height: 70px; max-width: 250px; object-fit: contain; }
        .site { display: inline-block; font-size: 24px; font-weight: 600; color: var(--on-brand); }

        .card { background: #fff; border-radius: 4px; padding: 32px 28px; box-shadow: 0 1px 2px rgba(56, 65, 74, .15); }
        .head { text-align: center; margin-bottom: 24px; }
        .icon { width: 56px; height: 56px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
                font-size: 26px; margin-bottom: 16px; }
        .icon.success { background: #daf4f0; color: #0ab39c; }
        .icon.warning { background: #fef4e4; color: #d29c40; }
        .icon.info { background: color-mix(in srgb, var(--brand) 12%, #fff); color: var(--brand); }
        h1 { font-size: 16px; font-weight: 500; color: var(--brand); margin: 0 0 6px; }
        p { color: var(--muted); line-height: 1.6; margin: 0; word-break: break-word; }

        label { display: block; font-weight: 500; margin-bottom: 8px; }
        input[type=password] { width: 100%; padding: 8px 15px; font: inherit; color: var(--text); background: #fff;
                               border: 1px solid var(--border); border-radius: 4px; outline: none; transition: border-color .15s, box-shadow .15s; }
        input[type=password]:focus { border-color: #b1b8c0; }
        input.is-invalid { border-color: var(--danger); }
        .error { color: var(--danger); font-size: 12px; margin-top: 6px; }

        .btn { display: block; width: 100%; margin-top: 24px; padding: 9px 15px; border: 0; border-radius: 4px;
               background: var(--brand); color: var(--on-brand); font: inherit; font-weight: 500; text-align: center;
               text-decoration: none; cursor: pointer; }
        .btn:hover { filter: brightness(.92); }

        footer { position: relative; z-index: 2; text-align: center; color: var(--muted); padding: 20px 16px; }
        @media (max-width: 575.98px) { .band { height: 280px; } .content { padding-top: 48px; } }
    </style>
</head>
<body>
    <div class="band" aria-hidden="true">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 120" preserveAspectRatio="none">
            <path d="M 0,36 C 144,53.6 432,123.2 720,124 C 1008,124.8 1296,56.8 1440,40L1440 140L0 140z"></path>
        </svg>
    </div>

    <main class="content">
        <div class="logo-wrap">
            @if ($logoUrl)
                <img class="logo" src="{{ $logoUrl }}" alt="{{ $siteName }}">
            @else
                <span class="site">{{ $siteName }}</span>
            @endif
        </div>

        <div class="card">
            <div class="head">
                <div class="icon {{ $tone }}" aria-hidden="true">{{ $tone === 'success' ? '✓' : ($tone === 'info' ? '✉' : '!') }}</div>
                <h1>{{ $title }}</h1>
                <p>{{ $body }}</p>
            </div>

            @if ($form)
                <form method="POST" action="{{ $form['action'] }}">
                    @csrf
                    @if ($form['password'])
                        <label for="password">{{ __('email_change.password_label') }} <span style="color: var(--danger)">*</span></label>
                        <input id="password" type="password" name="password" class="{{ $error ? 'is-invalid' : '' }}"
                               placeholder="{{ __('email_change.password_placeholder') }}" autocomplete="current-password" required autofocus>
                    @endif
                    @if ($error)
                        <div class="error" role="alert">{{ $error }}</div>
                    @endif
                    <button type="submit" class="btn">{{ $form['button'] }}</button>
                </form>
            @elseif ($loginUrl)
                <a class="btn" href="{{ $loginUrl }}">{{ __('email_change.sign_in') }}</a>
            @endif
        </div>
    </main>

    <footer>&copy; {{ date('Y') }} {{ $siteName }}. All rights reserved.</footer>
</body>
</html>
