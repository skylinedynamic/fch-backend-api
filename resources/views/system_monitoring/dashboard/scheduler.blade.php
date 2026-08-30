@extends('system_monitoring.layouts.admin')

@section('title', 'Scheduler Details - FCH Admin')

@section('content')
<div class="px-10 py-12 animate-fade-in max-w-7xl mx-auto space-y-12">
    {{-- Header --}}
    <div class="flex justify-between items-end pb-8 border-b border-white/5">
        <div class="relative">
            <div class="absolute -left-4 top-1 w-1 h-12 bg-indigo-500 rounded-full shadow-[0_0_15px_rgba(99,102,241,0.5)]"></div>
            <h3 class="text-4xl font-black text-white tracking-tighter uppercase italic">
                Task <span class="text-indigo-400">Registry</span>
            </h3>
            <p class="text-slate-500 mt-2 text-xs font-black uppercase tracking-[0.3em] flex items-center">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full mr-3 animate-pulse"></span>
                SCHEDULED COMMAND OBSERVATORY & RUNTIME FORECAST
            </p>
        </div>

        <div class="flex items-center space-x-4">
            <div class="bg-slate-900/50 backdrop-blur-xl px-6 py-4 rounded-2xl border border-white/5 flex flex-col items-end">
                <span class="text-[9px] font-black text-slate-500 uppercase tracking-widest leading-none mb-1">Registered Tasks</span>
                <span class="text-lg font-black text-white uppercase italic tracking-tight">{{ $events->count() }} COMMANDS</span>
            </div>

            <a href="{{ route('dashboard.scheduler') }}" class="p-4 bg-slate-900/50 backdrop-blur-xl hover:bg-indigo-600/10 rounded-2xl border border-white/5 text-slate-400 hover:text-indigo-400 transition-all shadow-2xl hover:scale-105 active:scale-95 group">
                <svg class="w-5 h-5 group-hover:rotate-180 transition-transform duration-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
            </a>
        </div>
    </div>

    {{-- Cron note --}}
    <div class="p-6 bg-slate-900/40 backdrop-blur-3xl rounded-2xl border border-white/5">
        <div class="flex items-start space-x-4">
            <div class="w-10 h-10 bg-indigo-500/10 rounded-xl flex items-center justify-center text-indigo-500 flex-shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <div>
                <h4 class="text-sm font-black text-white uppercase tracking-widest italic">Server Requirement</h4>
                <p class="text-xs text-slate-500 mt-2 font-bold leading-relaxed">
                    The scheduler must be triggered by a system cron that runs
                    <code class="bg-slate-950 px-2 py-1 rounded text-indigo-400 font-mono">php artisan schedule:run</code>
                    every minute. Without it, the tasks below will never execute.
                </p>
                <code class="block mt-3 text-[10px] font-mono text-slate-400 bg-slate-950 p-3 rounded-xl border border-white/5">
                    * * * * * cd {{ base_path() }} && php artisan schedule:run >> /dev/null 2>&1
                </code>
            </div>
        </div>
    </div>

    {{-- Task list --}}
    <div class="space-y-4">
        <h4 class="text-xs font-black uppercase tracking-[0.3em] text-slate-600 px-2 italic">Registered Tasks</h4>

        @forelse($events as $event)
        <div class="group relative">
            <div class="absolute -inset-0.5 bg-gradient-to-r from-indigo-500/10 to-transparent rounded-[1.5rem] blur opacity-0 group-hover:opacity-100 transition duration-1000"></div>
            <div class="relative bg-slate-900/40 backdrop-blur-3xl rounded-[1.5rem] border border-white/5 p-6 flex flex-col md:flex-row md:items-center justify-between gap-4 transition-all hover:border-white/10">
                <div class="flex items-center space-x-4">
                    <div class="w-12 h-12 bg-indigo-500/10 rounded-xl border border-indigo-500/20 flex items-center justify-center text-indigo-500 flex-shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    </div>
                    <div>
                        <h5 class="text-sm font-black text-white uppercase tracking-widest italic">{{ $event['command'] }}</h5>
                        @if($event['description'] && $event['description'] !== $event['command'])
                            <p class="text-[10px] text-slate-500 font-bold mt-1 uppercase tracking-wider">{{ $event['description'] }}</p>
                        @endif
                    </div>
                </div>

                <div class="flex items-center space-x-6 md:pl-6 md:border-l md:border-white/5">
                    <div class="text-right">
                        <span class="text-[9px] font-black text-slate-500 uppercase tracking-widest leading-none block mb-1">Cron Expression</span>
                        <code class="text-xs font-mono text-indigo-400 font-bold bg-slate-950 px-2 py-1 rounded">{{ $event['expression'] }}</code>
                    </div>
                    <div class="text-right">
                        <span class="text-[9px] font-black text-slate-500 uppercase tracking-widest leading-none block mb-1">Next Run</span>
                        <span class="text-xs font-black text-emerald-400 uppercase tracking-wider">{{ $event['next_run']->diffForHumans() }}</span>
                        <span class="text-[10px] text-slate-600 block">{{ $event['next_run']->format('M d, Y H:i') }}</span>
                    </div>
                </div>
            </div>
        </div>
        @empty
        <div class="py-12 bg-slate-900/20 rounded-2xl border-2 border-dashed border-white/5 flex flex-col items-center justify-center text-slate-600">
            <p class="text-xs font-black uppercase tracking-widest">No scheduled tasks found</p>
        </div>
        @endforelse
    </div>
</div>
@endsection
