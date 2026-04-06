@extends('system_monitoring.layouts.admin')

@section('title', $title . ' - FCH Admin')

@section('content')
<div class="absolute inset-0 flex flex-col pt-0.5">
    <div class="bg-indigo-600 h-1 px-40">
            <div class="bg-white/20 h-full w-full rounded-full blur-sm"></div>
    </div>
    <div class="flex-1 bg-white relative">
        <div class="absolute inset-x-0 top-0 h-12 bg-white flex items-center px-10 border-b border-slate-100 shadow-sm z-[5]">
                <div class="flex items-center space-x-4">
                <p class="text-xs font-bold text-slate-700 uppercase tracking-widest">
                    {{ $subtitle }}
                </p>
                </div>
                <div class="ml-auto flex items-center space-x-4">
                <a href="{{ $url }}" target="_blank" class="text-[10px] font-bold text-indigo-600 hover:text-indigo-800 uppercase tracking-widest flex items-center transition-all bg-indigo-50 px-3 py-1.5 rounded-full">
                    Full Screen <svg class="w-2.5 h-2.5 ml-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                </a>
                </div>
        </div>
        <iframe src="{{ $url }}" class="w-full h-full border-none pt-12"></iframe>
    </div>
</div>
@endsection
