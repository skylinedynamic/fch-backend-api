@extends('system_monitoring.layouts.admin')

@section('title', 'Fleet Monitor - FCH Admin')

@section('content')
<div class="p-8 animate-fade-in max-w-7xl mx-auto space-y-10">
    <div class="flex justify-between items-end">
        <div>
            <h3 class="text-3xl font-black text-slate-800 dark:text-white tracking-tight">Fleet Monitor</h3>
            <p class="text-slate-500 dark:text-slate-400 mt-1 text-sm font-medium">Global infrastructure reach and server node connectivity.</p>
        </div>
        <div class="flex items-center space-x-3">
             <div class="px-4 py-2 bg-white dark:bg-slate-900 rounded-xl border border-slate-100 dark:border-slate-800 text-[10px] font-black text-slate-400 dark:text-slate-500 shadow-sm uppercase tracking-widest transition-colors">
                Active Nodes: <span class="text-indigo-600 dark:text-indigo-400 ml-1">{{ $hosts->count() }}</span>
            </div>
            <a href="{{ route('dashboard.fleet') }}" class="p-2.5 bg-white dark:bg-slate-900 hover:bg-slate-50 dark:hover:bg-slate-800 rounded-xl border border-slate-100 dark:border-slate-800 shadow-sm transition-all text-slate-400 dark:text-slate-500 hover:text-indigo-600 dark:hover:text-indigo-400">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
            </a>
        </div>
    </div>

    @if($hosts->isEmpty())
    <div class="bg-white dark:bg-slate-900 rounded-2xl p-16 border-2 border-dashed border-slate-200 dark:border-slate-700 text-center flex flex-col items-center transition-colors">
        <div class="w-16 h-16 bg-slate-50 dark:bg-slate-800 text-slate-300 dark:text-slate-500 rounded-2xl flex items-center justify-center mb-6">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
        </div>
        <h4 class="text-xl font-bold text-slate-800 dark:text-white">No active nodes registered</h4>
        <p class="text-slate-400 dark:text-slate-500 text-sm mt-2 max-w-sm font-medium">To begin monitoring your production infrastructure, register your first host node via the artisan CLI.</p>
        <div class="mt-8 p-4 bg-slate-900 dark:bg-slate-950 text-slate-400 rounded-xl text-left font-mono text-xs shadow-xl border border-slate-800 transition-colors">
             <span class="text-indigo-400">php</span> artisan server-monitor:add-host production-server --ip=1.2.3.4
        </div>
    </div>
    @else
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @foreach($hosts as $host)
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm dark:shadow-none overflow-hidden flex flex-col transition-all hover:shadow-md dark:hover:border-slate-700">
            <div class="p-6 pb-4">
                <div class="flex items-center justify-between mb-4">
                    <span class="text-[9px] font-black uppercase tracking-widest text-slate-400 dark:text-slate-500">Node Reachability</span>
                    <div class="flex items-center">
                         <div class="w-2 h-2 rounded-full mr-2 {{ $host->health === 'healthy' ? 'bg-emerald-500' : 'bg-rose-500' }}"></div>
                         <span class="text-[9px] font-black uppercase tracking-widest leading-none {{ $host->health === 'healthy' ? 'text-emerald-500 dark:text-emerald-400' : 'text-rose-500 dark:text-rose-400' }}">{{ $host->health }}</span>
                    </div>
                </div>
                <h4 class="text-xl font-black text-slate-800 dark:text-white tracking-tight">{{ $host->name }}</h4>
                <p class="text-xs font-bold text-slate-400 dark:text-slate-500 mt-1 uppercase tracking-widest">{{ $host->ip }}</p>
            </div>
            <div class="bg-slate-50/50 dark:bg-slate-800/20 flex-1 border-t border-slate-50 dark:border-slate-800 p-6 space-y-4 transition-colors">
                @foreach($host->monitoredChecks as $check)
                <div class="flex items-center justify-between p-3.5 bg-white dark:bg-slate-800/50 rounded-xl border border-slate-100 dark:border-slate-800">
                     <div class="flex items-center space-x-3">
                         <div class="w-1.5 h-1.5 rounded-full {{ $check->status === 'success' ? 'bg-emerald-500' : 'bg-rose-500' }}"></div>
                         <h5 class="text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wide">{{ $check->type }}</h5>
                     </div>
                     <span class="text-[9px] font-black text-slate-400 dark:text-slate-500 uppercase tracking-widest opacity-60">{{ $check->last_run_at ? $check->last_run_at->diffForHumans() : 'Never' }}</span>
                </div>
                @endforeach
            </div>
        </div>
        @endforeach
    </div>
    @endif
</div>
@endsection
