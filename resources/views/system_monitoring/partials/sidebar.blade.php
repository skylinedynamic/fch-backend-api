@php
    $currentRoute = Route::currentRouteName();
@endphp

<aside class="w-72 bg-slate-900 dark:bg-slate-950 flex flex-col flex-shrink-0 z-20 shadow-2xl dark:border-r dark:border-slate-800 transition-colors duration-200">
    <div class="h-20 flex items-center px-8 border-b border-white/5 dark:border-slate-800">
        <div class="flex items-center space-x-3">
            <div class="w-10 h-10 rounded-xl bg-indigo-600 flex items-center justify-center text-white shadow-lg shadow-indigo-500/20">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
            </div>
            <h1 class="text-xl font-bold tracking-tight text-white uppercase">FCH <span class="font-light text-slate-400">Core</span></h1>
        </div>
    </div>
    
    <div class="flex-1 overflow-y-auto py-8">
        <div class="px-6 mb-6">
            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-[0.2em] mb-4 px-2">Main Menu</p>
            <nav class="space-y-1">
                <a href="{{ route('dashboard') }}" class="group flex items-center px-4 py-3.5 text-sm font-medium rounded-xl transition-all {{ $currentRoute === 'dashboard' ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'text-slate-400 hover:text-white hover:bg-white/5' }}">
                    <svg class="w-5 h-5 mr-3 transition-colors {{ $currentRoute === 'dashboard' ? 'text-white' : 'text-slate-500 group-hover:text-indigo-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                    Overview
                </a>
                
                <a href="{{ route('dashboard.logs') }}" class="group flex items-center px-4 py-3.5 text-sm font-medium rounded-xl transition-all {{ $currentRoute === 'dashboard.logs' ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'text-slate-400 hover:text-white hover:bg-white/5' }}">
                    <svg class="w-5 h-5 mr-3 transition-colors {{ $currentRoute === 'dashboard.logs' ? 'text-white' : 'text-slate-500 group-hover:text-amber-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1-1-1-1 1-1-1-1 1-1-1-1 1-1-1-1 1zM4 8.885V16a2 2 0 002 2h12a2 2 0 002-2V8.885a2 2 0 00-1.328-1.885L12.672 6.13a2 2 0 00-1.344 0L4.328 7a2 2 0 00-1.328 1.885z"></path></svg>
                    System Logs
                </a>

                <a href="{{ route('dashboard.scheduler') }}" class="group flex items-center px-4 py-3.5 text-sm font-medium rounded-xl transition-all {{ $currentRoute === 'dashboard.scheduler' ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'text-slate-400 hover:text-white hover:bg-white/5' }}">
                    <svg class="w-5 h-5 mr-3 transition-colors {{ $currentRoute === 'dashboard.scheduler' ? 'text-white' : 'text-slate-500 group-hover:text-amber-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    Task Scheduler
                </a>

                <a href="{{ route('dashboard.queues') }}" class="group flex items-center px-4 py-3.5 text-sm font-medium rounded-xl transition-all {{ $currentRoute === 'dashboard.queues' ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'text-slate-400 hover:text-white hover:bg-white/5' }}">
                    <svg class="w-5 h-5 mr-3 transition-colors {{ $currentRoute === 'dashboard.queues' ? 'text-white' : 'text-slate-500 group-hover:text-sky-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path></svg>
                    Queue Jobs
                </a>
                
                <a href="{{ route('dashboard.health') }}" class="group flex items-center px-4 py-3.5 text-sm font-medium rounded-xl transition-all {{ $currentRoute === 'dashboard.health' ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'text-slate-400 hover:text-white hover:bg-white/5' }}">
                    <svg class="w-5 h-5 mr-3 transition-colors {{ $currentRoute === 'dashboard.health' ? 'text-white' : 'text-slate-500 group-hover:text-emerald-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                    System Health
                </a>

                <a href="{{ route('dashboard.charts') }}" class="group flex items-center px-4 py-3.5 text-sm font-medium rounded-xl transition-all {{ $currentRoute === 'dashboard.charts' ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'text-slate-400 hover:text-white hover:bg-white/5' }}">
                    <svg class="w-5 h-5 mr-3 transition-colors {{ $currentRoute === 'dashboard.charts' ? 'text-white' : 'text-slate-500 group-hover:text-purple-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path></svg>
                    Resource History
                </a>

                <a href="{{ route('dashboard.backups') }}" class="group flex items-center px-4 py-3.5 text-sm font-medium rounded-xl transition-all {{ $currentRoute === 'dashboard.backups' ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'text-slate-400 hover:text-white hover:bg-white/5' }}">
                    <svg class="w-5 h-5 mr-3 transition-colors {{ $currentRoute === 'dashboard.backups' ? 'text-white' : 'text-slate-500 group-hover:text-rose-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path></svg>
                    Database Backups
                </a>
            </nav>
        </div>

        <div class="px-6 mb-6">
            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-[0.2em] mb-4 px-2">Documentation</p>
            <nav class="space-y-1">
                <!-- Parent: API Docs -->
                <div class="px-4 py-2 flex items-center text-xs font-bold text-slate-500 uppercase tracking-widest">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    Api Docs
                </div>

                <!-- Sub-menu nested items -->
                <div class="pl-4 space-y-1">
                    <a href="{{ route('dashboard.docs.admin') }}" class="group flex items-center px-4 py-2.5 text-[13px] font-medium rounded-xl transition-all {{ $currentRoute === 'dashboard.docs.admin' ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'text-slate-400 hover:text-white hover:bg-white/5' }}">
                        <div class="w-1.5 h-1.5 rounded-full mr-3 {{ $currentRoute === 'dashboard.docs.admin' ? 'bg-white' : 'bg-slate-600 group-hover:bg-purple-400' }}"></div>
                        Admin / Partner
                    </a>

                    <a href="{{ route('dashboard.docs.borrower') }}" class="group flex items-center px-4 py-2.5 text-[13px] font-medium rounded-xl transition-all {{ $currentRoute === 'dashboard.docs.borrower' ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'text-slate-400 hover:text-white hover:bg-white/5' }}">
                        <div class="w-1.5 h-1.5 rounded-full mr-3 {{ $currentRoute === 'dashboard.docs.borrower' ? 'bg-white' : 'bg-slate-600 group-hover:bg-emerald-400' }}"></div>
                        Borrower Portal
                    </a>

                </div>
            </nav>
        </div>
    </div>

    <div class="mx-6 mb-6 p-4 rounded-2xl bg-slate-800/50 border border-white/5 shadow-inner">
        <div class="flex items-center space-x-3 mb-4">
            <div class="w-10 h-10 rounded-full bg-gradient-to-tr from-indigo-500 to-purple-500 flex items-center justify-center text-white font-bold ring-2 ring-slate-800">
                {{ substr(Auth::user()->name ?? 'D', 0, 1) }}
            </div>
            <div class="min-w-0">
                <p class="text-sm font-bold text-white truncate">{{ Auth::user()->name ?? 'Developer' }}</p>
                <p class="text-[10px] text-indigo-400 uppercase tracking-widest font-bold">Project Lead</p>
            </div>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="w-full flex items-center justify-center space-x-2 py-2 px-4 rounded-xl bg-slate-800 text-slate-300 hover:text-white hover:bg-red-600 transition-all text-xs font-bold border border-white/5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                <span>Log out</span>
            </button>
        </form>
    </div>
</aside>
