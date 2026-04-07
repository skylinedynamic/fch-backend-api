@extends('system_monitoring.layouts.admin')

@section('title', 'Fleet Monitor - FCH Admin')

@section('content')
<div class="px-10 py-8 animate-fade-in max-w-7xl mx-auto space-y-12">
    {{-- Header Section --}}
    <div class="flex justify-between items-end pb-8 border-b border-white/5">
        <div class="relative">
             <div class="flex items-center space-x-4">
                <div class="w-1.5 h-12 bg-indigo-500 rounded-full shadow-[0_0_15px_rgba(99,102,241,0.5)]"></div>
                <div>
                    <h3 class="text-4xl font-black text-white tracking-tighter uppercase italic leading-none">
                        Fleet <span class="text-indigo-400">Monitor</span>
                    </h3>
                    <p class="text-slate-500 mt-2 text-xs font-black uppercase tracking-[0.4em] flex items-center">
                        <span class="w-1.5 h-1.5 bg-indigo-500 rounded-full mr-3 animate-pulse"></span>
                        GLOBAL INFRASTRUCTURE // DISCOVERED NODES
                    </p>
                </div>
            </div>
        </div>
        
        <div class="flex items-center space-x-4">
             <div class="group px-6 py-3 bg-slate-900/50 backdrop-blur-xl rounded-2xl border border-white/5 flex flex-col items-end transition-all hover:border-indigo-500/30">
                <span class="text-[9px] font-black text-slate-500 uppercase tracking-widest leading-none mb-1">Status Report</span>
                <span class="text-xl font-black text-indigo-400 tabular-nums lowercase italic">{{ $hosts->count() }} ACTIVE</span>
            </div>
            
            <a href="{{ route('dashboard.fleet') }}" class="p-4 bg-slate-900/50 backdrop-blur-xl hover:bg-indigo-600/10 rounded-2xl border border-white/5 text-slate-400 hover:text-indigo-400 transition-all shadow-2xl hover:scale-105 active:scale-95 group">
                <svg class="w-5 h-5 group-hover:rotate-180 transition-transform duration-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
            </a>
        </div>
    </div>

    @if($hosts->isEmpty())
    <div class="relative group mt-10">
        <div class="absolute -inset-1 bg-gradient-to-r from-indigo-500/20 to-slate-500/10 rounded-[3rem] blur opacity-50 group-hover:opacity-100 transition duration-1000"></div>
        <div class="relative bg-slate-900/40 backdrop-blur-3xl rounded-[2.5rem] p-24 border border-white/5 text-center flex flex-col items-center transition-all duration-500">
            <div class="w-24 h-24 bg-slate-950 rounded-3xl border border-white/5 text-slate-700 mb-8 flex items-center justify-center shadow-inner relative overflow-hidden group-hover:border-indigo-500/30 transition-colors">
                <div class="absolute inset-0 bg-indigo-500/5 blur-xl"></div>
                <svg class="w-12 h-12 relative z-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
            </div>
            <h4 class="text-3xl font-black text-white italic uppercase tracking-tighter">No Active Nodes <span class="text-slate-600 italic">Discovered</span></h4>
            <p class="text-slate-500 text-sm mt-4 max-w-sm font-bold uppercase tracking-widest leading-relaxed">Infrastructure unreachable. Register your first host node via the artisan command protocol.</p>
            <div class="mt-12 p-6 bg-slate-950 rounded-2xl text-slate-400 font-mono text-xs shadow-2xl border border-white/5 group-hover:border-indigo-500/40 transition-all duration-500 w-full max-w-md">
                 <span class="text-indigo-400 opacity-60 font-black tracking-widest text-[10px] block mb-2">SHELL PROTOCOL</span>
                 <span class="text-indigo-400">$ php</span> artisan server-monitor:add-host <span class="text-white">production-server</span> --ip=1.2.3.4
            </div>
        </div>
    </div>
    @else
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
        @foreach($hosts as $host)
        <div class="relative group">
            <div class="absolute -inset-0.5 bg-indigo-400/20 rounded-[2rem] blur-md opacity-0 group-hover:opacity-100 transition-all duration-500 pointer-events-none"></div>
            
            <div class="relative bg-slate-900/40 backdrop-blur-3xl rounded-[1.5rem] border border-white/5 overflow-hidden flex flex-col transition-all duration-500 hover:bg-slate-900/60">
                <div class="p-8 pb-6">
                    <div class="flex items-center justify-between mb-8">
                        <span class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-500">NODE REACHABILITY</span>
                        <div class="flex items-center space-x-3">
                             <span class="text-[9px] font-black uppercase tracking-widest leading-none {{ $host->health === 'healthy' ? 'text-indigo-400' : 'text-rose-500' }}">{{ $host->health }}</span>
                             <div class="w-2.5 h-2.5 rounded-full {{ $host->health === 'healthy' ? 'bg-indigo-400 shadow-[0_0_10px_rgba(129,140,248,0.5)]' : 'bg-rose-500 shadow-[0_0_10px_rgba(244,63,94,0.5)]' }}"></div>
                        </div>
                    </div>
                    <div class="flex flex-col">
                        <h4 class="text-xl font-black text-white tracking-widest uppercase italic leading-none drop-shadow-[0_2px_10px_rgba(129,140,248,0.2)]">
                            {{ $host->name }}
                        </h4>
                        <p class="text-[11px] font-black text-indigo-400 mt-3 uppercase tracking-widest opacity-80 decoration-indigo-500/50 underline decoration-2 underline-offset-4 tabular-nums">{{ $host->ip }}</p>
                    </div>
                </div>
                
                <div class="bg-slate-950 flex-1 border-t border-white/5 p-8 space-y-4">
                    <span class="text-[9px] font-black text-slate-600 uppercase tracking-[0.3em] block mb-2">MONITORED CHECKS</span>
                    @forelse($host->monitoredChecks ?? [] as $check)
                    <div class="flex items-center justify-between p-4 bg-slate-900/50 rounded-xl border border-white/5 group/check hover:border-indigo-500/30 transition-all duration-300">
                         <div class="flex items-center space-x-4">
                             <div class="w-2 h-2 rounded-full {{ $check->status === 'success' ? 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.4)]' : 'bg-rose-500' }}"></div>
                             <h5 class="text-xs font-black text-slate-300 uppercase tracking-widest italic group-hover/check:text-white transition-colors">{{ $check->type }}</h5>
                         </div>
                         <span class="text-[9px] font-black text-slate-600 uppercase tracking-tighter opacity-80">{{ $check->last_run_at ? $check->last_run_at->diffForHumans() : 'Never' }}</span>
                    </div>
                    @empty
                    <div class="py-4 text-center text-[9px] font-black text-slate-600 uppercase tracking-widest border border-dashed border-white/5 rounded-xl">
                        No Diagnostic Checks Registered
                    </div>
                    @endforelse
                </div>
            </div>
        </div>
        @endforeach
    </div>
    @endif
</div>
@endsection
