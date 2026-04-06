@extends('system_monitoring.layouts.admin')

@section('title', 'Overview - FCH Admin')

@section('content')
<div class="p-10 animate-fade-in max-w-7xl mx-auto">
    <div class="mb-10 bg-gradient-to-br from-indigo-900 to-indigo-800 rounded-3xl p-10 text-white relative overflow-hidden shadow-2xl shadow-indigo-900/20">
        <div class="absolute top-0 right-0 -mr-20 -mt-20 w-80 h-80 bg-white/10 rounded-full blur-3xl"></div>
        <div class="relative z-10">
            <h3 class="text-4xl font-bold mb-4 tracking-tight">Technical Control Panel</h3>
            <p class="text-indigo-100/80 max-w-xl text-lg font-light leading-relaxed">Centralized management interface for FCH Internal Systems. Monitor logs, explore auto-generated API schema, and manage system resources from one professional environment.</p>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-10">
        <div class="bg-white rounded-3xl p-8 border border-slate-200 shadow-sm transition-all hover:shadow-xl hover:-translate-y-1">
            <div class="w-12 h-12 rounded-2xl bg-indigo-50 flex items-center justify-center text-indigo-600 mb-6">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <h4 class="text-2xl font-bold text-slate-800 mb-1">{{ ucfirst(app()->environment()) }}</h4>
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">Environment</p>
        </div>

        <div class="bg-white rounded-3xl p-8 border border-slate-200 shadow-sm transition-all hover:shadow-xl hover:-translate-y-1">
            <div class="w-12 h-12 rounded-2xl bg-emerald-50 flex items-center justify-center text-emerald-600 mb-6 font-bold text-lg">v8</div>
            <h4 class="text-2xl font-bold text-slate-800 mb-1">{{ phpversion() }}</h4>
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">PHP Engine</p>
        </div>

        <div class="bg-white rounded-3xl p-8 border border-slate-200 shadow-sm transition-all hover:shadow-xl hover:-translate-y-1">
            <div class="w-12 h-12 rounded-2xl bg-amber-50 flex items-center justify-center text-amber-600 mb-6">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <h4 class="text-2xl font-bold text-slate-800 mb-1">Stable</h4>
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">System Health</p>
        </div>

        <div class="bg-white rounded-3xl p-8 border border-slate-200 shadow-sm transition-all hover:shadow-xl hover:-translate-y-1">
            <div class="w-12 h-12 rounded-2xl bg-purple-50 flex items-center justify-center text-purple-600 mb-6 font-bold text-lg">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
            </div>
            <h4 class="text-2xl font-bold text-slate-800 mb-1">Secure</h4>
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">Auth Protocol</p>
        </div>
    </div>

    <div class="bg-white rounded-[2rem] border border-slate-200 overflow-hidden shadow-sm">
        <div class="px-10 py-8 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
            <div>
                <h3 class="text-xl font-bold text-slate-800">Operational Summary</h3>
                <p class="text-sm text-slate-500 font-medium">Overview of the current system configuration and available tools.</p>
            </div>
        </div>
        <div class="p-10">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-10">
                <div class="flex items-start space-x-5 group p-4 rounded-3xl transition-all hover:bg-slate-50">
                    <div class="w-14 h-14 rounded-2xl bg-indigo-100 text-indigo-600 flex items-center justify-center flex-shrink-0 group-hover:scale-110 transition-transform">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                    </div>
                    <div>
                        <h5 class="text-lg font-bold text-slate-800 mb-1 group-hover:text-indigo-600 transition-colors">Unified Logging</h5>
                        <p class="text-sm text-slate-500 leading-relaxed">Deep-dive into application behavior. Filter by severity, timeframe, and context to resolve issues rapidly.</p>
                    </div>
                </div>
                <div class="flex items-start space-x-5 group p-4 rounded-3xl transition-all hover:bg-slate-50">
                    <div class="w-14 h-14 rounded-2xl bg-emerald-100 text-emerald-600 flex items-center justify-center flex-shrink-0 group-hover:scale-110 transition-transform">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path></svg>
                    </div>
                    <div>
                        <h5 class="text-lg font-bold text-slate-800 mb-1 group-hover:text-emerald-600 transition-colors">API Specification</h5>
                        <p class="text-sm text-slate-500 leading-relaxed">Interactive Swagger documentation. Test endpoints, view schemas, and integrate with Borrower and Admin services.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
