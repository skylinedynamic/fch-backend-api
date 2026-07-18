@extends('system_monitoring.layouts.admin')

@section('title', 'System Resource History - FCH Admin')

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
@endpush

@section('content')
<div class="px-10 py-12 animate-fade-in max-w-7xl mx-auto space-y-12">
    {{-- Header --}}
    <div class="flex justify-between items-end pb-8 border-b border-white/5">
        <div class="relative">
            <div class="absolute -left-4 top-1 w-1 h-12 bg-indigo-500 rounded-full shadow-[0_0_15px_rgba(99,102,241,0.5)]"></div>
            <h3 class="text-4xl font-black text-white tracking-tighter uppercase italic">
                Telemetry <span class="text-indigo-400">Spectrum</span>
            </h3>
            <p class="text-slate-500 mt-2 text-xs font-black uppercase tracking-[0.3em] flex items-center">
                <span class="w-1.5 h-1.5 bg-indigo-500 rounded-full mr-3 animate-pulse"></span>
                MULTI-INSTRUMENT SYSTEM OBSERVATORY • HETEROGENEOUS DATA MODELING
            </p>
        </div>
        
        <div class="bg-slate-900/50 backdrop-blur-xl px-6 py-4 rounded-2xl border border-white/5 flex flex-col items-end">
            <span class="text-[9px] font-black text-slate-500 uppercase tracking-widest leading-none mb-1">Telemetry Count</span>
            <span class="text-lg font-black text-white uppercase italic tracking-tight">{{ $metrics->count() }} DATA POINTS</span>
        </div>
    </div>

    <!-- 1. CPU: Digital Pulse Pulse (Stepped Area) -->
    <div class="relative group">
        <div class="absolute -inset-0.5 bg-gradient-to-r from-indigo-500/20 to-indigo-500/5 rounded-[2rem] blur opacity-0 group-hover:opacity-100 transition duration-1000"></div>
        <div class="relative bg-slate-900/40 backdrop-blur-3xl rounded-[1.5rem] border border-white/5 p-10">
            <div class="flex items-center justify-between mb-8">
                <div class="flex flex-col">
                    <span class="text-[10px] font-black text-indigo-500 uppercase tracking-[0.3em] mb-1">Clock Registry</span>
                    <h4 class="text-xl font-black text-white uppercase tracking-tighter italic">PROCESSOR LOGIC (STEPPED)</h4>
                </div>
                <div class="px-3 py-1.5 bg-indigo-500/10 border border-indigo-500/20 rounded-lg text-[9px] font-black text-indigo-400 uppercase tracking-widest italic">Digital Pulse Mapping</div>
            </div>
            <div class="h-[250px]">
                <canvas id="cpuChart"></canvas>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-10">
        <!-- 2. RAM: Spectrum Analyzer (High Density Bars) -->
        <div class="relative group">
            <div class="absolute -inset-0.5 bg-gradient-to-r from-emerald-500/20 to-emerald-500/5 rounded-[2rem] blur opacity-0 group-hover:opacity-100 transition duration-1000"></div>
            <div class="relative bg-slate-900/40 backdrop-blur-3xl rounded-[1.5rem] border border-white/5 p-10 flex flex-col">
                <div class="flex items-center justify-between mb-8">
                     <div class="flex flex-col">
                        <span class="text-[10px] font-black text-emerald-500 uppercase tracking-[0.3em] mb-1">Buffer Analysis</span>
                        <h4 class="text-xl font-black text-white uppercase tracking-tighter italic">MEMORY SPECTRUM</h4>
                    </div>
                </div>
                <div class="h-[300px]">
                    <canvas id="ramChart"></canvas>
                </div>
            </div>
        </div>

        <!-- 3. Disk: Starfield Persitence (Point Matrix) -->
        <div class="relative group">
            <div class="absolute -inset-0.5 bg-gradient-to-r from-amber-500/20 to-amber-500/5 rounded-[2rem] blur opacity-0 group-hover:opacity-100 transition duration-1000"></div>
            <div class="relative bg-slate-900/40 backdrop-blur-3xl rounded-[1.5rem] border border-white/5 p-10 flex flex-col">
                <div class="flex items-center justify-between mb-8">
                     <div class="flex flex-col">
                        <span class="text-[10px] font-black text-amber-500 uppercase tracking-[0.3em] mb-1">Persistence Vault</span>
                        <h4 class="text-xl font-black text-white uppercase tracking-tighter italic">STORAGE MATRIX</h4>
                    </div>
                </div>
                <div class="h-[300px]">
                    <canvas id="diskChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const rawData = {!! $metrics->toJson() !!};
    const labels = rawData.map(m => {
        const d = new Date(m.created_at);
        return d.toLocaleDateString([], { month: 'short', day: '2-digit' }) + ' ' + 
               d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    });

    const commonOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: {
                beginAtZero: true, max: 100,
                grid: { color: 'rgba(255,255,255,0.03)', drawBorder: false },
                ticks: { color: '#475569', font: { size: 10, weight: '700' }, callback: v => v + '%' }
            },
            x: {
                grid: { display: false },
                ticks: { color: '#475569', font: { size: 9, weight: '700' }, maxTicksLimit: 8 }
            }
        }
    };

    // 1. CPU: Stepped Digital Pulse (Unchanged)
    new Chart(document.getElementById('cpuChart'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                data: rawData.map(m => m.cpu_usage),
                borderColor: '#6366f1',
                borderWidth: 2,
                stepped: 'after',
                fill: true,
                backgroundColor: 'rgba(99, 102, 241, 0.05)',
                pointRadius: 0
            }]
        },
        options: commonOptions
    });

    // 2. RAM: Fluid Neon Spline (Changed)
    new Chart(document.getElementById('ramChart'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                data: rawData.map(m => m.ram_usage),
                borderColor: '#10b981',
                borderWidth: 4,
                cubicInterpolationMode: 'monotone',
                fill: false,
                pointRadius: 0,
                pointHoverRadius: 8,
                pointHoverBackgroundColor: '#10b981',
                pointHoverBorderColor: '#fff',
                pointHoverBorderWidth: 4,
            }]
        },
        options: commonOptions
    });

    // 3. Disk: Pillar Architecture (Changed to Bar)
    const diskCtx = document.getElementById('diskChart').getContext('2d');
    const diskGradient = diskCtx.createLinearGradient(0, 0, 0, 300);
    diskGradient.addColorStop(0, '#f59e0b');
    diskGradient.addColorStop(1, 'rgba(245, 158, 11, 0.1)');

    new Chart(diskCtx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                data: rawData.map(m => m.disk_usage),
                backgroundColor: diskGradient,
                borderRadius: 4,
                borderSkipped: false,
                barPercentage: 0.8,
                categoryPercentage: 1.0,
            }]
        },
        options: commonOptions
    });
});
</script>
@endsection
