@extends('system_monitoring.layouts.admin')

@section('title', 'System Health Status - FCH Admin')

@section('content')
<div class="p-8 animate-fade-in max-w-7xl mx-auto space-y-10">
    <div class="flex justify-between items-end">
        <div>
            <h3 class="text-3xl font-black text-slate-800 dark:text-white tracking-tight">System Health</h3>
            <p class="text-slate-500 dark:text-slate-400 mt-1 text-sm font-medium">Deep diagnostics and real-time resource integrity.</p>
        </div>
        <div class="flex items-center space-x-3">
             <div class="px-4 py-2 bg-white dark:bg-slate-900 rounded-xl border border-slate-100 dark:border-slate-800 text-[10px] font-black text-slate-400 dark:text-slate-500 shadow-sm uppercase tracking-widest transition-colors">
                Uptime: <span class="text-indigo-600 dark:text-indigo-400 ml-1">{{ $stats['uptime'] }}</span>
            </div>
            <a href="{{ route('dashboard.health') }}" class="p-2.5 bg-white dark:bg-slate-900 hover:bg-slate-50 dark:hover:bg-slate-800 rounded-xl border border-slate-100 dark:border-slate-800 shadow-sm transition-all text-slate-400 dark:text-slate-500 hover:text-indigo-600 dark:hover:text-indigo-400">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
            </a>
        </div>
    </div>

    <!-- 1. Resource Gauges (The Performance Layer) -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- CPU Gauge -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl p-6 border border-slate-100 dark:border-slate-800 shadow-sm dark:shadow-none transition-all hover:border-indigo-100 dark:hover:border-indigo-900 relative overflow-hidden group">
            <h4 class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 dark:text-slate-500 mb-6">Processor Load</h4>
            <div class="flex items-end justify-between mb-4">
                <span class="text-4xl font-black text-slate-800 dark:text-white">{{ $stats['cpu'] }}<span class="text-xl text-slate-300 dark:text-slate-600 ml-1">%</span></span>
                <span class="text-[10px] font-bold text-slate-400 dark:text-slate-500 mb-2 uppercase">Core Load</span>
            </div>
            <div class="w-full bg-slate-50 dark:bg-slate-800 h-2 rounded-full overflow-hidden">
                <div class="h-full bg-indigo-500 rounded-full transition-all duration-1000" style="width: {{ $stats['cpu'] }}%"></div>
            </div>
        </div>

        <!-- RAM Gauge -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl p-6 border border-slate-100 dark:border-slate-800 shadow-sm dark:shadow-none transition-all hover:border-emerald-100 dark:hover:border-emerald-900 relative overflow-hidden group">
            <h4 class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 dark:text-slate-500 mb-6">Memory Usage</h4>
            <div class="flex items-end justify-between mb-4">
                <span class="text-4xl font-black text-slate-800 dark:text-white">{{ $stats['ram'] }}<span class="text-xl text-slate-300 dark:text-slate-600 ml-1">%</span></span>
                <span class="text-[10px] font-bold text-slate-500 dark:text-slate-400 mb-2">{{ $stats['ram_used'] }}<span class="text-slate-300 dark:text-slate-600">/{{ $stats['ram_total'] }}GB</span></span>
            </div>
            <div class="w-full bg-slate-50 dark:bg-slate-800 h-2 rounded-full overflow-hidden">
                <div class="h-full bg-emerald-500 rounded-full transition-all duration-1000" style="width: {{ $stats['ram'] }}%"></div>
            </div>
        </div>

        <!-- Disk Gauge -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl p-6 border border-slate-100 dark:border-slate-800 shadow-sm dark:shadow-none transition-all hover:border-amber-100 dark:hover:border-amber-900 relative overflow-hidden group">
            <h4 class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 dark:text-slate-500 mb-6">Storage Persistence</h4>
            <div class="flex items-end justify-between mb-4">
                <span class="text-4xl font-black text-slate-800 dark:text-white">{{ $stats['disk'] }}<span class="text-xl text-slate-300 dark:text-slate-600 ml-1">%</span></span>
                <span class="text-[10px] font-bold text-slate-500 dark:text-slate-400 mb-2">{{ $stats['disk_used'] }}<span class="text-slate-300 dark:text-slate-600">/{{ $stats['disk_total'] }}GB</span></span>
            </div>
            <div class="w-full bg-slate-50 dark:bg-slate-800 h-2 rounded-full overflow-hidden">
                <div class="h-full bg-amber-500 rounded-full transition-all duration-1000" style="width: {{ $stats['disk'] }}%"></div>
            </div>
        </div>
    </div>

    <!-- 2. Diagnostic Integrity (The Spatie Health Layer) -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="space-y-4">
            <h4 class="text-xs font-black uppercase tracking-widest text-slate-400 dark:text-slate-500 px-2">Integrity Diagnostics</h4>
            @foreach($healthResults->storedCheckResults as $result)
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-slate-100 dark:border-slate-800 shadow-sm dark:shadow-none flex items-start space-x-4 transition-all hover:border-slate-200 dark:hover:border-slate-700">
                <div class="mt-0.5">
                    @if($result->status === 'ok')
                        <div class="w-8 h-8 bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 rounded-xl flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                        </div>
                    @elseif($result->status === 'warning')
                        <div class="w-8 h-8 bg-amber-50 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 rounded-xl flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        </div>
                    @else
                        <div class="w-8 h-8 bg-rose-50 dark:bg-rose-900/30 text-rose-600 dark:text-rose-400 rounded-xl flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </div>
                    @endif
                </div>
                <div class="flex-1">
                    <div class="flex items-center justify-between">
                        <h5 class="text-xs font-bold text-slate-800 dark:text-slate-200 uppercase tracking-wide">{{ $result->label }}</h5>
                        <span class="text-[9px] font-black uppercase tracking-widest px-2 py-0.5 rounded-lg {{ $result->status === 'ok' ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400' : 'bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-400' }}">
                            {{ $result->status }}
                        </span>
                    </div>
                    <p class="text-[12px] text-slate-500 dark:text-slate-400 mt-1 font-medium leading-relaxed">
                        {{ $result->notificationMessage ?? $result->shortSummary }}
                    </p>
                </div>
            </div>
            @endforeach
        </div>

        <div class="space-y-4">
             <h4 class="text-xs font-black uppercase tracking-widest text-slate-400 dark:text-slate-500 px-2">Network Snapshot</h4>
             <!-- Manual Service Check (Legacy Bridge) -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-6 border border-slate-100 dark:border-slate-800 shadow-sm dark:shadow-none">
                <div class="grid grid-cols-1 gap-3">
                    @foreach($stats['services'] as $name => $status)
                    <div class="flex items-center justify-between p-4 bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-50 dark:border-slate-800 shadow-inner dark:shadow-none">
                         <span class="text-sm font-bold text-slate-600 dark:text-slate-300">{{ $name }}</span>
                         <div class="flex items-center">
                             <span class="text-[9px] font-black uppercase mr-3 {{ $status ? 'text-emerald-500 dark:text-emerald-400' : 'text-rose-500 dark:text-rose-400' }}">{{ $status ? 'Active' : 'Offline' }}</span>
                             <div class="w-2 h-2 rounded-full {{ $status ? 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.5)]' : 'bg-rose-500' }}"></div>
                         </div>
                    </div>
                    @endforeach
                </div>
                <div class="mt-6 p-4 bg-indigo-600 dark:bg-indigo-700 rounded-xl text-white">
                     <p class="text-[10px] font-black uppercase tracking-widest opacity-60 text-white">Node Integrity</p>
                     <p class="text-sm font-bold mt-1 text-white">Gateway verified via {{ config('app.url') }}</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
