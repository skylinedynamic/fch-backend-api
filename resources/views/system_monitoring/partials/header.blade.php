@php
    $currentRoute = Route::currentRouteName();
    $pageTitle = match($currentRoute) {
        'dashboard' => 'System Overview',
        'dashboard.logs' => 'System Monitoring',
        'dashboard.docs.admin' => 'API Reference (Admin)',
        'dashboard.docs.borrower' => 'API Reference (Borrower)',
        default => 'Management Console'
    };
@endphp

<header class="h-20 bg-white border-b border-slate-200 flex items-center justify-between px-10 shadow-sm z-10">
    <div>
        <h2 class="text-sm font-bold text-slate-400 uppercase tracking-[0.2em]">
            {{ $pageTitle }}
        </h2>
        <div class="flex items-center mt-0.5">
            <div class="w-2 h-2 rounded-full {{ app()->isLocal() ? 'bg-amber-500' : 'bg-emerald-500' }} animate-pulse mr-2"></div>
            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">{{ ucfirst(app()->environment()) }} Environment</span>
        </div>
    </div>
    
    <div class="flex items-center space-x-6">
        <div class="flex items-center space-x-4 px-6 border-r border-slate-200">
            <div class="text-right">
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">PHP Server</p>
                <p class="text-xs font-bold text-slate-700">v{{ phpversion() }}</p>
            </div>
        </div>
        <div class="text-right">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">{{ now()->format('l') }}</p>
            <p class="text-xs font-bold text-slate-700">{{ now()->format('M d, Y') }}</p>
        </div>
    </div>
</header>
