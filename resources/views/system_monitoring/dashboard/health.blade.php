@extends('system_monitoring.layouts.admin')

@section('title', 'System Health Status - FCH Admin')

@section('content')
<div class="px-10 py-12 animate-fade-in max-w-7xl mx-auto space-y-12">
    {{-- Header Section --}}
    <div class="flex justify-between items-end pb-8 border-b border-white/5">
        <div class="relative">
            <div class="absolute -left-4 top-1 w-1 h-12 bg-indigo-500 rounded-full shadow-[0_0_15px_rgba(99,102,241,0.5)]"></div>
            <h3 class="text-4xl font-black text-white tracking-tighter uppercase italic">
                System <span class="text-indigo-400">Vitality</span>
            </h3>
            <p class="text-slate-500 mt-2 text-xs font-black uppercase tracking-[0.3em] flex items-center">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full mr-3 animate-pulse"></span>
                DEEP DIAGNOSTICS & REAL-TIME RESOURCE INTEGRITY
            </p>
        </div>
        
        <div class="flex items-center space-x-4">
             <div class="group px-6 py-3 bg-slate-900/50 backdrop-blur-xl rounded-2xl border border-white/5 flex flex-col items-end transition-all hover:border-indigo-500/30">
                <span class="text-[9px] font-black text-slate-500 uppercase tracking-widest leading-none mb-1">System Uptime</span>
                <span class="text-xl font-black text-indigo-400 tabular-nums lowercase italic">{{ $stats['uptime'] }}</span>
            </div>
            
            <a href="{{ route('dashboard.health') }}" class="p-4 bg-slate-900/50 backdrop-blur-xl hover:bg-indigo-600/10 rounded-2xl border border-white/5 text-slate-400 hover:text-indigo-400 transition-all shadow-2xl hover:scale-105 active:scale-95 group">
                <svg class="w-5 h-5 group-hover:rotate-180 transition-transform duration-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
            </a>
        </div>
    </div>

    <!-- 1. Resource Gauges (The Performance Layer) -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
        {{-- CPU Gauge --}}
        <div class="relative group">
            <div class="absolute -inset-0.5 bg-gradient-to-r from-indigo-500/20 to-indigo-500/5 rounded-[2rem] blur opacity-0 group-hover:opacity-100 transition duration-1000"></div>
            <div class="relative bg-slate-900/40 backdrop-blur-3xl rounded-[1.5rem] border border-white/5 p-8 transition-all duration-500">
                <div class="flex items-center justify-between mb-8">
                    <span class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-500">PROCESSOR LOAD</span>
                    <div class="w-8 h-8 bg-indigo-500/10 rounded-lg flex items-center justify-center text-indigo-500">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path></svg>
                    </div>
                </div>
                <div class="flex items-end justify-between mb-6">
                    <span class="text-5xl font-black text-white tabular-nums tracking-tighter italic">{{ $stats['cpu'] }}<span class="text-xl text-slate-600 ml-1 not-italic">%</span></span>
                    <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Core Utilization</span>
                </div>
                <div class="w-full bg-slate-950 h-2.5 rounded-full overflow-hidden shadow-inner p-0.5">
                    <div class="h-full bg-indigo-500 rounded-full shadow-[0_0_15px_rgba(99,102,241,0.6)] transition-all duration-1000" style="width: {{ $stats['cpu'] }}%"></div>
                </div>
            </div>
        </div>

        {{-- RAM Gauge --}}
        <div class="relative group">
            <div class="absolute -inset-0.5 bg-gradient-to-r from-emerald-500/20 to-emerald-500/5 rounded-[2rem] blur opacity-0 group-hover:opacity-100 transition duration-1000"></div>
            <div class="relative bg-slate-900/40 backdrop-blur-3xl rounded-[1.5rem] border border-white/5 p-8 transition-all duration-500">
                <div class="flex items-center justify-between mb-8">
                    <span class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-500">MEMORY OVERHEAD</span>
                    <div class="w-8 h-8 bg-emerald-500/10 rounded-lg flex items-center justify-center text-emerald-500">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                    </div>
                </div>
                <div class="flex items-end justify-between mb-6">
                    <span class="text-5xl font-black text-white tabular-nums tracking-tighter italic">{{ $stats['ram'] }}<span class="text-xl text-slate-600 ml-1 not-italic">%</span></span>
                    <div class="flex flex-col items-end mb-1">
                        <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest">{{ $stats['ram_used'] }} GB USED</span>
                        <span class="text-[9px] font-bold text-slate-700 uppercase">Limit: {{ $stats['ram_total'] }} GB</span>
                    </div>
                </div>
                <div class="w-full bg-slate-950 h-2.5 rounded-full overflow-hidden shadow-inner p-0.5">
                    <div class="h-full bg-emerald-500 rounded-full shadow-[0_0_15px_rgba(16,185,129,0.6)] transition-all duration-1000" style="width: {{ $stats['ram'] }}%"></div>
                </div>
            </div>
        </div>

        {{-- Disk Gauge --}}
        <div class="relative group">
            <div class="absolute -inset-0.5 bg-gradient-to-r from-amber-500/20 to-amber-500/5 rounded-[2rem] blur opacity-0 group-hover:opacity-100 transition duration-1000"></div>
            <div class="relative bg-slate-900/40 backdrop-blur-3xl rounded-[1.5rem] border border-white/5 p-8 transition-all duration-500">
                <div class="flex items-center justify-between mb-8">
                    <span class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-500">STORAGE PERSISTENCE</span>
                    <div class="w-8 h-8 bg-amber-500/10 rounded-lg flex items-center justify-center text-amber-500">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path></svg>
                    </div>
                </div>
                <div class="flex items-end justify-between mb-6">
                    <span class="text-5xl font-black text-white tabular-nums tracking-tighter italic">{{ $stats['disk'] }}<span class="text-xl text-slate-600 ml-1 not-italic">%</span></span>
                    <div class="flex flex-col items-end mb-1">
                        <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest text-right">{{ $stats['disk_used'] }} GB FILLED</span>
                        <span class="text-[9px] font-bold text-slate-700 uppercase">Vault: {{ $stats['disk_total'] }} GB</span>
                    </div>
                </div>
                <div class="w-full bg-slate-950 h-2.5 rounded-full overflow-hidden shadow-inner p-0.5">
                    <div class="h-full bg-amber-500 rounded-full shadow-[0_0_15px_rgba(245,158,11,0.6)] transition-all duration-1000" style="width: {{ $stats['disk'] }}%"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. Diagnostic Integrity (The Spatie Health Layer) -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-10">
        <div class="space-y-6">
            <h4 class="text-xs font-black uppercase tracking-[0.3em] text-slate-600 px-2 italic">Integrity Diagnostics</h4>
            <div class="grid gap-4">
                @if($healthResults && $healthResults->storedCheckResults)
                    @forelse($healthResults->storedCheckResults as $result)
                    <div class="group relative">
                        <div class="relative bg-slate-900/40 backdrop-blur-3xl rounded-2xl p-5 border border-white/5 flex items-center space-x-6 transition-all hover:bg-slate-900/60 hover:border-white/10">
                            <div class="relative">
                                @if($result->status === 'ok')
                                    <div class="w-12 h-12 bg-emerald-500/10 text-emerald-500 rounded-xl border border-emerald-500/20 flex items-center justify-center shadow-[0_0_15px_rgba(16,185,129,0.15)]">
                                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                                    </div>
                                @elseif($result->status === 'warning')
                                    <div class="w-12 h-12 bg-amber-500/10 text-amber-500 rounded-xl border border-amber-500/20 flex items-center justify-center shadow-[0_0_15px_rgba(245,158,11,0.15)] animate-pulse">
                                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                                    </div>
                                @else
                                    <div class="w-12 h-12 bg-rose-500/10 text-rose-500 rounded-xl border border-rose-500/20 flex items-center justify-center shadow-[0_0_15px_rgba(244,63,94,0.15)]">
                                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"></path></svg>
                                    </div>
                                @endif
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center justify-between mb-1">
                                    <h5 class="text-xs font-black text-slate-200 uppercase tracking-widest">{{ $result->label }}</h5>
                                    <span class="text-[8px] font-black uppercase tracking-widest px-2 py-0.5 rounded-md italic border
                                        {{ $result->status === 'ok' ? 'bg-emerald-500/10 text-emerald-500 border-emerald-500/20' : 'bg-rose-500/10 text-rose-500 border-rose-500/20' }}">
                                        {{ $result->status }}
                                    </span>
                                </div>
                                <p class="text-[11px] text-slate-500 font-bold leading-tight">
                                    {{ $result->notificationMessage ?? $result->shortSummary }}
                                </p>
                            </div>
                        </div>
                    </div>
                    @empty
                    <div class="py-12 bg-slate-900/20 rounded-2xl border-2 border-dashed border-white/5 flex flex-col items-center justify-center text-slate-600">
                        <p class="text-xs font-black uppercase tracking-widest">No diagnostics discovered</p>
                        <p class="text-[10px] mt-2 font-bold opacity-60">Run health:check to populate this view</p>
                    </div>
                    @endforelse
                @endif
            </div>
        </div>

        <div class="space-y-6">
             <h4 class="text-xs font-black uppercase tracking-[0.3em] text-slate-600 px-2 italic">Connectivity Bridge</h4>
             
            <div class="bg-slate-900/40 backdrop-blur-3xl rounded-[1.5rem] border border-white/5 p-8 relative overflow-hidden">
                {{-- Decorative background glow --}}
                <div class="absolute -bottom-24 -right-24 w-64 h-64 bg-indigo-500/5 blur-[100px] rounded-full"></div>
                
                <div class="relative grid grid-cols-1 gap-4">
                    @foreach($stats['services'] as $name => $status)
                    <div class="flex items-center justify-between p-5 bg-slate-950 rounded-2xl border border-white/5 group/service hover:border-indigo-500/30 transition-all duration-500">
                         <div class="flex flex-col">
                             <span class="text-[10px] font-black text-slate-600 uppercase tracking-widest mb-1 leading-none">Service Identity</span>
                             <span class="text-sm font-bold text-white tracking-tight uppercase italic">{{ $name }}</span>
                         </div>
                         <div class="flex items-center">
                             <div class="flex flex-col items-end mr-6">
                                 <span class="text-[8px] font-black uppercase tracking-tighter {{ $status ? 'text-emerald-500' : 'text-rose-500' }}">{{ $status ? 'SECURE CONNECTION' : 'SYSTEM OFFLINE' }}</span>
                                 <span class="text-[10px] font-black text-slate-700 uppercase tracking-widest leading-none mt-1">{{ $status ? 'ONLINE' : 'CRITICAL' }}</span>
                             </div>
                             <div class="w-3 h-3 rounded-full {{ $status ? 'bg-emerald-500 shadow-[0_0_12px_rgba(16,185,129,0.8)]' : 'bg-rose-500 animate-pulse shadow-[0_0_12px_rgba(244,63,94,0.8)]' }}"></div>
                         </div>
                    </div>
                    @endforeach
                </div>
                
                <div class="mt-10 p-6 bg-gradient-to-br from-indigo-600 to-indigo-800 rounded-2xl text-white shadow-2xl relative overflow-hidden group">
                     <div class="absolute inset-0 bg-[url('https://www.transparenttextures.com/patterns/carbon-fibre.png')] opacity-10"></div>
                     <div class="relative">
                         <p class="text-[10px] font-black uppercase tracking-[0.2em] opacity-60">Verified Node Integrity</p>
                         <p class="text-base font-black mt-2 tracking-tight uppercase italic">Primary Gateway: <span class="text-indigo-200">{{ parse_url(config('app.url'), PHP_URL_HOST) }}</span></p>
                     </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
