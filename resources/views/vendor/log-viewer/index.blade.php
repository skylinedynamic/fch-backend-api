<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Always dark mode --}}
    <script>localStorage.setItem('logViewerTheme', 'Dark');</script>

    @if ($assetsPublished)
        <link rel="shortcut icon" href="{{ asset(mix('img/log-viewer-32.png', config('log-viewer.assets_path'))) }}">
    @else
        {!! \Opcodes\LogViewer\Facades\LogViewer::favicon() !!}
    @endif

    <title>Log Viewer{{ config('app.name') ? ' - ' . config('app.name') : '' }}</title>

    <!-- Style sheets-->
    @if ($assetsPublished)
        <link href="{{ asset(mix('app.css', config('log-viewer.assets_path'))) }}" rel="stylesheet" onerror="alert('app.css failed to load. Please refresh the page, re-publish Log Viewer assets, or fix routing for vendor assets.')">
    @else
        {!! \Opcodes\LogViewer\Facades\LogViewer::css() !!}
    @endif

    {{-- Overrides --}}
    <link href="{{ asset('css/monitoring-theme.css') }}" rel="stylesheet">

    <style>
        
     </style>   

</head>

<body class="h-full px-3 lg:px-5 transition-colors duration-200" style="background-color: #020617;">
<div id="log-viewer" class="flex h-full max-h-screen max-w-full overflow-hidden">
    <router-view></router-view>
</div>

<!-- Always dark mode - no sync script needed -->
<script>
    window.LogViewer = @json($logViewerScriptVariables);

    // Add additional headers for LogViewer requests like so:
    // window.LogViewer.headers['Authorization'] = 'Bearer xxxxxxx';
</script>
@if ($assetsPublished)
    <script src="{{ asset(mix('app.js', config('log-viewer.assets_path'))) }}" onerror="alert('app.js failed to load. Please refresh the page, re-publish Log Viewer assets, or fix routing for vendor assets.')"></script>
@else
    {!! \Opcodes\LogViewer\Facades\LogViewer::js() !!}
@endif
</body>
</html>
