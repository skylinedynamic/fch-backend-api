<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'FCH Admin Dashboard')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .sidebar-active { 
            background: linear-gradient(to right, rgba(99, 102, 241, 0.1), rgba(99, 102, 241, 0.05));
            color: #6366f1;
            border-left: 4px solid #6366f1;
        }
        .animate-fade-in { animation: fadeIn 0.3s ease-in-out; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
    @stack('styles')
</head>
<body class="h-full overflow-hidden flex bg-slate-50">

    @include('system_monitoring.partials.sidebar')

    <div class="flex-1 flex flex-col min-w-0 h-full">
        @include('system_monitoring.partials.header')

        <!-- Dynamic Content -->
        <main class="flex-1 relative overflow-y-auto overflow-x-hidden">
            @yield('content')
        </main>
    </div>

    @stack('scripts')
</body>
</html>
