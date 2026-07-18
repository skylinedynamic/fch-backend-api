<!doctype html>
<html lang="en" data-theme="{{ $config->get('ui.theme', 'light') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="color-scheme" content="{{ $config->get('ui.theme', 'light') }}">
    <title>{{ $config->get('ui.title', config('app.name') . ' - API Docs') }}</title>

    <script src="https://unpkg.com/@stoplight/elements@8.4.2/web-components.min.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/@stoplight/elements@8.4.2/styles.min.css">

    <script>
        const originalFetch = window.fetch;

        // intercept TryIt requests and add the XSRF-TOKEN header,
        // which is necessary for Sanctum cookie-based authentication to work correctly
        window.fetch = (url, options) => {
            const CSRF_TOKEN_COOKIE_KEY = "XSRF-TOKEN";
            const CSRF_TOKEN_HEADER_KEY = "X-XSRF-TOKEN";
            const getCookieValue = (key) => {
                const cookie = document.cookie.split(';').find((cookie) => cookie.trim().startsWith(key));
                return cookie?.split("=")[1];
            };

            const updateFetchHeaders = (
                headers,
                headerKey,
                headerValue,
            ) => {
                if (headers instanceof Headers) {
                    headers.set(headerKey, headerValue);
                } else if (Array.isArray(headers)) {
                    headers.push([headerKey, headerValue]);
                } else if (headers) {
                    headers[headerKey] = headerValue;
                }
            };
            const csrfToken = getCookieValue(CSRF_TOKEN_COOKIE_KEY);
            if (csrfToken) {
                const { headers = new Headers() } = options || {};
                updateFetchHeaders(headers, CSRF_TOKEN_HEADER_KEY, decodeURIComponent(csrfToken));
                return originalFetch(url, {
                    ...options,
                    headers,
                });
            }

            return originalFetch(url, options);
        };
    </script>

    <link href="{{ asset('css/monitoring-theme.css') }}" rel="stylesheet">
    <style>
        html, body { margin:0; height:100%; transition: all 0.2s ease; }
        body { background-color: var(--bg-primary); }
    </style>
</head>
<body style="height: 100vh; overflow-y: hidden" class="transition-colors duration-200">
<elements-api
    id="docs"
    tryItCredentialsPolicy="{{ $config->get('ui.try_it_credentials_policy', 'include') }}"
    router="hash"
    @if($config->get('ui.hide_try_it')) hideTryIt="true" @endif
    @if($config->get('ui.hide_schemas')) hideSchemas="true" @endif
    @if($config->get('ui.logo')) logo="{{ $config->get('ui.logo') }}" @endif
    @if($config->get('ui.layout')) layout="{{ $config->get('ui.layout') }}" @endif
/>
<script>
    (async () => {
        const docs = document.getElementById('docs');
        docs.apiDescriptionDocument = @json($spec);
    })();
</script>

<!-- Theme Sync for FCH Dashboard -->
<script>
    (function() {
        const root = document.documentElement;
        const sync = () => {
            let isDark = false;
            try {
                if (window.self !== window.top && window.parent.document.documentElement.classList.contains('dark')) {
                    isDark = true;
                }
            } catch (e) {}

            if (!isDark) {
                isDark = localStorage.getItem('theme') === 'dark' || 
                         (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches);
            }

            if (isDark) {
                root.classList.add('dark');
                root.setAttribute('data-theme', 'dark');
                document.querySelector('meta[name="color-scheme"]').setAttribute('content', 'dark');
            } else {
                root.classList.remove('dark');
                root.setAttribute('data-theme', 'light');
                document.querySelector('meta[name="color-scheme"]').setAttribute('content', 'light');
            }
        };

        sync();
        if (window.self !== window.top) setInterval(sync, 2000);
    })();
</script>
</body>
</html>
