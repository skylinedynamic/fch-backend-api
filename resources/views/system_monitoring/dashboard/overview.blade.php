@extends('system_monitoring.layouts.admin')

@section('title', 'System Overview - FCH Admin')

@section('content')
<div class="p-8 animate-fade-in max-w-7xl mx-auto space-y-8">
    <!-- Slim Header Hero -->
    <div class="relative overflow-hidden bg-slate-900 dark:bg-slate-950 rounded-2xl p-8 text-white shadow-xl shadow-slate-900/10 dark:shadow-none dark:border dark:border-slate-800 mb-2 transition-colors duration-200">
        <div class="absolute top-0 right-0 -mr-20 -mt-20 w-64 h-64 bg-indigo-500/10 rounded-full blur-[80px] pointer-events-none"></div>
        <div class="absolute bottom-0 left-0 -ml-20 -mb-20 w-48 h-48 bg-indigo-600/5 rounded-full blur-[60px] pointer-events-none"></div>
        
        <div class="relative z-10">
            <div class="flex items-center space-x-3 mb-4">
                <span class="px-3 py-1 bg-indigo-500/10 border border-indigo-500/20 rounded-full text-xs font-black uppercase tracking-widest text-indigo-400">Core Infrastructure</span>
                <span class="w-1.5 h-1.5 rounded-full bg-indigo-400 animate-pulse"></span>
            </div>
            <h3 class="text-4xl font-black tracking-tighter mb-3 leading-none">System <span class="text-indigo-400 italic">Pulse</span></h3>
            <p class="text-slate-400 dark:text-slate-500 text-base font-medium leading-relaxed max-w-2xl bg-gradient-to-r from-slate-400 to-slate-500 dark:from-slate-300 dark:to-slate-400 bg-clip-text text-transparent transition-colors">
                Unified technical interface for FCH Internal Systems. Monitor server integrity, investigate application logs, and explore API specifications from a single source of truth.
            </p>
            
            <div class="mt-8 flex items-center space-x-6 border-t border-white/5 dark:border-slate-800 pt-6 transition-colors">
                <div class="flex items-center">
                    <div class="w-2 h-2 rounded-full bg-emerald-500 mr-2 shadow-[0_0_8px_rgba(16,185,129,0.5)]"></div>
                    <span class="text-xs font-black text-slate-300 dark:text-slate-400 tracking-widest uppercase">Node: {{ gethostname() }}</span>
                </div>
                <div class="flex items-center">
                    <div class="w-2 h-2 rounded-full bg-indigo-500 mr-2 shadow-[0_0_8px_rgba(99,102,241,0.5)]"></div>
                    <span class="text-xs font-black text-slate-300 dark:text-slate-400 tracking-widest uppercase">Env: {{ $env }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Metrics Strip -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-5">
        <!-- CPU Card -->
        <div class="group bg-white dark:bg-slate-900 rounded-2xl p-6 border border-slate-100 dark:border-slate-800 shadow-sm dark:shadow-none transition-all hover:border-indigo-100 dark:hover:border-indigo-900 hover:shadow-indigo-500/5">
             <div class="flex items-center justify-between mb-4">
                 <div class="w-10 h-10 bg-slate-50 dark:bg-slate-800 group-hover:bg-indigo-50 dark:group-hover:bg-indigo-900/30 text-slate-400 dark:text-slate-500 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 rounded-xl flex items-center justify-center transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                 </div>
                 <span class="text-xs font-black text-slate-400 dark:text-slate-500 tracking-[0.2em] uppercase">Processor</span>
             </div>
             <div class="flex items-baseline space-x-1">
                 <p class="text-3xl font-black text-slate-800 dark:text-white tracking-tight">{{ $stats['cpu'] }}</p>
                 <p class="text-base font-bold text-slate-300 dark:text-slate-600">%</p>
             </div>
             <div class="mt-4 w-full bg-slate-50 dark:bg-slate-800 h-1.5 rounded-full overflow-hidden">
                 <div class="h-full bg-indigo-500 rounded-full transition-all duration-700" style="width: {{ $stats['cpu'] }}%"></div>
             </div>
        </div>

        <!-- RAM Card -->
        <div class="group bg-white dark:bg-slate-900 rounded-2xl p-6 border border-slate-100 dark:border-slate-800 shadow-sm dark:shadow-none transition-all hover:border-emerald-100 dark:hover:border-emerald-900/50 hover:shadow-emerald-500/5">
             <div class="flex items-center justify-between mb-4">
                 <div class="w-10 h-10 bg-slate-50 dark:bg-slate-800 group-hover:bg-emerald-50 dark:group-hover:bg-emerald-900/30 text-slate-400 dark:text-slate-500 group-hover:text-emerald-600 dark:group-hover:text-emerald-400 rounded-xl flex items-center justify-center transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path></svg>
                 </div>
                 <span class="text-xs font-black text-slate-400 dark:text-slate-500 tracking-[0.2em] uppercase">Memory</span>
             </div>
             <div class="flex items-baseline space-x-1">
                 <p class="text-3xl font-black text-slate-800 dark:text-white tracking-tight">{{ $stats['ram'] }}</p>
                 <p class="text-base font-bold text-slate-300 dark:text-slate-600">%</p>
             </div>
             <p class="text-xs font-bold text-slate-400 dark:text-slate-500 mt-2 uppercase tracking-wide">{{ $stats['ram_used'] }}G USED</p>
        </div>

        <!-- Integrity -->
        <div class="group bg-white dark:bg-slate-900 rounded-2xl p-6 border border-slate-100 dark:border-slate-800 shadow-sm dark:shadow-none transition-all hover:border-amber-100 dark:hover:border-amber-900/50 hover:shadow-amber-500/5">
             <div class="flex items-center justify-between mb-4">
                 <div class="w-10 h-10 bg-slate-50 dark:bg-slate-800 group-hover:bg-amber-50 dark:group-hover:bg-amber-900/30 text-slate-400 dark:text-slate-500 group-hover:text-amber-600 dark:group-hover:text-amber-400 rounded-xl flex items-center justify-center transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                 </div>
                 <span class="text-xs font-black text-slate-400 dark:text-slate-500 tracking-[0.2em] uppercase">Security</span>
             </div>
             <p class="text-2xl font-black {{ $isHealthy ? 'text-slate-800 dark:text-white' : 'text-rose-600 dark:text-rose-500' }} tracking-tight">
                {{ $isHealthy ? 'Passed' : 'At Risk' }}
             </p>
             <div class="flex items-center mt-2.5">
                 <div class="w-1.5 h-1.5 rounded-full {{ $isHealthy ? 'bg-emerald-500' : 'bg-rose-500' }} mr-2"></div>
                 <span class="text-xs font-black text-slate-400 dark:text-slate-500 uppercase tracking-widest">Diagnostics</span>
             </div>
        </div>

        <!-- Backup -->
        <div class="group bg-white dark:bg-slate-900 rounded-2xl p-6 border border-slate-100 dark:border-slate-800 shadow-sm dark:shadow-none transition-all hover:border-purple-100 dark:hover:border-purple-900/50 hover:shadow-purple-500/5">
             <div class="flex items-center justify-between mb-4">
                 <div class="w-10 h-10 bg-slate-50 dark:bg-slate-800 group-hover:bg-purple-50 dark:group-hover:bg-purple-900/30 text-slate-400 dark:text-slate-500 group-hover:text-purple-600 dark:group-hover:text-purple-400 rounded-xl flex items-center justify-center transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                 </div>
                 <span class="text-xs font-black text-slate-400 dark:text-slate-500 tracking-[0.2em] uppercase">Reliability</span>
             </div>
             <p class="text-2xl font-black text-slate-800 dark:text-white tracking-tight">Snapshot</p>
             <p class="text-xs font-bold text-slate-400 dark:text-slate-500 mt-2.5 uppercase tracking-wide">
                {{ $latestBackupDate ? $latestBackupDate->diffForHumans(null, true) : 'OFFLINE' }}
             </p>
        </div>
    </div>

    <!-- Operational Hub -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Service Diagnostics Table -->
        <div class="lg:col-span-2 bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm dark:shadow-none overflow-hidden flex flex-col transition-colors">
            <div class="px-8 py-6 border-b border-slate-50 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/20 flex justify-between items-center transition-colors">
                <h4 class="text-xs font-black uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Service Runtime Status</h4>
                <div class="flex items-center space-x-2">
                    <span class="text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest">PHP:</span>
                    <span class="px-2.5 py-1 bg-white dark:bg-slate-800 border border-slate-100 dark:border-slate-700 rounded-md text-xs font-black text-slate-800 dark:text-slate-300">{{ $phpVersion }}</span>
                </div>
            </div>
            <div class="p-8">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    @foreach($stats['services'] as $name => $status)
                    <div class="flex items-center justify-between p-5 bg-slate-50/50 dark:bg-slate-800/30 rounded-xl border border-slate-50 dark:border-slate-800 group hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        <div class="flex items-center space-x-4">
                            <div class="w-2 h-2 rounded-full {{ $status ? 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.5)]' : 'bg-rose-500 shadow-[0_0_8px_rgba(244,63,94,0.5)]' }}"></div>
                            <span class="text-sm font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wide">{{ $name }}</span>
                        </div>
                        <span class="text-xs font-black uppercase tracking-widest {{ $status ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-500' }}">{{ $status ? 'Operational' : 'Failure' }}</span>
                    </div>
                    @endforeach
                </div>
                
                <div class="mt-8 pt-8 border-t border-slate-50 dark:border-slate-800 grid grid-cols-2 gap-8 transition-colors">
                    <div>
                        <h5 class="text-xs font-black uppercase tracking-widest text-slate-400 dark:text-slate-500 mb-4 text-center">System Load Trend</h5>
                        <div class="h-8 w-full bg-slate-100 dark:bg-slate-800 rounded-xl relative overflow-hidden flex items-center justify-center">
                             <div class="absolute inset-0 bg-indigo-500/10 dark:bg-indigo-500/20" style="width: {{ $stats['cpu'] }}%"></div>
                             <span class="relative text-[10px] font-black text-indigo-600 dark:text-indigo-400 uppercase tracking-widest">Live: {{ $stats['cpu'] }}% Load</span>
                        </div>
                    </div>
                    <div class="flex items-center justify-center text-center">
                         <div>
                            <p class="text-base font-bold text-slate-800 dark:text-white">Operational Integrity</p>
                            <p class="text-xs text-slate-400 dark:text-slate-500 font-bold uppercase tracking-widest mt-1">Status: {{ $isHealthy ? 'Nominal' : 'Investigate' }}</p>
                         </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Documentation & Logs Space -->
        <div class="space-y-6 flex flex-col">
            <a href="{{ route('dashboard.docs.admin') }}" class="group relative overflow-hidden bg-indigo-600 dark:bg-indigo-700 rounded-2xl p-8 text-white shadow-xl shadow-indigo-600/20 dark:shadow-none transition-all hover:-translate-y-1 block flex-1">
                 <div class="absolute top-0 right-0 -mr-8 -mt-8 w-32 h-32 bg-white/10 rounded-full blur-2xl group-hover:scale-150 transition-transform duration-700"></div>
                 <div class="relative z-10 h-full flex flex-col justify-between">
                     <div>
                        <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center mb-6 backdrop-blur-md">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                        </div>
                        <h4 class="text-2xl font-black tracking-tight mb-2 uppercase text-white">API Schema</h4>
                        <p class="text-indigo-100 text-sm font-medium leading-relaxed opacity-90">Interactive technical specification for all borrower and admin endpoints.</p>
                     </div>
                     <div class="mt-8 flex items-center text-xs font-black uppercase tracking-[0.2em] text-white">
                         Open explorer <svg class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                     </div>
                 </div>
            </a>

            <div class="bg-slate-900 dark:bg-slate-950 rounded-2xl p-8 text-white dark:border dark:border-slate-800 relative overflow-hidden flex flex-col justify-between h-[250px] transition-colors">
                 <div class="relative z-10">
                     <div class="flex items-center justify-between mb-5">
                        <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Standard Streams</span>
                        <div class="px-2.5 py-1 bg-rose-500/10 text-rose-400 text-[10px] font-black rounded-md uppercase">Diagnostics</div>
                     </div>
                     <h4 class="text-xl font-black uppercase tracking-tight text-white mb-2">Error Logs</h4>
                     <p class="text-slate-500 text-sm font-medium leading-relaxed">Direct access to the application debug streams.</p>
                 </div>
                 <a href="{{ route('dashboard.logs') }}" class="relative z-10 w-full py-4 bg-white/5 hover:bg-white/10 dark:bg-slate-800 dark:hover:bg-slate-700 border border-white/5 dark:border-slate-700 text-slate-300 dark:text-slate-200 rounded-xl text-xs font-black text-center uppercase tracking-widest transition-all">
                    Launch LogViewer
                 </a>
            </div>
        </div>
    </div>
</div>
@endsection
