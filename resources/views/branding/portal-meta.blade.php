{{-- Included into each portal's index.html by Nginx (SSI); see BrandingController::meta(). --}}
<title>{{ $meta['title'] }}</title>
<meta name="description" content="{{ $meta['description'] }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $meta['site_name'] }}">
<meta property="og:title" content="{{ $meta['title'] }}">
<meta property="og:description" content="{{ $meta['description'] }}">
@if ($meta['url'])
<meta property="og:url" content="{{ $meta['url'] }}">
@endif
@if ($meta['image'])
<meta property="og:image" content="{{ $meta['image'] }}">
<meta property="og:image:alt" content="{{ $meta['site_name'] }}">
@endif
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="{{ $meta['title'] }}">
<meta name="twitter:description" content="{{ $meta['description'] }}">
@if ($meta['image'])
<meta name="twitter:image" content="{{ $meta['image'] }}">
@endif
@if ($meta['favicon'])
<link rel="icon" href="{{ $meta['favicon'] }}">
@endif
