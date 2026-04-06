@extends('system_monitoring.layouts.admin')

@section('title', 'System Monitoring Hub - FCH Admin')

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
@endpush

@section('content')
<div class="p-10 animate-fade-in max-w-7xl mx-auto">
    <div class="mb-10 flex justify-between items-end">
        <div>
            <h3 class="text-4xl font-bold text-slate-800 tracking-tight">Monitoring Analytics</h3>
            <p class="text-slate-500 mt-2 text-lg font-light">Detailed historical resource consumption patterns.</p>
        </div>
        <div class="bg-indigo-50 text-indigo-700 px-6 py-3 rounded-2xl text-xs font-bold uppercase tracking-widest border border-indigo-100">
            Node: {{ gethostname() }}
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-10">
        <!-- CPU Distribution -->
        <div class="bg-white rounded-[2.5rem] p-10 border border-slate-200 shadow-sm transition-all hover:shadow-lg">
            <h4 class="text-lg font-bold text-slate-800 mb-2">Processor Load (CPU)</h4>
            <p class="text-xs text-slate-400 font-bold uppercase tracking-widest mb-8">Utilization %</p>
            <div class="h-[250px]">
                <canvas id="cpuChart"></canvas>
            </div>
        </div>

        <!-- RAM Distribution -->
        <div class="bg-white rounded-[2.5rem] p-10 border border-slate-200 shadow-sm transition-all hover:shadow-lg">
            <h4 class="text-lg font-bold text-slate-800 mb-2">Memory Overhead (RAM)</h4>
            <p class="text-xs text-slate-400 font-bold uppercase tracking-widest mb-8">Utilization %</p>
            <div class="h-[250px]">
                <canvas id="ramChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Disk Distribution -->
    <div class="bg-white rounded-[2.5rem] p-10 border border-slate-200 shadow-sm transition-all hover:shadow-lg mb-10">
        <h4 class="text-lg font-bold text-slate-800 mb-2">Storage Persistence (Disk)</h4>
        <p class="text-xs text-slate-400 font-bold uppercase tracking-widest mb-8">Capacity Filled %</p>
        <div class="h-[300px]">
            <canvas id="diskChart"></canvas>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const data = @json($metrics);
    const labels = data.map(m => new Date(m.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}));
    
    const chartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: { 
                beginAtZero: true, 
                max: 100, 
                grid: { color: '#f8fafc' }, 
                ticks: { color: '#94a3b8', font: { size: 10, weight: 'bold' }, padding: 10 } 
            },
            x: { 
                grid: { display: false }, 
                ticks: { color: '#94a3b8', font: { size: 10, weight: 'bold' }, maxTicksLimit: 12 } 
            }
        },
        plugins: { 
            legend: { display: false },
            tooltip: {
                backgroundColor: '#1e293b',
                padding: 12,
                titleFont: { size: 12, weight: 'bold' },
                bodyFont: { size: 12 },
                cornerRadius: 12,
                displayColors: false
            }
        }
    };

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
