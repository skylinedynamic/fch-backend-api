@extends('system_monitoring.layouts.admin')

@section('title', 'System Resource History - FCH Admin')

@section('push_scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
@endsection

@section('content')
<div class="p-8 animate-fade-in max-w-7xl mx-auto space-y-10">
    <div class="mb-10 flex justify-between items-end">
        <div>
            <h3 class="text-3xl font-black text-slate-800 dark:text-white tracking-tight">Resource History</h3>
            <p class="text-slate-500 dark:text-slate-400 mt-1 text-sm font-medium italic">Visualization of the last 100 system performance snapshots.</p>
        </div>
        <div class="bg-white/50 dark:bg-slate-900 text-slate-500 dark:text-slate-400 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest border border-slate-100 dark:border-slate-800 shadow-sm dark:shadow-none">
            Node: {{ gethostname() }}
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-10">
        <!-- CPU Distribution -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl p-8 border border-slate-100 dark:border-slate-800 shadow-sm dark:shadow-none transition-all hover:border-indigo-100 dark:hover:border-slate-700">
            <h4 class="text-sm font-black text-slate-700 dark:text-slate-200 uppercase tracking-widest mb-2">Processor Load (CPU)</h4>
            <p class="text-[10px] text-slate-400 dark:text-slate-500 font-bold uppercase tracking-widest mb-10 border-b border-slate-50 dark:border-slate-800 pb-4">Utilization %</p>
            <div class="h-[200px]">
                <canvas id="cpuChart"></canvas>
            </div>
        </div>

        <!-- RAM Distribution -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl p-8 border border-slate-100 dark:border-slate-800 shadow-sm dark:shadow-none transition-all hover:border-emerald-100 dark:hover:border-slate-700">
            <h4 class="text-sm font-black text-slate-700 dark:text-slate-200 uppercase tracking-widest mb-2">Memory Overhead (RAM)</h4>
            <p class="text-[10px] text-slate-400 dark:text-slate-500 font-bold uppercase tracking-widest mb-10 border-b border-slate-50 dark:border-slate-800 pb-4">Utilization %</p>
            <div class="h-[200px]">
                <canvas id="ramChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Disk Distribution -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl p-8 border border-slate-100 dark:border-slate-800 shadow-sm dark:shadow-none transition-all hover:border-amber-100 dark:hover:border-slate-700 mb-10">
        <h4 class="text-sm font-black text-slate-700 dark:text-slate-200 uppercase tracking-widest mb-2">Storage Persistence (Disk)</h4>
        <p class="text-[10px] text-slate-400 dark:text-slate-500 font-bold uppercase tracking-widest mb-10 border-b border-slate-50 dark:border-slate-800 pb-4">Capacity Filled %</p>
        <div class="h-[250px]">
            <canvas id="diskChart"></canvas>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const data = {!! $metrics->toJson() !!};
    const labels = data.map(m => new Date(m.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}));
    
    // Determine if in dark mode
    const isDark = document.documentElement.classList.contains('dark');
    const gridColor = isDark ? '#1e293b' : '#f8fafc';
    const tickColor = isDark ? '#64748b' : '#94a3b8';
    
    const chartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: { 
                beginAtZero: true, 
                max: 100, 
                grid: { color: gridColor }, 
                ticks: { color: tickColor, font: { size: 10, weight: 'bold' }, padding: 10 } 
            },
            x: { 
                grid: { display: false }, 
                ticks: { color: tickColor, font: { size: 10, weight: 'bold' }, maxTicksLimit: 12 } 
            }
        },
        plugins: { 
            legend: { display: false },
            tooltip: {
                backgroundColor: isDark ? '#0f172a' : '#1e293b',
                padding: 12,
                titleFont: { size: 12, weight: 'bold' },
                bodyFont: { size: 12 },
                cornerRadius: 12,
                displayColors: false
            }
        }
    };

    // Observers to re-render chart grid lines on theme toggle could be added here later

    // CPU Chart
    new Chart(document.getElementById('cpuChart'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                data: data.map(m => m.cpu_usage),
                borderColor: '#6366f1',
                backgroundColor: (context) => {
                    const chart = context.chart;
                    const {ctx, chartArea} = chart;
                    if (!chartArea) return null;
                    const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                    gradient.addColorStop(0, 'rgba(99, 102, 241, 0)');
                    gradient.addColorStop(1, 'rgba(99, 102, 241, 0.15)');
                    return gradient;
                },
                fill: true,
                tension: 0.4,
                pointRadius: 0,
                borderWidth: 3
            }]
        },
        options: chartOptions
    });

    // RAM Chart
    new Chart(document.getElementById('ramChart'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                data: data.map(m => m.ram_usage),
                borderColor: '#10b981',
                backgroundColor: (context) => {
                    const chart = context.chart;
                    const {ctx, chartArea} = chart;
                    if (!chartArea) return null;
                    const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                    gradient.addColorStop(0, 'rgba(16, 185, 129, 0)');
                    gradient.addColorStop(1, 'rgba(16, 185, 129, 0.15)');
                    return gradient;
                },
                fill: true,
                tension: 0.4,
                pointRadius: 0,
                borderWidth: 3
            }]
        },
        options: chartOptions
    });

    // Disk Chart
    new Chart(document.getElementById('diskChart'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                data: data.map(m => m.disk_usage),
                borderColor: '#f59e0b',
                backgroundColor: (context) => {
                    const chart = context.chart;
                    const {ctx, chartArea} = chart;
                    if (!chartArea) return null;
                    const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                    gradient.addColorStop(0, 'rgba(245, 158, 11, 0)');
                    gradient.addColorStop(1, 'rgba(245, 158, 11, 0.15)');
                    return gradient;
                },
                fill: true,
                tension: 0.4,
                pointRadius: 0,
                borderWidth: 3
            }]
        },
        options: chartOptions
    });
});
</script>
@endsection
