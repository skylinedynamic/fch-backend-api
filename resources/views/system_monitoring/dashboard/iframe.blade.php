@extends('system_monitoring.layouts.admin')

@section('title', $title . ' - FCH Admin')

@section('content')
<div class="absolute inset-0 flex flex-col bg-slate-50 dark:bg-slate-950 transition-colors duration-200">
    <div class="h-1 bg-indigo-600 dark:bg-indigo-500 relative">
        <div class="absolute inset-0 bg-white/20 dark:bg-indigo-400/20 blur-sm"></div>
    </div>
    
    <div class="flex-1 flex flex-col relative overflow-hidden">
        <div class="h-14 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md flex items-center px-8 border-b border-slate-200 dark:border-white/5 shadow-sm dark:shadow-2xl z-[10] relative transition-colors duration-200">
            <div class="flex items-center space-x-3">
                <div class="w-1.5 h-1.5 bg-indigo-500 rounded-full animate-pulse shadow-[0_0_8px_rgba(99,102,241,0.5)]"></div>
                <p class="text-[10px] font-black text-slate-500 dark:text-slate-400 uppercase tracking-[0.2em] transition-colors">
                    {{ $subtitle }} <span class="mx-2 text-slate-300 dark:text-slate-700">/</span> <span class="text-indigo-600 dark:text-indigo-400">{{ $title }}</span>
                </p>
            </div>
            <div class="ml-auto flex items-center space-x-3">
                <a href="{{ $url }}" target="_blank" class="group px-4 py-1.5 bg-slate-100 dark:bg-white/5 hover:bg-indigo-600/10 dark:hover:bg-indigo-500/10 border border-slate-200 dark:border-white/5 rounded-xl text-[10px] font-black text-slate-600 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 uppercase tracking-widest flex items-center transition-all">
                    Full Stream <svg class="w-2.5 h-2.5 ml-2 group-hover:translate-x-0.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                </a>
            </div>
        </div>

        <div class="flex-1 relative bg-slate-950">
            <iframe id="monitoring-iframe" src="{{ $url }}" class="w-full h-full border-none opacity-0 transition-all duration-700"></iframe>
            
            <div id="iframe-loader" class="absolute inset-0 flex items-center justify-center bg-white dark:bg-slate-950 z-0 transition-colors">
                <div class="flex flex-col items-center">
                    <div class="w-10 h-10 border-2 border-indigo-500/10 dark:border-indigo-500/20 border-t-indigo-500 rounded-full animate-spin"></div>
                    <p class="mt-4 text-[9px] font-black text-slate-400 dark:text-indigo-500/50 uppercase tracking-[0.4em]">Initializing Interface...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (function() {
        const iframe = document.getElementById('monitoring-iframe');
        const loader = document.getElementById('iframe-loader');

        iframe.onload = () => {
            setTimeout(() => {
                iframe.classList.remove('opacity-0');
                loader.classList.add('hidden');
            }, 150);
        };
    })();
</script>
@endsection
