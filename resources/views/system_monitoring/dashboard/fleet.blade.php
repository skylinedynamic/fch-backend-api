@extends('system_monitoring.layouts.admin')

@section('title', 'Fleet Monitor - FCH Admin')

@section('content')
<div class="p-10 animate-fade-in max-w-7xl mx-auto">
    <div class="mb-10 flex justify-between items-end">
        <div>
            <h3 class="text-4xl font-bold text-slate-800 tracking-tight">Fleet Monitor</h3>
            <p class="text-slate-500 mt-2 text-lg font-light">Monitor entire server network via secure SSH snapshots.</p>
        </div>
         <div class="flex items-center space-x-3">
             <div class="px-4 py-2 bg-white rounded-xl border border-slate-200 text-xs font-bold text-slate-500 shadow-sm uppercase tracking-widest">
                Nodes: {{ $hosts->count() }}
            </div>
        </div>
    </div>

    @if($hosts->isEmpty())
    <div class="bg-white rounded-[2.5rem] p-20 border-2 border-dashed border-slate-200 text-center">
        <div class="w-20 h-20 bg-slate-50 rounded-3xl flex items-center justify-center mx-auto mb-6 text-slate-300">
             <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
        </div>
        <h4 class="text-xl font-bold text-slate-800">No nodes configured</h4>
        <p class="text-slate-500 mt-2">Add your first server via CLI: <code class="bg-slate-100 px-2 py-1 rounded text-indigo-600">php artisan server-monitor:add-host</code></p>
    </div>
    @else
    <div class="space-y-8">
        @foreach($hosts as $host)
        <div class="bg-white rounded-[2.5rem] p-10 border border-slate-200 shadow-sm overflow-hidden relative group">
            <div class="flex items-start justify-between mb-10">
                <div class="flex items-center space-x-4">
                     <div class="w-16 h-16 bg-slate-900 rounded-3xl flex items-center justify-center text-white shadow-xl shadow-slate-900/20">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"></path></svg>
                    </div>
                    <div>
                        <h4 class="text-2xl font-bold text-slate-800 tracking-tight">{{ $host->name }}</h4>
                        <p class="text-slate-400 text-sm font-medium mt-1">Host: <span class="text-slate-600">{{ $host->ip }}</span> | SSH Port: {{ $host->port ?? 22 }}</p>
                    </div>
                </div>
                 <span class="text-[10px] font-black uppercase tracking-widest px-4 py-2 rounded-xl bg-slate-50 text-slate-400 border border-slate-100">
                    SSH: {{ $host->ssh_user ?? 'root' }}
                </span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                @foreach($host->checks as $check)
                <div class="bg-slate-50 rounded-3xl p-6 border border-slate-100 transition-all hover:bg-white hover:shadow-md hover:border-slate-200">
                    <div class="flex items-center justify-between mb-4">
                        <h5 class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-500">{{ $check->type }}</h5>
                        <div class="w-2 h-2 rounded-full {{ $check->status === 'success' ? 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.5)]' : ($check->status === 'warning' ? 'bg-amber-500' : 'bg-rose-500') }}"></div>
                    </div>
                    <div class="text-xs font-bold text-slate-800 mb-2">
                        {{ $check->last_run_message ?? 'No data collected' }}
                    </div>
                    <div class="text-[10px] text-slate-400 font-medium italic">
                        Last sync: {{ $check->last_run_at ? $check->last_run_at->diffForHumans() : 'Never' }}
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @endforeach
    </div>
    @endif
</div>
@endsection
