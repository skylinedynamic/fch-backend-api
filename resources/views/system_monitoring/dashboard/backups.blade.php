@extends('system_monitoring.layouts.admin')

@section('title', 'Database Backup History - FCH Admin')

@section('content')
<div class="p-8 animate-fade-in max-w-7xl mx-auto space-y-10">
    <div class="flex justify-between items-end">
        <div>
            <h3 class="text-3xl font-black text-slate-800 dark:text-white tracking-tight">Data Safety Portfolio</h3>
            <p class="text-slate-500 dark:text-slate-400 mt-1 text-sm font-medium">Historical database snapshots and recovery points.</p>
        </div>
        <div class="flex items-center space-x-3">
             <div class="px-4 py-2 bg-white dark:bg-slate-900 rounded-xl border border-slate-100 dark:border-slate-800 text-[10px] font-black text-slate-400 dark:text-slate-500 shadow-sm uppercase tracking-widest transition-colors">
                Storage Used: <span class="text-indigo-600 dark:text-indigo-400 ml-1">{{ $backups->first()['storage_used'] ?? '0 B' }}</span>
            </div>
            <a href="{{ route('dashboard.backups') }}" class="p-2.5 bg-white dark:bg-slate-900 hover:bg-slate-50 dark:hover:bg-slate-800 rounded-xl border border-slate-100 dark:border-slate-800 shadow-sm transition-all text-slate-400 dark:text-slate-500 hover:text-indigo-600 dark:hover:text-indigo-400">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
            </a>
        </div>
    </div>

    @foreach($backups as $destination)
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm dark:shadow-none overflow-hidden flex flex-col transition-all hover:shadow-md dark:hover:border-slate-700 mb-8">
        <div class="p-8 pb-6 border-b border-slate-50 dark:border-slate-800 bg-slate-50/20 dark:bg-slate-800/20 transition-colors">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center space-x-4">
                    <div class="w-12 h-12 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 rounded-xl flex items-center justify-center transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path></svg>
                    </div>
                    <div>
                        <h4 class="text-xl font-black text-slate-800 dark:text-white tracking-tight">Disk Destination: <span class="text-indigo-600 dark:text-indigo-400 uppercase">{{ $destination['disk'] }}</span></h4>
                        <p class="text-[10px] font-black uppercase tracking-[0.2em] {{ $destination['healthy'] ? 'text-emerald-500 dark:text-emerald-400' : 'text-rose-500 dark:text-rose-400' }} mt-1">STATUS: {{ $destination['healthy'] ? 'HEALTHY' : 'ISSUES DETECTED' }}</p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-400 dark:text-slate-500 mb-1">Last Backup</p>
                    <p class="text-sm font-bold text-slate-800 dark:text-slate-300">{{ $destination['newest_backup'] ? $destination['newest_backup']->diffForHumans() : 'Never' }}</p>
                </div>
            </div>
        </div>

        <div class="p-0">
            @if($destination['files']->isEmpty())
            <div class="p-16 text-center text-slate-400 dark:text-slate-500 italic text-sm font-medium transition-colors">
                 No backup snapshots found on this disk.
            </div>
            @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs font-bold border-collapse">
                    <thead class="bg-slate-50/50 dark:bg-slate-800/50 text-slate-400 dark:text-slate-500 uppercase tracking-widest border-b border-slate-50 dark:border-slate-800 text-[9px] transition-colors">
                        <tr>
                            <th class="px-8 py-4">Snapshot Identifier</th>
                            <th class="px-8 py-4">Creation Date</th>
                            <th class="px-8 py-4">Storage Size</th>
                            <th class="px-8 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50 dark:divide-slate-800 transition-colors">
                        @foreach($destination['files'] as $file)
                        <tr class="group hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors">
                            <td class="px-8 py-5 text-slate-700 dark:text-slate-300 font-mono text-[10px] tracking-tight">
                                {{ basename($file['path']) }}
                            </td>
                            <td class="px-8 py-5 text-slate-500 dark:text-slate-400">
                                {{ $file['date']->format('Y-m-d H:i') }}
                            </td>
                            <td class="px-8 py-5">
                                <span class="px-3 py-1 bg-slate-50 dark:bg-slate-800 text-slate-600 dark:text-slate-400 rounded-lg border border-slate-100 dark:border-slate-700 text-[10px] font-black transition-colors">
                                     {{ round($file['size'] / 1024 / 1024, 2) }} MB
                                </span>
                            </td>
                           <td class="px-8 py-5 text-right">
                               <div class="flex items-center justify-end space-x-3">
                                   <a href="{{ route('dashboard.backups.download', ['disk' => $destination['disk'], 'path' => $file['path']]) }}" class="p-2.5 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 rounded-xl hover:bg-indigo-600 dark:hover:bg-indigo-600 hover:text-white dark:hover:text-white transition-all shadow-sm dark:shadow-none border border-indigo-100 dark:border-indigo-800" title="Download Snapshot">
                                       <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                                   </a>
                               </div>
                           </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>
    @endforeach
</div>
@endsection
