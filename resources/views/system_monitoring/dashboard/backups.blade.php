@extends('system_monitoring.layouts.admin')

@section('title', 'Data Safety Portfolio - FCH Admin')

@section('content')
<div class="px-10 py-12 animate-fade-in max-w-7xl mx-auto space-y-12" x-data="{ 
    modalOpen: false,
    modalType: null, {{-- 'restore' or 'delete' --}}
    selectedBackup: null,
    selectedDisk: null,
    
    openModal(type, disk, path) {
        this.modalType = type;
        this.selectedBackup = path;
        this.selectedDisk = disk;
        this.modalOpen = true;
    }
}">
    {{-- Alerts Section --}}
    @if(session('success'))
    <div class="fixed top-8 right-8 z-[100] animate-fade-in">
        <div class="bg-emerald-500/10 backdrop-blur-xl border border-emerald-500/20 px-6 py-4 rounded-2xl flex items-center space-x-4 shadow-[0_0_20px_rgba(16,185,129,0.15)]">
            <div class="w-8 h-8 bg-emerald-500 rounded-full flex items-center justify-center text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
            </div>
            <div>
                <p class="text-white font-black text-xs uppercase tracking-widest">Operation Success</p>
                <p class="text-emerald-400/80 text-[10px] font-bold">{{ session('success') }}</p>
            </div>
            <button @click="$el.parentElement.remove()" class="text-slate-500 hover:text-white transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
    </div>
    @endif

    @if(session('error'))
    <div class="fixed top-8 right-8 z-[100] animate-fade-in">
        <div class="bg-rose-500/10 backdrop-blur-xl border border-rose-500/20 px-6 py-4 rounded-2xl flex items-center space-x-4 shadow-[0_0_20px_rgba(244,63,94,0.15)]">
            <div class="w-8 h-8 bg-rose-500 rounded-full flex items-center justify-center text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <div>
                <p class="text-white font-black text-xs uppercase tracking-widest">Critical Alert</p>
                <p class="text-rose-400/80 text-[10px] font-bold">{{ session('error') }}</p>
            </div>
            <button @click="$el.parentElement.remove()" class="text-slate-500 hover:text-white transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
    </div>
    @endif

    {{-- Rest of the original content --}}
    <div class="flex justify-between items-end pb-8 border-b border-white/5">
        <div class="relative">
            <div class="absolute -left-4 top-1 w-1 h-12 bg-indigo-500 rounded-full shadow-[0_0_15px_rgba(99,102,241,0.5)]"></div>
            <h3 class="text-4xl font-black text-white tracking-tighter uppercase italic">
                Data Safety <span class="text-indigo-400">Portfolio</span>
            </h3>
            <p class="text-slate-500 mt-2 text-xs font-black uppercase tracking-[0.3em] flex items-center">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full mr-3 animate-pulse"></span>
                ACTIVE RECOVERY POINTS & SYSTEM SNAPSHOTS
            </p>
        </div>
        
        <div class="flex items-center space-x-4">
             <div class="group px-6 py-3 bg-slate-900/50 backdrop-blur-xl rounded-2xl border border-white/5 flex flex-col items-end transition-all hover:border-indigo-500/30">
                <span class="text-[9px] font-black text-slate-500 uppercase tracking-widest leading-none mb-1">Total Storage Depth</span>
                <span class="text-xl font-black text-indigo-400 tabular-nums">{{ $backups->first()['storage_used'] ?? '0 B' }}</span>
            </div>
            
            <a href="{{ route('dashboard.backups') }}" class="p-4 bg-slate-900/50 backdrop-blur-xl hover:bg-indigo-600/10 rounded-2xl border border-white/5 text-slate-400 hover:text-indigo-400 transition-all shadow-2xl hover:scale-105 active:scale-95 group">
                <svg class="w-5 h-5 group-hover:rotate-180 transition-transform duration-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
            </a>
        </div>
    </div>

    {{-- Backup Destinations --}}
    @forelse($backups as $destination)
    <div class="relative group">
        {{-- Card Glow Effect --}}
        <div class="absolute -inset-0.5 bg-gradient-to-r {{ $destination['healthy'] ? 'from-emerald-500/20 to-indigo-500/20' : 'from-amber-500/10 to-slate-500/5' }} rounded-[2rem] blur opacity-0 group-hover:opacity-100 transition duration-1000"></div>
        
        <div class="relative bg-slate-900/40 backdrop-blur-3xl rounded-[1.5rem] border border-white/5 overflow-hidden flex flex-col transition-all duration-500">
            {{-- Card Header --}}
            <div class="px-10 py-8 bg-slate-900/60 border-b border-white/5">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-6">
                        <div class="w-16 h-16 bg-slate-950 rounded-2xl border border-white/5 flex items-center justify-center text-indigo-400 shadow-inner group-hover:border-indigo-500/50 transition-colors duration-500 relative overflow-hidden">
                            <div class="absolute inset-0 bg-indigo-500/5 blur-xl"></div>
                            <svg class="w-8 h-8 relative z-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path></svg>
                        </div>
                        <div>
                            <div class="flex items-center space-x-3 mb-2">
                                <span class="px-2 py-0.5 bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 rounded-md text-[9px] font-black uppercase tracking-widest">DRIVE MOUNT</span>
                                <h4 class="text-3xl font-black text-white tracking-tight leading-none italic uppercase">
                                    {{ $destination['disk'] }}
                                </h4>
                            </div>
                            <div class="flex items-center space-x-4">
                                <div class="flex items-center space-x-2">
                                    <div class="w-2.5 h-2.5 {{ $destination['healthy'] ? 'bg-emerald-500 shadow-[0_0_10px_rgba(16,185,129,0.5)]' : 'bg-amber-500 shadow-[0_0_10px_rgba(245,158,11,0.5)]' }} rounded-full"></div>
                                    <span class="text-[11px] font-black uppercase tracking-widest {{ $destination['healthy'] ? 'text-emerald-500' : 'text-amber-500' }}">
                                        {{ $destination['healthy'] ? 'PORTFOLIO OPTIMAL' : 'DISK WARNING' }}
                                    </span>
                                </div>
                                <span class="text-slate-700 font-black text-[10px]">/</span>
                                <div class="flex items-center space-x-2">
                                    <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    <span class="text-[11px] font-black text-slate-400 uppercase tracking-widest">
                                        Last Capture: <span class="text-slate-200 ml-1">{{ $destination['newest_backup'] ? $destination['newest_backup']->diffForHumans() : 'PENDING' }}</span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Per-Disk Statistics --}}
                    <div class="flex flex-col items-end">
                        <span class="text-[10px] font-black text-slate-600 uppercase tracking-[0.2em] mb-1">ALLOCATED SPACE</span>
                        <div class="px-4 py-2 bg-slate-950 rounded-xl border border-white/5 shadow-inner">
                            <span class="text-2xl font-black text-indigo-400 tabular-nums tracking-tighter">{{ $destination['storage_used'] ?? '0 B' }}</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Table Content --}}
            <div class="p-0">
                @if($destination['files']->isEmpty())
                <div class="py-24 flex flex-col items-center justify-center opacity-40 grayscale group-hover:grayscale-0 transition-all duration-1000">
                     <svg class="w-16 h-16 text-slate-700 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path></svg>
                     <p class="text-sm font-black uppercase tracking-[0.3em] text-slate-600">No backup snapshots discovered</p>
                </div>
                @else
                <div class="overflow-hidden">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-950/50 text-xs font-black text-slate-500 uppercase tracking-[0.2em] border-b border-white/5">
                                <th class="px-10 py-6">Snapshot Identifier</th>
                                <th class="px-10 py-6">Capture Timestamp</th>
                                <th class="px-10 py-6">Volume Metric</th>
                                <th class="px-10 py-6 text-right">Data Operations</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            @foreach($destination['files'] as $file)
                            <tr class="group/row hover:bg-white/[0.02] transition-all duration-300">
                                <td class="px-10 py-6">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-2 h-2 bg-slate-700 group-hover/row:bg-indigo-500 rounded-full transition-colors duration-300"></div>
                                        <span class="text-md font-mono font-bold text-slate-200 group-hover/row:text-white transition-colors tracking-tight">
                                            {{ basename($file['path']) }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-10 py-6">
                                    <span class="text-sm font-bold text-slate-400 group-hover/row:text-slate-300 transition-colors">
                                        {{ $file['date']->format('M d, Y') }} <span class="ml-2 text-slate-600 group-hover/row:text-slate-500 italic">{{ $file['date']->format('H:i:s') }}</span>
                                    </span>
                                </td>
                                <td class="px-10 py-6">
                                    <div class="inline-flex items-center px-4 py-1.5 bg-slate-950 rounded-xl border border-white/5 group-hover/row:border-indigo-500/30 transition-all duration-500">
                                        <span class="text-sm font-black tabular-nums text-slate-300 group-hover/row:text-indigo-400 uppercase tracking-tight">
                                             {{ round($file['size'] / 1024 / 1024, 2) }} <span class="text-[10px] text-slate-600 group-hover/row:text-indigo-600/60 font-black ml-1">MB</span>
                                        </span>
                                    </div>
                                </td>
                               <td class="px-10 py-6 text-right">
                                   <div class="flex items-center justify-end space-x-3">
                                       {{-- Download Button --}}
                                       <a href="{{ route('dashboard.backups.download', ['disk' => $destination['disk'], 'path' => $file['path']]) }}" 
                                          class="p-3 bg-slate-950 hover:bg-indigo-600 text-slate-500 hover:text-white rounded-2xl border border-white/5 hover:border-indigo-500 transition-all duration-300 shadow-sm hover:scale-110 active:scale-90" 
                                          title="Fetch Archive">
                                           <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                                       </a>
                                       
                                       {{-- Restore Button --}}
                                       <button @click="openModal('restore', '{{ $destination['disk'] }}', '{{ $file['path'] }}')" 
                                          class="p-3 bg-slate-950 hover:bg-indigo-500/20 text-slate-500 hover:text-indigo-400 rounded-2xl border border-white/5 hover:border-indigo-500 transition-all duration-300 shadow-sm hover:scale-110 active:scale-90" 
                                          title="Initiate Restoration">
                                           <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                                       </button>

                                       {{-- Delete Button --}}
                                       <button @click="openModal('delete', '{{ $destination['disk'] }}', '{{ $file['path'] }}')" 
                                          class="p-3 bg-slate-950 hover:bg-slate-600 text-slate-600 hover:text-white rounded-2xl border border-white/5 hover:border-slate-500 transition-all duration-300 shadow-sm hover:scale-110 active:scale-90" 
                                          title="Permanently Delete">
                                           <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                       </button>
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
    </div>
    @empty
    <div class="p-24 bg-slate-900/40 backdrop-blur-3xl rounded-[2rem] border border-white/5 border-dashed flex flex-col items-center justify-center space-y-4">
        <div class="p-6 bg-slate-950 rounded-3xl border border-white/5 text-slate-700">
             <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
        </div>
        <p class="text-sm font-black uppercase tracking-[0.4em] text-slate-600">Security Protocols: No Destinations Configured</p>
    </div>
    @endforelse

    {{-- Confirmation Modal --}}
    <div x-show="modalOpen" 
         class="fixed inset-0 z-[200] flex items-center justify-center p-6 bg-slate-950/80 backdrop-blur-md"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         style="display: none;">
        
        <div class="bg-slate-900 rounded-[2.5rem] border border-white/10 max-w-lg w-full overflow-hidden shadow-[0_0_50px_rgba(0,0,0,0.5)] relative">
            {{-- Warning Glow --}}
            <div class="absolute -top-24 -left-24 w-48 h-48 blur-[100px] rounded-full"
                 :class="modalType === 'restore' ? 'bg-indigo-500/20' : 'bg-rose-500/20'"></div>
            
            <div class="p-10 relative z-10 text-center">
                {{-- Icon Container --}}
                <div class="w-20 h-20 rounded-3xl border flex items-center justify-center mx-auto mb-8 shadow-inner transition-all duration-500"
                     :class="modalType === 'restore' ? 'bg-indigo-500/10 text-indigo-500 border-indigo-500/30' : 'bg-rose-500/10 text-rose-500 border-rose-500/30'">
                    <template x-if="modalType === 'restore'">
                        <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                    </template>
                    <template x-if="modalType === 'delete'">
                        <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                    </template>
                </div>
                
                <h3 class="text-3xl font-black text-white tracking-tighter uppercase italic mb-4" x-text="modalType === 'restore' ? 'System Restoration' : 'Permanent Purge'"></h3>
                
                <p class="text-slate-400 text-sm font-medium leading-relaxed mb-10">
                    Target Snapshot: <br>
                    <span class="text-white font-mono mt-2 block break-all opacity-80" x-text="selectedBackup"></span>
                    <br>
                    <template x-if="modalType === 'restore'">
                        <span>All current database content will be <span class="text-white font-black underline decoration-indigo-500">overwritten</span>. This is a terminal synchronization.</span>
                    </template>
                    <template x-if="modalType === 'delete'">
                        <span>This action will <span class="text-white font-black underline decoration-rose-500">permanently delete</span> the snapshot from storage depth. Recovery is impossible.</span>
                    </template>
                </p>
                
                <form :action="modalType === 'restore' ? '{{ route('dashboard.backups.restore') }}' : '{{ route('dashboard.backups.delete') }}'" method="POST" class="flex items-center space-x-4">
                    @csrf
                    <template x-if="modalType === 'delete'">
                        @method('DELETE')
                    </template>
                    <input type="hidden" name="disk" :value="selectedDisk">
                    <input type="hidden" name="path" :value="selectedBackup">
                    
                    <button type="button" @click="modalOpen = false" 
                            class="flex-1 py-4 bg-slate-950 text-slate-400 font-black text-xs uppercase tracking-widest rounded-2xl border border-white/5 hover:bg-slate-800 transition-all">
                        Abort Operation
                    </button>
                    
                    <button type="submit" 
                            class="flex-1 py-4 text-white font-black text-xs uppercase tracking-widest rounded-2xl border transition-all shadow-lg"
                            :class="modalType === 'restore' ? 'bg-indigo-600 border-indigo-500 hover:bg-indigo-700 shadow-indigo-500/20' : 'bg-rose-600 border-rose-500 hover:bg-rose-700 shadow-rose-500/20'">
                        <span x-text="modalType === 'restore' ? 'Initiate Sync' : 'Purge Archives'"></span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
