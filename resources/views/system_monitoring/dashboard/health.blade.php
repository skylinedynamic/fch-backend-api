@extends('system_monitoring.layouts.admin')

@section('title', 'System Health Monitor - FCH Admin')

@section('content')
<div class="p-10 animate-fade-in max-w-7xl mx-auto">
    <div class="mb-10 flex justify-between items-end">
        <div>
            <h3 class="text-4xl font-bold text-slate-800 tracking-tight">System Health</h3>
            <p class="text-slate-500 mt-2 text-lg font-light">Combined Resource Load & Service Integrity Monitor.</p>
        </div>
        <div class="flex items-center space-x-4">
             <div class="px-4 py-2 bg-white rounded-xl border border-slate-200 text-xs font-bold text-slate-500 shadow-sm">
                UPTIME: <span class="text-indigo-600">{{ $stats['uptime'] }}</span>
            </div>
            <a href="{{ route('dashboard.health') }}" class="p-3 bg-white hover:bg-slate-50 rounded-xl border border-slate-200 shadow-sm transition-all text-slate-400 hover:text-indigo-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
            </a>
        </div>
    </div>

    <!-- 1. Resource Gauges (The Performance Layer) -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-12">
        <!-- CPU Gauge -->
        <div class="bg-white rounded-[2.5rem] p-8 border border-slate-200 shadow-sm transition-all hover:shadow-lg relative overflow-hidden group">
            <div class="absolute top-0 right-0 p-6 opacity-10 transition-opacity group-hover:opacity-20 text-indigo-600">
                <svg class="w-16 h-16" fill="currentColor" viewBox="0 0 24 24"><path d="M11 20H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v7h-2V4H4v14h7v2zM21 16H13v2h8v-2zm0-3H13v2h8v-2z"></path></svg>
            </div>
            <h4 class="text-xs font-black uppercase tracking-[0.2em] text-slate-400 mb-6">Processor Load</h4>
            <div class="flex items-end justify-between mb-4">
                <span class="text-5xl font-black text-slate-800">{{ $stats['cpu'] }}<span class="text-2xl text-slate-300 ml-1">%</span></span>
                <span class="text-xs font-bold text-slate-400 mb-2 uppercase">Used</span>
            </div>
            <div class="w-full bg-slate-100 h-3 rounded-full overflow-hidden">
                <div class="h-full bg-indigo-500 rounded-full transition-all duration-1000" style="width: {{ $stats['cpu'] }}%"></div>
            </div>
        </div>

        <!-- RAM Gauge -->
        <div class="bg-white rounded-[2.5rem] p-8 border border-slate-200 shadow-sm transition-all hover:shadow-lg relative overflow-hidden group">
             <div class="absolute top-0 right-0 p-6 opacity-10 transition-opacity group-hover:opacity-20 text-emerald-600">
                <svg class="w-16 h-16" fill="currentColor" viewBox="0 0 24 24"><path d="M4 2h16a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"></path></svg>
            </div>
            <h4 class="text-xs font-black uppercase tracking-[0.2em] text-slate-400 mb-6">Memory Usage</h4>
            <div class="flex items-end justify-between mb-4">
                <span class="text-5xl font-black text-slate-800">{{ $stats['ram'] }}<span class="text-2xl text-slate-300 ml-1">%</span></span>
                <span class="text-xs font-bold text-slate-500 mb-2">{{ $stats['ram_used'] }}<span class="text-slate-300">/{{ $stats['ram_total'] }}GB</span></span>
            </div>
            <div class="w-full bg-slate-100 h-3 rounded-full overflow-hidden">
                <div class="h-full bg-emerald-500 rounded-full transition-all duration-1000" style="width: {{ $stats['ram'] }}%"></div>
            </div>
        </div>

        <!-- Disk Gauge -->
        <div class="bg-white rounded-[2.5rem] p-8 border border-slate-200 shadow-sm transition-all hover:shadow-lg relative overflow-hidden group">
             <div class="absolute top-0 right-0 p-6 opacity-10 transition-opacity group-hover:opacity-20 text-amber-600">
                <svg class="w-16 h-16" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"></path></svg>
            </div>
            <h4 class="text-xs font-black uppercase tracking-[0.2em] text-slate-400 mb-6">Storage Persistence</h4>
            <div class="flex items-end justify-between mb-4">
                <span class="text-5xl font-black text-slate-800">{{ $stats['disk'] }}<span class="text-2xl text-slate-300 ml-1">%</span></span>
                <span class="text-xs font-bold text-slate-500 mb-2">{{ $stats['disk_used'] }}<span class="text-slate-300">/{{ $stats['disk_total'] }}GB</span></span>
            </div>
            <div class="w-full bg-slate-100 h-3 rounded-full overflow-hidden">
                <div class="h-full bg-amber-500 rounded-full transition-all duration-1000" style="width: {{ $stats['disk'] }}%"></div>
            </div>
        </div>
    </div>

    <!-- 2. Diagnostic Integrity (The Spatie Health Layer) -->
    <div class="mb-6">
        <h4 class="text-xl font-bold text-slate-800 tracking-tight">Service Integrity Diagnostics</h4>
        <p class="text-slate-500 text-sm mt-1">Automated health checks for critical app dependencies.</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        @foreach($healthResults->storedCheckResults as $result)
        <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex items-start space-x-4 transition-all hover:border-slate-300">
            <div class="mt-1">
                @if($result->status === 'ok')
                    <div class="w-10 h-10 bg-emerald-50 text-emerald-600 rounded-2xl flex items-center justify-center">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                    </div>
                @elseif($result->status === 'warning')
                    <div class="w-10 h-10 bg-amber-50 text-amber-600 rounded-2xl flex items-center justify-center">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                    </div>
                @else
                    <div class="w-10 h-10 bg-rose-50 text-rose-600 rounded-2xl flex items-center justify-center">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </div>
                @endif
            </div>
            <div class="flex-1">
                <div class="flex items-center justify-between">
                    <h5 class="text-sm font-bold text-slate-800 uppercase tracking-wide">{{ $result->label }}</h5>
                    <span class="text-[10px] font-black uppercase tracking-widest px-2 py-1 rounded-lg {{ $result->status === 'ok' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">
                        {{ $result->status }}
                    </span>
                </div>
                <p class="text-[13px] text-slate-500 mt-2 font-medium leading-relaxed">
                    {{ $result->notificationMessage ?? $result->shortSummary }}
                </p>
                @if($result->status !== 'ok')
                <div class="mt-3 p-3 bg-slate-50 rounded-xl text-xs text-slate-600 italic border border-slate-100">
                    {{ $result->meta['message'] ?? 'Check logs for details' }}
                </div>
                @endif
            </div>
        </div>
        @endforeach

        <!-- Manual Service Check (Legacy Bridge) -->
        <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex items-start space-x-4">
            <div class="mt-1">
                <div class="w-10 h-10 bg-indigo-50 text-indigo-600 rounded-2xl flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path></svg>
                </div>
            </div>
            <div class="flex-1">
                <h5 class="text-sm font-bold text-slate-800 uppercase tracking-wide">Network Connectivity</h5>
                <div class="mt-4 grid grid-cols-2 gap-4">
                    @foreach($stats['services'] as $name => $status)
                    <div class="flex items-center justify-between p-3 bg-slate-50 rounded-2xl border border-slate-100">
                         <span class="text-xs font-bold text-slate-600">{{ $name }}</span>
                         <div class="w-2.5 h-2.5 rounded-full {{ $status ? 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.5)]' : 'bg-rose-500' }}"></div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
