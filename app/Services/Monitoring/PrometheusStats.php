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
            $cpu = (int)shell_exec('powershell -Command "(Get-CimInstance Win32_Processor).LoadPercentage"');
        }
        else {
            $load = sys_getloadavg();
            $cpu = $load ? $load[0] : 0; // Use load average for Linux
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
