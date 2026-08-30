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

<header class="h-20 bg-slate-900 border-b border-white/5 flex items-center justify-between px-10 shadow-2xl z-20">
    <div class="flex items-center space-x-6">
        <div class="flex flex-col">
            <div class="flex items-center space-x-3">
                <div class="w-1.5 h-6 bg-indigo-500 rounded-full shadow-[0_0_12px_rgba(99,102,241,0.6)]"></div>
                <h2 class="text-xl font-black text-white italic tracking-tighter uppercase leading-none">
                    {{ $pageTitle }}
                </h2>
            </div>
            <div class="flex items-center mt-1.5 ml-4.5">
                <div class="w-1 h-1 rounded-full {{ app()->isLocal() ? 'bg-amber-500' : 'bg-emerald-500' }} mr-2 animate-pulse shadow-[0_0_8px_rgba(16,185,129,0.5)]"></div>
                <span class="text-[9px] font-black text-slate-500 uppercase tracking-[0.3em] leading-none">SYSTEM PROTOCOL // {{ app()->environment() }}</span>
            </div>
        </div>
    </div>
    
    <div class="flex items-center space-x-6">

        <div class="flex items-center space-x-4 px-4 border-x border-slate-800">
            <div class="text-right">
                <p class="text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest">PHP Server</p>
                <p class="text-xs font-bold text-slate-700 dark:text-slate-300">v{{ phpversion() }}</p>
            </div>
        </div>
        <div class="text-right">
            <p class="text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest">{{ now()->format('l') }}</p>
            <p class="text-xs font-bold text-slate-700 dark:text-slate-300">{{ now()->format('M d, Y') }}</p>
        </div>
    </div>
</header>


