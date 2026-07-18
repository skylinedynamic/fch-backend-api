<?php

namespace App\Services\Monitoring;

class PrometheusStats
{
    /**
     * Get system metrics for Prometheus gauges.
     */
    public function getMetrics()
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        // 1. CPU
        $cpu = 0;
        if ($isWindows) {
            $cpu = (int)shell_exec('powershell -Command "$v=(Get-CimInstance Win32_Processor | Measure-Object LoadPercentage -Average).Average; if($v -eq $null){0} else {[math]::Round($v)}"');
        }
        else {
            $load = sys_getloadavg();
            $cores = max(1, (int)shell_exec('nproc 2>/dev/null') ?: (int)shell_exec('cat /proc/cpuinfo | grep -c ^processor 2>/dev/null') ?: 1);
            $cpu = ($load && $cores > 0) ? round(($load[0] / $cores) * 100, 2) : 0;
        }

        // 2. RAM
        $ramUsagePercent = 0;
        if ($isWindows) {
            $totalRamKB = (int)shell_exec('powershell -Command "(Get-CimInstance Win32_OperatingSystem).TotalVisibleMemorySize"');
            $freeRamKB = (int)shell_exec('powershell -Command "(Get-CimInstance Win32_OperatingSystem).FreePhysicalMemory"');
            $ramUsagePercent = $totalRamKB > 0 ? (($totalRamKB - $freeRamKB) / $totalRamKB) * 100 : 0;
        }
        else {
            $free = shell_exec('free -m');
            if ($free) {
                $free_arr = explode("\n", trim($free));
                $mem = explode(" ", preg_replace("/\s+/", " ", $free_arr[1]));
                $ramUsagePercent = ($mem[2] / $mem[1]) * 100;
            }
        }

        // 3. Disk
        $diskPath = $isWindows ? "C:" : "/";
        $totalDisk = disk_total_space($diskPath);
        $freeDisk = disk_free_space($diskPath);
        $diskUsagePercent = $totalDisk > 0 ? (($totalDisk - $freeDisk) / $totalDisk) * 100 : 0;

        return [
            'cpu_usage_ratio' => $cpu / 100,
            'memory_usage_ratio' => $ramUsagePercent / 100,
            'disk_usage_ratio' => $diskUsagePercent / 100,
        ];
    }

    /**
     * Get application business metrics (DBS).
     */
    public function getAppMetrics()
    {
        return [
            'total_users' => \App\Models\User\User::count('*'),
        ];
    }
}
